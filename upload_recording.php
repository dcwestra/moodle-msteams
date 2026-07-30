<?php
/**
 * Manual recording upload for a meeting occurrence.
 *
 * Linked from view.php when recording_mode = 'manual' and the occurrence has
 * ended. Stores the uploaded file in the mod_msteamsecp 'recording' filearea
 * (itemid = occurrence ID) and marks the occurrence recording_ready so the
 * inline player appears for learners.
 *
 * @package    mod_msteamsecp
 * @copyright  2026 Eyecare Partners
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once('../../config.php');
require_once($CFG->libdir . '/formslib.php');

$occurrenceid = required_param('occurrenceid', PARAM_INT);
$cmid         = required_param('cmid', PARAM_INT);

$cm       = get_coursemodule_from_id('msteamsecp', $cmid, 0, false, MUST_EXIST);
$course   = $DB->get_record('course', ['id' => $cm->course], '*', MUST_EXIST);
$instance = $DB->get_record('msteamsecp', ['id' => $cm->instance], '*', MUST_EXIST);
$occ      = $DB->get_record('msteamsecp_occurrences',
    ['id' => $occurrenceid, 'instanceid' => $cm->instance], '*', MUST_EXIST);

require_login($course, true, $cm);
$context = context_module::instance($cm->id);
require_capability('mod/msteamsecp:uploadrecording', $context);

$PAGE->set_url('/mod/msteamsecp/upload_recording.php',
    ['occurrenceid' => $occurrenceid, 'cmid' => $cmid]);
$PAGE->set_title(format_string($instance->name));
$PAGE->set_heading($course->fullname);

class msteamsecp_upload_recording_form extends moodleform {
    public function definition(): void {
        $mform = $this->_form;

        $mform->addElement('filepicker', 'recordingfile',
            get_string('recording_file', 'mod_msteamsecp'), null, [
                'maxbytes'       => 0,
                'accepted_types' => ['.mp4', '.m4v', '.webm', '.mov'],
            ]);
        $mform->addRule('recordingfile', null, 'required', null, 'client');

        $mform->addElement('hidden', 'occurrenceid', $this->_customdata['occurrenceid']);
        $mform->setType('occurrenceid', PARAM_INT);
        $mform->addElement('hidden', 'cmid', $this->_customdata['cmid']);
        $mform->setType('cmid', PARAM_INT);

        $this->add_action_buttons(true, get_string('upload_recording', 'mod_msteamsecp'));
    }
}

$form = new msteamsecp_upload_recording_form(null,
    ['occurrenceid' => $occurrenceid, 'cmid' => $cmid]);

$return_url = new moodle_url('/mod/msteamsecp/view.php', ['id' => $cm->id]);

if ($form->is_cancelled()) {
    redirect($return_url);

} else if ($data = $form->get_data()) {
    $fs = get_file_storage();

    // Replace any previous recording for this occurrence.
    $fs->delete_area_files($context->id, 'mod_msteamsecp', 'recording', $occ->id);
    file_save_draft_area_files($data->recordingfile, $context->id,
        'mod_msteamsecp', 'recording', $occ->id, ['subdirs' => 0, 'maxfiles' => 1]);

    // Verify a file actually landed before flagging the recording as ready.
    $files = $fs->get_area_files($context->id, 'mod_msteamsecp', 'recording',
        $occ->id, 'filename', false);
    if (empty($files)) {
        redirect($return_url, get_string('recording_upload_failed', 'mod_msteamsecp'),
            null, \core\output\notification::NOTIFY_ERROR);
    }

    // Clear recording_abandoned too: a manual upload supersedes any earlier
    // decision to stop looking for an automatic one.
    $DB->update_record('msteamsecp_occurrences', (object) [
        'id'                  => $occ->id,
        'recording_cmid'      => $occ->id,
        'recording_ready'     => 1,
        'recording_abandoned' => 0,
    ]);

    redirect($return_url, get_string('recording_upload_success', 'mod_msteamsecp'),
        null, \core\output\notification::NOTIFY_SUCCESS);
}

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('upload_recording_for', 'mod_msteamsecp',
    userdate($occ->starttime, get_string('strftimedaydatetime', 'langconfig'))));
$form->display();
echo $OUTPUT->footer();
