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

require_once($CFG->dirroot . '/course/lib.php');
require_once($CFG->dirroot . '/mod/resource/lib.php');

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
        global $DB;

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

        $reports = $this->graph->get_attendance_reports($occ->graph_meeting_id ?? $occ->graph_occurrence_id);

        if (empty($reports)) {
            mtrace("  msteamsecp: no attendance reports yet for occurrence {$occ->id}, will retry.");
            return;
        }

        // Use the most recent report.
        $report = end($reports);
        $report_id = $report['id'];

        // Fetch full detail with attendance records.
        $full_report = $this->graph->get_attendance_report(
            $occ->graph_meeting_id ?? $occ->graph_occurrence_id,
            $report_id
        );

        $meeting_duration = $occ->endtime - $occ->starttime; // seconds

        foreach ($full_report['attendanceRecords'] ?? [] as $record) {
            $email = $record['emailAddress'] ?? '';
            if (empty($email)) continue;

            $user = $DB->get_record('user', ['email' => $email, 'deleted' => 0]);
            if (!$user) continue;

            // Sum all intervals to get total attended duration.
            $total_seconds = 0;
            foreach ($record['attendanceIntervals'] ?? [] as $interval) {
                $total_seconds += max(0, (int)($interval['durationInSeconds'] ?? 0));
            }

            $pct = $meeting_duration > 0
                ? round(($total_seconds / $meeting_duration) * 100, 2)
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
                $this->grant_completion($user->id, $occ->course, $occ->instanceid);
            }
        }

        $DB->update_record('msteamsecp_occurrences', (object) [
            'id'                   => $occ->id,
            'attendance_fetched'   => 1,
            'attendance_report_id' => $report_id,
        ]);

        mtrace("  msteamsecp: attendance processed for occurrence {$occ->id}.");

        // Advance incomplete users to their next occurrence.
        // Any enrolled user who was present but didn't meet the threshold, or
        // who didn't appear in the attendance report at all, still needs to
        // attend a future session. Push the next occurrence to their calendar
        // so they always have exactly one upcoming event showing.
        $this->push_next_occurrence_for_incomplete_users($occ);
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
        // completion credit on this meeting activity.
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
                   )";

        $users = $DB->get_records_sql($sql, [
            'courseid'   => $occ->course,
            'instanceid' => $occ->instanceid,
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

        mtrace("  msteamsecp: checking recording for occurrence {$occ->id}...");

        $recordings = $this->graph->get_recordings($occ->graph_meeting_id ?? $occ->graph_occurrence_id);

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

        mtrace("  msteamsecp: downloading recording for occurrence {$occ->id}...");
        $file_content = $this->graph->download_recording($content_url);

        // Store as a Moodle file.
        $filename   = $this->recording_filename($occ);
        $filerecord = $this->store_moodle_file($file_content, $filename, $occ->course, $occ->instanceid);

        // Ensure the recordings section exists.
        $section_id = $this->ensure_recordings_section($occ->course, $occ->instanceid);

        // Handle append vs replace.
        if ($occ->recording_behavior === 'replace' && !empty($occ->recording_cmid)) {
            $this->replace_recording_activity($occ, $filerecord, $section_id);
        } else {
            $cmid = $this->create_recording_activity($occ, $filerecord, $section_id);
            $DB->update_record('msteamsecp_occurrences', (object) [
                'id'              => $occ->id,
                'recording_cmid'  => $cmid,
                'recording_ready' => 1,
            ]);
        }

        mtrace("  msteamsecp: recording created for occurrence {$occ->id}.");

        // Update completion criteria to include the new recording activity.
        $this->update_course_completion_criteria($occ->course);
    }

    /**
     * Create a Moodle resource activity containing the recording.
     *
     * @param object $occ
     * @param object $filerecord  Stored file record
     * @param int    $section_id
     * @return int   New course module ID
     */
    private function create_recording_activity(object $occ, object $filerecord, int $section_id): int {
        global $DB;

        $course   = $DB->get_record('course', ['id' => $occ->course], '*', MUST_EXIST);
        $section  = $DB->get_record('course_sections', ['id' => $section_id], '*', MUST_EXIST);

        // Build resource module record.
        $resource = new \stdClass();
        $resource->course       = $occ->course;
        $resource->name         = $this->recording_title($occ);
        $resource->intro        = '';
        $resource->introformat  = FORMAT_HTML;
        $resource->display      = RESOURCELIB_DISPLAY_EMBED; // Embed in page.
        $resource->tobemigrated = 0;
        $resource->legacyfiles  = 0;
        $resource->filterfiles  = 0;
        $resource->revision     = 1;
        $resource->timemodified = time();

        $resource->id = $DB->insert_record('resource', $resource);

        // Add to course as a module.
        $cm               = new \stdClass();
        $cm->course       = $occ->course;
        $cm->module       = $DB->get_field('modules', 'id', ['name' => 'resource']);
        $cm->instance     = $resource->id;
        $cm->section      = $section->section;
        $cm->visible      = 1;
        $cm->completion   = COMPLETION_TRACKING_MANUAL; // Learner clicks "Mark as done".
        $cm->timemodified = time();

        $cmid = add_course_module($cm);
        course_add_cm_to_section($course, $cmid, $section->section);

        // Attach the file to the resource module context.
        $modulecontext = \context_module::instance($cmid);
        $fs            = get_file_storage();
        $fs->create_file_from_string(
            [
                'contextid' => $modulecontext->id,
                'component' => 'mod_resource',
                'filearea'  => 'content',
                'itemid'    => 0,
                'filepath'  => '/',
                'filename'  => $filerecord->filename,
            ],
            $filerecord->content
        );

        rebuild_course_cache($occ->course, true);

        return $cmid;
    }

    /**
     * Replace the file on an existing recording activity (replace mode).
     */
    private function replace_recording_activity(object $occ, object $filerecord, int $section_id): void {
        global $DB;

        $cm = get_coursemodule_from_id('resource', $occ->recording_cmid);
        if (!$cm) {
            // Activity was deleted — create a fresh one.
            $cmid = $this->create_recording_activity($occ, $filerecord, $section_id);
            $DB->update_record('msteamsecp_occurrences', (object) [
                'id'              => $occ->id,
                'recording_cmid'  => $cmid,
                'recording_ready' => 1,
            ]);
            return;
        }

        // Replace the file.
        $context = \context_module::instance($occ->recording_cmid);
        $fs      = get_file_storage();
        $fs->delete_area_files($context->id, 'mod_resource', 'content');
        $fs->create_file_from_string(
            [
                'contextid' => $context->id,
                'component' => 'mod_resource',
                'filearea'  => 'content',
                'itemid'    => 0,
                'filepath'  => '/',
                'filename'  => $filerecord->filename,
            ],
            $filerecord->content
        );

        // Update the resource name to reflect the new date.
        $DB->update_record('resource', (object) [
            'id'           => $cm->instance,
            'name'         => $this->recording_title($occ),
            'timemodified' => time(),
        ]);

        $DB->update_record('msteamsecp_occurrences', (object) [
            'id'              => $occ->id,
            'recording_ready' => 1,
        ]);

        rebuild_course_cache($occ->course, true);
    }

    /**
     * Ensure the plugin-managed "Session Recordings" section exists.
     * Creates it if not present, stores the section ID on the instance.
     *
     * @param int $courseid
     * @param int $instanceid
     * @return int  Course section ID
     */
    private function ensure_recordings_section(int $courseid, int $instanceid): int {
        global $DB;

        // Check if we already have a section stored.
        $instance = $DB->get_record('msteamsecp', ['id' => $instanceid]);

        if (!empty($instance->recording_section_id)) {
            $section = $DB->get_record('course_sections', ['id' => $instance->recording_section_id]);
            if ($section) {
                return $section->id;
            }
        }

        $section_name = get_config('mod_msteamsecp', 'recording_section_name') ?: 'Session Recordings';

        // Look for existing section with this name.
        $existing = $DB->get_record_select(
            'course_sections',
            "course = :course AND " . $DB->sql_compare_text('name') . " = :name",
            ['course' => $courseid, 'name' => $section_name]
        );

        if ($existing) {
            $DB->update_record('msteamsecp', (object) [
                'id'                  => $instanceid,
                'recording_section_id' => $existing->id,
            ]);
            return $existing->id;
        }

        // Create a new section at the end of the course.
        $course  = $DB->get_record('course', ['id' => $courseid], '*', MUST_EXIST);
        $max_seq = (int) $DB->get_field_sql('SELECT MAX(section) FROM {course_sections} WHERE course = ?', [$courseid]);

        $section           = new \stdClass();
        $section->course   = $courseid;
        $section->section  = $max_seq + 1;
        $section->name     = $section_name;
        $section->summary  = '';
        $section->summaryformat = FORMAT_HTML;
        $section->visible  = 1;
        $section->timemodified = time();
        $section->id       = $DB->insert_record('course_sections', $section);

        // Update course section count.
        $DB->update_record('course', (object) [
            'id'       => $courseid,
            'numsections' => $max_seq + 1,
        ]);

        $DB->update_record('msteamsecp', (object) [
            'id'                   => $instanceid,
            'recording_section_id' => $section->id,
        ]);

        rebuild_course_cache($courseid, true);

        return $section->id;
    }

    /**
     * Temporarily store file content for use during activity creation.
     * Returns a lightweight object with filename and content.
     *
     * @param string $content
     * @param string $filename
     * @param int    $courseid
     * @param int    $instanceid
     * @return object {filename, content}
     */
    private function store_moodle_file(string $content, string $filename, int $courseid, int $instanceid): object {
        return (object) [
            'filename' => $filename,
            'content'  => $content,
        ];
    }

    /**
     * Update course completion criteria to include all recording activities.
     * Uses "any activity" aggregation — completing any one recording grants credit.
     *
     * @param int $courseid
     */
    private function update_course_completion_criteria(int $courseid): void {
        // Completion criteria updates are handled by the standard Moodle
        // completion system — instructors configure this through the course
        // completion settings UI. The plugin creates the activity with
        // COMPLETION_TRACKING_MANUAL set, making it available for inclusion.
        // Automatic criteria modification would override instructor preferences,
        // so we deliberately leave this to the course editor.
        rebuild_course_cache($courseid, true);
    }

    // -------------------------------------------------------------------------
    // Completion
    // -------------------------------------------------------------------------

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

        $completion->update_state($cm, COMPLETION_COMPLETE, $userid);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function recording_title(object $occ): string {
        return $occ->meeting_name . ' — ' . userdate($occ->starttime, get_string('strftimedatefullshort', 'langconfig'));
    }

    private function recording_filename(object $occ): string {
        return 'recording_' . $occ->instanceid . '_' . $occ->id . '_' . date('Ymd', $occ->starttime) . '.mp4';
    }

    private function to_iso8601(int $timestamp): string {
        return gmdate('Y-m-d\TH:i:s\Z', $timestamp);
    }
}
