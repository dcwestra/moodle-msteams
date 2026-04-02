<?php
/**
 * Privacy API implementation for mod_msteamsecp.
 *
 * Handles GDPR data export and deletion requests.
 *
 * Data stored per user:
 *   - msteamsecp_attendance   : join/leave times, duration, attendance %, credit granted
 *   - msteamsecp_enrollee_events : Graph calendar event IDs pushed to user's calendar
 *
 * On deletion:
 *   - Attendance records are anonymised (userid set to 0) — stats are preserved
 *   - Enrollee event records are hard deleted (they are purely operational/personal)
 *
 * @package    mod_msteamsecp
 * @copyright  2026 Eyecare Partners
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_msteamsecp\privacy;

defined('MOODLE_INTERNAL') || die();

use core_privacy\local\metadata\collection;
use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\contextlist;
use core_privacy\local\request\deletion_criteria;
use core_privacy\local\request\helper;
use core_privacy\local\request\transform;
use core_privacy\local\request\userlist;
use core_privacy\local\request\writer;

class provider implements
    \core_privacy\local\metadata\provider,
    \core_privacy\local\request\plugin\provider,
    \core_privacy\local\request\core_userlist_provider {

    // -------------------------------------------------------------------------
    // Metadata
    // -------------------------------------------------------------------------

    /**
     * Describe the data this plugin stores about users.
     */
    public static function get_metadata(collection $collection): collection {

        $collection->add_database_table('msteamsecp_attendance', [
            'userid'           => 'privacy:metadata:attendance:userid',
            'join_time'        => 'privacy:metadata:attendance:join_time',
            'leave_time'       => 'privacy:metadata:attendance:leave_time',
            'duration_seconds' => 'privacy:metadata:attendance:duration_seconds',
            'attendance_pct'   => 'privacy:metadata:attendance:attendance_pct',
            'credit_granted'   => 'privacy:metadata:attendance:credit_granted',
            'credit_method'    => 'privacy:metadata:attendance:credit_method',
        ], 'privacy:metadata:attendance');

        $collection->add_database_table('msteamsecp_enrollee_events', [
            'userid'         => 'privacy:metadata:enrollee_events:userid',
            'graph_event_id' => 'privacy:metadata:enrollee_events:graph_event_id',
            'timepushed'     => 'privacy:metadata:enrollee_events:timepushed',
        ], 'privacy:metadata:enrollee_events');

        $collection->add_external_location_link('microsoft_graph', [
            'email'    => 'privacy:metadata:graph:email',
            'calendar' => 'privacy:metadata:graph:calendar',
        ], 'privacy:metadata:graph');

        return $collection;
    }

    // -------------------------------------------------------------------------
    // Context discovery
    // -------------------------------------------------------------------------

    /**
     * Get contexts containing user data for the given user.
     */
    public static function get_contexts_for_userid(int $userid): contextlist {
        $contextlist = new contextlist();

        // Attendance records.
        $sql = "SELECT ctx.id
                  FROM {context} ctx
                  JOIN {course_modules} cm ON cm.id = ctx.instanceid AND ctx.contextlevel = :ctxlevel
                  JOIN {msteamsecp} m ON m.id = cm.instance
                  JOIN {msteamsecp_attendance} a ON a.instanceid = m.id AND a.userid = :userid";

        $contextlist->add_from_sql($sql, [
            'ctxlevel' => CONTEXT_MODULE,
            'userid'   => $userid,
        ]);

        // Enrollee calendar events.
        $sql = "SELECT ctx.id
                  FROM {context} ctx
                  JOIN {course_modules} cm ON cm.id = ctx.instanceid AND ctx.contextlevel = :ctxlevel
                  JOIN {msteamsecp} m ON m.id = cm.instance
                  JOIN {msteamsecp_enrollee_events} ee ON ee.instanceid = m.id AND ee.userid = :userid";

        $contextlist->add_from_sql($sql, [
            'ctxlevel' => CONTEXT_MODULE,
            'userid'   => $userid,
        ]);

        return $contextlist;
    }

    /**
     * Get users who have data in the given context.
     */
    public static function get_users_in_context(userlist $userlist): void {
        $context = $userlist->get_context();

        if (!$context instanceof \context_module) {
            return;
        }

        $params = ['ctxid' => $context->id, 'ctxlevel' => CONTEXT_MODULE];

        $sql = "SELECT a.userid
                  FROM {msteamsecp_attendance} a
                  JOIN {msteamsecp} m ON m.id = a.instanceid
                  JOIN {course_modules} cm ON cm.instance = m.id
                  JOIN {context} ctx ON ctx.instanceid = cm.id AND ctx.contextlevel = :ctxlevel
                 WHERE ctx.id = :ctxid";

        $userlist->add_from_sql('userid', $sql, $params);

        $sql = "SELECT ee.userid
                  FROM {msteamsecp_enrollee_events} ee
                  JOIN {msteamsecp} m ON m.id = ee.instanceid
                  JOIN {course_modules} cm ON cm.instance = m.id
                  JOIN {context} ctx ON ctx.instanceid = cm.id AND ctx.contextlevel = :ctxlevel
                 WHERE ctx.id = :ctxid";

        $userlist->add_from_sql('userid', $sql, $params);
    }

    // -------------------------------------------------------------------------
    // Data export
    // -------------------------------------------------------------------------

    /**
     * Export personal data for the given approved contexts.
     */
    public static function export_user_data(approved_contextlist $contextlist): void {
        global $DB;

        if (empty($contextlist->count())) {
            return;
        }

        $userid = $contextlist->get_user()->id;

        foreach ($contextlist->get_contexts() as $context) {
            if (!$context instanceof \context_module) {
                continue;
            }

            $cm = get_coursemodule_from_id('msteamsecp', $context->instanceid);
            if (!$cm) {
                continue;
            }

            // Export attendance records.
            $attendance = $DB->get_records_sql(
                "SELECT a.*, occ.starttime AS occurrence_start
                   FROM {msteamsecp_attendance} a
                   JOIN {msteamsecp_occurrences} occ ON occ.id = a.occurrenceid
                  WHERE a.instanceid = :instanceid AND a.userid = :userid
               ORDER BY occ.starttime ASC",
                ['instanceid' => $cm->instance, 'userid' => $userid]
            );

            if (!empty($attendance)) {
                $export_data = array_map(function($rec) {
                    return [
                        'occurrence_date'  => transform::datetime($rec->occurrence_start),
                        'join_time'        => $rec->join_time ? transform::datetime($rec->join_time) : null,
                        'leave_time'       => $rec->leave_time ? transform::datetime($rec->leave_time) : null,
                        'duration_seconds' => $rec->duration_seconds,
                        'attendance_pct'   => $rec->attendance_pct,
                        'credit_granted'   => transform::yesno($rec->credit_granted),
                        'credit_method'    => $rec->credit_method,
                    ];
                }, $attendance);

                writer::with_context($context)->export_data(
                    [get_string('privacy:export:attendance', 'mod_msteamsecp')],
                    (object) ['records' => array_values($export_data)]
                );
            }

            // Export enrollee calendar events (Graph event IDs — personal operational data).
            $events = $DB->get_records_sql(
                "SELECT ee.*, occ.starttime AS occurrence_start
                   FROM {msteamsecp_enrollee_events} ee
                   JOIN {msteamsecp_occurrences} occ ON occ.id = ee.occurrenceid
                  WHERE ee.instanceid = :instanceid AND ee.userid = :userid
               ORDER BY occ.starttime ASC",
                ['instanceid' => $cm->instance, 'userid' => $userid]
            );

            if (!empty($events)) {
                $export_events = array_map(function($rec) {
                    return [
                        'occurrence_date' => transform::datetime($rec->occurrence_start),
                        'calendar_pushed' => transform::datetime($rec->timepushed),
                        'removed'         => transform::yesno($rec->removed),
                    ];
                }, $events);

                writer::with_context($context)->export_data(
                    [get_string('privacy:export:calendar_events', 'mod_msteamsecp')],
                    (object) ['records' => array_values($export_events)]
                );
            }
        }
    }

    // -------------------------------------------------------------------------
    // Data deletion
    // -------------------------------------------------------------------------

    /**
     * Delete all data for all users in the given context.
     */
    public static function delete_data_for_all_users_in_context(\context $context): void {
        global $DB;

        if (!$context instanceof \context_module) {
            return;
        }

        $cm = get_coursemodule_from_id('msteamsecp', $context->instanceid);
        if (!$cm) {
            return;
        }

        // Anonymise attendance — preserve stats, remove personal identifier.
        $DB->execute(
            "UPDATE {msteamsecp_attendance} SET userid = 0 WHERE instanceid = :instanceid",
            ['instanceid' => $cm->instance]
        );

        // Hard delete enrollee calendar event records.
        $DB->delete_records('msteamsecp_enrollee_events', ['instanceid' => $cm->instance]);
    }

    /**
     * Delete data for an approved list of users in a given context.
     */
    public static function delete_data_for_users(approved_userlist $userlist): void {
        global $DB;

        $context = $userlist->get_context();
        if (!$context instanceof \context_module) {
            return;
        }

        $cm = get_coursemodule_from_id('msteamsecp', $context->instanceid);
        if (!$cm) {
            return;
        }

        $userids = $userlist->get_userids();
        if (empty($userids)) {
            return;
        }

        list($in_sql, $params) = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED);
        $params['instanceid'] = $cm->instance;

        // Anonymise attendance records.
        $DB->execute(
            "UPDATE {msteamsecp_attendance}
                SET userid = 0
              WHERE instanceid = :instanceid AND userid $in_sql",
            $params
        );

        // Hard delete enrollee events.
        $DB->execute(
            "DELETE FROM {msteamsecp_enrollee_events}
              WHERE instanceid = :instanceid AND userid $in_sql",
            $params
        );
    }

    /**
     * Delete all data for a single user across all their contexts.
     */
    public static function delete_data_for_user(approved_contextlist $contextlist): void {
        global $DB;

        $userid = $contextlist->get_user()->id;

        foreach ($contextlist->get_contexts() as $context) {
            if (!$context instanceof \context_module) {
                continue;
            }

            $cm = get_coursemodule_from_id('msteamsecp', $context->instanceid);
            if (!$cm) {
                continue;
            }

            // Anonymise attendance.
            $DB->execute(
                "UPDATE {msteamsecp_attendance}
                    SET userid = 0
                  WHERE instanceid = :instanceid AND userid = :userid",
                ['instanceid' => $cm->instance, 'userid' => $userid]
            );

            // Hard delete enrollee events.
            $DB->delete_records('msteamsecp_enrollee_events', [
                'instanceid' => $cm->instance,
                'userid'     => $userid,
            ]);
        }
    }
}
