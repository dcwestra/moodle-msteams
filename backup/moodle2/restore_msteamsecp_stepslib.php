<?php
/**
 * Restore structure step for mod_msteamsecp.
 *
 * Restores the instance and its occurrences.
 * Graph IDs and join URL are cleared so the restored instance starts
 * in draft state — the instructor must create a new Teams meeting.
 * Occurrence status is reset to 'upcoming' for future occurrences.
 * Recording cmids are cleared since they reference activities in the
 * source course that won't exist in the destination.
 *
 * @package    mod_msteamsecp
 * @copyright  2026 Eyecare Partners
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

class restore_msteamsecp_activity_structure_step extends restore_activity_structure_step {

    protected function define_structure() {
        $paths = [];

        $paths[] = new restore_path_element('msteamsecp', '/activity/msteamsecp');
        $paths[] = new restore_path_element(
            'msteamsecp_occurrence',
            '/activity/msteamsecp/occurrences/occurrence'
        );

        return $this->prepare_activity_structure($paths);
    }

    /**
     * Restore the main msteamsecp instance.
     * Clears Graph IDs and join URL — restored instance is in draft state.
     */
    public function process_msteamsecp($data) {
        global $DB;

        $data = (object) $data;
        $oldid = $data->id;

        $data->course = $this->get_courseid();

        // Clear all Graph-specific data — the restored instance needs
        // a new meeting created by the instructor.
        $data->graph_meeting_id    = null;
        $data->graph_event_id      = null;
        $data->join_url            = null;
        $data->recording_section_id = null; // Will be re-created on first recording.

        $data->timemodified = time();

        $newitemid = $DB->insert_record('msteamsecp', $data);
        $this->apply_activity_instance($newitemid);
        $this->set_mapping('msteamsecp', $oldid, $newitemid);
    }

    /**
     * Restore occurrence records.
     * Clears Graph IDs, recording cmids, and resets processing flags.
     * Future occurrences keep their scheduled times.
     */
    public function process_msteamsecp_occurrence($data) {
        global $DB;

        $data = (object) $data;
        $data->instanceid = $this->get_new_parentid('msteamsecp');

        // Clear Graph-specific and processed-state fields.
        $data->graph_occurrence_id  = null;
        $data->graph_event_id       = null;
        $data->recording_cmid       = null;
        $data->recording_ready      = 0;
        $data->attendance_fetched   = 0;
        $data->attendance_report_id = null;

        // Reset status based on time — if in the future, upcoming; otherwise ended.
        $data->status = $data->starttime > time() ? 'upcoming' : 'ended';

        $data->timecreated = time();

        $DB->insert_record('msteamsecp_occurrences', $data);
    }

    /**
     * Add related files after restore.
     */
    protected function after_execute() {
        $this->add_related_files('mod_msteamsecp', 'intro', null);
    }
}
