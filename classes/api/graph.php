<?php
/**
 * Microsoft Graph API client for mod_msteamsecp.
 *
 * Dual-token architecture:
 *   - App-only token (client credentials): used for all calls except meeting creation.
 *     Suitable for background tasks, calendar events, attendance, recordings.
 *   - Delegated token (authorization code / refresh token): used exclusively for
 *     POST/PATCH /me/onlineMeetings so Teams treats the meeting as user-created,
 *     giving co-organisers full permissions without the organiser joining first.
 *
 * The delegated token is obtained once via an admin OAuth flow (oauth_authorize.php
 * → oauth_callback.php) and refreshed automatically from the stored refresh token.
 *
 * @package    mod_msteamsecp
 * @copyright  2026 Eyecare Partners
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_msteamsecp\api;

defined('MOODLE_INTERNAL') || die();

class graph {

    const GRAPH_BASE = 'https://graph.microsoft.com/v1.0';

    /** Safety bound on @odata.nextLink following so a paging loop can't hang cron. */
    const MAX_PAGES = 20;

    // ── App-only token (client credentials) ───────────────────────────────────
    /** @var string|null Cached app-only access token */
    private $token = null;

    /** @var int App-only token expiry unix timestamp */
    private $token_expires = 0;

    // ── Delegated token (authorization code flow) ─────────────────────────────
    /** @var string|null Cached delegated access token */
    private $delegated_token = null;

    /** @var int Delegated token expiry unix timestamp */
    private $delegated_token_expires = 0;

    // ── Config ────────────────────────────────────────────────────────────────
    /** @var string Azure tenant ID */
    private $tenant_id;

    /** @var string Azure app client ID */
    private $client_id;

    /** @var string Azure app client secret */
    private $client_secret;

    /** @var string Service account UPN */
    private $service_account_upn;

    /** @var string|null Resolved object ID of the service account */
    private $service_account_id = null;

    /** @var array Per-request cache of get_user_by_email() lookups, keyed by lower-cased address */
    private $user_cache = [];

    public function __construct() {
        $this->tenant_id           = get_config('mod_msteamsecp', 'tenant_id');
        $this->client_id           = (string) get_config('mod_msteamsecp', 'client_id');
        $this->client_secret       = (string) get_config('mod_msteamsecp', 'client_secret');
        $this->service_account_upn = get_config('mod_msteamsecp', 'service_account_upn');

        if (empty($this->tenant_id) || empty($this->client_id) || empty($this->client_secret)) {
            throw new \moodle_exception('error_graph_not_configured', 'mod_msteamsecp');
        }
    }

    /**
     * Resolve the service account UPN to its Azure AD object ID.
     * Required for onlineMeetings calls with application permissions.
     * Result is cached for the lifetime of this object.
     *
     * @return string Object ID (GUID)
     */
    private function get_service_account_id(): string {
        if ($this->service_account_id) {
            return $this->service_account_id;
        }

        $response = $this->request('GET', '/users/' . urlencode($this->service_account_upn) . '?$select=id');
        if (empty($response['id'])) {
            throw new \moodle_exception('error_graph_request', 'mod_msteamsecp', '',
                'Could not resolve service account UPN to object ID: ' . $this->service_account_upn);
        }

        $this->service_account_id = $response['id'];
        return $this->service_account_id;
    }

    // -------------------------------------------------------------------------
    // User lookup
    // -------------------------------------------------------------------------

    /**
     * Get a user's basic profile by email / UPN.
     * Used to resolve email addresses to object IDs for presenter assignment.
     *
     * @param string $email
     * @return array Graph user object (id, mail, displayName)
     */
    public function get_user_by_email(string $email): array {
        $key = \core_text::strtolower(trim($email));

        // Creating a meeting resolves the same co-organiser addresses more than
        // once (once for the onlineMeeting attendees, again for the calendar
        // event, again during sync_coorganisers). Each lookup is a blocking
        // round-trip inside the form POST, so cache them for the request.
        if (array_key_exists($key, $this->user_cache)) {
            return $this->user_cache[$key];
        }

        try {
            $this->user_cache[$key] = $this->request('GET',
                '/users/' . urlencode($email) . '?$select=id,mail,displayName,userPrincipalName'
            );
        } catch (\Throwable $e) {
            debugging('msteamsecp: could not look up user ' . $email . ': ' . $e->getMessage(), \DEBUG_DEVELOPER);
            $this->user_cache[$key] = [];
        }

        return $this->user_cache[$key];
    }

    // -------------------------------------------------------------------------
    // Meetings
    // -------------------------------------------------------------------------

    /**
     * Create a Teams online meeting via Graph using a delegated token.
     *
     * Uses POST /me/onlineMeetings so Teams treats the meeting as user-created
     * by the service account. This gives co-organisers full permissions from
     * the moment the meeting begins, without the service account needing to join.
     * Falls back to app-only token if no delegated token is configured.
     *
     * @param array $params  Meeting parameters
     * @return array         Graph onlineMeeting object
     */
    public function create_meeting(array $params): array {
        if ($this->has_delegated_token()) {
            debugging('msteamsecp: create_meeting using DELEGATED token via /me/onlineMeetings', \DEBUG_NORMAL);
            $result = $this->request('POST', '/me/onlineMeetings', $params, true);
            debugging('msteamsecp: create_meeting response — meetingType: '
                . json_encode($result['meetingType'] ?? 'missing')
                . ' | lobby: ' . json_encode($result['lobbyBypassSettings'] ?? 'missing')
                . ' | attendees: ' . json_encode(array_column($result['participants']['attendees'] ?? [], 'role')),
                \DEBUG_NORMAL);
            return $result;
        }
        debugging('msteamsecp: create_meeting using APP-ONLY token via /users/{id}/onlineMeetings — delegated token not configured', \DEBUG_NORMAL);
        return $this->request(
            'POST',
            '/users/' . urlencode($this->get_service_account_id()) . '/onlineMeetings',
            $params
        );
    }

    /**
     * Update an existing online meeting.
     *
     * Uses delegated token if available (required for /me/ path).
     * Falls back to app-only with /users/{id}/ path.
     *
     * @param string $meeting_id  Graph onlineMeeting ID
     * @param array  $params      Fields to update
     * @return array
     */
    public function update_meeting(string $meeting_id, array $params): array {
        if ($this->has_delegated_token()) {
            return $this->request('PATCH', '/me/onlineMeetings/' . urlencode($meeting_id), $params, true);
        }
        return $this->request(
            'PATCH',
            '/users/' . urlencode($this->get_service_account_id()) . '/onlineMeetings/' . urlencode($meeting_id),
            $params
        );
    }


    /**
     * Delete an online meeting.
     *
     * @param string $meeting_id
     */
    public function delete_meeting(string $meeting_id): void {
        $this->request(
            'DELETE',
            '/users/' . urlencode($this->get_service_account_id()) . '/onlineMeetings/' . urlencode($meeting_id)
        );
    }

    // -------------------------------------------------------------------------
    // Calendar events — service account (organiser calendar)
    // -------------------------------------------------------------------------

    /**
     * Fetch a calendar event from the service account's calendar.
     * Used before PATCHing attendees to preserve the Teams meeting blob in the body.
     *
     * @param string $event_id
     * @return array
     */
    public function get_event(string $event_id): array {
        return $this->request(
            'GET',
            '/users/' . urlencode($this->get_service_account_id()) . '/events/' . urlencode($event_id)
        );
    }

    /**
     * Create a calendar event on the service account's calendar.
     *
     * @param array $params   Event parameters
     * @param array $options  Optional query parameters e.g. ['sendUpdates' => 'all']
     * @return array          Graph event object
     */
    public function create_event(array $params, array $options = []): array {
        $query = '';
        if (!empty($options['sendUpdates'])) {
            $query = '?sendUpdates=' . urlencode($options['sendUpdates']);
        }
        $result = $this->request(
            'POST',
            '/users/' . urlencode($this->get_service_account_id()) . '/events' . $query,
            $params
        );
        debugging('msteamsecp create_event response — isOnlineMeeting: '
            . json_encode($result['isOnlineMeeting'] ?? 'missing')
            . ' | joinUrl: ' . json_encode($result['onlineMeeting']['joinUrl'] ?? $result['onlineMeetingUrl'] ?? 'missing')
            . ' | meetingId: ' . json_encode($result['onlineMeeting']['id'] ?? 'missing'),
            \DEBUG_NORMAL);
        return $result;
    }

    /**
     * Update a calendar event on the service account's calendar.
     *
     * @param string $event_id
     * @param array  $params
     * @return array
     */
    public function update_event(string $event_id, array $params): array {
        return $this->request(
            'PATCH',
            '/users/' . urlencode($this->get_service_account_id()) . '/events/' . urlencode($event_id),
            $params
        );
    }

    /**
     * Delete a calendar event on the service account's calendar.
     *
     * @param string $event_id
     */
    public function delete_event(string $event_id): void {
        $this->request(
            'DELETE',
            '/users/' . urlencode($this->get_service_account_id()) . '/events/' . urlencode($event_id)
        );
    }

    // -------------------------------------------------------------------------
    // Calendar events — user mailboxes (enrollee calendar push)
    // -------------------------------------------------------------------------

    /**
     * Create a calendar event directly on a specific user's Outlook calendar.
     * Used for learner invites — avoids the service account calendar creating
     * a new Teams meeting automatically due to its default meeting provider.
     *
     * @param string $user_email  Learner's M365 email / UPN
     * @param array  $params      Event parameters
     * @return array              Graph event object including id
     */
    public function create_user_event(string $user_email, array $params): array {
        return $this->request(
            'POST',
            '/users/' . urlencode($user_email) . '/events',
            $params
        );
    }

    /**
     * Delete a calendar event from a specific user's Outlook calendar.
     *
     * @param string $user_email
     * @param string $event_id
     */
    public function delete_user_event(string $user_email, string $event_id): void {
        $this->request(
            'DELETE',
            '/users/' . urlencode($user_email) . '/events/' . urlencode($event_id)
        );
    }

    // -------------------------------------------------------------------------
    // Attendance reports
    // -------------------------------------------------------------------------

    /**
     * Fetch all attendance reports for a meeting.
     *
     * Teams issues a separate report every time a meeting session restarts (the
     * last participant leaves and someone rejoins), and for a recurring meeting
     * the onlineMeeting ID is series-level — so this returns reports for every
     * occurrence held so far. Callers must select the reports belonging to the
     * occurrence they are processing; see
     * post_event_processor::reports_for_occurrence().
     *
     * @param string $meeting_id  Graph onlineMeeting ID
     * @return array              Array of attendanceReport objects, all pages
     */
    public function get_attendance_reports(string $meeting_id): array {
        return $this->request_paged(
            '/users/' . urlencode($this->get_service_account_id())
                . '/onlineMeetings/' . urlencode($meeting_id)
                . '/attendanceReports'
        );
    }

    /**
     * Fetch a specific attendance report with full attendee detail.
     *
     * @param string $meeting_id
     * @param string $report_id
     * @return array
     */
    public function get_attendance_report(string $meeting_id, string $report_id): array {
        return $this->request(
            'GET',
            '/users/' . urlencode($this->get_service_account_id())
                . '/onlineMeetings/' . urlencode($meeting_id)
                . '/attendanceReports/' . urlencode($report_id)
                . '?$expand=attendanceRecords'
        );
    }

    // -------------------------------------------------------------------------
    // Recordings
    // -------------------------------------------------------------------------

    /**
     * List recordings available for a meeting.
     *
     * As with attendance reports, a recurring meeting's ID is series-level, so
     * this returns every recording made across the series.
     *
     * @param string $meeting_id  Graph onlineMeeting ID
     * @return array              Array of recording objects, all pages
     */
    public function get_recordings(string $meeting_id): array {
        return $this->request_paged(
            '/users/' . urlencode($this->get_service_account_id())
                . '/onlineMeetings/' . urlencode($meeting_id)
                . '/recordings'
        );
    }

    /**
     * Download a recording file from its content URL and write it to disk.
     * Streams directly to a temp file to avoid loading large video into memory.
     *
     * @param string $content_url  Recording content URL from Graph
     * @param string $dest_path    Local file path to write to
     * @throws \moodle_exception   On HTTP error or write failure
     */
    public function download_recording_to_file(string $content_url, string $dest_path): void {
        $token = $this->get_token();

        $fp = fopen($dest_path, 'wb');
        if (!$fp) {
            throw new \moodle_exception('error_recording_download', 'mod_msteamsecp', '',
                'Could not open temp file for writing: ' . $dest_path);
        }

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $content_url,
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_FILE           => $fp,
            CURLOPT_TIMEOUT        => 600, // 10 min for large recordings.
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $token],
        ]);

        curl_exec($ch);
        $code  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        fclose($fp);

        if (!empty($error)) {
            throw new \moodle_exception('error_recording_download', 'mod_msteamsecp', '',
                'curl error: ' . $error);
        }

        if ($code < 200 || $code >= 300) {
            throw new \moodle_exception('error_recording_download', 'mod_msteamsecp', '',
                'HTTP ' . $code);
        }
    }

    // -------------------------------------------------------------------------
    // Token management
    // -------------------------------------------------------------------------

    /**
     * Return a valid app-only access token (client credentials flow).
     * Used for all Graph calls except meeting creation/update.
     *
     * @return string
     */
    public function get_token(): string {
        if ($this->token && time() < ($this->token_expires - 60)) {
            return $this->token;
        }

        $url  = 'https://login.microsoftonline.com/' . $this->tenant_id . '/oauth2/v2.0/token';
        $body = 'grant_type=client_credentials'
              . '&client_id=' . rawurlencode($this->client_id)
              . '&client_secret=' . rawurlencode($this->client_secret)
              . '&scope=' . rawurlencode('https://graph.microsoft.com/.default');

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/x-www-form-urlencoded'],
        ]);
        $raw  = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($code < 200 || $code >= 300) {
            throw new \moodle_exception('error_graph_auth', 'mod_msteamsecp', '', $raw);
        }

        $data = json_decode($raw, true);
        if (empty($data['access_token'])) {
            throw new \moodle_exception('error_graph_auth', 'mod_msteamsecp', '', 'No access_token in response');
        }

        $this->token         = $data['access_token'];
        $this->token_expires = time() + ($data['expires_in'] ?? 3600);

        return $this->token;
    }

    /**
     * Check the health of the delegated token and return a status array.
     * Used by lib.php to surface reconnect notifications in web context.
     *
     * @return array {
     *   'state'         => 'ok' | 'warning' | 'expired' | 'missing',
     *   'message'       => string,
     *   'reconnect_url' => string,
     *   'age_days'      => int|null,
     * }
     */
    public static function delegated_token_status(): array {
        global $CFG;

        $reconnect_url = (new \moodle_url('/mod/msteamsecp/oauth_authorize.php'))->out(false);
        $has_token     = !empty(get_config('mod_msteamsecp', 'delegated_refresh_token'));
        $stored_since  = (int) get_config('mod_msteamsecp', 'delegated_token_stored_at');
        $age_days      = $stored_since ? (int) floor((time() - $stored_since) / 86400) : null;

        if (!$has_token) {
            return [
                'state'         => 'missing',
                'message'       => 'Service account not authorized. Meetings will use app-only token — co-organisers may not have full permissions.',
                'reconnect_url' => $reconnect_url,
                'age_days'      => null,
            ];
        }

        // Warn at 75 days — refresh tokens expire at 90.
        if ($age_days !== null && $age_days >= 75) {
            return [
                'state'         => 'warning',
                'message'       => "Delegated token is {$age_days} days old and may expire soon (90-day limit). Re-authorize the service account to avoid disruption.",
                'reconnect_url' => $reconnect_url,
                'age_days'      => $age_days,
            ];
        }

        return [
            'state'         => 'ok',
            'message'       => '',
            'reconnect_url' => $reconnect_url,
            'age_days'      => $age_days,
        ];
    }

    /**
     * Return a valid delegated access token, refreshing from the stored
     * refresh token if needed. Used exclusively for meeting creation/update.
     *
     * @return string
     * @throws \moodle_exception If no refresh token is stored or refresh fails
     */
    public function get_delegated_token(): string {
        // Return cached token if still valid.
        if ($this->delegated_token && time() < ($this->delegated_token_expires - 60)) {
            return $this->delegated_token;
        }

        // Try the cached access token from config first.
        $cached_access   = get_config('mod_msteamsecp', 'delegated_access_token');
        $cached_expires  = (int) get_config('mod_msteamsecp', 'delegated_token_expires');
        if ($cached_access && time() < ($cached_expires - 60)) {
            $this->delegated_token         = $cached_access;
            $this->delegated_token_expires = $cached_expires;
            return $this->delegated_token;
        }

        // Refresh using stored refresh token.
        $encrypted_refresh = get_config('mod_msteamsecp', 'delegated_refresh_token');
        if (empty($encrypted_refresh)) {
            throw new \moodle_exception('error_graph_auth', 'mod_msteamsecp', '',
                'MSTEAMSECP_AUTH_MISSING: No delegated refresh token stored. Please authorize the service account in plugin settings.');
        }

        $refresh_token = self::decrypt_token($encrypted_refresh);
        $url  = 'https://login.microsoftonline.com/' . $this->tenant_id . '/oauth2/v2.0/token';
        $body = 'grant_type=refresh_token'
              . '&client_id=' . rawurlencode($this->client_id)
              . '&client_secret=' . rawurlencode($this->client_secret)
              . '&refresh_token=' . rawurlencode($refresh_token)
              . '&scope=' . rawurlencode('https://graph.microsoft.com/OnlineMeetings.ReadWrite offline_access');

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/x-www-form-urlencoded'],
        ]);
        $raw  = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $data = json_decode($raw, true);

        if ($code < 200 || $code >= 300 || empty($data['access_token'])) {
            // Clear stored tokens so the settings page shows "not connected".
            unset_config('delegated_refresh_token', 'mod_msteamsecp');
            unset_config('delegated_access_token',  'mod_msteamsecp');
            unset_config('delegated_token_expires',  'mod_msteamsecp');
            unset_config('delegated_token_stored_at', 'mod_msteamsecp');

            $error = $data['error'] ?? '';
            $msg   = $data['error_description'] ?? $data['error'] ?? "HTTP $code";

            // invalid_grant = refresh token expired or revoked.
            $prefix = ($error === 'invalid_grant')
                ? 'MSTEAMSECP_AUTH_EXPIRED'
                : 'MSTEAMSECP_AUTH_FAILED';

            throw new \moodle_exception('error_graph_auth', 'mod_msteamsecp', '',
                $prefix . ': ' . $msg . '. Please re-authorize the service account.');
        }

        // Store new tokens — Microsoft issues a new refresh token each time.
        $new_encrypted = self::encrypt_token($data['refresh_token']);
        set_config('delegated_refresh_token',  $new_encrypted,          'mod_msteamsecp');
        set_config('delegated_access_token',   $data['access_token'],   'mod_msteamsecp');
        set_config('delegated_token_expires',  time() + ($data['expires_in'] ?? 3600), 'mod_msteamsecp');
        // Preserve the original stored_at timestamp — only update on fresh auth.

        $this->delegated_token         = $data['access_token'];
        $this->delegated_token_expires = time() + ($data['expires_in'] ?? 3600);

        return $this->delegated_token;
    }

    /**
     * Check whether a valid delegated refresh token is stored.
     *
     * @return bool
     */
    public function has_delegated_token(): bool {
        return !empty(get_config('mod_msteamsecp', 'delegated_refresh_token'));
    }

    /**
     * Encrypt a token for storage using AES-256-CBC with a key derived
     * from the Moodle site's $CFG->passwordsaltmain.
     *
     * @param string $token
     * @return string  Base64-encoded IV + ciphertext
     */
    public static function encrypt_token(string $token): string {
        global $CFG;
        $key = substr(hash('sha256', $CFG->passwordsaltmain ?? $CFG->wwwroot, true), 0, 32);
        $iv  = random_bytes(16);
        $enc = openssl_encrypt($token, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv);
        return base64_encode($iv . $enc);
    }

    /**
     * Decrypt a token stored by encrypt_token().
     *
     * @param string $stored  Base64-encoded IV + ciphertext
     * @return string         Plaintext token
     */
    public static function decrypt_token(string $stored): string {
        global $CFG;
        $key  = substr(hash('sha256', $CFG->passwordsaltmain ?? $CFG->wwwroot, true), 0, 32);
        $raw  = base64_decode($stored);
        $iv   = substr($raw, 0, 16);
        $enc  = substr($raw, 16);
        return openssl_decrypt($enc, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv);
    }

    // -------------------------------------------------------------------------
    // HTTP core
    // -------------------------------------------------------------------------

    /**
     * Make an authenticated Graph API request.
     *
     * @param string     $method     GET | POST | PATCH | DELETE
     * @param string     $path       Graph path starting with /
     * @param array|null $body       Request body (will be JSON-encoded)
     * @param bool       $delegated  If true, use delegated token instead of app-only token
     * @return array                 Decoded JSON response (empty array for 204)
     */
    /**
     * GET a collection endpoint, following @odata.nextLink until exhausted.
     *
     * Graph pages collection responses. Reading only $response['value'] returns
     * the first page and silently drops the rest — which for a long-running
     * recurring meeting means losing older attendance reports and recordings.
     *
     * @param string $path  Path relative to GRAPH_BASE
     * @return array        Concatenated 'value' entries from every page
     */
    public function request_paged(string $path): array {
        $items = [];
        $pages = 0;

        while ($path !== '' && $pages < self::MAX_PAGES) {
            $response = $this->request('GET', $path);
            $pages++;

            foreach ($response['value'] ?? [] as $item) {
                $items[] = $item;
            }

            // nextLink is an absolute URL — convert it back to a GRAPH_BASE
            // relative path. Anything pointing elsewhere (e.g. /beta) is not
            // followed.
            $next = (string) ($response['@odata.nextLink'] ?? '');
            if ($next === '' || strpos($next, self::GRAPH_BASE . '/') !== 0) {
                break;
            }
            $path = substr($next, strlen(self::GRAPH_BASE));
        }

        return $items;
    }

    public function request(string $method, string $path, ?array $body = null, bool $delegated = false): array {
        global $CFG;
        // Moodle's \curl class lives in lib/filelib.php — not always autoloaded in cron context.
        require_once($CFG->libdir . '/filelib.php');

        $url  = self::GRAPH_BASE . $path;
        $curl = new \curl(['ignoresecurity' => true]);
        $curl->setopt(['CURLOPT_RETURNTRANSFER' => true, 'CURLOPT_TIMEOUT' => 60]);
        $curl->setHeader([
            'Authorization: Bearer ' . ($delegated ? $this->get_delegated_token() : $this->get_token()),
            'Content-Type: application/json',
            'Accept: application/json',
            'Prefer: include-unknown-enum-members',
        ]);

        $json = $body !== null ? json_encode($body, JSON_UNESCAPED_UNICODE) : null;

        switch (strtoupper($method)) {
            case 'GET':
                $raw = $curl->get($url);
                break;
            case 'POST':
                $raw = $curl->post($url, $json ?? '{}');
                break;
            case 'PATCH':
                $raw = $curl->patch($url, $json ?? '{}');
                break;
            case 'DELETE':
                $raw = $curl->delete($url);
                break;
            default:
                throw new \moodle_exception('error_graph_method', 'mod_msteamsecp', '', $method);
        }

        $code = $curl->get_info()['http_code'] ?? 0;

        if ($code === 204) {
            return []; // No content — success.
        }

        if ($code === 401) {
            $this->token = null;
            throw new \moodle_exception('error_graph_auth', 'mod_msteamsecp', '', 'Token rejected (401)');
        }

        if ($code < 200 || $code >= 300) {
            throw new \moodle_exception('error_graph_request', 'mod_msteamsecp', '',
                "HTTP $code for $method $path: " . substr($raw, 0, 500));
        }

        if (empty($raw)) {
            return [];
        }

        $decoded = json_decode($raw, true);
        if ($decoded === null) {
            throw new \moodle_exception('error_graph_response', 'mod_msteamsecp', '',
                'Non-JSON response: ' . substr($raw, 0, 200));
        }

        return $decoded;
    }
}
