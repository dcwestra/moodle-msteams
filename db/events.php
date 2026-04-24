<?php
/**
 * Event observer definitions for mod_msteamsecp.
 *
 * Wires Moodle enrolment and completion events to the plugin's
 * enrolment_handler so calendar events are pushed/removed automatically.
 *
 * @package    mod_msteamsecp
 * @copyright  2026 Eyecare Partners
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
defined('MOODLE_INTERNAL') || die();

$observers = [

    // User enrolled in a course — push calendar events for upcoming meetings.
    [
        'eventname'  => '\core\event\user_enrolment_created',
        'callback'   => 'msteamsecp_user_enrolment_created',
        'includefile' => '/mod/msteamsecp/lib.php',
        'internal'   => false,
    ],

    // User unenrolled — remove future calendar events.
    [
        'eventname'  => '\core\event\user_enrolment_deleted',
        'callback'   => 'msteamsecp_user_enrolment_deleted',
        'includefile' => '/mod/msteamsecp/lib.php',
        'internal'   => false,
    ],

    // Course completed — remove future calendar events.
    [
        'eventname'  => '\core\event\course_completed',
        'callback'   => 'msteamsecp_course_completed',
        'includefile' => '/mod/msteamsecp/lib.php',
        'internal'   => false,
    ],

    // Activity completion updated — remove future Outlook invites when a user
    // earns activity credit, even if the course itself hasn't completed yet.
    // This covers the common case where the meeting is the only graded item
    // and course completion fires separately or not at all.
    [
        'eventname'  => '\core\event\course_module_completion_updated',
        'callback'   => 'msteamsecp_module_completion_updated',
        'includefile' => '/mod/msteamsecp/lib.php',
        'internal'   => false,
    ],
];
