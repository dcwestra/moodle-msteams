<?php
/**
 * Post-event processor — runs after meetings end to:
 *   1. Mark occurrence status as 'ended'
 *   2. Fetch and store attendance reports from Graph
 *   3. Grant live-attendance completion credit
 *   4. Retrieve recordings from OneDrive and create Moodle video activities
 *   5. Grant recording-watch completion eligibility
 *
 * Called every 15 minutes by the scheduled task.
 *
 * @package    mod_msteamsecp
 * @copyright  2026 Eyecare Partners
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_msteamsecp\sync;

defined('MOODLE_INTERNAL') || die();

use mod_msteamsecp\api\graph;
use mod_msteamsecp\sync\enrolment_handler;

class post_event_processor {

    /** @var graph */
    private $graph;

    public function __construct() {
        $this->graph = new graph();
    }

    /**
     * Main entry point — process all occurrences that have ended
     * but haven't had attendance or recording handled yet.
     */
    public function run(): void {
        global $DB, $CFG;

        require_once($CFG->libdir . '/completionlib.php');

        $now = time();

        // Find ended occurrences needing processing.
        // Grace period: wait 20 minutes after end time for Teams to generate reports.
        $grace = $now - (20 * MINSECS);

        // Fetch all occurrences that have ended and need processing:
        // - Any occurrence past grace period not yet marked ended, OR
        // - Ended occurrences still needing attendance fetch, OR
        // - Ended occurrences in auto recording mode still needing recording
        $sql = "SELECT occ.*, m.graph_meeting_id, m.course, m.name AS meeting_name,
                       m.completion_attendance, m.completion_attendance_pct,
                       m.completion_recording, m.recording_mode, m.recording_behavior,
                       m.recording_section_id, m.id AS instanceid
                  FROM {msteamsecp_occurrences} occ
                  JOIN {msteamsecp} m ON m.id = occ.instanceid
                 WHERE (
                     -- Not yet marked ended but past grace period
                     (occ.endtime < :grace AND occ.status != 'ended')
                     OR
                     -- Marked ended but attendance not yet fetched
                     (occ.status = 'ended' AND occ.attendance_fetched = 0 AND occ.endtime < :grace2)
                     OR
                     -- Marked ended, auto recording mode, recording not yet retrieved
                     (occ.status = 'ended' AND occ.recording_ready = 0
                      AND m.recording_mode = 'auto' AND occ.endtime < :grace3)
                 )
              ORDER BY occ.endtime ASC";

        $occurrences = $DB->get_records_sql($sql, [
            'grace'  => $grace,
            'grace2' => $grace,
            'grace3' => $grace,
        ]);

        foreach ($occurrences as $occ) {
            try {
                $this->process_occurrence($occ);
            } catch (\Throwable $e) {
                mtrace('  msteamsecp post_event_processor error [occ ' . $occ->id . ']: ' . $e->getMessage());
            }
        }
    }

    // -------------------------------------------------------------------------
    // Per-occurrence processing
    // -------------------------------------------------------------------------

    private function process_occurrence(object $occ): void {
        global $DB;

        // Mark as ended.
        if ($occ->status !== 'ended') {
            $DB->update_record('msteamsecp_occurrences', (object) [
                'id'     => $occ->id,
                'status' => 'ended',
            ]);
            $occ->status = 'ended';
            mtrace("  msteamsecp: marked occurrence {$occ->id} as ended.");
        }

        // Attendance — always fetch if not yet done, regardless of whether
        // completion by attendance is enabled. Data is useful for reporting
        // even if automatic completion credit isn't configured.
        if (!$occ->attendance_fetched) {
            $this->process_attendance($occ);
        }

        // Recording — fetch and upload automatically if recording_mode = auto.
        // This runs independently of lobby bypass and organiser settings.
        // As long as the meeting was recorded (auto_record was on, or the
        // organiser started recording manually), the file will be retrieved
        // and a video activity created in the Session Recordings section.
        if (!$occ->recording_ready && $occ->recording_mode === 'auto') {
            $this->process_recording($occ);
        }
    }

    // -------------------------------------------------------------------------
    // Attendance
    // -------------------------------------------------------------------------

    private function process_attendance(object $occ): void {
        global $DB;

        mtrace("  msteamsecp: fetching attendance for occurrence {$occ->id}...");

        $reports = $this->graph->get_attendance_reports($occ->graph_meeting_id);

        if (empty($reports)) {
            mtrace("  msteamsecp: no attendance reports yet for occurrence {$occ->id}, will retry.");
            return;
        }

        // Use the most recent report.
        $report = end($reports);
        $report_id = $report['id'];

        // Fetch full detail with attendance records.
        $full_report = $this->graph->get_attendance_report(
            $occ->graph_meeting_id,
            $report_id
        );

        // Use actual meeting duration from the report if available.
        // This handles meetings that run long or end early — fairer than
        // using the scheduled duration as the denominator.
        $actual_start = !empty($full_report['meetingStartDateTime'])
            ? strtotime($full_report['meetingStartDateTime']) : null;
        $actual_end   = !empty($full_report['meetingEndDateTime'])
            ? strtotime($full_report['meetingEndDateTime']) : null;

        if ($actual_start && $actual_end && $actual_end > $actual_start) {
            $meeting_duration = $actual_end - $actual_start;
        } else {
            // Fall back to scheduled duration if report timestamps aren't available.
            $meeting_duration = $occ->endtime - $occ->starttime;
        }

        foreach ($full_report['attendanceRecords'] ?? [] as $record) {
            $email = $record['emailAddress'] ?? '';
            if (empty($email)) continue;

            // Use get_records in case of duplicate emails — take the first active match.
            $users = $DB->get_records('user', ['email' => $email, 'deleted' => 0, 'suspended' => 0],
                'id ASC', '*', 0, 1);
            $user = reset($users);
            if (!$user) continue;

            // Sum all intervals to get total attended duration.
            $total_seconds = 0;
            foreach ($record['attendanceIntervals'] ?? [] as $interval) {
                $total_seconds += max(0, (int)($interval['durationInSeconds'] ?? 0));
            }

            $pct = $meeting_duration > 0
                ? min(100, round(($total_seconds / $meeting_duration) * 100, 2))
                : 0;

            $first_join = !empty($record['attendanceIntervals'])
                ? strtotime($record['attendanceIntervals'][0]['joinDateTime'] ?? '') ?: null
                : null;
            $last_leave = !empty($record['attendanceIntervals'])
                ? strtotime(end($record['attendanceIntervals'])['leaveDateTime'] ?? '') ?: null
                : null;

            // Upsert attendance record.
            $existing = $DB->get_record('msteamsecp_attendance', [
                'occurrenceid' => $occ->id,
                'userid'       => $user->id,
            ]);

            $threshold = (int) ($occ->completion_attendance_pct ?? 0);
            $credit    = $pct >= $threshold;

            if ($existing) {
                $DB->update_record('msteamsecp_attendance', (object) [
                    'id'               => $existing->id,
                    'duration_seconds' => $total_seconds,
                    'attendance_pct'   => $pct,
                    'join_time'        => $first_join,
                    'leave_time'       => $last_leave,
                    'credit_granted'   => $credit ? 1 : $existing->credit_granted,
                    'credit_method'    => ($credit && !$existing->credit_granted) ? 'live' : $existing->credit_method,
                    'timemodified'     => time(),
                ]);
            } else {
                $DB->insert_record('msteamsecp_attendance', (object) [
                    'instanceid'       => $occ->instanceid,
                    'occurrenceid'     => $occ->id,
                    'userid'           => $user->id,
                    'join_time'        => $first_join,
                    'leave_time'       => $last_leave,
                    'duration_seconds' => $total_seconds,
                    'attendance_pct'   => $pct,
                    'credit_granted'   => $credit ? 1 : 0,
                    'credit_method'    => $credit ? 'live' : 'none',
                    'timecreated'      => time(),
                    'timemodified'     => time(),
                ]);
            }

            // Grant Moodle completion credit.
            if ($credit) {
                $this->maybe_grant_completion($user->id, $occ);
            }
        }

        $DB->update_record('msteamsecp_occurrences', (object) [
            'id'                   => $occ->id,
            'attendance_fetched'   => 1,
            'attendance_report_id' => $report_id,
        ]);

        mtrace("  msteamsecp: attendance processed for occurrence {$occ->id}.");

        // In 'all' mode learners already have invites for every occurrence —
        // no advancing needed. In 'any' mode push the next occurrence to
        // users who still haven't earned completion credit.
        $instance = $DB->get_record('msteamsecp', ['id' => $occ->instanceid]);
        if ($instance && ($instance->attendance_requirement ?? 'any') === 'any') {
            $this->push_next_occurrence_for_incomplete_users($occ);
        }
    }

    // -------------------------------------------------------------------------
    // Next-occurrence calendar advance
    // -------------------------------------------------------------------------

    /**
     * After attendance is processed for an occurrence, find all enrolled users
     * who have not yet completed the course and push their next occurrence to
     * their calendar.
     *
     * This keeps each incomplete user's Teams/Outlook calendar showing exactly
     * one upcoming session at a time, rather than the full series.
     *
     * @param object $occ  Occurrence record (with instanceid, course joined in)
     */
    private function push_next_occurrence_for_incomplete_users(object $occ): void {
        global $DB;

        // Find all users enrolled in this course who do not yet have
        // completion credit — check BOTH the plugin's own attendance table
        // AND Moodle's standard completion state so that manually granted
        // completion credit (e.g. for users sharing a computer) also stops
        // further invites being sent.
        $sql = "SELECT u.id, u.email, u.firstname, u.lastname
                  FROM {user_enrolments} ue
                  JOIN {enrol} e ON e.id = ue.enrolid AND e.courseid = :courseid
                  JOIN {user} u ON u.id = ue.userid AND u.deleted = 0 AND u.suspended = 0
                 WHERE u.email <> ''
                   AND NOT EXISTS (
                       SELECT 1
                         FROM {msteamsecp_attendance} att
                        WHERE att.instanceid = :instanceid
                          AND att.userid = u.id
                          AND att.credit_granted = 1
                   )
                   AND NOT EXISTS (
                       SELECT 1
                         FROM {course_modules_completion} cmc
                        WHERE cmc.coursemoduleid = :cmid
                          AND cmc.userid = u.id
                          AND cmc.completionstate >= 1
                   )";

        // Resolve the course module ID for the completion check.
        $cmid = $DB->get_field('course_modules', 'id', [
            'instance' => $occ->instanceid,
            'module'   => $DB->get_field('modules', 'id', ['name' => 'msteamsecp']),
        ]);

        $users = $DB->get_records_sql($sql, [
            'courseid'   => $occ->course,
            'instanceid' => $occ->instanceid,
            'cmid'       => (int) $cmid,
        ]);

        if (empty($users)) {
            return;
        }

        $instance = $DB->get_record('msteamsecp', ['id' => $occ->instanceid]);
        if (!$instance) {
            return;
        }

        $enrolment_handler = new enrolment_handler();

        foreach ($users as $user) {
            // push_events_for_user already checks for existing pushes via
            // LEFT JOIN on msteamsecp_enrollee_events, so it's safe to call
            // unconditionally — it only pushes if there's nothing already there.
            $enrolment_handler->push_events_for_user($instance, $user);
        }

        mtrace("  msteamsecp: next-occurrence push sent for " . count($users) . " incomplete user(s) after occurrence {$occ->id}.");
    }

    // -------------------------------------------------------------------------
    // Recording
    // -------------------------------------------------------------------------

    private function process_recording(object $occ): void {
        global $DB, $CFG;

        mtrace("  msteamsecp: checking recording for occurrence {$occ->id} using meeting ID: " . ($occ->graph_meeting_id ?? 'MISSING'));

        if (empty($occ->graph_meeting_id)) {
            mtrace("  msteamsecp: no graph_meeting_id on occurrence {$occ->id}, cannot fetch recording.");
            return;
        }

        $recordings = $this->graph->get_recordings($occ->graph_meeting_id);

        if (empty($recordings)) {
            mtrace("  msteamsecp: recording not ready yet for occurrence {$occ->id}, will retry.");
            return;
        }

        // Use the first (most likely only) recording.
        $recording   = $recordings[0];
        $content_url = $recording['recordingContentUrl'] ?? '';

        if (empty($content_url)) {
            mtrace("  msteamsecp: recording content URL missing for occurrence {$occ->id}.");
            return;
        }

        // Resolve the msteamsecp course module ID — needed for file context.
        $module_id = $DB->get_field('modules', 'id', ['name' => 'msteamsecp']);
        $occ->cmid = (int) $DB->get_field('course_modules', 'id', [
            'module'   => $module_id,
            'instance' => $occ->instanceid,
        ]);

        if (empty($occ->cmid)) {
            mtrace("  msteamsecp: could not resolve cmid for instance {$occ->instanceid}, skipping.");
            return;
        }

        // Download to a temp file to avoid loading the entire binary into memory.
        $tmp_path = $CFG->tempdir . '/msteamsecp_recording_' . $occ->id . '_' . time() . '.mp4';

        mtrace("  msteamsecp: downloading recording for occurrence {$occ->id}...");

        try {
            $this->graph->download_recording_to_file($content_url, $tmp_path);
        } catch (\Throwable $e) {
            mtrace("  msteamsecp: recording download failed for occurrence {$occ->id}: " . $e->getMessage());
            @unlink($tmp_path);
            return;
        }

        if (!file_exists($tmp_path) || filesize($tmp_path) === 0) {
            mtrace("  msteamsecp: recording download produced empty file for occurrence {$occ->id}.");
            @unlink($tmp_path);
            return;
        }

        mtrace("  msteamsecp: recording downloaded (" . round(filesize($tmp_path) / 1048576, 1) . " MB), storing...");

        try {
            $filename = 'recording_' . $occ->instanceid . '_' . $occ->id . '_' . date('Ymd', $occ->starttime) . '.mp4';

            if ($occ->recording_behavior === 'replace' && !empty($occ->recording_cmid)) {
                $this->replace_recording_activity($occ, $tmp_path, $filename, 0);
            } else {
                $anchor = $this->create_recording_activity($occ, $tmp_path, $filename, 0);
                $DB->update_record('msteamsecp_occurrences', (object) [
                    'id'              => $occ->id,
                    'recording_cmid'  => $anchor,
                    'recording_ready' => 1,
                ]);
            }

            mtrace("  msteamsecp: recording stored for occurrence {$occ->id}.");

        } finally {
            @unlink($tmp_path);
        }
    }

    /**
     * Create a Moodle resource activity containing the downloaded recording file.
     *
     * @param object $occ
     * @param string $tmp_path   Path to downloaded temp file on disk
     * @param string $filename   Desired filename for the Moodle file
     * @param int    $section_id
     * @return int   New course module ID
     */
    private function create_recording_activity(object $occ, string $tmp_path, string $filename, int $section_id): int {
        global $DB, $CFG;

        // Store the recording file under the mod_msteamsecp component so it can
        // be served via pluginfile.php and tracked for completion within the plugin.
        // filearea='recording', itemid=$occ->id uniquely identifies each recording.
        $context = \context_module::instance($occ->cmid ?? $this->get_cmid_for_instance($occ->instanceid));
        $fs      = get_file_storage();

        // Remove any previous recording for this occurrence.
        $fs->delete_area_files($context->id, 'mod_msteamsecp', 'recording', $occ->id);

        $fs->create_file_from_pathname(
            [
                'contextid' => $context->id,
                'component' => 'mod_msteamsecp',
                'filearea'  => 'recording',
                'itemid'    => $occ->id,
                'filepath'  => '/',
                'filename'  => $filename,
            ],
            $tmp_path
        );

        // Return occ->id as the "cmid" — recording_cmid is repurposed as the
        // file anchor (occurrence ID) so view.php knows a recording is stored.
        return $occ->id;
    }

    /**
     * Get the course module ID for a given msteamsecp instance.
     */
    private function get_cmid_for_instance(int $instanceid): int {
        global $DB;
        return (int) $DB->get_field('course_modules', 'id', [
            'module'   => $DB->get_field('modules', 'id', ['name' => 'msteamsecp']),
            'instance' => $instanceid,
        ]);
    }

    /**
     * Add a newly created recording activity to the course completion criteria.
     * Uses activity completion aggregation — any one activity completing is
     * sufficient for course completion. Learners who already have completion
     * (via attendance) are unaffected.
     *
     * @param int $courseid
     * @param int $cmid  Course module ID of the recording activity
     */
    /**
     * Replace the file on an existing recording activity (replace mode).
     */
    private function replace_recording_activity(object $occ, string $tmp_path, string $filename, int $section_id): void {
        global $DB;

        // Ensure cmid is resolved — may not be set if called outside process_recording.
        if (empty($occ->cmid)) {
            $occ->cmid = $this->get_cmid_for_instance($occ->instanceid);
        }
        if (empty($occ->cmid)) {
            mtrace("  msteamsecp: could not resolve cmid for replace_recording on occurrence {$occ->id}, skipping.");
            return;
        }

        $context = \context_module::instance($occ->cmid);
        $fs      = get_file_storage();

        $fs->delete_area_files($context->id, 'mod_msteamsecp', 'recording', $occ->id);
        $fs->create_file_from_pathname(
            [
                'contextid' => $context->id,
                'component' => 'mod_msteamsecp',
                'filearea'  => 'recording',
                'itemid'    => $occ->id,
                'filepath'  => '/',
                'filename'  => $filename,
            ],
            $tmp_path
        );

        $DB->update_record('msteamsecp_occurrences', (object) [
            'id'              => $occ->id,
            'recording_ready' => 1,
        ]);
    }

    // -------------------------------------------------------------------------
    // Completion
    // -------------------------------------------------------------------------

    /**
     * Grant completion only if the user has met the attendance_requirement.
     *
     * 'any' mode: credit on this occurrence is sufficient — grant immediately.
     * 'all' mode: only grant when the user has credit_granted = 1 on every
     *             ended occurrence in the series (whether via live attendance
     *             or recording watch).
     *
     * @param int    $userid
     * @param object $occ  Occurrence record (must have instanceid and course)
     */
    private function maybe_grant_completion(int $userid, object $occ): void {
        global $DB;

        $instance = $DB->get_record('msteamsecp', ['id' => $occ->instanceid]);
        if (!$instance) {
            return;
        }

        if (($instance->attendance_requirement ?? 'any') !== 'all') {
            // 'any' mode — one credit is enough.
            $this->grant_completion($userid, $occ->course, $occ->instanceid);
            return;
        }

        // 'all' mode — every ended occurrence must have credit for this user.
        $ended_count = $DB->count_records_select(
            'msteamsecp_occurrences',
            "instanceid = :instanceid AND status = 'ended'",
            ['instanceid' => $occ->instanceid]
        );

        $credited_count = $DB->count_records_select(
            'msteamsecp_attendance',
            'instanceid = :instanceid AND userid = :userid AND credit_granted = 1',
            ['instanceid' => $occ->instanceid, 'userid' => $userid]
        );

        if ($ended_count > 0 && $credited_count >= $ended_count) {
            $this->grant_completion($userid, $occ->course, $occ->instanceid);
        }
    }

    /**
     * Grant Moodle activity completion for a user on this meeting activity.
     *
     * @param int $userid
     * @param int $courseid
     * @param int $instanceid  msteamsecp instance ID
     */
    private function grant_completion(int $userid, int $courseid, int $instanceid): void {
        $cm = get_coursemodule_from_instance('msteamsecp', $instanceid, $courseid);
        if (!$cm) {
            return;
        }

        $completion = new \completion_info(get_course($courseid));
        if (!$completion->is_enabled($cm)) {
            return;
        }

        $completion->update_state($cm, \COMPLETION_COMPLETE, $userid);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function recording_title(object $occ): string {
        return $occ->meeting_name . ' — ' . userdate($occ->starttime, get_string('strftimedatefullshort', 'langconfig'));
    }

    private function to_iso8601(int $timestamp): string {
        return gmdate('Y-m-d\TH:i:s\Z', $timestamp);
    }
}
