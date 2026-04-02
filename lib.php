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
        $data->lobby_bypass = get_config('mod_msteamsecp', 'default_lobby_bypass') ?: 'invited';
    }

    // Consolidate days-of-week checkboxes into JSON.
    $data->recurrence_days_of_week = msteamsecp_extract_days_of_week($data);

    // Normalise end type for recurring.
    if (empty($data->is_recurring)) {
        $data->recurrence_type      = null;
        $data->recurrence_interval  = null;
        $data->recurrence_end_date  = null;
        $data->recurrence_count     = null;
        $data->recording_behavior   = 'append';
    } else {
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

    // Create Teams meeting via Graph.
    try {
        $course = $DB->get_record('course', ['id' => $data->course], '*', MUST_EXIST);
        $creator->create($data, $course);
    } catch (\Throwable $e) {
        debugging('msteamsecp: Graph meeting creation failed: ' . $e->getMessage(), DEBUG_DEVELOPER);
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
        $data->lobby_bypass = get_config('mod_msteamsecp', 'default_lobby_bypass') ?: 'invited';
    }

    $DB->update_record('msteamsecp', $data);

    // Save co-organiser selections.
    $creator = new \mod_msteamsecp\sync\meeting_creator();
    $coorganiser_ids = !empty($data->coorganiser_userids) ? (array) $data->coorganiser_userids : [];
    $creator->save_coorganisers($data->id, $coorganiser_ids);

    try {
        $instance = $DB->get_record('msteamsecp', ['id' => $data->id], '*', MUST_EXIST);
        $creator->update($instance);
    } catch (\Throwable $e) {
        debugging('msteamsecp: Graph meeting update failed: ' . $e->getMessage(), DEBUG_DEVELOPER);
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
    $DB->delete_records('msteamsecp',               ['id'         => $id]);

    return true;
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

// ── Completion ────────────────────────────────────────────────────────────────

/**
 * Defines what completion data this module stores.
 */
function msteamsecp_get_completion_state($course, $cm, $userid, $type): bool {
    global $DB;

    $instance = $DB->get_record('msteamsecp', ['id' => $cm->instance], '*', MUST_EXIST);

    // Check attendance credit.
    if ($instance->completion_attendance) {
        $credited = $DB->get_record_select(
            'msteamsecp_attendance',
            'instanceid = :instanceid AND userid = :userid AND credit_granted = 1',
            ['instanceid' => $instance->id, 'userid' => $userid]
        );
        if ($credited) {
            return true;
        }
    }

    return false;
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
        case FEATURE_MOD_INTRO:           return true;
        case FEATURE_COMPLETION_TRACKS_VIEWS: return false;
        case FEATURE_GRADE_HAS_GRADE:     return false;
        case FEATURE_BACKUP_MOODLE2:      return false;
        case FEATURE_SHOW_DESCRIPTION:    return true;
        case FEATURE_MOD_PURPOSE:         return MOD_PURPOSE_COMMUNICATION;
        default:                          return null;
    }
}
