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

    /** @var graph */
    private $graph;

    public function __construct() {
        $this->graph = new graph();
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

        $this->sync_coorganisers($instance);
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
     * @param int   $instanceid
     * @param array $userids     Array of user IDs selected in the form
     */
    public function save_coorganisers(int $instanceid, array $userids): void {
        global $DB;

        $DB->delete_records('msteamsecp_coorganisers', ['instanceid' => $instanceid]);

        foreach ($userids as $userid) {
            $userid = (int) $userid;
            if ($userid > 0) {
                $DB->insert_record('msteamsecp_coorganisers', (object) [
                    'instanceid'  => $instanceid,
                    'userid'      => $userid,
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
        $event_params = [
            'subject'   => $instance->name,
            'body'      => $this->build_event_body($course->fullname, $join_url),
            'start'     => ['dateTime' => $this->to_iso8601($instance->starttime), 'timeZone' => 'UTC'],
            'end'       => ['dateTime' => $this->to_iso8601($instance->endtime),   'timeZone' => 'UTC'],
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
        $event_params = [
            'subject'   => $instance->name,
            'body'      => $this->build_event_body($course->fullname, $join_url),
            'start'     => ['dateTime' => $this->to_iso8601($instance->starttime), 'timeZone' => 'UTC'],
            'end'       => ['dateTime' => $this->to_iso8601($instance->endtime),   'timeZone' => 'UTC'],
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

        $this->generate_occurrence_rows($instance, $meeting['id'], $event['id']);

        $instance->graph_meeting_id = $meeting['id'];
        $instance->graph_event_id   = $event['id'];
        $this->sync_coorganisers($instance);
    }

    private function generate_occurrence_rows(object $instance, string $meeting_id, string $event_id): void {
        global $DB;

        $duration    = $instance->endtime - $instance->starttime;
        $occurrences = $this->expand_recurrence($instance);

        foreach ($occurrences as $index => $starttime) {
            $DB->insert_record('msteamsecp_occurrences', (object) [
                'instanceid'          => $instance->id,
                'occurrence_index'    => $index + 1,
                'graph_occurrence_id' => $meeting_id,
                'graph_event_id'      => $event_id,
                'starttime'           => $starttime,
                'endtime'             => $starttime + $duration,
                'status'              => $starttime > time() ? 'upcoming' : 'ended',
                'timecreated'         => time(),
            ]);
        }
    }

    private function expand_recurrence(object $instance): array {
        $starts   = [];
        $current  = $instance->starttime;
        $interval = max(1, (int) $instance->recurrence_interval);
        $count    = 0;
        $max      = $instance->recurrence_count ? (int) $instance->recurrence_count : 500;
        // Extend end_date to end of the selected day (23:59:59) so that meetings
        // scheduled on the end date are included regardless of their time-of-day.
        $end_date = isset($instance->recurrence_end_date)
            ? (int) $instance->recurrence_end_date + 86399
            : \PHP_INT_MAX;

        $days_of_week = [];
        if ($instance->recurrence_type === 'weekly' && !empty($instance->recurrence_days_of_week)) {
            $days_of_week = json_decode($instance->recurrence_days_of_week, true) ?? [];
        }

        while ($count < $max && $current <= $end_date) {
            if ($instance->recurrence_type === 'weekly' && !empty($days_of_week)) {
                for ($d = 0; $d < (7 * $interval); $d++) {
                    $candidate = strtotime("+$d days", $current);
                    $dow = (int) date('N', $candidate);
                    if (in_array($dow, $days_of_week) && $candidate <= $end_date && $count < $max) {
                        $starts[] = $candidate;
                        $count++;
                    }
                }
                $current = strtotime('+' . (7 * $interval) . ' days', $current);
            } else {
                $starts[] = $current;
                $count++;
                switch ($instance->recurrence_type) {
                    case 'daily':
                        $current = strtotime("+{$interval} days", $current);
                        break;
                    case 'weekly':
                        $current = strtotime('+' . (7 * $interval) . ' days', $current);
                        break;
                    case 'monthly':
                        $current = strtotime("+{$interval} months", $current);
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

        $sql = "SELECT u.email
                  FROM {msteamsecp_coorganisers} c
                  JOIN {user} u ON u.id = c.userid AND u.deleted = 0 AND u.suspended = 0
                 WHERE c.instanceid = :instanceid AND u.email <> ''";

        return array_column(
            array_values($DB->get_records_sql($sql, ['instanceid' => $instanceid])),
            'email'
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

        if ($instance->recurrence_type === 'weekly' && !empty($instance->recurrence_days_of_week)) {
            $day_map  = [1 => 'monday', 2 => 'tuesday', 3 => 'wednesday',
                         4 => 'thursday', 5 => 'friday', 6 => 'saturday', 7 => 'sunday'];
            $day_ints = json_decode($instance->recurrence_days_of_week, true) ?? [];
            $pattern['daysOfWeek'] = array_values(array_filter(
                array_map(fn($d) => $day_map[$d] ?? null, $day_ints)
            ));
        }

        $range = ['startDate' => date('Y-m-d', $instance->starttime)];

        if (!empty($instance->recurrence_end_date)) {
            $range['type']    = 'endDate';
            // recurrence_end_date is stored as midnight in the user's local timezone.
            // Adding 86399 (end of day) matches expand_recurrence's boundary and
            // ensures the correct calendar date is sent regardless of server timezone.
            $range['endDate'] = date('Y-m-d', (int) $instance->recurrence_end_date + 86399);
        } else {
            $range['type']                = 'numbered';
            $range['numberOfOccurrences'] = (int) ($instance->recurrence_count ?? 1);
        }

        return ['pattern' => $pattern, 'range' => $range];
    }

    private function to_iso8601(int $timestamp): string {
        return gmdate('Y-m-d\TH:i:s\Z', $timestamp);
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
