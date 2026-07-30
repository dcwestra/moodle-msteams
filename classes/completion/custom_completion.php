<?php
/**
 * Custom completion rules for mod_msteamsecp.
 *
 * Replaces the deprecated msteamsecp_get_completion_state() callback function.
 * Moodle 4.2+ requires completion logic to be implemented as a class extending
 * \core_completion\activity_custom_completion.
 *
 * @package    mod_msteamsecp
 * @copyright  2026 Eyecare Partners
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_msteamsecp\completion;

defined('MOODLE_INTERNAL') || die();

class custom_completion extends \core_completion\activity_custom_completion {

    /**
     * Return completion state for a given rule.
     *
     * @param string $rule  One of the keys returned by get_defined_custom_rules()
     * @return int  COMPLETION_COMPLETE or COMPLETION_INCOMPLETE
     */
    public function get_state(string $rule): int {
        global $DB;

        $this->validate_rule($rule);

        $instance = $DB->get_record('msteamsecp', ['id' => $this->cm->instance], '*', MUST_EXIST);
        $userid   = $this->userid;

        $all_mode = ($instance->attendance_requirement ?? 'any') === 'all';

        if ($rule === 'completion_attendance') {
            if (empty($instance->completion_attendance)) {
                return COMPLETION_INCOMPLETE;
            }
            return $this->check_attendance($instance, $userid, $all_mode)
                ? COMPLETION_COMPLETE
                : COMPLETION_INCOMPLETE;
        }

        if ($rule === 'completion_recording') {
            if (empty($instance->completion_recording)) {
                return COMPLETION_INCOMPLETE;
            }
            return $this->check_recording($instance, $userid, $all_mode)
                ? COMPLETION_COMPLETE
                : COMPLETION_INCOMPLETE;
        }

        return COMPLETION_INCOMPLETE;
    }

    /**
     * Return the list of custom completion rule keys defined by this module.
     *
     * @return string[]
     */
    public static function get_defined_custom_rules(): array {
        return ['completion_attendance', 'completion_recording'];
    }

    /**
     * Return human-readable descriptions of active completion rules for display
     * on the course page completion status block.
     *
     * @return string[]  Keyed by rule name
     */
    public function get_custom_rule_descriptions(): array {
        $rules = $this->cm->customdata['customcompletionrules'] ?? [];
        $pct   = (int) ($this->cm->customdata['completion_attendance_pct'] ?? 0);
        $descriptions = [];

        if (!empty($rules['completion_attendance'])) {
            $descriptions['completion_attendance'] = $pct > 0
                ? get_string('completion_attendance_pct_desc', 'mod_msteamsecp', $pct)
                : get_string('completion_attendance_desc', 'mod_msteamsecp');
        }

        if (!empty($rules['completion_recording'])) {
            $rpct = (int) ($this->cm->customdata['completion_recording_pct'] ?? 0);
            $descriptions['completion_recording'] = $rpct > 0
                ? get_string('completion_recording_pct_desc', 'mod_msteamsecp', $rpct)
                : get_string('completion_recording_desc', 'mod_msteamsecp');
        }

        return $descriptions;
    }

    /**
     * Return the sort order for custom rules — used when displaying
     * multiple completion conditions on the course page.
     *
     * @return string[]
     */
    public function get_sort_order(): array {
        return ['completion_attendance', 'completion_recording'];
    }

    /**
     * OR logic: completing either attendance or recording is sufficient.
     *
     * Moodle's default implementation requires ALL enabled rules (AND logic),
     * which prevents live-attendance credit from granting completion when
     * recording completion is also enabled on the same activity. Since the two
     * rules represent alternative paths to the same outcome — attending live vs.
     * watching the recording later — either one should be enough.
     *
     * @return int  COMPLETION_COMPLETE or COMPLETION_INCOMPLETE
     */
    public function get_overall_completion_state(): int {
        $active = 0;

        foreach (static::get_defined_custom_rules() as $rule) {
            if (!($this->cm->customdata['customcompletionrules'][$rule] ?? false)) {
                continue;
            }
            $active++;
            if ($this->get_state($rule) === COMPLETION_COMPLETE) {
                return COMPLETION_COMPLETE;
            }
        }

        // No custom rule is switched on, so this method has nothing to say.
        // Core's default returns COMPLETE here and lets the standard criteria
        // (view / grade) decide; returning INCOMPLETE instead would make an
        // activity set to automatic completion with only "student must view"
        // ticked impossible to ever complete.
        return $active === 0 ? COMPLETION_COMPLETE : COMPLETION_INCOMPLETE;
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    private function check_attendance(object $instance, int $userid, bool $all_mode): bool {
        global $DB;

        if ($all_mode) {
            $ended_count = $DB->count_records_select(
                'msteamsecp_occurrences',
                "instanceid = :instanceid AND status = 'ended'",
                ['instanceid' => $instance->id]
            );
            if ($ended_count === 0) {
                return false;
            }
            $credited_count = $DB->count_records_select(
                'msteamsecp_attendance',
                'instanceid = :instanceid AND userid = :userid AND credit_granted = 1',
                ['instanceid' => $instance->id, 'userid' => $userid]
            );
            return $credited_count >= $ended_count;
        }

        // 'any' mode — one credit is enough.
        return $DB->record_exists_select(
            'msteamsecp_attendance',
            'instanceid = :instanceid AND userid = :userid AND credit_granted = 1',
            ['instanceid' => $instance->id, 'userid' => $userid]
        );
    }

    private function check_recording(object $instance, int $userid, bool $all_mode): bool {
        global $DB;

        // Recording completion is granted server-side by save_watch_progress,
        // which hands off to the mark_recording_complete class once accumulated
        // unique watch time crosses the threshold; that sets
        // credit_method='recording'. We check for any attendance record with
        // credit granted via recording.
        if ($all_mode) {
            $ended_count = $DB->count_records_select(
                'msteamsecp_occurrences',
                "instanceid = :instanceid AND status = 'ended'",
                ['instanceid' => $instance->id]
            );
            if ($ended_count === 0) {
                return false;
            }
            $credited_count = $DB->count_records_select(
                'msteamsecp_attendance',
                "instanceid = :instanceid AND userid = :userid AND credit_granted = 1
                 AND credit_method = 'recording'",
                ['instanceid' => $instance->id, 'userid' => $userid]
            );
            return $credited_count >= $ended_count;
        }

        return $DB->record_exists_select(
            'msteamsecp_attendance',
            "instanceid = :instanceid AND userid = :userid AND credit_granted = 1
             AND credit_method = 'recording'",
            ['instanceid' => $instance->id, 'userid' => $userid]
        );
    }
}
