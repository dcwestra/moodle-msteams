<?php
/**
 * OAuth 2.0 callback endpoint for mod_msteamsecp delegated token setup.
 *
 * Microsoft redirects here after the service account admin authorizes the app.
 * Exchanges the authorization code for access + refresh tokens and stores
 * the refresh token encrypted in the plugin config.
 *
 * URL: /mod/msteamsecp/oauth_callback.php
 * Must be registered as a redirect URI in the Azure app registration.
 *
 * @package    mod_msteamsecp
 * @copyright  2026 Eyecare Partners
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/adminlib.php');

require_login();
require_capability('moodle/site:config', context_system::instance());

$tenant_id     = get_config('mod_msteamsecp', 'tenant_id');
$client_id     = get_config('mod_msteamsecp', 'client_id');
$client_secret = get_config('mod_msteamsecp', 'client_secret');
$redirect_uri  = (new moodle_url('/mod/msteamsecp/oauth_callback.php'))->out(false);

// ── Error from Microsoft ──────────────────────────────────────────────────────
if ($error = optional_param('error', '', PARAM_TEXT)) {
    $desc = optional_param('error_description', 'Unknown error', PARAM_TEXT);
    redirect(
        new moodle_url('/admin/settings.php', ['section' => 'mod_msteamsecp']),
        get_string('oauth_error', 'mod_msteamsecp', $desc),
        null,
        \core\output\notification::NOTIFY_ERROR
    );
}

// ── Authorization code returned ───────────────────────────────────────────────
$code  = required_param('code', PARAM_RAW);
$state = required_param('state', PARAM_ALPHANUMEXT);

// Validate state to prevent CSRF.
$expected_state = get_config('mod_msteamsecp', 'oauth_state');
if (!$expected_state || !hash_equals($expected_state, $state)) {
    redirect(
        new moodle_url('/admin/settings.php', ['section' => 'mod_msteamsecp']),
        get_string('oauth_state_mismatch', 'mod_msteamsecp'),
        null,
        \core\output\notification::NOTIFY_ERROR
    );
}

// Clear the state nonce — single use.
unset_config('oauth_state', 'mod_msteamsecp');

// ── Exchange code for tokens ──────────────────────────────────────────────────
// Use manual rawurlencode string construction — same pattern as get_token() —
// to avoid any http_build_query encoding differences with special characters.
$token_url = 'https://login.microsoftonline.com/' . $tenant_id . '/oauth2/v2.0/token';
$body = 'grant_type=authorization_code'
      . '&client_id=' . rawurlencode($client_id)
      . '&client_secret=' . rawurlencode($client_secret)
      . '&code=' . rawurlencode($code)
      . '&redirect_uri=' . rawurlencode($redirect_uri)
      . '&scope=' . rawurlencode('https://graph.microsoft.com/OnlineMeetings.ReadWrite offline_access');

$ch = curl_init();
curl_setopt_array($ch, [
    CURLOPT_URL            => $token_url,
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => $body,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 30,
    CURLOPT_HTTPHEADER     => ['Content-Type: application/x-www-form-urlencoded'],
]);
$raw  = curl_exec($ch);
$code_http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

$data = json_decode($raw, true);

if ($code_http < 200 || $code_http >= 300 || empty($data['refresh_token'])) {
    $msg = $data['error_description'] ?? $data['error'] ?? "HTTP $code_http";
    redirect(
        new moodle_url('/admin/settings.php', ['section' => 'mod_msteamsecp']),
        get_string('oauth_token_error', 'mod_msteamsecp', $msg),
        null,
        \core\output\notification::NOTIFY_ERROR
    );
}

// ── Store tokens ──────────────────────────────────────────────────────────────
// Refresh token is stored encrypted. Access token is cached with expiry.
$encrypted_refresh = \mod_msteamsecp\api\graph::encrypt_token($data['refresh_token']);
set_config('delegated_refresh_token',  $encrypted_refresh,                        'mod_msteamsecp');
set_config('delegated_access_token',   $data['access_token'],                     'mod_msteamsecp');
set_config('delegated_token_expires',  time() + ($data['expires_in'] ?? 3600),    'mod_msteamsecp');
set_config('delegated_token_stored_at', time(),                                   'mod_msteamsecp');

redirect(
    new moodle_url('/admin/settings.php', ['section' => 'mod_msteamsecp']),
    get_string('oauth_success', 'mod_msteamsecp'),
    null,
    \core\output\notification::NOTIFY_SUCCESS
);
