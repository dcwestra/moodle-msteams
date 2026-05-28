<?php
/**
 * Meeting activity creation / edit form.
 *
 * @package    mod_msteamsecp
 * @copyright  2026 Eyecare Partners
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/course/moodleform_mod.php');

class mod_msteamsecp_mod_form extends moodleform_mod {

    public function definition() {
        global $CFG;

        $mform = $this->_form;

        // ── General ────────────────────────────────────────────────────────
        $mform->addElement('header', 'general', get_string('general', 'form'));

        $mform->addElement('text', 'name', get_string('meetingname', 'mod_msteamsecp'), ['size' => '64']);
        $mform->setType('name', PARAM_TEXT);
        $mform->addRule('name', null, 'required', null, 'client');

        $this->standard_intro_elements();

        // ── Timing ─────────────────────────────────────────────────────────
        $mform->addElement('header', 'timing_header', get_string('timing', 'mod_msteamsecp'));

        $mform->addElement('date_time_selector', 'starttime', get_string('starttime', 'mod_msteamsecp'));

        $mform->addElement('date_time_selector', 'endtime', get_string('endtime', 'mod_msteamsecp'));

        // ── Meeting settings ───────────────────────────────────────────────
        $mform->addElement('header', 'meeting_header', get_string('meeting_settings', 'mod_msteamsecp'));

        $mform->addElement('select', 'lobby_bypass', get_string('lobby_bypass', 'mod_msteamsecp'),
            [
                'organizer'                  => get_string('lobby_bypass_coorganizers',               'mod_msteamsecp'),
                'invited'                    => get_string('lobby_bypass_invited',                    'mod_msteamsecp'),
                'organization'               => get_string('lobby_bypass_organization',               'mod_msteamsecp'),
                'organizationExcludingGuests'=> get_string('lobby_bypass_organization_no_guests',     'mod_msteamsecp'),
                'organizationAndFederated'   => get_string('lobby_bypass_organization_and_federated', 'mod_msteamsecp'),
                'everyone'                   => get_string('lobby_bypass_everyone',                   'mod_msteamsecp'),
            ]
        );
        $mform->setType('lobby_bypass', PARAM_ALPHANUMEXT);
        $mform->setDefault('lobby_bypass', get_config('mod_msteamsecp', 'default_lobby_bypass') ?: 'organizer');
        $mform->addHelpButton('lobby_bypass', 'lobby_bypass', 'mod_msteamsecp');

        $mform->addElement('advcheckbox', 'auto_record', get_string('auto_record', 'mod_msteamsecp'),
            get_string('auto_record_help', 'mod_msteamsecp'));
        $mform->setDefault('auto_record', (int) get_config('mod_msteamsecp', 'default_auto_record'));

        $mform->addElement('select', 'recording_mode', get_string('recording_mode', 'mod_msteamsecp'), [
            'manual' => get_string('recording_mode_manual', 'mod_msteamsecp'),
            'auto'   => get_string('recording_mode_auto',   'mod_msteamsecp'),
        ]);
        $mform->setDefault('recording_mode', get_config('mod_msteamsecp', 'default_recording_mode') ?: 'manual');
        $mform->hideIf('recording_mode', 'auto_record', 'eq', 0);

        // ── Recurrence ─────────────────────────────────────────────────────
        $mform->addElement('header', 'recurrence_header', get_string('recurrence', 'mod_msteamsecp'));

        $mform->addElement('advcheckbox', 'is_recurring', get_string('is_recurring', 'mod_msteamsecp'));

        $mform->addElement('select', 'recurrence_type', get_string('recurrence_type', 'mod_msteamsecp'), [
            'daily'   => get_string('recurrence_daily',   'mod_msteamsecp'),
            'weekly'  => get_string('recurrence_weekly',  'mod_msteamsecp'),
            'monthly' => get_string('recurrence_monthly', 'mod_msteamsecp'),
        ]);
        $mform->hideIf('recurrence_type', 'is_recurring', 'eq', 0);

        $mform->addElement('text', 'recurrence_interval', get_string('recurrence_interval', 'mod_msteamsecp'), ['size' => 5]);
        $mform->setType('recurrence_interval', PARAM_INT);
        $mform->setDefault('recurrence_interval', 1);
        $mform->hideIf('recurrence_interval', 'is_recurring', 'eq', 0);

        // Days of week (weekly only).
        $days = [
            1 => get_string('monday',    'calendar'),
            2 => get_string('tuesday',   'calendar'),
            3 => get_string('wednesday', 'calendar'),
            4 => get_string('thursday',  'calendar'),
            5 => get_string('friday',    'calendar'),
            6 => get_string('saturday',  'calendar'),
            7 => get_string('sunday',    'calendar'),
        ];
        $day_checkboxes = [];
        foreach ($days as $num => $label) {
            $day_checkboxes[] = $mform->createElement('advcheckbox', 'dow_' . $num, '', $label, ['group' => 1]);
        }
        $mform->addGroup($day_checkboxes, 'days_of_week_group', get_string('recurrence_days', 'mod_msteamsecp'), ' ', false);
        $mform->hideIf('days_of_week_group', 'is_recurring', 'eq', 0);
        $mform->hideIf('days_of_week_group', 'recurrence_type', 'neq', 'weekly');

        // End condition.
        $mform->addElement('select', 'recurrence_end_type', get_string('recurrence_end_type', 'mod_msteamsecp'), [
            'date'  => get_string('recurrence_end_date',  'mod_msteamsecp'),
            'count' => get_string('recurrence_end_count', 'mod_msteamsecp'),
        ]);
        $mform->hideIf('recurrence_end_type', 'is_recurring', 'eq', 0);

        $mform->addElement('date_selector', 'recurrence_end_date', get_string('recurrence_end_date', 'mod_msteamsecp'));
        $mform->hideIf('recurrence_end_date', 'is_recurring', 'eq', 0);
        $mform->hideIf('recurrence_end_date', 'recurrence_end_type', 'neq', 'date');

        $mform->addElement('text', 'recurrence_count', get_string('recurrence_count', 'mod_msteamsecp'), ['size' => 5]);
        $mform->setType('recurrence_count', PARAM_INT);
        $mform->setDefault('recurrence_count', 4);
        $mform->hideIf('recurrence_count', 'is_recurring', 'eq', 0);
        $mform->hideIf('recurrence_count', 'recurrence_end_type', 'neq', 'count');

        // ── Attendance requirement (recurring only) ────────────────────────
        // Controls both how calendar invites are sent and how recordings accumulate.
        // 'any'  → attend once or watch one recording → course complete
        //          Calendar: rolling push (next occurrence only), recording: replace
        // 'all'  → must attend every occurrence or watch each recording
        //          Calendar: all upcoming occurrences pushed on enrol, recording: append
        $mform->addElement('select', 'attendance_requirement',
            get_string('attendance_requirement', 'mod_msteamsecp'), [
                'any' => get_string('attendance_requirement_any', 'mod_msteamsecp'),
                'all' => get_string('attendance_requirement_all', 'mod_msteamsecp'),
            ]
        );
        $mform->setDefault('attendance_requirement', 'any');
        $mform->addHelpButton('attendance_requirement', 'attendance_requirement', 'mod_msteamsecp');
        $mform->hideIf('attendance_requirement', 'is_recurring', 'eq', 0);

        // recording_behavior is now derived from attendance_requirement — hidden from UI.
        $mform->addElement('hidden', 'recording_behavior', 'replace');
        $mform->setType('recording_behavior', PARAM_ALPHA);

        // ── Co-organisers ──────────────────────────────────────────────────────
        $mform->addElement('header', 'coorganisers_header', get_string('coorganisers', 'mod_msteamsecp'));

        $mform->addElement('textarea', 'coorganiser_emails',
            get_string('coorganisers', 'mod_msteamsecp'),
            ['rows' => 4, 'cols' => 50, 'placeholder' => "user1@example.com\nuser2@example.com"]
        );
        $mform->setType('coorganiser_emails', PARAM_TEXT);
        $mform->addHelpButton('coorganiser_emails', 'coorganisers', 'mod_msteamsecp');

        // Standard Moodle completion fields — our custom rules are added via
        // add_completion_rules() below, which Moodle calls from within this.
        $this->standard_coursemodule_elements();
        $this->add_action_buttons();
    }

    /**
     * Return the form suffix — get_suffix() was added in Moodle 4.3.
     * On 4.2 and earlier there is no suffix so we return an empty string.
     */
    private function msteamsecp_suffix(): string {
        return method_exists($this, 'get_suffix') ? $this->get_suffix() : '';
    }

    /**
     * Add custom completion rule controls.
     *
     * @return array Names of added elements.
     */
    public function add_completion_rules(): array {
        $mform = $this->_form;

        // Attendance rule — two separate elements, no group.
        $mform->addElement('advcheckbox', 'completion_attendance', '',
            get_string('completion_attendance', 'mod_msteamsecp'));
        $mform->addHelpButton('completion_attendance',
            'completion_attendance', 'mod_msteamsecp');

        $mform->addElement('text', 'completion_attendance_pct',
            get_string('completion_attendance_pct', 'mod_msteamsecp'), ['size' => 4]);
        $mform->setType('completion_attendance_pct', PARAM_INT);
        $mform->setDefault('completion_attendance_pct',
            (int) get_config('mod_msteamsecp', 'default_attendance_pct') ?: 75);
        $mform->addHelpButton('completion_attendance_pct',
            'completion_attendance_pct', 'mod_msteamsecp');
        $mform->hideIf('completion_attendance_pct', 'completion_attendance', 'eq', 0);

        // Recording watch rule.
        $mform->addElement('advcheckbox', 'completion_recording', '',
            get_string('completion_recording', 'mod_msteamsecp'));
        $mform->addHelpButton('completion_recording',
            'completion_recording', 'mod_msteamsecp');

        return [
            'completion_attendance',
            'completion_recording',
        ];
    }

    /**
     * Called during validation — returns true if at least one custom rule is enabled.
     *
     * @param array $data
     * @return bool
     */
    public function completion_rule_enabled($data): bool {
        return !empty($data['completion_attendance'])
            || !empty($data['completion_recording']);
    }

    public function validation($data, $files) {
        $errors = parent::validation($data, $files);

        if (!empty($data['starttime']) && !empty($data['endtime'])) {
            if ($data['endtime'] <= $data['starttime']) {
                $errors['endtime'] = get_string('error_endtime_before_start', 'mod_msteamsecp');
            }
        }

        if (!empty($data['is_recurring'])) {
            if (!empty($data['recurrence_end_type']) && $data['recurrence_end_type'] === 'count') {
                if (empty($data['recurrence_count']) || $data['recurrence_count'] < 1) {
                    $errors['recurrence_count'] = get_string('error_recurrence_count', 'mod_msteamsecp');
                }
            }
        }

        // Co-organiser is required — without one, learners will be stuck in
        // the lobby with no one to admit them (the service account is never
        // present in the meeting).
        $raw_emails = preg_split('/[\s,;]+/', trim($data['coorganiser_emails'] ?? ''), -1, PREG_SPLIT_NO_EMPTY);
        if (empty($raw_emails)) {
            $errors['coorganiser_emails'] = get_string('error_coorganiser_required', 'mod_msteamsecp');
        } else {
            foreach ($raw_emails as $email) {
                if (!validate_email($email)) {
                    $errors['coorganiser_emails'] = get_string('error_coorganiser_email_invalid', 'mod_msteamsecp');
                    break;
                }
            }
        }

        return $errors;
    }

    /**
     * Pre-process form data before set_data() — expand stored JSON days-of-week
     * into individual checkbox fields.
     */
    public function set_data($data) {
        global $DB;

        if (!empty($data->recurrence_days_of_week)) {
            $days = json_decode($data->recurrence_days_of_week, true) ?? [];
            foreach ($days as $d) {
                $data->{'dow_' . $d} = 1;
            }
        }

        // Populate attendance_requirement from recording_behavior for instances
        // saved before this field existed (backward compat on edit).
        if (empty($data->attendance_requirement) && !empty($data->recording_behavior)) {
            $data->attendance_requirement = ($data->recording_behavior === 'append') ? 'all' : 'any';
        }

        // Load existing co-organiser emails for display when editing.
        if (!empty($data->instance)) {
            $emails = $DB->get_fieldset_select(
                'msteamsecp_coorganisers',
                'email',
                "instanceid = :instanceid AND email <> ''",
                ['instanceid' => $data->instance]
            );
            if (!empty($emails)) {
                $data->coorganiser_emails = implode("\n", $emails);
            }
        }

        parent::set_data($data);
    }

    /**
     * Zero out custom completion rules if automatic completion is not selected.
     */
    public function get_data() {
        $data = parent::get_data();
        if (!$data) {
            return $data;
        }

        // If completion is not set to automatic, clear our custom rule values.
        if (empty($data->completion) || $data->completion != COMPLETION_TRACKING_AUTOMATIC) {
            $data->completion_attendance     = 0;
            $data->completion_attendance_pct = 0;
            $data->completion_recording      = 0;
        }

        return $data;
    }
}
