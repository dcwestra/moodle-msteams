<?php
/**
 * Microsoft Graph API client for mod_msteamsecp.
 *
 * Handles OAuth2 client_credentials token acquisition and all Graph
 * calls needed by the plugin:
 *   - Create / update / delete onlineMeetings
 *   - Create / update / delete calendar events
 *   - Push calendar events to user mailboxes
 *   - Fetch attendance reports
 *   - Fetch and download recordings from OneDrive
 *
 * @package    mod_msteamsecp
 * @copyright  2026 Eyecare Partners
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_msteamsecp\api;

defined('MOODLE_INTERNAL') || die();

class graph {

    const GRAPH_BASE = 'https://graph.microsoft.com/v1.0';

    /** @var string Cached access token */
    private $token = null;

    /** @var int Token expiry unix timestamp */
    private $token_expires = 0;

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

    public function __construct() {
        $this->tenant_id           = get_config('mod_msteamsecp', 'tenant_id');
        $this->client_id           = get_config('mod_msteamsecp', 'client_id');
        $this->client_secret       = get_config('mod_msteamsecp', 'client_secret');
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
        try {
            return $this->request('GET',
                '/users/' . urlencode($email) . '?$select=id,mail,displayName,userPrincipalName'
            );
        } catch (\Throwable $e) {
            debugging('msteamsecp: could not look up user ' . $email . ': ' . $e->getMessage(), DEBUG_DEVELOPER);
            return [];
        }
    }

    // -------------------------------------------------------------------------
    // Meetings
    // -------------------------------------------------------------------------

    /**
     * Create a Teams online meeting via Graph.
     *
     * Uses the standard POST /onlineMeetings endpoint. The Prefer header
     * is set globally in request() so the coorganizer role value in
     * participants.attendees is accepted and returned correctly.
     *
     * @param array $params  Meeting parameters including participants for co-organisers
     * @return array         Graph onlineMeeting object
     */
    public function create_meeting(array $params): array {
        return $this->request(
            'POST',
            '/users/' . urlencode($this->get_service_account_id()) . '/onlineMeetings',
            $params
        );
    }

    /**
     * Update an existing online meeting.
     *
     * @param string $meeting_id  Graph onlineMeeting ID
     * @param array  $params      Fields to update
     * @return array
     */
    public function update_meeting(string $meeting_id, array $params): array {
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
     * @param array $params  Event parameters
     * @return array         Graph event object
     */
    public function create_event(array $params): array {
        return $this->request(
            'POST',
            '/users/' . urlencode($this->get_service_account_id()) . '/events',
            $params
        );
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
     * Push a calendar event to a specific user's Outlook calendar.
     *
     * @param string $user_email  Enrolees M365 email / UPN
     * @param array  $params      Event parameters (subject, start, end, joinUrl etc.)
     * @return array              Graph event object including id
     */
    public function push_event_to_user(string $user_email, array $params): array {
        return $this->request(
            'POST',
            '/users/' . urlencode($user_email) . '/events',
            $params
        );
    }

    /**
     * Remove a previously pushed calendar event from a user's calendar.
     *
     * @param string $user_email
     * @param string $event_id    Graph event ID stored in msteamsecp_enrollee_events
     */
    public function remove_event_from_user(string $user_email, string $event_id): void {
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
     * @param string $meeting_id  Graph onlineMeeting ID
     * @return array              Array of attendanceReport objects
     */
    public function get_attendance_reports(string $meeting_id): array {
        $response = $this->request(
            'GET',
            '/users/' . urlencode($this->get_service_account_id())
                . '/onlineMeetings/' . urlencode($meeting_id)
                . '/attendanceReports'
        );
        return $response['value'] ?? [];
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
     * @param string $meeting_id  Graph onlineMeeting ID
     * @return array              Array of recording objects
     */
    public function get_recordings(string $meeting_id): array {
        $response = $this->request(
            'GET',
            '/users/' . urlencode($this->get_service_account_id())
                . '/onlineMeetings/' . urlencode($meeting_id)
                . '/recordings'
        );
        return $response['value'] ?? [];
    }

    /**
     * Download a recording file from its content URL.
     * Returns the raw binary content.
     *
     * @param string $content_url  Recording content URL from Graph
     * @return string              Raw file binary
     */
    public function download_recording(string $content_url): string {
        $curl = new \curl(['ignoresecurity' => true]);
        $curl->setopt(['CURLOPT_RETURNTRANSFER' => true, 'CURLOPT_TIMEOUT' => 300]);
        $curl->setHeader(['Authorization: Bearer ' . $this->get_token()]);

        $raw  = $curl->get($content_url);
        $code = $curl->get_info()['http_code'] ?? 0;

        if ($code < 200 || $code >= 300) {
            throw new \moodle_exception('error_recording_download', 'mod_msteamsecp', '', $code);
        }

        return $raw;
    }

    // -------------------------------------------------------------------------
    // Token management
    // -------------------------------------------------------------------------

    /**
     * Return a valid access token, fetching a new one if needed.
     *
     * @return string
     */
    public function get_token(): string {
        if ($this->token && time() < ($this->token_expires - 60)) {
            return $this->token;
        }

        $url  = 'https://login.microsoftonline.com/' . $this->tenant_id . '/oauth2/v2.0/token';

        // Use native PHP curl for the token request — Moodle's curl wrapper
        // can re-encode the POST body in ways that cause Azure to reject it
        // with AADSTS7000216 (missing client_secret).
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

    // -------------------------------------------------------------------------
    // HTTP core
    // -------------------------------------------------------------------------

    /**
     * Make an authenticated Graph API request.
     *
     * Uses Moodle's curl wrapper consistent with v1.1.0/v1.2.0 which were
     * confirmed working. The Prefer header ensures the coorganizer role enum
     * value is accepted and returned by Graph.
     *
     * @param string     $method   GET | POST | PATCH | DELETE
     * @param string     $path     Graph path starting with /
     * @param array|null $body     Request body (will be JSON-encoded)
     * @return array               Decoded JSON response (empty array for 204)
     */
    public function request(string $method, string $path, ?array $body = null): array {
        $url  = self::GRAPH_BASE . $path;
        $curl = new \curl(['ignoresecurity' => true]);
        $curl->setopt(['CURLOPT_RETURNTRANSFER' => true, 'CURLOPT_TIMEOUT' => 60]);
        $curl->setHeader([
            'Authorization: Bearer ' . $this->get_token(),
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
