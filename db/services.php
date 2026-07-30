<?php
/**
 * Web service function definitions for mod_msteamsecp.
 *
 * @package    mod_msteamsecp
 * @copyright  2026 Eyecare Partners
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$functions = [
    'mod_msteamsecp_save_watch_progress' => [
        'classname'     => 'mod_msteamsecp\external\save_watch_progress',
        'methodname'    => 'execute',
        'description'   => 'Persist a learner\'s unique recording watch time (merged server-side across sessions) and grant completion credit when the threshold is reached.',
        'type'          => 'write',
        'ajax'          => true,
        'loginrequired' => true,
        'capabilities'  => 'mod/msteamsecp:view',
    ],
    // mark_recording_complete is deliberately NOT registered as a web service.
    // It trusts the percentage it is handed, so exposing it over AJAX let any
    // learner with mod/msteamsecp:view POST percent_watched=100 and grant
    // themselves completion, bypassing the watch-time model entirely. Since
    // v1.7.0 nothing client-side calls it — save_watch_progress invokes the
    // class directly in PHP after computing the percentage server-side from
    // stored watch ranges.
];
