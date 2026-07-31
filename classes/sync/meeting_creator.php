<?php
/**
 * Orchestrates Teams meeting creation via Graph API.
 *
 * Called from lib.php add_instance / update_instance.
 * Creates (or updates) both the onlineMeeting resource and the
 * linked calendar event, applies lobby settings, auto-record,
 * and assigns co-organisers with role:coorganizer on the Teams meeting.
 *
 * Co-organiser role assignment requires:
 *   - The Prefer: include-unknown-enum-members header (set globally in graph.php)
 *   - The Teams Application Access Policy granted to the service account
 *
 * Learner calendar invites are handled separately by enrolment_handler.php
 * using the rolling single-occurrence push model. Adding learners to the
 * onlineMeeting participants list does NOT push calendar events — that
 * only happens via POST /users/{email}/events.
 *
 * @package    mod_msteamsecp
 * @copyright  2026 Eyecare Partners
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_msteamsecp\sync;

defined('MOODLE_INTERNAL') || die();

use mod_msteamsecp\api\graph;

class meeting_creator {

    /**
     * Upper bound on occurrences in a single recurring series.
     *
     * Recurrence is bounded by a count rather than an end date (see 1.7.2), so
     * this is the only thing standing between a mistyped number and thousands
     * of occurrence rows plus Graph invites.
     */
    const MAX_OCCURRENCES = 200;

    /** @var graph */
    private $graph;

    public function __construct() {
        $this->graph = new graph();
    }

    /**
     * Coerce a stored/submitted occurrence count into the supported range.
     *
     * @param mixed $value
     * @return int  Between 1 and MAX_OCCURRENCES
     */
    public static function clamp_occurrence_count($value): int {
        return max(1, min((int) $value, self::MAX_OCCURRENCES));
    }

    // -------------------------------------------------------------------------
    // Public entry points
    // -------------------------------------------------------------------------

    /**
     * Create a new meeting from a just-saved instance record.
     *
     * @param object $instance  msteamsecp record (freshly inserted)
     * @param object $course    Moodle course record
     */
    public function create(object $instance, object $course): void {
        if ($instance->is_recurring) {
            $this->create_recurring($instance, $course);
        } else {
            $this->create_single($instance, $course);
        }
    }

    /**
     * Update an existing meeting after the instance is edited.
     *
     * @param object $instance  Updated msteamsecp record
     */
    public function update(object $instance): void {
        global $DB;

        if (empty($instance->graph_meeting_id)) {
            $course = $DB->get_record('course', ['id' => $instance->course], '*', \MUST_EXIST);
            $this->create($instance, $course);
            return;
        }

        try {
            $this->graph->update_meeting($instance->graph_meeting_id, [
                'subject'               => $instance->name,
                'startDateTime'         => $this->to_iso8601($instance->starttime),
                'endDateTime'           => $this->to_iso8601($instance->endtime),
                'lobbyBypassSettings'   => $this->lobby_settings($instance->lobby_bypass ?? 'organizer'),
                'allowedLobbyAdmitters' => 'organizerAndCoOrganizers',
                'allowRecording'        => true,
                'allowTranscription'    => true,
                'recordAutomatically'   => (bool) $instance->auto_record,
            ]);
        } catch (\Throwable $e) {
            debugging('msteamsecp: Graph meeting update failed: ' . $e->getMessage(), \DEBUG_DEVELOPER);
        }

        $this->sync_occurrences($instance);
        $this->recreate_calendar_event($instance);

        // Re-push Outlook invites to enrolled learners using the updated occurrence schedule.
        $handler = new enrolment_handler();
        $handler->push_events_for_instance($instance);

        $this->sync_coorganisers($instance);
    }

    /**
     * Rebuild upcoming occurrence rows after an instance edit so that
     * msteamsecp_sync_calendar_events() recreates LMS calendar events with
     * the correct schedule.
     *
     * Ended rows are left intact — they carry attendance and completion data.
     * For recurring meetings, upcoming occurrences are replaced (existing
     * Outlook invites are retracted first so learners are not left with stale
     * calendar entries). For single meetings, starttime/endtime are stamped
     * in-place after retracting the current invite.
     *
     * @param object $instance  msteamsecp record
     */
    private function sync_occurrences(object $instance): void {
        global $DB;

        if ($instance->is_recurring) {
            $this->reconcile_occurrence_rows(
                $instance,
                $instance->graph_meeting_id,
                $instance->graph_event_id ?? ''
            );
        } else {
            $occ_ids = $DB->get_fieldset_select(
                'msteamsecp_occurrences',
                'id',
                "instanceid = :id AND status != 'ended'",
                ['id' => $instance->id]
            );
            if ($occ_ids) {
                $this->retract_enrollee_events($occ_ids);
            }
            $DB->execute(
                "UPDATE {msteamsecp_occurrences}
                    SET starttime = :start, endtime = :end
                  WHERE instanceid = :id AND status != 'ended'",
                ['start' => $instance->starttime, 'end' => $instance->endtime, 'id' => $instance->id]
            );
        }
    }

    /**
     * Delete Outlook calendar invites from learners' calendars via Graph and
     * remove the corresponding enrollee_event tracking rows.
     *
     * @param int[] $occ_ids  msteamsecp_occurrences IDs whose invites should be retracted
     */
    private function retract_enrollee_events(array $occ_ids): void {
        global $DB;

        [$in_sql, $in_params] = $DB->get_in_or_equal($occ_ids);
        $enrollee_events = $DB->get_records_select(
            'msteamsecp_enrollee_events',
            "occurrenceid $in_sql AND removed = 0",
            $in_params
        );

        foreach ($enrollee_events as $ee) {
            if (!empty($ee->graph_event_id)) {
                try {
                    $user = $DB->get_record('user', ['id' => $ee->userid]);
                    if ($user && !empty($user->email)) {
                        $this->graph->delete_user_event($user->email, $ee->graph_event_id);
                    }
                } catch (\Throwable $e) {
                    debugging('msteamsecp: could not retract calendar invite: ' . $e->getMessage(), \DEBUG_DEVELOPER);
                }
            }
        }

        $DB->delete_records_select('msteamsecp_enrollee_events', "occurrenceid $in_sql", $in_params);
    }

    /**
     * Delete and recreate the service account calendar event with the current
     * schedule. The Graph API returns 405 when PATCHing startDateTime/endDateTime
     * on a meeting-linked calendar event, so delete+recreate is the only way to
     * move the event in Outlook/Teams.
     *
     * Updates msteamsecp.graph_event_id and all occurrence rows to the new ID so
     * that sync_coorganisers() and post_event_processor use the correct event.
     *
     * @param object $instance  msteamsecp record (mutated: graph_event_id updated)
     */
    private function recreate_calendar_event(object $instance): void {
        global $DB;

        if (!empty($instance->graph_event_id)) {
            try {
                $this->graph->delete_event($instance->graph_event_id);
            } catch (\Throwable $e) {
                debugging('msteamsecp: could not delete old calendar event: ' . $e->getMessage(), \DEBUG_DEVELOPER);
            }
        }

        $tz                 = \core_date::get_server_timezone();
        $course             = $DB->get_record('course', ['id' => $instance->course], '*', \MUST_EXIST);
        $coorganiser_emails = $this->get_coorganiser_emails($instance->id);

        $event_params = [
            'subject'   => $instance->name,
            'body'      => $this->build_event_body($course->fullname, $instance->join_url ?? ''),
            'start'     => ['dateTime' => $this->to_local_datetime($instance->starttime), 'timeZone' => $tz],
            'end'       => ['dateTime' => $this->to_local_datetime($instance->endtime),   'timeZone' => $tz],
            'attendees' => $this->build_calendar_attendees($coorganiser_emails),
        ];

        if ($instance->is_recurring) {
            $event_params['recurrence'] = $this->build_recurrence_pattern($instance);
        }

        try {
            $new_event    = $this->graph->create_event($event_params);
            $new_event_id = $new_event['id'] ?? '';

            $DB->update_record('msteamsecp', (object) [
                'id'             => $instance->id,
                'graph_event_id' => $new_event_id,
                'timemodified'   => time(),
            ]);
            $DB->execute(
                "UPDATE {msteamsecp_occurrences} SET graph_event_id = :evtid WHERE instanceid = :id",
                ['evtid' => $new_event_id, 'id' => $instance->id]
            );

            $instance->graph_event_id = $new_event_id;
        } catch (\Throwable $e) {
            debugging('msteamsecp: could not recreate calendar event: ' . $e->getMessage(), \DEBUG_DEVELOPER);
        }
    }

    /**
     * Delete all Graph resources for an instance.
     *
     * @param object $instance
     */
    public function delete(object $instance): void {
        global $DB;

        if (!empty($instance->graph_meeting_id)) {
            try {
                $this->graph->delete_meeting($instance->graph_meeting_id);
            } catch (\Throwable $e) {
                debugging('msteamsecp: could not delete Graph meeting: ' . $e->getMessage(), \DEBUG_DEVELOPER);
            }
        }

        if (!empty($instance->graph_event_id)) {
            try {
                $this->graph->delete_event($instance->graph_event_id);
            } catch (\Throwable $e) {
                debugging('msteamsecp: could not delete Graph event: ' . $e->getMessage(), \DEBUG_DEVELOPER);
            }
        }

        // Remove learner calendar events from each learner's own calendar.
        $enrollee_events = $DB->get_records('msteamsecp_enrollee_events', [
            'instanceid' => $instance->id,
            'removed'    => 0,
        ]);
        foreach ($enrollee_events as $ee) {
            if (!empty($ee->graph_event_id)) {
                try {
                    $user = $DB->get_record('user', ['id' => $ee->userid]);
                    if ($user && !empty($user->email)) {
                        $this->graph->delete_user_event($user->email, $ee->graph_event_id);
                    }
                } catch (\Throwable $e) {
                    debugging('msteamsecp: could not remove user calendar event: ' . $e->getMessage(), \DEBUG_DEVELOPER);
                }
            }
        }
    }

    // -------------------------------------------------------------------------
    // Co-organiser sync
    // -------------------------------------------------------------------------

    /**
     * Sync co-organisers onto both the Teams meeting and the service account
     * calendar event.
     *
     * onlineMeeting: PATCHes participants.attendees with role:coorganizer,
     * giving co-organisers true co-organiser rights in Teams (start meeting,
     * manage recording, admit from lobby, end for all).
     *
     * Calendar event: PATCHes attendees so co-organisers receive an updated
     * Outlook calendar invite reflecting any changes. The existing event body
     * is fetched first to preserve the Teams meeting blob — PATCHing without
     * it would strip the join URL from the calendar event.
     *
     * Note: both PATCHes require the full attendee list, not a delta.
     *
     * @param object $instance
     */
    public function sync_coorganisers(object $instance): void {
        global $DB;

        if (empty($instance->graph_meeting_id)) {
            return;
        }

        $coorganiser_emails = $this->get_coorganiser_emails($instance->id);

        if (empty($coorganiser_emails)) {
            return;
        }

        // 1. Sync co-organiser role on the onlineMeeting resource.
        try {
            $attendees = $this->build_coorganiser_attendees($coorganiser_emails);
            debugging('msteamsecp sync_coorganisers PATCH body: ' . json_encode([
                'participants' => ['attendees' => $attendees],
            ]), \DEBUG_NORMAL);
            $result = $this->graph->update_meeting($instance->graph_meeting_id, [
                'participants' => ['attendees' => $attendees],
            ]);
            debugging('msteamsecp sync_coorganisers PATCH result — attendee roles: '
                . json_encode(array_map(
                    fn($a) => ['upn' => $a['upn'] ?? '?', 'role' => $a['role'] ?? '?'],
                    $result['participants']['attendees'] ?? []
                )) . ' | lobby: ' . json_encode($result['lobbyBypassSettings'] ?? 'missing'),
                \DEBUG_NORMAL);
        } catch (\Throwable $e) {
            debugging('msteamsecp: co-organiser meeting sync failed: ' . $e->getMessage(), \DEBUG_NORMAL);
        }

        // 2. Sync attendees on the service account calendar event so co-organisers
        //    receive an updated Outlook invite. Must fetch the existing body first
        //    to preserve the Teams meeting blob — omitting it strips the join URL.
        if (!empty($instance->graph_event_id)) {
            try {
                $existing = $this->graph->get_event($instance->graph_event_id);
                // Always re-include recurrence when patching a recurring series master.
                // Graph API can silently truncate the series when recurrence is omitted
                // from a PATCH — the last occurrence is dropped relative to today's date.
                $patch = [
                    'attendees' => $this->build_calendar_attendees($coorganiser_emails),
                    'body'      => $existing['body'] ?? [],
                ];
                if (!empty($existing['recurrence'])) {
                    $patch['recurrence'] = $existing['recurrence'];
                }
                $this->graph->update_event($instance->graph_event_id, $patch);
            } catch (\Throwable $e) {
                debugging('msteamsecp: co-organiser calendar event sync failed: ' . $e->getMessage(), \DEBUG_DEVELOPER);
            }
        }
    }

    /**
     * Save co-organiser selections from form data.
     * Called from lib.php after add_instance / update_instance.
     *
     * @param int      $instanceid
     * @param string[] $emails  Email addresses entered in the co-organiser textarea
     */
    public function save_coorganisers(int $instanceid, array $emails): void {
        global $DB;

        $DB->delete_records('msteamsecp_coorganisers', ['instanceid' => $instanceid]);

        foreach ($emails as $email) {
            $email = strtolower(trim($email));
            if ($email !== '') {
                $DB->insert_record('msteamsecp_coorganisers', (object) [
                    'instanceid'  => $instanceid,
                    'email'       => $email,
                    'timecreated' => time(),
                ]);
            }
        }
    }

    // -------------------------------------------------------------------------
    // Private — single meeting
    // -------------------------------------------------------------------------

    private function create_single(object $instance, object $course): void {
        global $DB;

        $coorganiser_emails = $this->get_coorganiser_emails($instance->id);

        // Step 1: Create the Teams meeting via /me/onlineMeetings.
        // This is the ONLY thing allowed to create a Teams meeting.
        $meeting = $this->graph->create_meeting([
            'subject'               => $instance->name,
            'startDateTime'         => $this->to_iso8601($instance->starttime),
            'endDateTime'           => $this->to_iso8601($instance->endtime),
            'lobbyBypassSettings'   => $this->lobby_settings($instance->lobby_bypass ?? 'organizer'),
            'allowedLobbyAdmitters' => 'organizerAndCoOrganizers',
            'allowRecording'        => true,
            'allowTranscription'    => true,
            'recordAutomatically'   => (bool) $instance->auto_record,
            'participants'          => [
                'attendees' => $this->build_coorganiser_attendees($coorganiser_emails),
            ],
        ]);

        $join_url = $meeting['joinWebUrl'] ?? '';

        // Step 2: Create a calendar event that REFERENCES the existing meeting.
        // IMPORTANT: Do NOT set isOnlineMeeting or onlineMeetingProvider here.
        // Setting those fields causes the Calendar API to create a brand new
        // Teams meeting, generating a different meeting ID and ignoring the one
        // we just created above. The join link lives only in the event body HTML.
        $tz = \core_date::get_server_timezone();
        $event_params = [
            'subject'   => $instance->name,
            'body'      => $this->build_event_body($course->fullname, $join_url),
            'start'     => ['dateTime' => $this->to_local_datetime($instance->starttime), 'timeZone' => $tz],
            'end'       => ['dateTime' => $this->to_local_datetime($instance->endtime),   'timeZone' => $tz],
            'attendees' => $this->build_calendar_attendees($coorganiser_emails),
        ];

        $event = $this->graph->create_event($event_params);

        $DB->update_record('msteamsecp', (object) [
            'id'               => $instance->id,
            'graph_meeting_id' => $meeting['id'],
            'graph_event_id'   => $event['id'],
            'join_url'         => $join_url,
            'timemodified'     => time(),
        ]);

        $DB->insert_record('msteamsecp_occurrences', (object) [
            'instanceid'          => $instance->id,
            'occurrence_index'    => 1,
            'graph_occurrence_id' => $meeting['id'],
            'graph_event_id'      => $event['id'],
            'starttime'           => $instance->starttime,
            'endtime'             => $instance->endtime,
            'status'              => 'upcoming',
            'timecreated'         => time(),
        ]);

        $instance->graph_meeting_id = $meeting['id'];
        $instance->graph_event_id   = $event['id'];
        $this->sync_coorganisers($instance);
    }

    private function create_recurring(object $instance, object $course): void {
        global $DB;

        $coorganiser_emails = $this->get_coorganiser_emails($instance->id);

        $meeting = $this->graph->create_meeting([
            'subject'               => $instance->name,
            'startDateTime'         => $this->to_iso8601($instance->starttime),
            'endDateTime'           => $this->to_iso8601($instance->endtime),
            'lobbyBypassSettings'   => $this->lobby_settings($instance->lobby_bypass ?? 'organizer'),
            'allowedLobbyAdmitters' => 'organizerAndCoOrganizers',
            'allowRecording'        => true,
            'allowTranscription'    => true,
            'recordAutomatically'   => (bool) $instance->auto_record,
            'participants'          => [
                'attendees' => $this->build_coorganiser_attendees($coorganiser_emails),
            ],
        ]);

        $join_url = $meeting['joinWebUrl'] ?? '';

        // Calendar event references the meeting via body only — no isOnlineMeeting.
        $tz = \core_date::get_server_timezone();
        $event_params = [
            'subject'   => $instance->name,
            'body'      => $this->build_event_body($course->fullname, $join_url),
            'start'     => ['dateTime' => $this->to_local_datetime($instance->starttime), 'timeZone' => $tz],
            'end'       => ['dateTime' => $this->to_local_datetime($instance->endtime),   'timeZone' => $tz],
            'recurrence'=> $this->build_recurrence_pattern($instance),
            'attendees' => $this->build_calendar_attendees($coorganiser_emails),
        ];

        $event = $this->graph->create_event($event_params);

        $DB->update_record('msteamsecp', (object) [
            'id'               => $instance->id,
            'graph_meeting_id' => $meeting['id'],
            'graph_event_id'   => $event['id'],
            'join_url'         => $join_url,
            'timemodified'     => time(),
        ]);

        $this->reconcile_occurrence_rows($instance, $meeting['id'], $event['id']);

        $instance->graph_meeting_id = $meeting['id'];
        $instance->graph_event_id   = $event['id'];
        $this->sync_coorganisers($instance);
    }

    /**
     * Reconcile occurrence rows against the current recurrence definition.
     *
     * Idempotent by design: existing rows are matched to expanded start times,
     * so saving an activity any number of times converges on exactly one row
     * per scheduled occurrence.
     *
     * This replaced a delete-then-reinsert approach that deleted only rows with
     * status != 'ended' but re-inserted the *entire* expansion, stamping
     * anything already started as 'ended'. Those freshly inserted 'ended' rows
     * were invisible to the next save's delete, so every re-save appended
     * another complete copy of every occurrence that had already begun. Three
     * saves of a three-session series produced nine rows — appearing as three
     * separate meetings at the same time, each recurring three times.
     *
     * Ended occurrences that have dropped out of the schedule are KEPT: they
     * carry attendance and completion history. Upcoming ones that have dropped
     * out are retracted from learners' calendars and removed.
     *
     * @param object $instance
     * @param string $meeting_id
     * @param string $event_id
     */
    private function reconcile_occurrence_rows(object $instance, string $meeting_id, string $event_id): void {
        global $DB;

        $duration = $instance->endtime - $instance->starttime;
        $targets  = $this->expand_recurrence($instance);
        $now      = time();

        $existing = $DB->get_records('msteamsecp_occurrences',
            ['instanceid' => $instance->id], 'starttime ASC, id ASC');

        // Index by start time. Any collision is a duplicate left behind by the
        // pre-1.7.2 re-save bug; keep whichever row carries learner data.
        $by_start  = [];
        $duplicate = [];
        foreach ($existing as $row) {
            if (!isset($by_start[$row->starttime])) {
                $by_start[$row->starttime] = $row;
                continue;
            }
            $incumbent = $by_start[$row->starttime];
            if (!$this->occurrence_has_data($incumbent->id) && $this->occurrence_has_data($row->id)) {
                $by_start[$row->starttime] = $row;
                $duplicate[] = $incumbent;
            } else {
                $duplicate[] = $row;
            }
        }

        $matched = [];

        foreach ($targets as $index => $starttime) {
            if (isset($by_start[$starttime])) {
                // Already scheduled at this time — update in place so attendance
                // and any invite already pushed for it survive the edit.
                $row = $by_start[$starttime];
                $matched[$row->id] = true;
                $DB->update_record('msteamsecp_occurrences', (object) [
                    'id'                  => $row->id,
                    'occurrence_index'    => $index + 1,
                    'endtime'             => $starttime + $duration,
                    'graph_occurrence_id' => $meeting_id,
                    'graph_event_id'      => $event_id,
                ]);
                continue;
            }

            $DB->insert_record('msteamsecp_occurrences', (object) [
                'instanceid'          => $instance->id,
                'occurrence_index'    => $index + 1,
                'graph_occurrence_id' => $meeting_id,
                'graph_event_id'      => $event_id,
                'starttime'           => $starttime,
                'endtime'             => $starttime + $duration,
                'status'              => $starttime > $now ? 'upcoming' : 'ended',
                'timecreated'         => $now,
            ]);
        }

        // Rows that are no longer part of the schedule.
        $stale = [];
        foreach ($by_start as $row) {
            if (isset($matched[$row->id]) || $row->status === 'ended') {
                continue;
            }
            $stale[] = (int) $row->id;
        }

        // Duplicates are only safe to drop when nothing references them.
        foreach ($duplicate as $row) {
            if (!$this->occurrence_has_data((int) $row->id)) {
                $stale[] = (int) $row->id;
            }
        }

        if (!empty($stale)) {
            $this->retract_enrollee_events($stale);
            [$in_sql, $in_params] = $DB->get_in_or_equal($stale);
            $DB->delete_records_select('msteamsecp_occurrences', "id $in_sql", $in_params);
        }
    }

    /**
     * True when an occurrence row has learner data hanging off it, so deleting
     * it would lose attendance, watch progress or invite tracking.
     *
     * @param int $occurrenceid
     * @return bool
     */
    private function occurrence_has_data(int $occurrenceid): bool {
        global $DB;

        return $DB->record_exists('msteamsecp_attendance',      ['occurrenceid' => $occurrenceid])
            || $DB->record_exists('msteamsecp_watch_progress',  ['occurrenceid' => $occurrenceid])
            || $DB->record_exists('msteamsecp_enrollee_events', ['occurrenceid' => $occurrenceid]);
    }

    /**
     * Expand a recurrence definition into occurrence start timestamps.
     *
     * Recurrence is always bounded by a number of occurrences. The "ends on
     * date" option was removed in 1.7.2 — see msteamsecp_recurrence_count()
     * in lib.php.
     *
     * @param object $instance
     * @return int[]  Occurrence start timestamps, ascending
     */
    private function expand_recurrence(object $instance): array {
        $starts   = [];
        $interval = max(1, (int) $instance->recurrence_interval);
        $count    = 0;
        $max      = self::clamp_occurrence_count($instance->recurrence_count ?? null);

        // Work in the site timezone so that +N days preserves wall-clock time
        // across DST transitions. Without this, adding 7*86400 seconds shifts
        // meetings by one hour after clocks fall back (e.g. 11am becomes 10am).
        $tz      = new \DateTimeZone(\core_date::get_server_timezone());
        $current = (new \DateTimeImmutable('@' . (int) $instance->starttime))->setTimezone($tz);

        $days_of_week = [];
        if ($instance->recurrence_type === 'weekly' && !empty($instance->recurrence_days_of_week)) {
            $days_of_week = json_decode($instance->recurrence_days_of_week, true) ?? [];
        }

        // Hard iteration bound. The loop is driven purely by $count now that
        // there is no end date, so a recurrence_type that matches no branch
        // must not be able to spin.
        $guard = 0;

        while ($count < $max && $guard++ < self::MAX_OCCURRENCES * 2) {
            if ($instance->recurrence_type === 'weekly' && !empty($days_of_week)) {
                // Scan only the FIRST 7 days of each interval window.
                // The window advances by (7 * interval) days each iteration,
                // so only one occurrence of each day-of-week is possible per window.
                for ($d = 0; $d < 7; $d++) {
                    $candidate = $current->modify("+{$d} days");
                    $dow = (int) $candidate->format('N');
                    if (in_array($dow, $days_of_week) && $count < $max) {
                        $starts[] = $candidate->getTimestamp();
                        $count++;
                    }
                }
                $current = $current->modify('+' . (7 * $interval) . ' days');
            } else {
                $starts[] = $current->getTimestamp();
                $count++;
                switch ($instance->recurrence_type) {
                    case 'daily':
                        $current = $current->modify("+{$interval} days");
                        break;
                    case 'weekly':
                        $current = $current->modify('+' . (7 * $interval) . ' days');
                        break;
                    case 'monthly':
                        $current = $current->modify("+{$interval} months");
                        break;
                    default:
                        break 2;
                }
            }
        }

        return $starts;
    }

    // -------------------------------------------------------------------------
    // Private — helpers
    // -------------------------------------------------------------------------

    /**
     * Get email addresses of manually selected co-organisers for this instance.
     *
     * @param int $instanceid
     * @return string[]
     */
    private function get_coorganiser_emails(int $instanceid): array {
        global $DB;

        return array_values(
            $DB->get_fieldset_select(
                'msteamsecp_coorganisers',
                'email',
                "instanceid = :instanceid AND email <> ''",
                ['instanceid' => $instanceid]
            )
        );
    }

    /**
     * Build the participants.attendees array for the onlineMeeting resource.
     *
     * Uses role:coorganizer — requires Prefer: include-unknown-enum-members header
     * (set globally in graph.php). Resolves each email to an Azure AD object ID.
     * The full identity structure including tenantId is required for the
     * coorganizer role to be applied correctly.
     *
     * @param string[] $emails
     * @return array
     */
    private function build_coorganiser_attendees(array $emails): array {
        $tenant_id = get_config('mod_msteamsecp', 'tenant_id');
        $attendees = [];
        foreach ($emails as $email) {
            try {
                $user_info = $this->graph->get_user_by_email($email);
                if (!empty($user_info['id'])) {
                    $attendees[] = [
                        'upn'  => $email,
                        'role' => 'coorganizer',
                        'identity' => [
                            'application' => null,
                            'device'      => null,
                            'user' => [
                                'id'          => $user_info['id'],
                                'displayName' => $user_info['displayName'] ?? null,
                                'tenantId'    => $tenant_id,
                            ],
                        ],
                    ];
                }
            } catch (\Throwable $e) {
                debugging('msteamsecp: could not resolve co-organiser ' . $email . ': ' . $e->getMessage(), \DEBUG_DEVELOPER);
            }
        }
        return $attendees;
    }

    /**
     * Build the attendees array for the calendar event resource.
     *
     * These are standard Outlook calendar required attendees — separate from
     * the onlineMeeting participants list. Adding someone here sends them an
     * Outlook calendar invite. This is used for co-organisers only; learner
     * invites are sent individually by enrolment_handler.php.
     *
     * @param string[] $emails
     * @return array
     */
    private function build_calendar_attendees(array $emails): array {
        return array_map(function(string $email): array {
            return [
                'emailAddress' => ['address' => $email],
                'type'         => 'required',
            ];
        }, $emails);
    }

    /**
     * Build lobbyBypassSettings from the per-meeting lobby_bypass scope string.
     *
     * Scope values match the Graph API lobbyBypassScope enum:
     *   organizer                  — organiser only (service account, never present — avoid)
     *   coorganizers               — organisers and co-organisers bypass (recommended default)
     *   organization               — all org members and guests bypass
     *   organizationAndFederated   — org members, guests, and federated orgs bypass
     *   everyone                   — everyone bypasses (no lobby)
     *   invited                    — only explicitly invited attendees bypass
     *   organizationExcludingGuests — org members only, no guests
     *
     * @param string $scope  Value from mod_form lobby_bypass select field
     * @return array
     */
    private function lobby_settings(string $scope): array {
        $valid = [
            'organizer', 'invited', 'organization', 'organizationExcludingGuests',
            'organizationAndFederated', 'everyone',
        ];
        // Fall back to 'organizer' for any stale or invalid value.
        if (!in_array($scope, $valid, true)) {
            $scope = 'organizer';
        }
        return [
            'scope'                 => $scope,
            'isDialInBypassEnabled' => false,
        ];
    }

    private function build_recurrence_pattern(object $instance): array {
        $pattern = [
            'type'     => $instance->recurrence_type,
            'interval' => (int) ($instance->recurrence_interval ?? 1),
        ];

        $tz = new \DateTimeZone(\core_date::get_server_timezone());

        if ($instance->recurrence_type === 'weekly') {
            $day_map  = [1 => 'monday', 2 => 'tuesday', 3 => 'wednesday',
                         4 => 'thursday', 5 => 'friday', 6 => 'saturday', 7 => 'sunday'];
            $day_ints = !empty($instance->recurrence_days_of_week)
                ? (json_decode($instance->recurrence_days_of_week, true) ?? [])
                : [];
            $days = array_values(array_filter(
                array_map(fn($d) => $day_map[$d] ?? null, $day_ints)
            ));

            // Graph requires daysOfWeek on a weekly pattern and rejects the
            // request without it. When no day is ticked the series runs on the
            // start date's own weekday, which is what expand_recurrence() does.
            if (empty($days)) {
                $startdow = (int) (new \DateTimeImmutable('@' . (int) $instance->starttime))
                    ->setTimezone($tz)->format('N');
                $days = [$day_map[$startdow]];
            }

            $pattern['daysOfWeek'] = $days;
        }

        // Recurrence is always bounded by a count — the "ends on date" option
        // was removed in 1.7.2.
        $range = [
            'type'                => 'numbered',
            'startDate'           => (new \DateTimeImmutable('@' . (int) $instance->starttime))
                                        ->setTimezone($tz)->format('Y-m-d'),
            'numberOfOccurrences' => self::clamp_occurrence_count($instance->recurrence_count ?? null),
        ];

        return ['pattern' => $pattern, 'range' => $range];
    }

    private function to_iso8601(int $timestamp): string {
        return gmdate('Y-m-d\TH:i:s\Z', $timestamp);
    }

    private function to_local_datetime(int $timestamp): string {
        $tz = new \DateTimeZone(\core_date::get_server_timezone());
        return (new \DateTimeImmutable('@' . $timestamp))->setTimezone($tz)->format('Y-m-d\TH:i:s');
    }

    /**
     * Build an HTML event body matching the native Teams meeting invite style.
     * Segoe UI / sans-serif, 14px, #242424 — matching Microsoft's own formatting.
     * Graph appends the Teams join blob after this content automatically.
     *
     * @param string $description  Course name or other plain-text description
     * @return array               Graph body object {contentType, content}
     */
    private function build_event_body(string $description = '', string $join_url = ''): array {
        $safe = $description ? htmlspecialchars(strip_tags($description), ENT_QUOTES, 'UTF-8') : '';
        $url  = htmlspecialchars($join_url, ENT_QUOTES, 'UTF-8');
        $content = '<html><head><meta http-equiv="Content-Type" content="text/html; charset=utf-8"></head>'
            . '<body style="font-family:\'Segoe UI\',\'Segoe WP\',sans-serif; font-size:14px; color:#242424;">';
        if ($safe !== '') {
            $content .= '<p style="margin:0 0 16px 0;">' . $safe . '</p>';
        }
        if ($url) {
            $content .= '<p style="margin:0 0 8px 0;">'
                . '<a href="' . $url . '" style="color:#6264a7; font-weight:bold;">Join Microsoft Teams Meeting</a>'
                . '</p>'
                . '<p style="margin:0; font-size:12px; color:#666;">'
                . '<a href="' . $url . '" style="color:#666;">' . $url . '</a>'
                . '</p>';
        }
        $content .= '</body></html>';
        return ['contentType' => 'HTML', 'content' => $content];
    }
}
