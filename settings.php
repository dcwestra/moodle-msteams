<?php
/**
 * Plugin-level admin settings.
 *
 * @package    mod_msteamsecp
 * @copyright  2026 Eyecare Partners
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
defined('MOODLE_INTERNAL') || die();

/**
 * Masked credential setting — stores plaintext, renders as a password input.
 *
 * The stored value is never sent back to the browser. Leaving the field blank
 * on save preserves the existing value. Credentials are protected by RDS/DB
 * encryption at rest and Moodle's own access controls.
 */
if (!class_exists('mod_msteamsecp_setting_masked_credential')) {
class mod_msteamsecp_setting_masked_credential extends admin_setting {

    public function get_setting() {
        // Return empty string so Moodle doesn't treat it as a new/unsaved setting.
        $stored = $this->config_read($this->name);
        return ($stored !== null) ? '' : '';
    }

    public function get_defaultsetting() {
        return '';
    }

    public function write_setting($data) {
        $data = trim($data);
        if ($data === '') {
            // Blank = keep existing — do not overwrite.
            return '';
        }
        $this->config_write($this->name, $data);
        return '';
    }

    public function output_html($data, $query = '') {
        $stored    = $this->config_read($this->name);
        $has_value = !empty($stored);

        $desc = $this->description;
        if ($has_value) {
            $desc .= ' <span class="badge badge-success">Saved</span>'
                   . ' — leave blank to keep existing value.';
        }

        $input = html_writer::empty_tag('input', [
            'type'        => 'password',
            'id'          => $this->get_id(),
            'name'        => $this->get_full_name(),
            'value'       => '',
            'class'       => 'form-control',
            'autocomplete'=> 'new-password',
            'placeholder' => $has_value ? '••••••••' : '',
        ]);

        return format_admin_setting($this, $this->visiblename,
            html_writer::div($input, 'form-password'),
            $desc, true, '', null, $query
        );
    }
} // end class
} // end if class_exists

if ($hassiteconfig) {
    $settings = new admin_settingpage('mod_msteamsecp', get_string('pluginname', 'mod_msteamsecp'));

    // ── Azure / Graph credentials ─────────────────────────────────────────
    $settings->add(new admin_setting_heading(
        'mod_msteamsecp/graph_heading',
        get_string('settings_graph_heading', 'mod_msteamsecp'), ''
    ));

    $settings->add(new admin_setting_configtext(
        'mod_msteamsecp/tenant_id',
        get_string('settings_tenant_id', 'mod_msteamsecp'),
        get_string('settings_tenant_id_desc', 'mod_msteamsecp'), ''
    ));

    $settings->add(new mod_msteamsecp_setting_masked_credential(
        'mod_msteamsecp/client_id',
        get_string('settings_client_id', 'mod_msteamsecp'),
        get_string('settings_client_id_desc', 'mod_msteamsecp'), ''
    ));

    $settings->add(new mod_msteamsecp_setting_masked_credential(
        'mod_msteamsecp/client_secret',
        get_string('settings_client_secret', 'mod_msteamsecp'),
        get_string('settings_client_secret_desc', 'mod_msteamsecp'), ''
    ));

    $settings->add(new admin_setting_configtext(
        'mod_msteamsecp/service_account_upn',
        get_string('settings_service_account_upn', 'mod_msteamsecp'),
        get_string('settings_service_account_upn_desc', 'mod_msteamsecp'), ''
    ));

    // ── Delegated auth (service account OAuth) ────────────────────────────
    $settings->add(new admin_setting_heading(
        'mod_msteamsecp/oauth_heading',
        get_string('settings_oauth_heading', 'mod_msteamsecp'), ''
    ));

    // Show connection status and authorize button — only query token status
    // when actually on the plugin settings page, not on every admin page load.
    $is_settings_page = optional_param('section', '', PARAM_ALPHANUMEXT) === 'mod_msteamsecp';
    if ($is_settings_page) {
        $token_status  = \mod_msteamsecp\api\graph::delegated_token_status();
    } else {
        $token_status = ['state' => 'unknown'];
    }
    $authorize_url = new moodle_url('/mod/msteamsecp/oauth_authorize.php');

    if ($token_status['state'] === 'ok') {
        $age_str = $token_status['age_days'] !== null
            ? ' (' . $token_status['age_days'] . ' days old)'
            : '';
        $status_html = html_writer::tag('span',
            '✅ ' . get_string('oauth_status_connected', 'mod_msteamsecp') . $age_str,
            ['style' => 'color:#1a6b1a; font-weight:bold;']
        );
        $status_html .= html_writer::empty_tag('br') . html_writer::empty_tag('br');
        $status_html .= html_writer::link(
            $authorize_url,
            get_string('oauth_reauthorize', 'mod_msteamsecp'),
            ['class' => 'btn btn-secondary btn-sm']
        );
    } elseif ($token_status['state'] === 'warning') {
        $status_html = html_writer::tag('span',
            '⚠️ ' . $token_status['message'],
            ['style' => 'color:#8a6d00; font-weight:bold;']
        );
        $status_html .= html_writer::empty_tag('br') . html_writer::empty_tag('br');
        $status_html .= html_writer::link(
            $authorize_url,
            get_string('oauth_reauthorize', 'mod_msteamsecp'),
            ['class' => 'btn btn-warning btn-sm']
        );
    } elseif ($token_status['state'] === 'unknown') {
        // Not on the settings page — show a placeholder link only.
        $status_html = html_writer::link(
            $authorize_url,
            get_string('oauth_authorize_btn', 'mod_msteamsecp'),
            ['class' => 'btn btn-primary btn-sm']
        );
    } else {
        $status_html = html_writer::tag('span',
            '⚠️ ' . get_string('oauth_status_not_connected', 'mod_msteamsecp'),
            ['style' => 'color:#8a6d00; font-weight:bold;']
        );
        $status_html .= html_writer::empty_tag('br');
        $status_html .= html_writer::tag('small',
            get_string('oauth_status_not_connected_desc', 'mod_msteamsecp'),
            ['style' => 'color:#666;']
        );
        $status_html .= html_writer::empty_tag('br') . html_writer::empty_tag('br');
        $status_html .= html_writer::link(
            $authorize_url,
            get_string('oauth_authorize_btn', 'mod_msteamsecp'),
            ['class' => 'btn btn-primary btn-sm']
        );
    }

    $settings->add(new admin_setting_heading(
        'mod_msteamsecp/oauth_status_display',
        get_string('settings_oauth_status', 'mod_msteamsecp'),
        $status_html
    ));

    // ── Role mapping ──────────────────────────────────────────────────────
    $settings->add(new admin_setting_heading(
        'mod_msteamsecp/roles_heading',
        get_string('settings_roles_heading', 'mod_msteamsecp'), ''
    ));

    $settings->add(new admin_setting_configtext(
        'mod_msteamsecp/coorganiser_roles',
        get_string('settings_coorganiser_roles', 'mod_msteamsecp'),
        get_string('settings_coorganiser_roles_desc', 'mod_msteamsecp'),
        'editingteacher,teacher,manager'
    ));

    // ── Defaults ──────────────────────────────────────────────────────────
    $settings->add(new admin_setting_heading(
        'mod_msteamsecp/defaults_heading',
        get_string('settings_defaults_heading', 'mod_msteamsecp'), ''
    ));

    $settings->add(new admin_setting_configselect(
        'mod_msteamsecp/default_lobby_bypass',
        get_string('settings_default_lobby_bypass', 'mod_msteamsecp'),
        get_string('settings_default_lobby_bypass_desc', 'mod_msteamsecp'),
        'organizer',
        [
            'organizer'                   => get_string('lobby_bypass_coorganizers',               'mod_msteamsecp'),
            'invited'                     => get_string('lobby_bypass_invited',                    'mod_msteamsecp'),
            'organization'                => get_string('lobby_bypass_organization',               'mod_msteamsecp'),
            'organizationExcludingGuests' => get_string('lobby_bypass_organization_no_guests',     'mod_msteamsecp'),
            'organizationAndFederated'    => get_string('lobby_bypass_organization_and_federated', 'mod_msteamsecp'),
            'everyone'                    => get_string('lobby_bypass_everyone',                   'mod_msteamsecp'),
        ]
    ));

    $settings->add(new admin_setting_configcheckbox(
        'mod_msteamsecp/default_auto_record',
        get_string('settings_default_auto_record', 'mod_msteamsecp'),
        get_string('settings_default_auto_record_desc', 'mod_msteamsecp'), 0
    ));

    $settings->add(new admin_setting_configselect(
        'mod_msteamsecp/default_recording_mode',
        get_string('settings_default_recording_mode', 'mod_msteamsecp'),
        get_string('settings_default_recording_mode_desc', 'mod_msteamsecp'),
        'manual',
        ['manual' => get_string('recording_mode_manual', 'mod_msteamsecp'),
         'auto'   => get_string('recording_mode_auto',   'mod_msteamsecp')]
    ));

    $settings->add(new admin_setting_configtext(
        'mod_msteamsecp/default_attendance_pct',
        get_string('settings_default_attendance_pct', 'mod_msteamsecp'),
        get_string('settings_default_attendance_pct_desc', 'mod_msteamsecp'),
        '0', PARAM_INT
    ));

    $settings->add(new admin_setting_configtext(
        'mod_msteamsecp/recording_completion_threshold',
        get_string('settings_recording_completion_threshold', 'mod_msteamsecp'),
        get_string('settings_recording_completion_threshold_desc', 'mod_msteamsecp'),
        '80', PARAM_INT
    ));
}
