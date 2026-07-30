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

    /** Wait this long after an occurrence ends before the first attendance fetch. */
    const ATTENDANCE_GRACE = 1200;             // 20 minutes.

    /**
     * Keep re-polling attendance for this long after an occurrence ends.
     *
     * Teams frequently publishes the attendance report later than the 20-minute
     * grace period, and publishes additional reports when a session restarts.
     * Fetching once and latching meant whatever was (or wasn't) available at
     * +20 minutes was final. Re-merging is safe: intervals are unioned and
     * credit is never revoked.
     */
    const ATTENDANCE_REFRESH_WINDOW = 21600;   // 6 hours.

    /** Stop polling for an attendance report that never appeared. */
    const ATTENDANCE_ABANDON_AFTER = 604800;   // 7 days.

    /**
     * Stop polling for a recording that never appeared.
     *
     * A recurring occurrence that was simply not held — skipped for a holiday,
     * cancelled — never produces one, and without this the cron re-queries
     * Graph for it on every run indefinitely. Sets recording_abandoned; clear
     * that column to make the cron look again.
     */
    const RECORDING_ABANDON_AFTER = 604800;    // 7 days.

    /**
     * How far a report's / recording's start may fall outside an occurrence's
     * scheduled window and still be attributed to it. Reports are assigned to
     * the *nearest* occurrence first, so this only rejects material that
     * belongs to no occurrence at all.
     */
    const OCCURRENCE_MATCH_TOLERANCE = 14400;  // 4 hours.

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
        // ENROL_USER_ACTIVE / ENROL_INSTANCE_ENABLED for the next-occurrence
        // push query — cheap insurance, matching how completionlib is handled.
        require_once($CFG->libdir . '/enrollib.php');

        $now = time();

        // Find ended occurrences needing processing.
        // Grace period: wait 20 minutes after end time for Teams to generate reports.
        $grace   = $now - self::ATTENDANCE_GRACE;
        $refresh = $now - self::ATTENDANCE_REFRESH_WINDOW;

        // Fetch all occurrences that have ended and need processing:
        // - Any occurrence past grace period not yet marked ended, OR
        // - Ended occurrences still needing attendance fetch, OR
        // - Ended occurrences inside the attendance refresh window, OR
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
                     -- Recently ended: re-poll so reports Teams publishes late,
                     -- and extra reports from session restarts, are merged in
                     (occ.status = 'ended' AND occ.attendance_fetched = 1
                      AND occ.endtime < :grace4 AND occ.endtime > :refresh)
                     OR
                     -- Marked ended, auto recording mode, recording not yet
                     -- retrieved and not already given up on
                     (occ.status = 'ended' AND occ.recording_ready = 0
                      AND occ.recording_abandoned = 0
                      AND m.recording_mode = 'auto' AND occ.endtime < :grace3)
                 )
              ORDER BY occ.endtime ASC";

        $occurrences = $DB->get_records_sql($sql, [
            'grace'   => $grace,
            'grace2'  => $grace,
            'grace3'  => $grace,
            'grace4'  => $grace,
            'refresh' => $refresh,
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
        // even if automatic completion credit isn't configured. Keep re-polling
        // for a few hours afterwards to pick up late or additional reports.
        $first_fetch = empty($occ->attendance_fetched);
        if ($first_fetch || $occ->endtime > time() - self::ATTENDANCE_REFRESH_WINDOW) {
            $this->process_attendance($occ, $first_fetch);
        }

        // Recording — fetch and upload automatically if recording_mode = auto.
        // This runs independently of lobby bypass and organiser settings.
        // As long as the meeting was recorded (auto_record was on, or the
        // organiser started recording manually), the file will be retrieved
        // and a video activity created in the Session Recordings section.
        if (!$occ->recording_ready && empty($occ->recording_abandoned)
                && $occ->recording_mode === 'auto') {
            $this->process_recording($occ);
        }
    }

    // -------------------------------------------------------------------------
    // Attendance
    // -------------------------------------------------------------------------

    /**
     * Fetch the attendance report(s) belonging to this occurrence and record
     * attendance for every participant that resolves to a Moodle user.
     *
     * Teams issues a separate attendanceReport each time a meeting session
     * restarts, and for a recurring meeting the onlineMeeting ID is
     * series-level — so /attendanceReports returns reports for every occurrence
     * held so far, in no guaranteed order. Reports are therefore matched to
     * occurrences by meeting start time, and every report belonging to this
     * occurrence is merged into a single per-user interval set.
     *
     * @param object $occ
     * @param bool   $first_fetch  True on the first fetch for this occurrence
     */
    private function process_attendance(object $occ, bool $first_fetch = true): void {
        global $DB;

        if (empty($occ->graph_meeting_id)) {
            mtrace("  msteamsecp: no graph_meeting_id on occurrence {$occ->id}, cannot fetch attendance.");
            $this->retry_or_abandon_attendance($occ, 'meeting has no graph_meeting_id');
            return;
        }

        mtrace("  msteamsecp: fetching attendance for occurrence {$occ->id}...");

        $reports = $this->graph->get_attendance_reports($occ->graph_meeting_id);

        if (empty($reports)) {
            $this->retry_or_abandon_attendance($occ, 'no attendance reports returned');
            return;
        }

        $matched = $this->reports_for_occurrence($reports, $occ);

        if (empty($matched)) {
            $this->retry_or_abandon_attendance($occ, count($reports)
                . ' report(s) returned, none matching this occurrence\'s scheduled window');
            return;
        }

        mtrace('  msteamsecp: ' . count($matched) . ' of ' . count($reports)
            . " attendance report(s) match occurrence {$occ->id}.");

        // Collapse every matched report into one interval set per attendee,
        // keyed by lower-cased email so a participant who appears in several
        // reports (session restarts) is counted once.
        $participants = [];
        $report_ids   = [];
        $anonymous    = 0;

        foreach ($matched as $report) {
            if (empty($report['id'])) {
                continue;
            }

            // The list endpoint doesn't include attendanceRecords — fetch each
            // matched report in full. One report failing (expired, transient
            // Graph error) must not discard the others.
            try {
                $full = $this->graph->get_attendance_report($occ->graph_meeting_id, $report['id']);
            } catch (\Throwable $e) {
                mtrace("  msteamsecp: could not fetch attendance report {$report['id']} for"
                    . " occurrence {$occ->id}: " . $e->getMessage());
                continue;
            }

            $report_ids[] = $report['id'];

            foreach ($full['attendanceRecords'] ?? [] as $record) {
                $email = trim((string) ($record['emailAddress'] ?? ''));
                if ($email === '') {
                    // Anonymous, dial-in or unauthenticated participant — Graph
                    // gives no address, so there is nobody to credit.
                    $anonymous++;
                    continue;
                }

                $key = \core_text::strtolower($email);
                if (!isset($participants[$key])) {
                    $participants[$key] = ['email' => $email, 'intervals' => [], 'orphan' => 0];
                }

                foreach ($record['attendanceIntervals'] ?? [] as $interval) {
                    $start = strtotime((string) ($interval['joinDateTime'] ?? ''));
                    $end   = strtotime((string) ($interval['leaveDateTime'] ?? ''));

                    if ($start && $end && $end > $start) {
                        $participants[$key]['intervals'][] = [$start, $end];
                    } else {
                        // Timestamps unusable (still in the meeting, malformed).
                        // Keep the reported duration so it still counts, but it
                        // can't participate in overlap detection.
                        $participants[$key]['orphan'] += max(0, (int) ($interval['durationInSeconds'] ?? 0));
                    }
                }
            }
        }

        if (empty($report_ids)) {
            // Every matched report failed to fetch — nothing was learned, so
            // don't latch the occurrence as done.
            $this->retry_or_abandon_attendance($occ, 'no matched report could be retrieved');
            return;
        }

        // Always use scheduled duration as the denominator so that early
        // joiners or late stayers don't dilute the percentage for attendees
        // who were present for the entire scheduled session.
        $meeting_duration = $occ->endtime - $occ->starttime;
        $threshold        = (int) ($occ->completion_attendance_pct ?? 0);

        $unmatched = [];
        $credited  = 0;

        foreach ($participants as $participant) {
            $user = $this->resolve_attendee($participant['email']);
            if (!$user) {
                $unmatched[] = $participant['email'];
                continue;
            }

            $merged = self::merge_intervals($participant['intervals']);

            $total_seconds = $participant['orphan'];
            foreach ($merged as $interval) {
                $total_seconds += $interval[1] - $interval[0];
            }

            $pct = $meeting_duration > 0
                ? min(100, round(($total_seconds / $meeting_duration) * 100, 2))
                : 0;

            $first_join = !empty($merged) ? $merged[0][0] : null;
            $last_leave = !empty($merged) ? end($merged)[1] : null;

            // Upsert attendance record.
            $existing = $DB->get_record('msteamsecp_attendance', [
                'occurrenceid' => $occ->id,
                'userid'       => $user->id,
            ]);

            // A re-poll can only ever add intervals, so never let a partial
            // later read shrink what was already recorded — and judge credit on
            // the best figure seen rather than just this run's.
            if ($existing) {
                $total_seconds = max($total_seconds, (int) $existing->duration_seconds);
                $pct           = max($pct, (float) $existing->attendance_pct);
            }

            $credit = $pct >= $threshold;

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
                $credited++;
                $this->maybe_grant_completion($user->id, $occ);
            }
        }

        // Report what was dropped. Silently skipping unresolvable addresses was
        // the single hardest failure mode to diagnose on a live site.
        if (!empty($unmatched)) {
            mtrace('  msteamsecp: ' . count($unmatched) . " attendee(s) on occurrence {$occ->id}"
                . ' had no matching Moodle account: ' . implode(', ', $unmatched));
        }
        if ($anonymous > 0) {
            mtrace("  msteamsecp: {$anonymous} anonymous/dial-in participant(s) on occurrence {$occ->id}"
                . ' had no email address and could not be credited.');
        }

        $DB->update_record('msteamsecp_occurrences', (object) [
            'id'                   => $occ->id,
            'attendance_fetched'   => 1,
            'attendance_report_id' => \core_text::substr(implode(',', $report_ids), 0, 500),
        ]);

        mtrace("  msteamsecp: attendance processed for occurrence {$occ->id} — "
            . count($participants) . ' participant(s), ' . $credited . ' credited'
            . ' (threshold ' . $threshold . '%).');

        // In 'all' mode learners already have invites for every occurrence —
        // no advancing needed. In 'any' mode push the next occurrence to
        // users who still haven't earned completion credit. Only on the first
        // successful fetch: re-polls must not re-run the Graph invite push.
        if (!$first_fetch) {
            return;
        }

        $instance = $DB->get_record('msteamsecp', ['id' => $occ->instanceid]);
        if ($instance && ($instance->attendance_requirement ?? 'any') === 'any') {
            $this->push_next_occurrence_for_incomplete_users($occ);
        }
    }

    /**
     * Select the attendance reports that belong to this occurrence.
     *
     * Each report is attributed to whichever occurrence of the series its
     * meeting start time is nearest to, then kept only if this is that
     * occurrence and the gap is within tolerance. Attributing by nearest
     * occurrence (rather than a fixed window around this one) keeps
     * back-to-back sessions on the same day from stealing each other's reports.
     *
     * @param array  $reports  Reports from get_attendance_reports()
     * @param object $occ
     * @return array           Subset of $reports belonging to $occ
     */
    private function reports_for_occurrence(array $reports, object $occ): array {
        global $DB;

        $siblings = $DB->get_records('msteamsecp_occurrences',
            ['instanceid' => $occ->instanceid], '', 'id, starttime, endtime');

        $matched   = [];
        $undatable = [];

        foreach ($reports as $report) {
            $start = strtotime((string) ($report['meetingStartDateTime'] ?? ''));
            if (!$start) {
                $undatable[] = $report;
                continue;
            }

            $bestid   = null;
            $bestgap  = null;
            foreach ($siblings as $sibling) {
                if ($start < $sibling->starttime) {
                    $gap = $sibling->starttime - $start;
                } else if ($start > $sibling->endtime) {
                    $gap = $start - $sibling->endtime;
                } else {
                    $gap = 0;
                }
                if ($bestgap === null || $gap < $bestgap) {
                    $bestgap = $gap;
                    $bestid  = (int) $sibling->id;
                }
            }

            if ($bestid === (int) $occ->id && $bestgap <= self::OCCURRENCE_MATCH_TOLERANCE) {
                $matched[] = $report;
            }
        }

        // Reports without a usable start time can only be placed when the
        // series has a single occurrence — then they must belong to it.
        if (!empty($undatable) && count($siblings) === 1) {
            $matched = array_merge($matched, $undatable);
        } else if (!empty($undatable)) {
            mtrace('  msteamsecp: ' . count($undatable) . ' attendance report(s) have no'
                . " meetingStartDateTime and cannot be attributed to an occurrence.");
        }

        return $matched;
    }

    /**
     * Resolve an attendance record's email address to a Moodle user.
     *
     * Matching is case-insensitive, and Azure guest addresses of the form
     * "person_example.com#EXT#@tenant.onmicrosoft.com" are also tried in their
     * original "person@example.com" form. Suspended accounts are matched but
     * ranked last, so attendance is still recorded for them rather than lost.
     *
     * @param string $email
     * @return object|null
     */
    private function resolve_attendee(string $email) {
        global $DB;

        $normalised = \core_text::strtolower(trim($email));
        if ($normalised === '') {
            return null;
        }

        $candidates = [$normalised];

        $extpos = strpos($normalised, '#ext#');
        if ($extpos !== false) {
            $prefix = substr($normalised, 0, $extpos);
            $sep    = strrpos($prefix, '_');
            if ($sep !== false) {
                $candidates[] = substr($prefix, 0, $sep) . '@' . substr($prefix, $sep + 1);
            }
        }

        $sql = "SELECT *
                  FROM {user}
                 WHERE deleted = 0
                   AND " . $DB->sql_equal('email', ':email', false) . "
              ORDER BY suspended ASC, id ASC";

        foreach ($candidates as $candidate) {
            $users = $DB->get_records_sql($sql, ['email' => $candidate], 0, 1);
            if (!empty($users)) {
                return reset($users);
            }
        }

        return null;
    }

    /**
     * Union a set of [start, end] intervals so overlapping presence — the same
     * participant reported twice across restarted sessions — is counted once.
     *
     * @param array $intervals  Array of [start, end] pairs
     * @return array            Non-overlapping intervals, ascending
     */
    private static function merge_intervals(array $intervals): array {
        if (empty($intervals)) {
            return [];
        }

        usort($intervals, static fn($a, $b) => $a[0] <=> $b[0]);

        $merged = [];
        foreach ($intervals as $interval) {
            $last = count($merged) - 1;
            if ($last >= 0 && $interval[0] <= $merged[$last][1]) {
                $merged[$last][1] = max($merged[$last][1], $interval[1]);
            } else {
                $merged[] = $interval;
            }
        }

        return $merged;
    }

    /**
     * Log that attendance couldn't be resolved this run, and stop polling once
     * the report is clearly never going to appear.
     *
     * Without the cut-off, an occurrence whose report never materialises is
     * re-queried on every cron run forever — one Graph call per stuck
     * occurrence, every 15 minutes, indefinitely.
     *
     * @param object $occ
     * @param string $reason
     */
    private function retry_or_abandon_attendance(object $occ, string $reason): void {
        global $DB;

        if (time() < $occ->endtime + self::ATTENDANCE_ABANDON_AFTER) {
            mtrace("  msteamsecp: attendance for occurrence {$occ->id} unavailable ({$reason}), will retry.");
            return;
        }

        $DB->update_record('msteamsecp_occurrences', (object) [
            'id'                 => $occ->id,
            'attendance_fetched' => 1,
        ]);

        mtrace("  msteamsecp: giving up on attendance for occurrence {$occ->id} ({$reason}) — "
            . 'more than ' . (self::ATTENDANCE_ABANDON_AFTER / DAYSECS) . ' days after it ended.');
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

        // Find all users actively enrolled in this course who do not yet have
        // completion credit — check BOTH the plugin's own attendance table
        // AND Moodle's standard completion state so that manually granted
        // completion credit (e.g. for users sharing a computer) also stops
        // further invites being sent.
        //
        // The enrolment must be active: a suspended user enrolment, a disabled
        // enrolment method, or an enrolment that hasn't started or has already
        // expired all mean the learner shouldn't be sent a Graph invite. These
        // are the same conditions core applies for $onlyactive in
        // get_enrolled_users(), which push_events_for_instance() already uses.
        $sql = "SELECT u.id, u.email, u.firstname, u.lastname
                  FROM {user_enrolments} ue
                  JOIN {enrol} e ON e.id = ue.enrolid AND e.courseid = :courseid
                                AND e.status = :enabledinstance
                  JOIN {user} u ON u.id = ue.userid AND u.deleted = 0 AND u.suspended = 0
                 WHERE u.email <> ''
                   AND ue.status = :activeuser
                   AND ue.timestart < :now1
                   AND (ue.timeend = 0 OR ue.timeend > :now2)
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

        if (empty($cmid)) {
            // Without a cmid the completion check below matches nothing, which
            // would treat every learner as incomplete and re-push invites.
            mtrace("  msteamsecp: could not resolve cmid for instance {$occ->instanceid}, "
                . 'skipping next-occurrence push.');
            return;
        }

        $now = time();

        $users = $DB->get_records_sql($sql, [
            'courseid'        => $occ->course,
            'instanceid'      => $occ->instanceid,
            'cmid'            => (int) $cmid,
            'enabledinstance' => ENROL_INSTANCE_ENABLED,
            'activeuser'      => ENROL_USER_ACTIVE,
            'now1'            => $now,
            'now2'            => $now,
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
            $this->retry_or_abandon_recording($occ, 'meeting has no graph_meeting_id');
            return;
        }

        $recordings = $this->graph->get_recordings($occ->graph_meeting_id);

        if (empty($recordings)) {
            $this->retry_or_abandon_recording($occ, 'no recordings returned');
            return;
        }

        // A recurring meeting's ID is series-level, so /recordings returns every
        // recording made across the series. Taking element 0 gave every
        // occurrence the same (usually the first) recording.
        $recording = $this->recording_for_occurrence($recordings, $occ);

        if ($recording === null) {
            $this->retry_or_abandon_recording($occ, count($recordings)
                . ' recording(s) returned, none matching this occurrence\'s scheduled window');
            return;
        }

        $content_url = $recording['recordingContentUrl'] ?? '';

        if (empty($content_url)) {
            $this->retry_or_abandon_recording($occ, 'matched recording has no content URL');
            return;
        }

        // Resolve the msteamsecp course module ID — needed for file context.
        $module_id = $DB->get_field('modules', 'id', ['name' => 'msteamsecp']);
        $occ->cmid = (int) $DB->get_field('course_modules', 'id', [
            'module'   => $module_id,
            'instance' => $occ->instanceid,
        ]);

        if (empty($occ->cmid)) {
            $this->retry_or_abandon_recording($occ, "could not resolve cmid for instance {$occ->instanceid}");
            return;
        }

        // Download to a temp file to avoid loading the entire binary into memory.
        $tmp_path = $CFG->tempdir . '/msteamsecp_recording_' . $occ->id . '_' . time() . '.mp4';

        mtrace("  msteamsecp: downloading recording for occurrence {$occ->id}...");

        try {
            $this->graph->download_recording_to_file($content_url, $tmp_path);
        } catch (\Throwable $e) {
            @unlink($tmp_path);
            $this->retry_or_abandon_recording($occ, 'download failed: ' . $e->getMessage());
            return;
        }

        if (!file_exists($tmp_path) || filesize($tmp_path) === 0) {
            @unlink($tmp_path);
            $this->retry_or_abandon_recording($occ, 'download produced an empty file');
            return;
        }

        mtrace("  msteamsecp: recording downloaded (" . round(filesize($tmp_path) / 1048576, 1) . " MB), storing...");

        try {
            $filename = 'recording_' . $occ->instanceid . '_' . $occ->id . '_' . date('Ymd', $occ->starttime) . '.mp4';

            if ($occ->recording_behavior === 'replace' && !empty($occ->recording_cmid)) {
                $this->replace_recording_activity($occ, $tmp_path, $filename);
            } else {
                $anchor = $this->create_recording_activity($occ, $tmp_path, $filename);
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
     * Log that the recording couldn't be retrieved this run, and stop looking
     * once it is clear one is never going to appear.
     *
     * An occurrence that was never actually held — a series meeting skipped for
     * a holiday, or cancelled — produces no recording, ever. Without the
     * cut-off that occurrence is re-queried against Graph on every cron run
     * indefinitely. Clear recording_abandoned to make the cron try again.
     *
     * @param object $occ
     * @param string $reason
     */
    private function retry_or_abandon_recording(object $occ, string $reason): void {
        global $DB;

        if (time() < $occ->endtime + self::RECORDING_ABANDON_AFTER) {
            mtrace("  msteamsecp: recording for occurrence {$occ->id} unavailable ({$reason}), will retry.");
            return;
        }

        $DB->update_record('msteamsecp_occurrences', (object) [
            'id'                  => $occ->id,
            'recording_abandoned' => 1,
        ]);

        mtrace("  msteamsecp: giving up on the recording for occurrence {$occ->id} ({$reason}) — "
            . 'more than ' . (self::RECORDING_ABANDON_AFTER / DAYSECS) . ' days after it ended. '
            . 'Set recording_abandoned = 0 on that row to retry.');
    }

    /**
     * Select the recording belonging to this occurrence.
     *
     * Mirrors reports_for_occurrence(): each recording is attributed to the
     * occurrence its creation time is nearest to. When the series has a single
     * occurrence, or no recording carries a usable timestamp, the first
     * recording is used — preserving the previous behaviour for simple
     * one-off meetings.
     *
     * @param array  $recordings  Recordings from get_recordings()
     * @param object $occ
     * @return array|null         The matching recording, or null
     */
    private function recording_for_occurrence(array $recordings, object $occ): ?array {
        global $DB;

        if (empty($recordings)) {
            return null;
        }

        $siblings = $DB->get_records('msteamsecp_occurrences',
            ['instanceid' => $occ->instanceid], '', 'id, starttime, endtime');

        if (count($siblings) <= 1) {
            return $recordings[0];
        }

        $best     = null;
        $bestgap  = null;
        $anydated = false;

        foreach ($recordings as $recording) {
            $created = strtotime((string) ($recording['createdDateTime'] ?? ''));
            if (!$created) {
                continue;
            }
            $anydated = true;

            if ($created < $occ->starttime) {
                $gap = $occ->starttime - $created;
            } else if ($created > $occ->endtime) {
                $gap = $created - $occ->endtime;
            } else {
                $gap = 0;
            }

            // Only claim the recording if this occurrence is its closest match.
            $closest = true;
            foreach ($siblings as $sibling) {
                if ((int) $sibling->id === (int) $occ->id) {
                    continue;
                }
                if ($created < $sibling->starttime) {
                    $siblinggap = $sibling->starttime - $created;
                } else if ($created > $sibling->endtime) {
                    $siblinggap = $created - $sibling->endtime;
                } else {
                    $siblinggap = 0;
                }
                if ($siblinggap < $gap) {
                    $closest = false;
                    break;
                }
            }

            if ($closest && $gap <= self::OCCURRENCE_MATCH_TOLERANCE
                    && ($bestgap === null || $gap < $bestgap)) {
                $bestgap = $gap;
                $best    = $recording;
            }
        }

        // No recording carried a usable timestamp — fall back to the first.
        if (!$anydated) {
            return $recordings[0];
        }

        return $best;
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
    private function create_recording_activity(object $occ, string $tmp_path, string $filename): int {
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
     * Replace the file on an existing recording activity (replace mode).
     */
    private function replace_recording_activity(object $occ, string $tmp_path, string $filename): void {
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
    public function grant_completion(int $userid, int $courseid, int $instanceid): void {
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

}
