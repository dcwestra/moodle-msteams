<?php
/**
 * Moodle activity module hooks for mod_msteamsecp.
 *
 * @package    mod_msteamsecp
 * @copyright  2026 Eyecare Partners
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

// ── Instance lifecycle ────────────────────────────────────────────────────────

/**
 * Called when a new meeting activity is saved.
 * Creates the Teams meeting via Graph and populates occurrences.
 */
function msteamsecp_add_instance(stdClass $data, mod_msteamsecp_mod_form $mform = null): int {
    global $DB;

    $data->timecreated  = time();
    $data->timemodified = time();

    // Ensure lobby_bypass has a valid value in case form cleaning dropped it.
    if (empty($data->lobby_bypass)) {
        $data->lobby_bypass = get_config('mod_msteamsecp', 'default_lobby_bypass') ?: 'organizer';
    }

    // Consolidate days-of-week checkboxes into JSON.
    $data->recurrence_days_of_week = msteamsecp_extract_days_of_week($data);

    // Normalise end type and derive recording_behavior from attendance_requirement.
    if (empty($data->is_recurring)) {
        $data->recurrence_type        = null;
        $data->recurrence_interval    = null;
        $data->recurrence_end_date    = null;
        $data->recurrence_count       = null;
        $data->attendance_requirement = 'any';
        $data->recording_behavior     = 'replace';
    } else {
        // 'all' → keep every recording (append); 'any' → replace with latest.
        $data->recording_behavior = ($data->attendance_requirement ?? 'any') === 'all' ? 'append' : 'replace';
        if (($data->recurrence_end_type ?? 'date') === 'date') {
            $data->recurrence_count = null;
        } else {
            $data->recurrence_end_date = null;
        }
    }

    $data->id = $DB->insert_record('msteamsecp', $data);

    // Save co-organiser selections.
    $creator = new \mod_msteamsecp\sync\meeting_creator();
    $coorganiser_ids = !empty($data->coorganiser_userids) ? (array) $data->coorganiser_userids : [];
    $creator->save_coorganisers($data->id, $coorganiser_ids);

    // Check delegated token health and notify if action needed.
    msteamsecp_notify_token_status();

    // Create Teams meeting via Graph.
    try {
        $course = $DB->get_record('course', ['id' => $data->course], '*', MUST_EXIST);
        $creator->create($data, $course);
    } catch (\Throwable $e) {
        $msg = $e->getMessage();
        // Surface auth failures as a notification with reconnect link.
        if (strpos($msg, 'MSTEAMSECP_AUTH_') !== false) {
            msteamsecp_notify_auth_failure($msg);
        } else {
            debugging('msteamsecp: Graph meeting creation failed: ' . $msg, DEBUG_DEVELOPER);
        }
    }

    // Sync Moodle internal calendar events so the meeting appears on the LMS calendar.
    // $data->coursemodule is set by modedit.php before add_instance is called.
    if (!empty($data->coursemodule)) {
        msteamsecp_sync_calendar_events($data, (int) $data->coursemodule);
    }

    return $data->id;
}

/**
 * Called when an existing meeting activity is updated.
 */
function msteamsecp_update_instance(stdClass $data, mod_msteamsecp_mod_form $mform = null): bool {
    global $DB;

    $data->timemodified            = time();
    $data->recurrence_days_of_week = msteamsecp_extract_days_of_week($data);
    $data->id                      = $data->instance;

    // Ensure lobby_bypass has a valid value in case form cleaning dropped it.
    if (empty($data->lobby_bypass)) {
        $data->lobby_bypass = get_config('mod_msteamsecp', 'default_lobby_bypass') ?: 'organizer';
    }

    // Derive recording_behavior from attendance_requirement.
    if (!empty($data->is_recurring)) {
        $data->recording_behavior = ($data->attendance_requirement ?? 'any') === 'all' ? 'append' : 'replace';
    } else {
        $data->attendance_requirement = 'any';
        $data->recording_behavior     = 'replace';
    }

    $DB->update_record('msteamsecp', $data);

    // Save co-organiser selections.
    $creator = new \mod_msteamsecp\sync\meeting_creator();
    $coorganiser_ids = !empty($data->coorganiser_userids) ? (array) $data->coorganiser_userids : [];
    $creator->save_coorganisers($data->id, $coorganiser_ids);

    msteamsecp_notify_token_status();

    try {
        $instance = $DB->get_record('msteamsecp', ['id' => $data->id], '*', MUST_EXIST);
        $creator->update($instance);
    } catch (\Throwable $e) {
        $msg = $e->getMessage();
        if (strpos($msg, 'MSTEAMSECP_AUTH_') !== false) {
            msteamsecp_notify_auth_failure($msg);
        } else {
            debugging('msteamsecp: Graph meeting update failed: ' . $msg, DEBUG_DEVELOPER);
        }
    }

    // Re-sync Moodle internal calendar events.
    $instance = $DB->get_record('msteamsecp', ['id' => $data->id]);
    if ($instance && !empty($data->coursemodule)) {
        msteamsecp_sync_calendar_events($instance, (int) $data->coursemodule);
    }

    return true;
}

/**
 * Called when a meeting activity is deleted.
 */
function msteamsecp_delete_instance(int $id): bool {
    global $DB;

    $instance = $DB->get_record('msteamsecp', ['id' => $id]);
    if (!$instance) {
        return false;
    }

    try {
        $creator = new \mod_msteamsecp\sync\meeting_creator();
        $creator->delete($instance);
    } catch (\Throwable $e) {
        debugging('msteamsecp: Graph meeting deletion failed: ' . $e->getMessage(), DEBUG_DEVELOPER);
    }

    // Clean up plugin tables.
    $occurrences = $DB->get_records('msteamsecp_occurrences', ['instanceid' => $id]);
    foreach ($occurrences as $occ) {
        $DB->delete_records('msteamsecp_attendance',      ['occurrenceid' => $occ->id]);
        $DB->delete_records('msteamsecp_enrollee_events', ['occurrenceid' => $occ->id]);
    }
    $DB->delete_records('msteamsecp_occurrences',   ['instanceid' => $id]);
    $DB->delete_records('msteamsecp_coorganisers',  ['instanceid' => $id]);
    $DB->delete_records('event',                    ['modulename' => 'msteamsecp', 'instance' => $id]);
    $DB->delete_records('msteamsecp',               ['id'         => $id]);

    return true;
}

// ── Delegated token health notifications ──────────────────────────────────────

/**
 * Check delegated token health and show a warning notification if needed.
 * Only runs in web context — silently skips in CLI/cron.
 */
function msteamsecp_notify_token_status(): void {
    if (defined('CLI_SCRIPT') && CLI_SCRIPT) {
        return;
    }
    $status = \mod_msteamsecp\api\graph::delegated_token_status();
    if ($status['state'] === 'ok') {
        return;
    }
    $reconnect_url = $status['reconnect_url'];
    $link = html_writer::link($reconnect_url,
        get_string('oauth_authorize_btn', 'mod_msteamsecp'),
        ['class' => 'btn btn-sm btn-warning ms-2']
    );
    $type = ($status['state'] === 'missing') ? \core\output\notification::NOTIFY_WARNING : \core\output\notification::NOTIFY_WARNING;
    \core\notification::add($status['message'] . ' ' . $link, $type);
}

/**
 * Show an auth failure notification with a reconnect link.
 * Called when a MSTEAMSECP_AUTH_* exception is caught during meeting creation.
 *
 * @param string $error_message  The exception message containing the MSTEAMSECP_AUTH_ prefix
 */
function msteamsecp_notify_auth_failure(string $error_message): void {
    if (defined('CLI_SCRIPT') && CLI_SCRIPT) {
        debugging('msteamsecp: delegated auth failure: ' . $error_message, DEBUG_NORMAL);
        return;
    }
    $reconnect_url = (new moodle_url('/mod/msteamsecp/oauth_authorize.php'))->out(false);
    $link = html_writer::link($reconnect_url,
        get_string('oauth_reauthorize', 'mod_msteamsecp'),
        ['class' => 'btn btn-sm btn-danger ms-2']
    );

    if (strpos($error_message, 'MSTEAMSECP_AUTH_EXPIRED') !== false) {
        $msg = get_string('oauth_token_expired_notice', 'mod_msteamsecp');
    } elseif (strpos($error_message, 'MSTEAMSECP_AUTH_MISSING') !== false) {
        $msg = get_string('oauth_token_missing_notice', 'mod_msteamsecp');
    } else {
        $msg = get_string('oauth_token_failed_notice', 'mod_msteamsecp');
    }

    \core\notification::add($msg . ' ' . $link, \core\output\notification::NOTIFY_ERROR);

    // Meeting creation still fails at this point — fall back to app-only.
    // The meeting will be created without delegated permissions.
    debugging('msteamsecp: delegated auth failed, meeting created with app-only token fallback. ' . $error_message, DEBUG_DEVELOPER);
}

// ── Enrolment observers ───────────────────────────────────────────────────────

/**
 * Called by Moodle's events system when a user is enrolled.
 */
function msteamsecp_user_enrolment_created(\core\event\user_enrolment_created $event): void {
    try {
        $handler = new \mod_msteamsecp\sync\enrolment_handler();
        $handler->on_enrol($event->relateduserid, $event->courseid);
    } catch (\Throwable $e) {
        debugging('msteamsecp: enrolment handler failed: ' . $e->getMessage(), DEBUG_DEVELOPER);
    }
}

/**
 * Called when a user is unenrolled.
 */
function msteamsecp_user_enrolment_deleted(\core\event\user_enrolment_deleted $event): void {
    try {
        $handler = new \mod_msteamsecp\sync\enrolment_handler();
        $handler->on_unenrol($event->relateduserid, $event->courseid);
    } catch (\Throwable $e) {
        debugging('msteamsecp: unenrolment handler failed: ' . $e->getMessage(), DEBUG_DEVELOPER);
    }
}

/**
 * Called when a user completes a course.
 */
function msteamsecp_course_completed(\core\event\course_completed $event): void {
    try {
        $handler = new \mod_msteamsecp\sync\enrolment_handler();
        $handler->on_course_complete($event->relateduserid, $event->courseid);
    } catch (\Throwable $e) {
        debugging('msteamsecp: course completion handler failed: ' . $e->getMessage(), DEBUG_DEVELOPER);
    }
}

// ── View tracking ─────────────────────────────────────────────────────────────

function msteamsecp_view(stdClass $instance, stdClass $course, stdClass $cm, context_module $context): void {
    $event = \mod_msteamsecp\event\course_module_viewed::create([
        'objectid' => $instance->id,
        'context'  => $context,
    ]);
    $event->add_record_snapshot('course', $course);
    $event->add_record_snapshot('msteamsecp', $instance);
    $event->trigger();
}

// ── Helpers ───────────────────────────────────────────────────────────────────

/**
 * Sync Moodle internal calendar events for all occurrences of a meeting instance.
 *
 * Creates one calendar event per occurrence so the meeting appears on the
 * LMS calendar and links back to the activity. Existing events for this
 * instance are deleted and recreated on every call to stay in sync.
 *
 * @param stdClass $instance  msteamsecp record (must have id, course, name)
 * @param int      $cmid      Course module ID (for the activity link)
 */
function msteamsecp_sync_calendar_events(stdClass $instance, int $cmid): void {
    global $CFG, $DB;

    require_once($CFG->dirroot . '/calendar/lib.php');
    require_once($CFG->dirroot . '/course/lib.php');

    // Remove all existing Moodle calendar events for this instance.
    $DB->delete_records('event', [
        'modulename' => 'msteamsecp',
        'instance'   => $instance->id,
    ]);

    // Fetch all occurrences — skip ones that have already ended.
    $occurrences = $DB->get_records_select(
        'msteamsecp_occurrences',
        "instanceid = :instanceid AND status != 'ended'",
        ['instanceid' => $instance->id],
        'starttime ASC'
    );

    if (empty($occurrences)) {
        return;
    }

    $visible = instance_is_visible('msteamsecp', $instance);

    foreach ($occurrences as $occ) {
        $event              = new stdClass();
        $event->name        = $instance->name;
        $event->description = format_module_intro('msteamsecp', $instance, $cmid, false);
        $event->format      = FORMAT_HTML;
        $event->courseid    = $instance->course;
        $event->groupid     = 0;
        $event->userid      = 0;
        $event->modulename  = 'msteamsecp';
        $event->instance    = $instance->id;
        $event->eventtype   = 'meeting';
        $event->type        = CALENDAR_EVENT_TYPE_ACTION;
        $event->timestart   = $occ->starttime;
        $event->timesort    = $occ->starttime;
        $event->timeduration = max(0, $occ->endtime - $occ->starttime);
        $event->visible     = $visible;
        $event->sequence    = 1;

        \calendar_event::create($event, false);
    }
}

/**
 * Extract individual day checkboxes from form data into a JSON string.
 */
function msteamsecp_extract_days_of_week(stdClass $data): ?string {
    $days = [];
    for ($i = 1; $i <= 7; $i++) {
        $key = 'dow_' . $i;
        if (!empty($data->$key)) {
            $days[] = $i;
        }
    }
    return !empty($days) ? json_encode($days) : null;
}

/**
 * Returns the features this module supports.
 */
function msteamsecp_supports(string $feature): ?bool {
    switch ($feature) {
        case FEATURE_MOD_INTRO:               return true;
        case FEATURE_COMPLETION_TRACKS_VIEWS: return false;
        case FEATURE_COMPLETION_HAS_RULES:    return true;
        case FEATURE_GRADE_HAS_GRADE:         return false;
        case FEATURE_BACKUP_MOODLE2:          return false;
        case FEATURE_SHOW_DESCRIPTION:        return true;
        // MOD_PURPOSE_COMMUNICATION added in 4.4 — guard for 4.2 compatibility.
        case FEATURE_MOD_PURPOSE:
            return defined('MOD_PURPOSE_COMMUNICATION') ? MOD_PURPOSE_COMMUNICATION : null;
        default:                              return null;
    }
}

/**
 * Populate the cm_info object with custom completion rule data.
 * Required by FEATURE_COMPLETION_HAS_RULES so Moodle can display and
 * evaluate our custom completion conditions.
 *
 * @param stdClass $coursemodule  The course_module record.
 * @return cached_cm_info|false
 */
function msteamsecp_get_coursemodule_info(stdClass $coursemodule) {
    global $DB;

    $instance = $DB->get_record('msteamsecp', ['id' => $coursemodule->instance],
        'id, name, intro, introformat');
    if (!$instance) {
        return false;
    }

    // Check for completion columns — may not exist on 4.2 before upgrade.
    // Use a static flag so we only hit the schema once per request.
    static $has_completion_cols = null;
    if ($has_completion_cols === null) {
        $dbman = $DB->get_manager();
        $has_completion_cols = $dbman->field_exists(
            new xmldb_table('msteamsecp'),
            new xmldb_field('completion_attendance')
        );
    }

    if ($has_completion_cols) {
        $extra = $DB->get_record('msteamsecp', ['id' => $coursemodule->instance],
            'completion_attendance, completion_attendance_pct, completion_recording');
        if ($extra) {
            $instance->completion_attendance     = $extra->completion_attendance;
            $instance->completion_attendance_pct = $extra->completion_attendance_pct;
            $instance->completion_recording      = $extra->completion_recording;
        }
    }

    $result = new cached_cm_info();
    $result->name = $instance->name;

    if ($coursemodule->showdescription) {
        $result->content = format_module_intro('msteamsecp', $instance, $coursemodule->id, false);
    }

    // Publish custom completion rules into customdata so Moodle can evaluate them.
    // NOTE: only actual rule names from get_defined_custom_rules() may appear in
    // customcompletionrules — extra fields like completion_attendance_pct must be
    // stored separately or Moodle throws a coding error.
    if ($coursemodule->completion == COMPLETION_TRACKING_AUTOMATIC) {
        $result->customdata['customcompletionrules']['completion_attendance'] = $instance->completion_attendance ?? 0;
        $result->customdata['customcompletionrules']['completion_recording']  = $instance->completion_recording ?? 0;
        // Store pct separately — used by custom_completion.php and rule descriptions.
        $result->customdata['completion_attendance_pct'] = $instance->completion_attendance_pct ?? 0;
    }

    return $result;
}



/**
 * Serve recording files stored under filearea='recording'.
 *
 * Called by Moodle when a learner requests a pluginfile.php URL.
 * Validates capability then sends the file.
 *
 * @param stdClass      $course
 * @param stdClass      $cm
 * @param context       $context
 * @param string        $filearea
 * @param array         $args       [itemid, filename]
 * @param bool          $forcedownload
 * @param array         $options
 * @return bool  False if file not found; otherwise sends file and exits.
 */
function msteamsecp_pluginfile(
    stdClass $course,
    stdClass $cm,
    context $context,
    string $filearea,
    array $args,
    bool $forcedownload,
    array $options = []
): bool {
    require_login($course, true, $cm);

    if ($filearea !== 'recording') {
        return false;
    }

    require_capability('mod/msteamsecp:view', $context);

    $itemid   = array_shift($args); // occurrence ID
    $filename = array_pop($args);
    $filepath = $args ? '/' . implode('/', $args) . '/' : '/';

    $fs   = get_file_storage();
    $file = $fs->get_file($context->id, 'mod_msteamsecp', 'recording', $itemid, $filepath, $filename);

    if (!$file || $file->is_directory()) {
        return false;
    }

    // Send with caching — recordings don't change.
    send_stored_file($file, 86400, 0, $forcedownload, $options);
    return true;
}

// ── Calendar API callbacks ─────────────────────────────────────────────────────

/**
 * Determines whether a calendar event should be visible to the current user.
 * Only enrolled course participants should see meeting events.
 *
 * @param calendar_event $event
 * @param int $userid
 * @return bool
 */
function mod_msteamsecp_core_calendar_is_event_visible(calendar_event $event, int $userid = 0): bool {
    global $CFG, $USER;
    require_once($CFG->dirroot . '/calendar/lib.php');
    if (empty($userid)) {
        $userid = $USER->id;
    }
    $cm = get_fast_modinfo($event->courseid, $userid)->instances['msteamsecp'][$event->instance] ?? null;
    if (!$cm) {
        return false;
    }
    return has_capability('mod/msteamsecp:view', context_module::instance($cm->id), $userid);
}

/**
 * Provides the action associated with a calendar event so it appears
 * on the Dashboard / block_myoverview with a clickable join link.
 *
 * @param calendar_event                $event
 * @param \core_calendar\action_factory $factory
 * @param int                           $userid
 * @return \core_calendar\local\event\entities\action_interface|null
 */
function mod_msteamsecp_core_calendar_provide_event_action(
    calendar_event $event,
    \core_calendar\action_factory $factory,
    int $userid = 0
) {
    global $CFG, $USER;
    require_once($CFG->dirroot . '/calendar/lib.php');
    if (empty($userid)) {
        $userid = $USER->id;
    }

    $cm = get_fast_modinfo($event->courseid, $userid)->instances['msteamsecp'][$event->instance] ?? null;
    if (!$cm) {
        return null;
    }

    // Don't show action if the user has already completed the activity.
    $completion = new \completion_info($cm->get_course());
    $completiondata = $completion->get_data($cm, false, $userid);
    if ($completiondata->completionstate != COMPLETION_INCOMPLETE) {
        return null;
    }

    // If the meeting has already ended, no action to show.
    if ($event->timestart + $event->timeduration < time()) {
        return null;
    }

    // Action is joinable 15 minutes before start.
    $actionable = $event->timestart <= time() + (15 * MINSECS);

    return $factory->create_instance(
        get_string('join_meeting', 'mod_msteamsecp'),
        new \moodle_url('/mod/msteamsecp/view.php', ['id' => $cm->id]),
        1,
        $actionable
    );
}
