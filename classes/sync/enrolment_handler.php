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
     * Check whether a user holds a co-organiser role in the given course.
     *
     * @param int $userid
     * @param int $courseid
     * @return bool
     */
    private function user_has_coorganiser_role(int $userid, int $courseid): bool {
        global $DB;

        $role_shortnames = array_filter(array_map('trim',
            explode(',', get_config('mod_msteamsecp', 'coorganiser_roles') ?? 'editingteacher,teacher,manager')
        ));

        if (empty($role_shortnames)) {
            return false;
        }

        $context = \context_course::instance($courseid);
        list($in_sql, $params) = $DB->get_in_or_equal($role_shortnames, SQL_PARAMS_NAMED);
        $params['userid']    = $userid;
        $params['contextid'] = $context->id;

        return $DB->record_exists_sql(
            "SELECT ra.id
               FROM {role_assignments} ra
               JOIN {role} r ON r.id = ra.roleid AND r.shortname $in_sql
              WHERE ra.userid = :userid AND ra.contextid = :contextid",
            $params
        );
    }

    /**
     * Push the next single upcoming occurrence to a user's calendar.
     *
     * On enrol we push only the immediately next occurrence rather than the
     * entire series. After each occurrence ends, the post-event processor calls
     * this again for users who still need to attend, advancing them one
     * occurrence at a time. Users who complete the course have the event
     * removed by on_course_complete() as usual — no change to that path.
     *
     * @param object $instance  msteamsecp record
     * @param object $user      Moodle user record
     */
    public function push_events_for_user(object $instance, object $user): void {
        global $DB;

        // Get only the next occurrence not yet pushed to this user.
        $sql = "SELECT occ.*
                  FROM {msteamsecp_occurrences} occ
             LEFT JOIN {msteamsecp_enrollee_events} ee
                    ON ee.occurrenceid = occ.id AND ee.userid = :userid
                 WHERE occ.instanceid = :instanceid
                   AND occ.status = 'upcoming'
                   AND occ.starttime > :now
                   AND ee.id IS NULL
              ORDER BY occ.starttime ASC
                 LIMIT 1";

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

        try {
            $event = $this->graph->push_event_to_user($user->email, [
                'subject'               => $instance->name,
                'body'                  => $this->build_event_body($instance->intro ?? ''),
                'start'                 => ['dateTime' => $this->to_iso8601($occurrence->starttime), 'timeZone' => 'UTC'],
                'end'                   => ['dateTime' => $this->to_iso8601($occurrence->endtime),   'timeZone' => 'UTC'],
                'isOnlineMeeting'       => true,
                'onlineMeetingProvider' => 'teamsForBusiness',
                'onlineMeetingUrl'      => $instance->join_url ?? '',
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
            debugging('msteamsecp: failed to push calendar event to user ' . $user->email . ': ' . $e->getMessage(), DEBUG_DEVELOPER);
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
                    $this->graph->remove_event_from_user($user->email, $ee->graph_event_id);
                } catch (\Throwable $e) {
                    debugging('msteamsecp: failed to remove calendar event: ' . $e->getMessage(), DEBUG_DEVELOPER);
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

    /**
     * Build an HTML event body matching the native Teams meeting invite style.
     * Segoe UI / sans-serif, 14px, #242424 — matching Microsoft's own formatting.
     * Graph appends the Teams join blob after this content automatically.
     *
     * @param string $description  Optional plain-text description (e.g. course intro)
     * @return array               Graph body object {contentType, content}
     */
    private function build_event_body(string $description = ''): array {
        $safe = $description ? htmlspecialchars(strip_tags($description), ENT_QUOTES, 'UTF-8') : '';
        $content = '<html><head><meta http-equiv="Content-Type" content="text/html; charset=utf-8"></head>'
            . '<body style="font-family:\'Segoe UI\',\'Segoe WP\',sans-serif; font-size:14px; color:#242424;">';
        if ($safe !== '') {
            $content .= '<p style="margin:0 0 16px 0;">' . $safe . '</p>';
        }
        $content .= '</body></html>';
        return ['contentType' => 'HTML', 'content' => $content];
    }
}
