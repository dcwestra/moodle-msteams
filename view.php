<?php
/**
 * Learner-facing view of a Teams meeting activity.
 *
 * Shows:
 *  - Meeting title, description, start/end time
 *  - Join button (live) or status badge (upcoming/ended)
 *  - For recurring: list of all occurrences with individual join links
 *  - Recordings section with links to recorded activities
 *  - Attendance summary for the current user
 *
 * @package    mod_msteamsecp
 * @copyright  2026 Eyecare Partners
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once('../../config.php');
require_once($CFG->dirroot . '/mod/msteamsecp/lib.php');

$id = required_param('id', PARAM_INT); // Course module ID.

$cm      = get_coursemodule_from_id('msteamsecp', $id, 0, false, MUST_EXIST);
$course  = $DB->get_record('course', ['id' => $cm->course], '*', MUST_EXIST);
$instance = $DB->get_record('msteamsecp', ['id' => $cm->instance], '*', MUST_EXIST);

require_login($course, true, $cm);
$context = context_module::instance($cm->id);
require_capability('mod/msteamsecp:view', $context);

msteamsecp_view($instance, $course, $cm, $context);

$PAGE->set_url('/mod/msteamsecp/view.php', ['id' => $id]);
$PAGE->set_title(format_string($instance->name));
$PAGE->set_heading($course->fullname);

echo $OUTPUT->header();
echo $OUTPUT->heading(format_string($instance->name));

// Description.
if (!empty($instance->intro)) {
    echo $OUTPUT->box(format_module_intro('msteamsecp', $instance, $cm->id), 'generalbox mod_introbox');
}

$now         = time();
$occurrences = $DB->get_records('msteamsecp_occurrences',
    ['instanceid' => $instance->id], 'starttime ASC');

if (empty($occurrences)) {
    echo $OUTPUT->notification(get_string('no_occurrences', 'mod_msteamsecp'), 'info');
    echo $OUTPUT->footer();
    exit;
}

// ── Single meeting view ──────────────────────────────────────────────────────
if (!$instance->is_recurring) {
    $occ = reset($occurrences);
    echo msteamsecp_render_occurrence($occ, $instance, $context, $now, $USER->id, $DB, $cm);
} else {
    // ── Recurring meeting view ────────────────────────────────────────────
    echo html_writer::tag('h3', get_string('occurrences', 'mod_msteamsecp'));
    $table = new html_table();
    $table->head = [
        get_string('occurrence_date', 'mod_msteamsecp'),
        get_string('status', 'mod_msteamsecp'),
        get_string('join', 'mod_msteamsecp'),
        get_string('recording', 'mod_msteamsecp'),
        get_string('attendance', 'mod_msteamsecp'),
    ];
    $table->data = [];

    foreach ($occurrences as $occ) {
        $table->data[] = msteamsecp_occurrence_row($occ, $instance, $context, $now, $USER->id, $DB, $cm);
    }

    echo html_writer::table($table);
}

// ── Attendance summary ───────────────────────────────────────────────────────
$attendance_records = $DB->get_records('msteamsecp_attendance',
    ['instanceid' => $instance->id, 'userid' => $USER->id]);

if (!empty($attendance_records)) {
    echo html_writer::tag('h3', get_string('your_attendance', 'mod_msteamsecp'));
    $credited = array_filter($attendance_records, fn($r) => $r->credit_granted);
    if (!empty($credited)) {
        echo $OUTPUT->notification(get_string('attendance_credit_granted', 'mod_msteamsecp'), 'success');
    } else {
        $total_pct = max(array_column($attendance_records, 'attendance_pct'));
        echo $OUTPUT->notification(
            get_string('attendance_no_credit', 'mod_msteamsecp', round($total_pct, 1)),
            'info'
        );
    }
}

echo $OUTPUT->footer();

// ── Render helpers ────────────────────────────────────────────────────────────

function msteamsecp_render_occurrence(
    object $occ, object $instance, context_module $context,
    int $now, int $userid, \moodle_database $DB, object $cm
): string {
    global $OUTPUT;
    $out = '';

    // Time display.
    $out .= html_writer::tag('p',
        userdate($occ->starttime) . ' – ' . userdate($occ->endtime)
    );

    // Status + join button.
    if ($occ->status === 'upcoming' && $occ->starttime > $now) {
        $out .= html_writer::tag('p',
            get_string('starts_in', 'mod_msteamsecp', format_time($occ->starttime - $now))
        );
        if (!empty($instance->join_url)) {
            $out .= html_writer::link(
                $instance->join_url,
                get_string('join_meeting', 'mod_msteamsecp'),
                ['class' => 'btn btn-primary', 'target' => '_blank']
            );
        }
        $out .= ' ' . html_writer::link(
            new moodle_url('/mod/msteamsecp/ical.php', [
                'cmid'         => $cm->id,
                'occurrenceid' => $occ->id,
            ]),
            get_string('add_to_calendar', 'mod_msteamsecp'),
            ['class' => 'btn btn-outline-secondary btn-sm']
        );
    } elseif ($occ->endtime > $now && $occ->starttime <= $now) {
        // Currently live.
        $out .= html_writer::span(get_string('live_now', 'mod_msteamsecp'), 'badge badge-danger');
        if (!empty($instance->join_url)) {
            $out .= ' ' . $OUTPUT->single_button(
                new moodle_url($instance->join_url),
                get_string('join_now', 'mod_msteamsecp'),
                'get', ['class' => 'btn-danger']
            );
        }
    } else {
        $out .= html_writer::span(get_string('meeting_ended', 'mod_msteamsecp'), 'badge badge-secondary');
    }

    // Recording.
    if ($occ->recording_ready && !empty($occ->recording_cmid)) {
        $recording_cm = get_coursemodule_from_id('resource', $occ->recording_cmid);
        if ($recording_cm) {
            $out .= html_writer::tag('p',
                html_writer::link(
                    new moodle_url('/mod/resource/view.php', ['id' => $occ->recording_cmid]),
                    get_string('watch_recording', 'mod_msteamsecp')
                )
            );
        }
    } elseif ($occ->status === 'ended' && $instance->recording_mode === 'manual') {
        // Manual upload — show upload form if instructor.
        if (has_capability('mod/msteamsecp:uploadrecording', $context)) {
            $out .= html_writer::link(
                new moodle_url('/mod/msteamsecp/upload_recording.php',
                    ['occurrenceid' => $occ->id, 'cmid' => $context->instanceid]),
                get_string('upload_recording', 'mod_msteamsecp'),
                ['class' => 'btn btn-secondary btn-sm']
            );
        } else {
            $out .= html_writer::tag('p', get_string('recording_pending', 'mod_msteamsecp'));
        }
    }

    return $out;
}

function msteamsecp_occurrence_row(
    object $occ, object $instance, context_module $context,
    int $now, int $userid, \moodle_database $DB, object $cm
): array {
    global $OUTPUT;

    $date   = userdate($occ->starttime, get_string('strftimedaydatetime', 'langconfig'));
    $status = html_writer::span(
        get_string('status_' . $occ->status, 'mod_msteamsecp'),
        'badge badge-' . msteamsecp_status_badge($occ->status)
    );

    $join = '';
    if ($occ->status === 'upcoming' && !empty($instance->join_url)) {
        $join = html_writer::link(
            new moodle_url($instance->join_url),
            get_string('join', 'mod_msteamsecp'),
            ['class' => 'btn btn-sm btn-primary', 'target' => '_blank']
        );
        $join .= ' ' . html_writer::link(
            new moodle_url('/mod/msteamsecp/ical.php', [
                'cmid'         => $cm->id,
                'occurrenceid' => $occ->id,
            ]),
            '📅',
            ['class' => 'btn btn-sm btn-outline-secondary', 'title' => get_string('add_to_calendar', 'mod_msteamsecp')]
        );
    }

    $recording = '';
    if ($occ->recording_ready && !empty($occ->recording_cmid)) {
        $recording = html_writer::link(
            new moodle_url('/mod/resource/view.php', ['id' => $occ->recording_cmid]),
            get_string('watch', 'mod_msteamsecp'),
            ['class' => 'btn btn-sm btn-outline-secondary']
        );
    } elseif ($occ->status === 'ended' && $instance->recording_mode === 'manual'
              && has_capability('mod/msteamsecp:uploadrecording', $context)) {
        $recording = html_writer::link(
            new moodle_url('/mod/msteamsecp/upload_recording.php',
                ['occurrenceid' => $occ->id, 'cmid' => $context->instanceid]),
            get_string('upload', 'mod_msteamsecp'),
            ['class' => 'btn btn-sm btn-outline-primary']
        );
    }

    $attendance_rec = $DB->get_record('msteamsecp_attendance',
        ['occurrenceid' => $occ->id, 'userid' => $userid]);

    $attendance = '';
    if ($attendance_rec) {
        $attendance = round($attendance_rec->attendance_pct, 1) . '%';
        if ($attendance_rec->credit_granted) {
            $attendance .= ' ' . html_writer::span('✓', 'text-success');
        }
    }

    return [$date, $status, $join, $recording, $attendance];
}

function msteamsecp_status_badge(string $status): string {
    return match($status) {
        'upcoming'  => 'primary',
        'live'      => 'danger',
        'ended'     => 'secondary',
        'cancelled' => 'warning',
        default     => 'light',
    };
}
