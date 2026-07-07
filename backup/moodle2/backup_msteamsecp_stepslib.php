<?php
/**
 * Backup structure step for mod_msteamsecp.
 *
 * Backs up:
 *   - msteamsecp instance settings
 *   - msteamsecp_occurrences (schedule data, not personal)
 *
 * Deliberately NOT backed up (personal data):
 *   - msteamsecp_attendance
 *   - msteamsecp_enrollee_events
 *   - msteamsecp_watch_progress
 *
 * Graph IDs (graph_meeting_id, graph_event_id, join_url) are backed up
 * but cleared on restore — the restored instance starts in draft state
 * and requires the instructor to create a new meeting.
 *
 * @package    mod_msteamsecp
 * @copyright  2026 Eyecare Partners
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

class backup_msteamsecp_activity_structure_step extends backup_activity_structure_step {

    protected function define_structure() {

        // Root element.
        $msteamsecp = new backup_nested_element('msteamsecp', ['id'], [
            'course',
            'name',
            'intro',
            'introformat',
            'graph_meeting_id',
            'graph_event_id',
            'join_url',
            'lobby_bypass',
            'auto_record',
            'recording_mode',
            'completion_attendance',
            'completion_attendance_pct',
            'completion_recording',
            'completion_recording_pct',
            'is_recurring',
            'recurrence_type',
            'recurrence_interval',
            'recurrence_end_date',
            'recurrence_count',
            'recurrence_days_of_week',
            'recording_behavior',
            'attendance_requirement',
            'recording_section_id',
            'starttime',
            'endtime',
            'timecreated',
            'timemodified',
        ]);

        // Occurrences — schedule data only, no personal data.
        $occurrences = new backup_nested_element('occurrences');
        $occurrence  = new backup_nested_element('occurrence', ['id'], [
            'instanceid',
            'occurrence_index',
            'graph_occurrence_id',
            'graph_event_id',
            'starttime',
            'endtime',
            'status',
            'recording_cmid',
            'recording_ready',
            'attendance_fetched',
            'attendance_report_id',
            'timecreated',
        ]);

        // Build tree.
        $msteamsecp->add_child($occurrences);
        $occurrences->add_child($occurrence);

        // Set sources.
        $msteamsecp->set_source_table('msteamsecp', ['id' => backup::VAR_ACTIVITYID]);
        $occurrence->set_source_table('msteamsecp_occurrences', ['instanceid' => backup::VAR_PARENTID]);

        // Annotate IDs.
        $msteamsecp->annotate_ids('course', 'course');

        // Define file areas (none for this plugin currently).
        $msteamsecp->annotate_files('mod_msteamsecp', 'intro', null);

        return $this->prepare_activity_structure($msteamsecp);
    }
}
