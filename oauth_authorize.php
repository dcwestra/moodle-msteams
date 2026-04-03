<?php
/**
 * Initiates the OAuth 2.0 authorization code flow for the service account.
 *
 * Generates a CSRF state nonce, stores it, then redirects the admin browser
 * to the Microsoft authorization endpoint. Microsoft returns to oauth_callback.php.
 *
 * @package    mod_msteamsecp
 * @copyright  2026 Eyecare Partners
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/adminlib.php');

require_login();
require_capability('moodle/site:config', context_system::instance());

$tenant_id    = get_config('mod_msteamsecp', 'tenant_id');
$client_id    = get_config('mod_msteamsecp', 'client_id');
$redirect_uri = (new moodle_url('/mod/msteamsecp/oauth_callback.php'))->out(false);

if (empty($tenant_id) || empty($client_id)) {
    redirect(
        new moodle_url('/admin/settings.php', ['section' => 'mod_msteamsecp']),
        get_string('oauth_not_configured', 'mod_msteamsecp'),
        null,
        \core\output\notification::NOTIFY_ERROR
    );
}

// Generate and store CSRF state nonce.
$state = bin2hex(random_bytes(16));
set_config('oauth_state', $state, 'mod_msteamsecp');

$auth_url = 'https://login.microsoftonline.com/' . $tenant_id . '/oauth2/v2.0/authorize?' . http_build_query([
    'client_id'     => $client_id,
    'response_type' => 'code',
    'redirect_uri'  => $redirect_uri,
    'scope'         => 'https://graph.microsoft.com/OnlineMeetings.ReadWrite offline_access',
    'state'         => $state,
    'prompt'        => 'select_account',
]);

redirect($auth_url);
