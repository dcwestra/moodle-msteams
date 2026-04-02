<?php
/**
 * List all msteamsecp activities in the current course.
 *
 * @package    mod_msteamsecp
 * @copyright  2026 Eyecare Partners
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once('../../config.php');

$id = required_param('id', PARAM_INT); // Course ID.

$course = $DB->get_record('course', ['id' => $id], '*', MUST_EXIST);
require_course_login($course);

$PAGE->set_url('/mod/msteamsecp/index.php', ['id' => $id]);
$PAGE->set_title($course->shortname . ': ' . get_string('modulenameplural', 'mod_msteamsecp'));
$PAGE->set_heading($course->fullname);
$PAGE->navbar->add(get_string('modulenameplural', 'mod_msteamsecp'));

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('modulenameplural', 'mod_msteamsecp'));

$meetings = get_all_instances_in_course('msteamsecp', $course);

if (empty($meetings)) {
    notice(get_string('nomeetings', 'mod_msteamsecp'), new moodle_url('/course/view.php', ['id' => $id]));
}

$table       = new html_table();
$table->head = [
    get_string('name'),
    get_string('starttime',  'mod_msteamsecp'),
    get_string('status',     'mod_msteamsecp'),
];
$table->data = [];

$now = time();
foreach ($meetings as $m) {
    $link = html_writer::link(
        new moodle_url('/mod/msteamsecp/view.php', ['id' => $m->coursemodule]),
        format_string($m->name, true)
    );

    $status = $m->starttime > $now
        ? get_string('status_upcoming', 'mod_msteamsecp')
        : ($m->endtime > $now ? get_string('status_live', 'mod_msteamsecp') : get_string('status_ended', 'mod_msteamsecp'));

    $table->data[] = [$link, userdate($m->starttime), $status];
}

echo html_writer::table($table);
echo $OUTPUT->footer();
