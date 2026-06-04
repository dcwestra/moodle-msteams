<?php
/**
 * Handles enrolment events — pushes calendar events to newly enrolled users
 * and removes them when a user completes the course or is unenrolled.
 *
 * Called from lib.php observer hooks.
 *
 * @package    mod_msteamsecp
 * @copyright  2026 Eyecare Partners
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_msteamsecp\sync;

defined('MOODLE_INTERNAL') || die();

use mod_msteamsecp\api\graph;

class enrolment_handler {

    /** @var graph */
    private $graph;

    public function __construct() {
        $this->graph = new graph();
    }

    // -------------------------------------------------------------------------
    // On enrolment
    // -------------------------------------------------------------------------

    /**
     * Called when a user is enrolled in a course.
     * Pushes calendar events for all upcoming (or past-with-recording) meetings.
     *
     * @param int $userid
     * @param int $courseid
     */
    public function on_enrol(int $userid, int $courseid): void {
        global $DB;

        $user = $DB->get_record('user', ['id' => $userid, 'deleted' => 0, 'suspended' => 0]);
        if (!$user || empty($user->email)) {
            return;
        }

        // Get all msteamsecp instances in this course.
        $instances = $DB->get_records('msteamsecp', ['course' => $courseid]);

        foreach ($instances as $instance) {
            // Push calendar events for upcoming occurrences.
            $this->push_events_for_user($instance, $user);

        }
    }

    /**
     * Push calendar event(s) to a user's Outlook calendar.
     *
     * 'any' mode (attend once): push only the next upcoming occurrence not yet sent.
     * 'all' mode (attend all):  push every upcoming occurrence not yet sent.
     *
     * @param object $instance  msteamsecp record
     * @param object $user      Moodle user record
     */
    public function push_events_for_user(object $instance, object $user): void {
        global $DB;

        // Don't push invites to users who already have completion credit —
        // this covers both plugin-granted and manually-granted completion.
        $cmid = $DB->get_field('course_modules', 'id', [
            'instance' => $instance->id,
            'module'   => $DB->get_field('modules', 'id', ['name' => 'msteamsecp']),
        ]);
        if ($cmid) {
            $already_complete = $DB->record_exists_select(
                'course_modules_completion',
                'coursemoduleid = :cmid AND userid = :userid AND completionstate >= 1',
                ['cmid' => (int) $cmid, 'userid' => $user->id]
            );
            if ($already_complete) {
                return;
            }
        }

        $all_mode = ($instance->attendance_requirement ?? 'any') === 'all';
        $limit    = $all_mode ? '' : 'LIMIT 1';

        $sql = "SELECT occ.*
                  FROM {msteamsecp_occurrences} occ
             LEFT JOIN {msteamsecp_enrollee_events} ee
                    ON ee.occurrenceid = occ.id AND ee.userid = :userid
                 WHERE occ.instanceid = :instanceid
                   AND occ.status = 'upcoming'
                   AND occ.starttime > :now
                   AND ee.id IS NULL
              ORDER BY occ.starttime ASC
                       $limit";

        $occurrences = $DB->get_records_sql($sql, [
            'userid'     => $user->id,
            'instanceid' => $instance->id,
            'now'        => time(),
        ]);

        foreach ($occurrences as $occ) {
            $this->push_single_event($instance, $occ, $user);
        }
    }

    /**
     * Push one calendar event to a user's Outlook for a specific occurrence.
     * Public so post_event_processor can advance users to their next occurrence
     * after attendance processing.
     *
     * @param object $instance
     * @param object $occurrence
     * @param object $user
     */
    public function push_single_event(object $instance, object $occurrence, object $user): void {
        global $DB;

        if (empty($user->email)) {
            return;
        }

        try {
            // Write the calendar event directly to the learner's own Outlook calendar.
            // Do NOT use create_event (service account calendar) — even without
            // isOnlineMeeting set, the Calendar API automatically creates a new Teams
            // meeting when called in the context of the Teams-licensed service account,
            // generating a different meeting ID and passcode for the learner.
            // Writing to /users/{email}/events on the learner's calendar avoids this.
            $tz       = \core_date::get_server_timezone();
            $join_url = $instance->join_url ?? '';
            $event = $this->graph->create_user_event($user->email, [
                'subject'  => $instance->name,
                'body'     => $this->build_event_body_with_link($instance->intro ?? '', $join_url),
                'start'    => ['dateTime' => $this->to_local_datetime($occurrence->starttime), 'timeZone' => $tz],
                'end'      => ['dateTime' => $this->to_local_datetime($occurrence->endtime),   'timeZone' => $tz],
                'location' => ['displayName' => $join_url],
            ]);

            $DB->insert_record('msteamsecp_enrollee_events', (object) [
                'instanceid'     => $instance->id,
                'occurrenceid'   => $occurrence->id,
                'userid'         => $user->id,
                'graph_event_id' => $event['id'] ?? '',
                'timepushed'     => time(),
                'removed'        => 0,
            ]);

        } catch (\Throwable $e) {
            debugging('msteamsecp: failed to send Teams invitation to ' . $user->email . ': ' . $e->getMessage(), \DEBUG_DEVELOPER);
        }
    }

    // -------------------------------------------------------------------------
    // On activity completion
    // -------------------------------------------------------------------------

    /**
     * Called when a user earns activity completion credit on a msteamsecp instance.
     * Removes future Outlook/Teams calendar invites for that specific meeting.
     * This fires before course_completed, covering the gap where course completion
     * is delayed or not configured.
     *
     * @param int $userid
     * @param int $courseid
     * @param int $instanceid  msteamsecp instance ID
     */
    public function on_activity_complete(int $userid, int $courseid, int $instanceid): void {
        global $DB;

        $user = $DB->get_record('user', ['id' => $userid]);
        if (!$user) {
            return;
        }

        // Remove all future invites for this specific meeting instance.
        $sql = "SELECT ee.*
                  FROM {msteamsecp_enrollee_events} ee
                  JOIN {msteamsecp_occurrences} occ ON occ.id = ee.occurrenceid
                 WHERE ee.userid    = :userid
                   AND ee.instanceid = :instanceid
                   AND ee.removed   = 0
                   AND occ.starttime > :now";

        $events = $DB->get_records_sql($sql, [
            'userid'     => $userid,
            'instanceid' => $instanceid,
            'now'        => time(),
        ]);

        foreach ($events as $ee) {
            if (!empty($ee->graph_event_id)) {
                try {
                    $this->graph->delete_user_event($user->email, $ee->graph_event_id);
                } catch (\Throwable $e) {
                    debugging('msteamsecp: failed to remove calendar event on activity completion: '
                        . $e->getMessage(), \DEBUG_DEVELOPER);
                }
            }

            $DB->update_record('msteamsecp_enrollee_events', (object) [
                'id'          => $ee->id,
                'removed'     => 1,
                'timeremoved' => time(),
            ]);
        }
    }

    // -------------------------------------------------------------------------
    // On course completion
    // -------------------------------------------------------------------------

    /**
     * Called when a user completes a course.
     * Removes all future meeting calendar events from their calendar.
     *
     * @param int $userid
     * @param int $courseid
     */
    public function on_course_complete(int $userid, int $courseid): void {
        global $DB;

        $user = $DB->get_record('user', ['id' => $userid]);
        if (!$user) {
            return;
        }

        // Find all unpushed-removed events for upcoming occurrences in this course.
        $sql = "SELECT ee.*
                  FROM {msteamsecp_enrollee_events} ee
                  JOIN {msteamsecp_occurrences} occ ON occ.id = ee.occurrenceid
                  JOIN {msteamsecp} m ON m.id = ee.instanceid AND m.course = :courseid
                 WHERE ee.userid = :userid
                   AND ee.removed = 0
                   AND occ.starttime > :now";

        $events = $DB->get_records_sql($sql, [
            'userid'   => $userid,
            'courseid' => $courseid,
            'now'      => time(),
        ]);

        foreach ($events as $ee) {
            if (!empty($ee->graph_event_id)) {
                try {
                    // Event lives on the learner's calendar — delete it there.
                    $this->graph->delete_user_event($user->email, $ee->graph_event_id);
                } catch (\Throwable $e) {
                    debugging('msteamsecp: failed to remove calendar event: ' . $e->getMessage(), \DEBUG_DEVELOPER);
                }
            }

            $DB->update_record('msteamsecp_enrollee_events', (object) [
                'id'          => $ee->id,
                'removed'     => 1,
                'timeremoved' => time(),
            ]);
        }
    }

    // -------------------------------------------------------------------------
    // On unenrolment
    // -------------------------------------------------------------------------

    /**
     * Called when a user is unenrolled from a course.
     * Same as course completion — remove future calendar events.
     *
     * @param int $userid
     * @param int $courseid
     */
    public function on_unenrol(int $userid, int $courseid): void {
        $this->on_course_complete($userid, $courseid);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

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
     * @param string $description  Optional plain-text description (e.g. course intro)
     * @return array               Graph body object {contentType, content}
     */
    private function build_event_body_with_link(string $description, string $join_url): array {
        $safe = $description ? htmlspecialchars(strip_tags($description), ENT_QUOTES, 'UTF-8') : '';
        $url  = htmlspecialchars($join_url, ENT_QUOTES, 'UTF-8');
        $content = '<html><head><meta http-equiv="Content-Type" content="text/html; charset=utf-8"></head>'
            . '<body style="font-family:\'Segoe UI\',\'Segoe WP\',sans-serif;font-size:14px;color:#242424;">';
        if ($safe !== '') {
            $content .= '<p style="margin:0 0 16px 0;">' . $safe . '</p>';
        }
        $content .= '<p style="font-size:15px;font-weight:600;margin:0 0 12px 0;">Microsoft Teams meeting</p>';
        if ($url) {
            $content .= '<p style="margin:0 0 12px 0;">'
                . '<a href="' . $url . '" style="color:#6264a7;font-weight:600;text-decoration:none;">Join the meeting</a>'
                . '</p>'
                . '<p style="margin:0;font-size:12px;color:#666;">If the button doesn\'t work, use this link:</p>'
                . '<p style="margin:4px 0 0 0;font-size:12px;color:#666;">'
                . '<a href="' . $url . '" style="color:#666;text-decoration:none;">' . $url . '</a>'
                . '</p>';
        }
        $content .= '</body></html>';
        return ['contentType' => 'HTML', 'content' => $content];
    }
}
