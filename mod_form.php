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
        $mform->addRule('starttime', null, 'required', null, 'client');

        $mform->addElement('date_time_selector', 'endtime', get_string('endtime', 'mod_msteamsecp'));
        $mform->addRule('endtime', null, 'required', null, 'client');

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
        $mform->disabledIf('recording_mode', 'auto_record');

        // ── Recurrence ─────────────────────────────────────────────────────
        $mform->addElement('header', 'recurrence_header', get_string('recurrence', 'mod_msteamsecp'));

        $mform->addElement('advcheckbox', 'is_recurring', get_string('is_recurring', 'mod_msteamsecp'));

        $mform->addElement('select', 'recurrence_type', get_string('recurrence_type', 'mod_msteamsecp'), [
            'daily'   => get_string('recurrence_daily',   'mod_msteamsecp'),
            'weekly'  => get_string('recurrence_weekly',  'mod_msteamsecp'),
            'monthly' => get_string('recurrence_monthly', 'mod_msteamsecp'),
        ]);
        $mform->hideIf('recurrence_type', 'is_recurring');

        $mform->addElement('text', 'recurrence_interval', get_string('recurrence_interval', 'mod_msteamsecp'), ['size' => 5]);
        $mform->setType('recurrence_interval', PARAM_INT);
        $mform->setDefault('recurrence_interval', 1);
        $mform->hideIf('recurrence_interval', 'is_recurring');

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
        $mform->hideIf('days_of_week_group', 'is_recurring');
        $mform->hideIf('days_of_week_group', 'recurrence_type', 'neq', 'weekly');

        // End condition.
        $mform->addElement('select', 'recurrence_end_type', get_string('recurrence_end_type', 'mod_msteamsecp'), [
            'date'  => get_string('recurrence_end_date',  'mod_msteamsecp'),
            'count' => get_string('recurrence_end_count', 'mod_msteamsecp'),
        ]);
        $mform->hideIf('recurrence_end_type', 'is_recurring');

        $mform->addElement('date_selector', 'recurrence_end_date', get_string('recurrence_end_date', 'mod_msteamsecp'));
        $mform->hideIf('recurrence_end_date', 'is_recurring');
        $mform->hideIf('recurrence_end_date', 'recurrence_end_type', 'neq', 'date');

        $mform->addElement('text', 'recurrence_count', get_string('recurrence_count', 'mod_msteamsecp'), ['size' => 5]);
        $mform->setType('recurrence_count', PARAM_INT);
        $mform->setDefault('recurrence_count', 4);
        $mform->hideIf('recurrence_count', 'is_recurring');
        $mform->hideIf('recurrence_count', 'recurrence_end_type', 'neq', 'count');

        $mform->addElement('select', 'recording_behavior', get_string('recording_behavior', 'mod_msteamsecp'), [
            'append'  => get_string('recording_behavior_append',  'mod_msteamsecp'),
            'replace' => get_string('recording_behavior_replace', 'mod_msteamsecp'),
        ]);
        $mform->hideIf('recording_behavior', 'is_recurring');

        // ── Co-organisers ──────────────────────────────────────────────────────
        $mform->addElement('header', 'coorganisers_header', get_string('coorganisers', 'mod_msteamsecp'));

        // Searchable autocomplete of all active non-guest LMS users.
        // At least one co-organiser is required — they bypass the lobby and
        // can start the meeting, admit participants, and control the session.
        global $DB;
        $users = $DB->get_records_select(
            'user',
            'deleted = 0 AND suspended = 0 AND id > 1',
            [],
            'lastname ASC, firstname ASC',
            'id, firstname, lastname, email'
        );
        $user_options = [];
        foreach ($users as $u) {
            if (!empty($u->email)) {
                $user_options[$u->id] = fullname($u) . ' (' . $u->email . ')';
            }
        }

        $mform->addElement('autocomplete', 'coorganiser_userids',
            get_string('coorganisers', 'mod_msteamsecp'),
            $user_options,
            [
                'multiple'          => true,
                'noselectionstring' => get_string('coorganisers_none', 'mod_msteamsecp'),
                'tags'              => false,
            ]
        );
        $mform->addHelpButton('coorganiser_userids', 'coorganisers', 'mod_msteamsecp');

        // ── Completion ─────────────────────────────────────────────────────
        $mform->addElement('header', 'completion_header', get_string('completion_settings', 'mod_msteamsecp'));

        $mform->addElement('advcheckbox', 'completion_attendance',
            get_string('completion_attendance', 'mod_msteamsecp'));

        $mform->addElement('text', 'completion_attendance_pct',
            get_string('completion_attendance_pct', 'mod_msteamsecp'), ['size' => 5]);
        $mform->setType('completion_attendance_pct', PARAM_INT);
        $mform->setDefault('completion_attendance_pct',
            (int) get_config('mod_msteamsecp', 'default_attendance_pct'));
        $mform->addHelpButton('completion_attendance_pct', 'completion_attendance_pct', 'mod_msteamsecp');
        $mform->hideIf('completion_attendance_pct', 'completion_attendance');

        $mform->addElement('advcheckbox', 'completion_recording',
            get_string('completion_recording', 'mod_msteamsecp'));
        $mform->addHelpButton('completion_recording', 'completion_recording', 'mod_msteamsecp');

        // Standard Moodle completion fields.
        $this->standard_coursemodule_elements();
        $this->add_action_buttons();
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
        if (empty($data['coorganiser_userids'])) {
            $errors['coorganiser_userids'] = get_string('error_coorganiser_required', 'mod_msteamsecp');
        }

        return $errors;
    }

    /**
     * Pre-process form data before set_data() — expand stored JSON days-of-week
     * into individual checkbox fields.
     */
    public function set_data($data) {
        if (!empty($data->recurrence_days_of_week)) {
            $days = json_decode($data->recurrence_days_of_week, true) ?? [];
            foreach ($days as $d) {
                $data->{'dow_' . $d} = 1;
            }
        }
        parent::set_data($data);
    }
}
