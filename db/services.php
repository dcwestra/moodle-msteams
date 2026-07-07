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
    'mod_msteamsecp_mark_recording_complete' => [
        'classname'     => 'mod_msteamsecp\external\mark_recording_complete',
        'methodname'    => 'execute',
        'description'   => 'Mark a Teams meeting recording as complete for the current user when they reach the configured watch threshold.',
        'type'          => 'write',
        'ajax'          => true,
        'loginrequired' => true,
        'capabilities'  => 'mod/msteamsecp:view',
    ],
];
