<?php
/**
 * iCal download for a specific meeting occurrence.
 *
 * Generates a standard .ics file that any calendar app can import —
 * Google Calendar, Apple Calendar, Outlook desktop, etc.
 * This is a fallback for users who don't have Outlook connected to
 * the LMS or whose calendar push failed.
 *
 * @package    mod_msteamsecp
 * @copyright  2026 Eyecare Partners
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once('../../config.php');
require_once($CFG->dirroot . '/mod/msteamsecp/lib.php');

$cmid         = required_param('cmid', PARAM_INT);
$occurrenceid = optional_param('occurrenceid', 0, PARAM_INT);

$cm       = get_coursemodule_from_id('msteamsecp', $cmid, 0, false, MUST_EXIST);
$course   = $DB->get_record('course', ['id' => $cm->course], '*', MUST_EXIST);
$instance = $DB->get_record('msteamsecp', ['id' => $cm->instance], '*', MUST_EXIST);

require_login($course, true, $cm);
require_capability('mod/msteamsecp:view', context_module::instance($cm->id));

// Get the occurrence to generate the iCal for.
if ($occurrenceid) {
    $occurrence = $DB->get_record('msteamsecp_occurrences',
        ['id' => $occurrenceid, 'instanceid' => $instance->id], '*', MUST_EXIST);
} else {
    // Default to the first upcoming occurrence.
    $occurrence = $DB->get_record_select(
        'msteamsecp_occurrences',
        'instanceid = :instanceid AND starttime > :now',
        ['instanceid' => $instance->id, 'now' => time()],
        'starttime ASC'
    );
    if (!$occurrence) {
        // Fall back to the first occurrence.
        $occurrence = $DB->get_record('msteamsecp_occurrences',
            ['instanceid' => $instance->id], '*', MUST_EXIST);
    }
}

// Build iCal content.
$uid        = 'msteamsecp-' . $instance->id . '-' . $occurrence->id . '@' . parse_url($CFG->wwwroot, PHP_URL_HOST);
$dtstart    = gmdate('Ymd\THis\Z', $occurrence->starttime);
$dtend      = gmdate('Ymd\THis\Z', $occurrence->endtime);
$dtstamp    = gmdate('Ymd\THis\Z');
$summary    = addcslashes(format_string($instance->name), '\\,;');
$description = addcslashes('Join: ' . ($instance->join_url ?? ''), '\\,;');
$location   = addcslashes($instance->join_url ?? '', '\\,;');
$url        = $CFG->wwwroot . '/mod/msteamsecp/view.php?id=' . $cmid;

$ical = "BEGIN:VCALENDAR\r\n"
      . "VERSION:2.0\r\n"
      . "PRODID:-//Eyecare Partners MapleLMS//msteamsecp//EN\r\n"
      . "CALSCALE:GREGORIAN\r\n"
      . "METHOD:REQUEST\r\n"
      . "BEGIN:VEVENT\r\n"
      . "UID:{$uid}\r\n"
      . "DTSTAMP:{$dtstamp}\r\n"
      . "DTSTART:{$dtstart}\r\n"
      . "DTEND:{$dtend}\r\n"
      . "SUMMARY:{$summary}\r\n"
      . "DESCRIPTION:{$description}\r\n"
      . "LOCATION:{$location}\r\n"
      . "URL:{$url}\r\n"
      . "END:VEVENT\r\n"
      . "END:VCALENDAR\r\n";

$filename = clean_filename($instance->name) . '_' . date('Ymd', $occurrence->starttime) . '.ics';

header('Content-Type: text/calendar; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Content-Length: ' . strlen($ical));
echo $ical;
exit;
