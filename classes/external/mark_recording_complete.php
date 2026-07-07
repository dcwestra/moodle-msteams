<?php
/**
 * External API: mark a recording as complete when learner reaches the threshold.
 *
 * Called by amd/src/recording_player.js via AJAX when the learner has watched
 * at least the configured percentage of the recording.
 *
 * @package    mod_msteamsecp
 * @copyright  2026 Eyecare Partners
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_msteamsecp\external;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->libdir . '/externallib.php');

use external_api;
use external_function_parameters;
use external_single_structure;
use external_value;
use context_module;

class mark_recording_complete extends external_api {

    /**
     * Parameters accepted by execute().
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'cmid'            => new external_value(PARAM_INT,   'Course module ID of the msteamsecp activity'),
            'occurrenceid'    => new external_value(PARAM_INT,   'Occurrence ID of the recording'),
            'percent_watched' => new external_value(PARAM_FLOAT, 'Percentage of video watched (0-100)'),
        ]);
    }

    /**
     * Mark the msteamsecp activity complete for the current user if they have
     * watched at least the configured threshold percentage.
     *
     * @param int   $cmid
     * @param int   $occurrenceid
     * @param float $percent_watched
     * @return array {success: bool, message: string}
     */
    public static function execute(int $cmid, int $occurrenceid, float $percent_watched): array {
        global $DB, $USER, $CFG;

        // Validate parameters.
        $params = self::validate_parameters(self::execute_parameters(), [
            'cmid'            => $cmid,
            'occurrenceid'    => $occurrenceid,
            'percent_watched' => $percent_watched,
        ]);

        $cmid            = $params['cmid'];
        $occurrenceid    = $params['occurrenceid'];
        $percent_watched = $params['percent_watched'];

        // Validate context and capability.
        $cm      = get_coursemodule_from_id('msteamsecp', $cmid, 0, false, \MUST_EXIST);
        $context = context_module::instance($cm->id);
        self::validate_context($context);
        require_capability('mod/msteamsecp:view', $context);

        // Verify the occurrence belongs to this instance.
        $occ = $DB->get_record('msteamsecp_occurrences', [
            'id'         => $occurrenceid,
            'instanceid' => $cm->instance,
        ]);

        if (!$occ || !$occ->recording_ready) {
            return ['success' => false, 'message' => 'Recording not available for this occurrence.'];
        }

        require_once($CFG->dirroot . '/mod/msteamsecp/lib.php');
        $instance = $DB->get_record('msteamsecp', ['id' => $cm->instance], '*', \MUST_EXIST);

        // Effective threshold — per-activity completion_recording_pct, or the
        // site-wide recording_completion_threshold when not set (default 80%).
        $threshold = (float) msteamsecp_recording_threshold($instance);

        if ($percent_watched < $threshold) {
            return [
                'success' => false,
                'message' => "Watched {$percent_watched}% — need {$threshold}% for completion.",
            ];
        }

        // Grant completion on the msteamsecp activity — respecting attendance_requirement.
        require_once($CFG->libdir . '/completionlib.php');
        $course     = $DB->get_record('course', ['id' => $cm->course], '*', \MUST_EXIST);
        $completion = new \completion_info($course);

        if (!$completion->is_enabled($cm)) {
            return ['success' => true, 'message' => "Watch threshold reached at {$percent_watched}% (completion not enabled)."];
        }

        $should_grant = true;

        if (($instance->attendance_requirement ?? 'any') === 'all') {
            // 'all' mode: only grant when every ended occurrence now has credit
            // (either live attendance or recording watch) for this user.
            $ended_count = $DB->count_records_select(
                'msteamsecp_occurrences',
                "instanceid = :instanceid AND status = 'ended'",
                ['instanceid' => $instance->id]
            );
            $credited_count = $DB->count_records_select(
                'msteamsecp_attendance',
                'instanceid = :instanceid AND userid = :userid AND credit_granted = 1',
                ['instanceid' => $instance->id, 'userid' => $USER->id]
            );
            // Count this occurrence's recording credit if not already in the table.
            $this_credited = $DB->record_exists('msteamsecp_attendance', [
                'occurrenceid'  => $occurrenceid,
                'userid'        => $USER->id,
                'credit_granted'=> 1,
            ]);
            $effective_credited = $this_credited ? $credited_count : $credited_count + 1;
            $should_grant = ($ended_count > 0 && $effective_credited >= $ended_count);
        }

        // Upsert attendance record with recording credit.
        $existing = $DB->get_record('msteamsecp_attendance', [
            'occurrenceid' => $occurrenceid,
            'userid'       => $USER->id,
        ]);
        if ($existing) {
            if (!$existing->credit_granted) {
                $DB->update_record('msteamsecp_attendance', (object) [
                    'id'            => $existing->id,
                    'credit_granted'=> 1,
                    'credit_method' => 'recording',
                    'timemodified'  => time(),
                ]);
            }
        } else {
            $DB->insert_record('msteamsecp_attendance', (object) [
                'instanceid'      => $instance->id,
                'occurrenceid'    => $occurrenceid,
                'userid'          => $USER->id,
                'join_time'       => null,
                'leave_time'      => null,
                'duration_seconds'=> 0,
                'attendance_pct'  => 0,
                'credit_granted'  => 1,
                'credit_method'   => 'recording',
                'timecreated'     => time(),
                'timemodified'    => time(),
            ]);
        }

        if ($should_grant) {
            $completion->update_state($cm, \COMPLETION_COMPLETE, $USER->id);
        }

        return ['success' => true, 'message' => "Completion granted at {$percent_watched}%."];
    }

    /**
     * Return value description.
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'success' => new external_value(PARAM_BOOL,  'Whether completion was granted'),
            'message' => new external_value(PARAM_TEXT,  'Status message'),
        ]);
    }
}
