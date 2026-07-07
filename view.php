<?php
/**
 * Learner-facing view of a Teams meeting activity.
 *
 * Behaviour by recording_behavior setting:
 *
 *  replace mode (recurring):
 *    - Shows only the most recent ended occurrence with a recording
 *    - Shows only the next upcoming occurrence
 *
 *  append mode (recurring):
 *    - Shows all occurrences in a table
 *    - Ended occurrences with recordings show an inline expandable player
 *
 *  Single meeting: always shows the single occurrence with inline player if ready.
 *
 *  Join button: only available 15 minutes before start through end of meeting.
 *
 * @package    mod_msteamsecp
 * @copyright  2026 Eyecare Partners
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once('../../config.php');
require_once($CFG->dirroot . '/mod/msteamsecp/lib.php');

$id = required_param('id', PARAM_INT);

$cm       = get_coursemodule_from_id('msteamsecp', $id, 0, false, MUST_EXIST);
$course   = $DB->get_record('course', ['id' => $cm->course], '*', MUST_EXIST);
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

if (!empty($instance->intro)) {
    echo $OUTPUT->box(format_module_intro('msteamsecp', $instance, $cm->id), 'generalbox mod_introbox');
}

$now         = time();
$join_window = 15 * 60; // 15 minutes before start.
$occurrences = $DB->get_records('msteamsecp_occurrences',
    ['instanceid' => $instance->id], 'starttime ASC');

if (empty($occurrences)) {
    echo $OUTPUT->notification(get_string('no_occurrences', 'mod_msteamsecp'), 'info');
    $PAGE->requires->strings_for_js(['recording_completion_granted', 'recording_progress'], 'mod_msteamsecp');
    echo $OUTPUT->footer();
    exit;
}

// ── Single meeting ────────────────────────────────────────────────────────────
if (!$instance->is_recurring) {
    $occ = reset($occurrences);
    echo msteamsecp_render_occurrence($occ, $instance, $context, $now, $join_window, $USER->id, $DB, $cm);

// ── Recurring — replace mode ──────────────────────────────────────────────────
} elseif ($instance->recording_behavior === 'replace') {

    $last_recording = null;
    foreach (array_reverse(array_values($occurrences)) as $occ) {
        if ($occ->recording_ready && !empty($occ->recording_cmid)) {
            $last_recording = $occ;
            break;
        }
    }

    $next_occurrence = null;
    foreach ($occurrences as $occ) {
        if ($occ->endtime > $now) {
            $next_occurrence = $occ;
            break;
        }
    }

    // ── Next session first ────────────────────────────────────────────────
    if ($next_occurrence) {
        echo html_writer::tag('h3', get_string('next_session', 'mod_msteamsecp'));
        // Render occurrence but suppress its inline recording player — we show
        // the recording separately below as a collapsible.
        echo msteamsecp_render_occurrence($next_occurrence, $instance, $context, $now, $join_window, $USER->id, $DB, $cm, true);
    }

    // ── Recording second — collapsible with date title ────────────────────
    if ($last_recording) {
        $rec_date  = userdate($last_recording->starttime, '%m/%d/%Y');
        $rec_title = get_string('session_recording', 'mod_msteamsecp') . ' — ' . $rec_date;
        $toggle_id = 'msteamsecp-rec-collapse-' . $last_recording->id;

        echo html_writer::tag('h3',
            html_writer::tag('button', $rec_title . ' ▾', [
                'class'         => 'btn btn-link p-0 font-weight-bold',
                'type'          => 'button',
                'data-toggle'   => 'collapse',
                'data-target'   => '#' . $toggle_id,
                'aria-expanded' => 'false',
                'style'         => 'font-size:1.1rem;',
            ]),
            ['style' => 'margin-top:1.5rem;']
        );
        echo html_writer::div(
            msteamsecp_render_recording_player(
                (int) $last_recording->recording_cmid,
                $last_recording->id,
                $instance, $context, $cm, $PAGE
            ),
            'collapse',
            ['id' => $toggle_id]
        );
    }

    if (!$last_recording && !$next_occurrence) {
        echo $OUTPUT->notification(get_string('all_sessions_ended', 'mod_msteamsecp'), 'info');
    }

// ── Recurring — append mode ───────────────────────────────────────────────────
} else {

    echo html_writer::tag('h3', get_string('occurrences', 'mod_msteamsecp'));

    $table       = new html_table();
    $table->head = [
        get_string('occurrence_date', 'mod_msteamsecp'),
        get_string('status',          'mod_msteamsecp'),
        get_string('join',            'mod_msteamsecp'),
        get_string('recording',       'mod_msteamsecp'),
        get_string('attendance',      'mod_msteamsecp'),
    ];
    $table->data = [];

    foreach ($occurrences as $occ) {
        $table->data[] = msteamsecp_occurrence_row(
            $occ, $instance, $context, $now, $join_window, $USER->id, $DB, $cm
        );
    }

    echo html_writer::table($table);
}

// ── Attendance summary ────────────────────────────────────────────────────────
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

$PAGE->requires->strings_for_js(['recording_completion_granted', 'recording_progress'], 'mod_msteamsecp');
echo $OUTPUT->footer();

// ── Render helpers ─────────────────────────────────────────────────────────────

function msteamsecp_render_occurrence(
    object $occ, object $instance, context_module $context,
    int $now, int $join_window, int $userid, \moodle_database $DB, object $cm,
    bool $suppress_recording = false
): string {
    global $OUTPUT, $PAGE;
    $out        = '';
    $join_opens = $occ->starttime - $join_window;

    $out .= html_writer::tag('p', userdate($occ->starttime) . ' – ' . userdate($occ->endtime));

    if ($occ->endtime > $now && $occ->starttime <= $now) {
        // Live.
        $out .= html_writer::span(get_string('live_now', 'mod_msteamsecp'), 'badge badge-danger');
        if (!empty($instance->join_url)) {
            $out .= ' ' . html_writer::link(
                $instance->join_url,
                get_string('join_now', 'mod_msteamsecp'),
                ['class' => 'btn btn-danger', 'target' => '_blank']
            );
        }

    } elseif ($occ->starttime > $now) {
        // Upcoming.
        if ($now >= $join_opens) {
            $out .= html_writer::tag('p',
                get_string('starts_in', 'mod_msteamsecp', format_time($occ->starttime - $now))
            );
            if (!empty($instance->join_url)) {
                $out .= html_writer::link(
                    $instance->join_url,
                    get_string('join_meeting', 'mod_msteamsecp'),
                    ['class' => 'btn btn-primary', 'target' => '_blank']
                );
                $out .= ' ';
            }
        } else {
            $out .= html_writer::tag('p',
                get_string('join_opens_in', 'mod_msteamsecp', format_time($join_opens - $now))
            );
        }
        $out .= html_writer::link(
            new moodle_url('/mod/msteamsecp/ical.php', ['cmid' => $cm->id, 'occurrenceid' => $occ->id]),
            get_string('add_to_calendar', 'mod_msteamsecp'),
            ['class' => 'btn btn-outline-secondary btn-sm']
        );

    } else {
        // Ended.
        $out .= html_writer::span(get_string('meeting_ended', 'mod_msteamsecp'), 'badge badge-secondary');
    }

    // Inline recording player (skipped in replace mode where we render it separately).
    if (!$suppress_recording && $occ->recording_ready && !empty($occ->recording_cmid)) {
        $out .= msteamsecp_render_recording_player((int)$occ->recording_cmid, $occ->id, $instance, $context, $cm, $PAGE);
    } elseif ($occ->status === 'ended' && $instance->recording_mode === 'manual') {
        if (has_capability('mod/msteamsecp:uploadrecording', $context)) {
            $out .= ' ' . html_writer::link(
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
    int $now, int $join_window, int $userid, \moodle_database $DB, object $cm
): array {
    global $PAGE;

    $date       = userdate($occ->starttime, get_string('strftimedaydatetime', 'langconfig'));
    $status     = html_writer::span(
        get_string('status_' . $occ->status, 'mod_msteamsecp'),
        'badge badge-' . msteamsecp_status_badge($occ->status)
    );
    $join_opens = $occ->starttime - $join_window;

    // Join column.
    $join = '';
    if ($occ->endtime > $now && $occ->starttime <= $now) {
        // Live.
        if (!empty($instance->join_url)) {
            $join = html_writer::link(
                new moodle_url($instance->join_url),
                get_string('join_now', 'mod_msteamsecp'),
                ['class' => 'btn btn-sm btn-danger', 'target' => '_blank']
            );
            $join .= ' ';
        }
    } elseif ($occ->starttime > $now) {
        if ($now >= $join_opens && !empty($instance->join_url)) {
            $join = html_writer::link(
                new moodle_url($instance->join_url),
                get_string('join', 'mod_msteamsecp'),
                ['class' => 'btn btn-sm btn-primary', 'target' => '_blank']
            );
            $join .= ' ';
        }
        $join .= html_writer::link(
            new moodle_url('/mod/msteamsecp/ical.php', ['cmid' => $cm->id, 'occurrenceid' => $occ->id]),
            '📅',
            ['class' => 'btn btn-sm btn-outline-secondary',
             'title' => get_string('add_to_calendar', 'mod_msteamsecp')]
        );
    }

    // Recording column — inline collapsible player.
    $recording = '';
    if ($occ->recording_ready && !empty($occ->recording_cmid)) {
        $toggle_id = 'msteamsecp-player-wrap-' . $occ->id;
        $recording = html_writer::tag('button',
            get_string('watch', 'mod_msteamsecp'),
            [
                'class'         => 'btn btn-sm btn-outline-secondary',
                'type'          => 'button',
                'data-toggle'   => 'collapse',
                'data-target'   => '#' . $toggle_id,
                'aria-expanded' => 'false',
            ]
        );
        $recording .= html_writer::div(
            msteamsecp_render_recording_player((int)$occ->recording_cmid, $occ->id, $instance, $context, $cm, $PAGE),
            'collapse mt-2',
            ['id' => $toggle_id]
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

    // Attendance column.
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

/**
 * Whether the current user already has completion credit for this recording,
 * so the player can skip watch-time tracking entirely and show a review
 * notice instead of the watch-threshold notice.
 *
 * True when the activity is already complete for the user (live attendance,
 * a previously watched recording, or manual completion), when the user has
 * per-occurrence credit, or when completion tracking is not enabled at all
 * (no credit to earn, so there is nothing to track).
 */
function msteamsecp_user_has_credit(int $occurrenceid, object $cm): bool {
    global $CFG, $DB, $USER;

    static $activity_complete = null;
    if ($activity_complete === null) {
        require_once($CFG->libdir . '/completionlib.php');
        $completion = new completion_info(get_course($cm->course));
        if (!$completion->is_enabled($cm)) {
            $activity_complete = true;
        } else {
            $data = $completion->get_data($cm, false, $USER->id);
            $activity_complete = ((int) $data->completionstate >= COMPLETION_COMPLETE);
        }
    }
    if ($activity_complete) {
        return true;
    }

    return $DB->record_exists('msteamsecp_attendance', [
        'occurrenceid'   => $occurrenceid,
        'userid'         => $USER->id,
        'credit_granted' => 1,
    ]);
}

function msteamsecp_render_recording_player(
    int $occid, int $real_occid, object $instance, context_module $context, object $cm, \moodle_page $PAGE
): string {
    global $DB, $USER;

    $fs    = get_file_storage();
    $files = $fs->get_area_files(
        $context->id, 'mod_msteamsecp', 'recording', $occid, 'filename', false
    );
    $file = reset($files);
    if (!$file) {
        return '';
    }

    $threshold  = msteamsecp_recording_threshold($instance);
    $has_credit = msteamsecp_user_has_credit($real_occid, $cm);

    // Saved watch progress — seeds the player so unique watch time accumulates
    // across sessions, and playback resumes at the last saved position.
    $progress       = $DB->get_record('msteamsecp_watch_progress',
        ['occurrenceid' => $real_occid, 'userid' => $USER->id]);
    $initial_ranges = [];
    $resume_pos     = 0;
    $progress_pct   = 0;
    if ($progress) {
        $initial_ranges = json_decode($progress->watched_ranges ?? '[]', true) ?: [];
        $resume_pos     = (int) $progress->last_position;
        if ($progress->duration > 0) {
            $progress_pct = min(100, round(($progress->watched_seconds / $progress->duration) * 100, 1));
        }
    }
    $video_url = moodle_url::make_pluginfile_url(
        $context->id, 'mod_msteamsecp', 'recording', $occid, '/', $file->get_filename()
    );
    $video_id  = 'msteamsecp-recording-' . $real_occid;

    $out  = html_writer::tag('h4', get_string('session_recording', 'mod_msteamsecp'),
        ['style' => 'margin-top:1rem;']);
    $out .= html_writer::tag('video', '', [
        'id'       => $video_id,
        'src'      => $video_url->out(),
        'controls' => 'controls',
        'style'    => 'max-width:100%; width:100%; border-radius:6px;',
        'preload'  => 'metadata',
    ]);
    $out .= html_writer::tag('p',
        $has_credit
            ? get_string('recording_review_notice', 'mod_msteamsecp')
            : get_string('recording_threshold_notice', 'mod_msteamsecp', $threshold),
        ['class' => 'text-muted small mt-1']
    );
    // Live-updated progress line (populated/refreshed by the AMD player).
    $out .= html_writer::tag('p',
        (!$has_credit && $progress_pct > 0)
            ? get_string('recording_progress', 'mod_msteamsecp', $progress_pct)
            : '',
        ['id' => 'msteamsecp-progress-' . $real_occid, 'class' => 'text-muted small mt-1']
    );
    $out .= html_writer::div('', '',
        ['id' => 'msteamsecp-completion-indicator-' . $real_occid, 'style' => 'display:none;']
    );

    $PAGE->requires->js_call_amd('mod_msteamsecp/recording_player', 'init', [
        $video_id, $cm->id, $real_occid, $threshold, $has_credit, $initial_ranges, $resume_pos,
    ]);

    return $out;
}

function msteamsecp_status_badge(string $status): string {
    $map = [
        'upcoming'  => 'primary',
        'live'      => 'danger',
        'ended'     => 'secondary',
        'cancelled' => 'warning',
    ];
    return $map[$status] ?? 'light';
}
