<?php
/**
 * External API: persist a learner's recording watch progress.
 *
 * Called by amd/src/recording_player.js every ~30 seconds of playback (and on
 * pause / video end / tab hide). The client sends its full set of watched
 * ranges; the server merges them with any previously stored ranges so progress
 * accumulates across sessions and devices. When the merged unique watch time
 * reaches the activity's threshold, completion credit is granted server-side
 * via mark_recording_complete.
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

class save_watch_progress extends external_api {

    /** Maximum number of ranges accepted per request (abuse guard). */
    const MAX_RANGES = 5000;

    /** Maximum plausible recording duration in seconds (24h). */
    const MAX_DURATION = 86400;

    /**
     * Parameters accepted by execute().
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'cmid'         => new external_value(PARAM_INT,   'Course module ID of the msteamsecp activity'),
            'occurrenceid' => new external_value(PARAM_INT,   'Occurrence ID of the recording'),
            'ranges'       => new external_value(PARAM_RAW,   'JSON array of watched [start, end] second ranges'),
            'duration'     => new external_value(PARAM_FLOAT, 'Video duration in seconds'),
            'position'     => new external_value(PARAM_FLOAT, 'Current playback position in seconds'),
        ]);
    }

    /**
     * Merge and store the user's watched ranges; grant completion credit when
     * the unique watch time reaches the activity's threshold.
     *
     * @param int    $cmid
     * @param int    $occurrenceid
     * @param string $ranges    JSON [[start, end], ...]
     * @param float  $duration
     * @param float  $position
     * @return array {pct: float, watched_seconds: int, credit_granted: bool}
     */
    public static function execute(int $cmid, int $occurrenceid, string $ranges,
                                   float $duration, float $position): array {
        global $DB, $USER, $CFG;

        $params = self::validate_parameters(self::execute_parameters(), [
            'cmid'         => $cmid,
            'occurrenceid' => $occurrenceid,
            'ranges'       => $ranges,
            'duration'     => $duration,
            'position'     => $position,
        ]);

        $cm      = get_coursemodule_from_id('msteamsecp', $params['cmid'], 0, false, \MUST_EXIST);
        $context = context_module::instance($cm->id);
        self::validate_context($context);
        require_capability('mod/msteamsecp:view', $context);

        $occ = $DB->get_record('msteamsecp_occurrences', [
            'id'         => $params['occurrenceid'],
            'instanceid' => $cm->instance,
        ]);
        if (!$occ || !$occ->recording_ready) {
            return ['pct' => 0, 'watched_seconds' => 0, 'credit_granted' => false];
        }

        $duration = min(max(0.0, $params['duration']), self::MAX_DURATION);
        $position = min(max(0.0, $params['position']), $duration);

        // Decode and sanitise incoming ranges.
        $incoming = self::clean_ranges(json_decode($params['ranges'], true), $duration);

        // Merge with previously stored ranges.
        $existing = $DB->get_record('msteamsecp_watch_progress', [
            'occurrenceid' => $occ->id,
            'userid'       => $USER->id,
        ]);
        $stored = $existing
            ? self::clean_ranges(json_decode($existing->watched_ranges ?? '[]', true), $duration)
            : [];

        $merged  = self::merge_ranges(array_merge($stored, $incoming));
        $seconds = 0;
        foreach ($merged as $r) {
            $seconds += $r[1] - $r[0];
        }
        $seconds = (int) round($seconds);

        // Prefer the largest duration seen — metadata can load slightly
        // differently between visits and we must not shrink the denominator.
        if ($existing && (int) $existing->duration > $duration) {
            $duration = (int) $existing->duration;
        }

        $record = (object) [
            'instanceid'      => (int) $cm->instance,
            'occurrenceid'    => (int) $occ->id,
            'userid'          => (int) $USER->id,
            'duration'        => (int) round($duration),
            'watched_seconds' => $seconds,
            'watched_ranges'  => json_encode($merged),
            'last_position'   => (int) round($position),
            'timemodified'    => time(),
        ];

        if ($existing) {
            $record->id = $existing->id;
            $DB->update_record('msteamsecp_watch_progress', $record);
        } else {
            $record->timecreated = time();
            try {
                $DB->insert_record('msteamsecp_watch_progress', $record);
            } catch (\dml_write_exception $e) {
                // Concurrent save from another tab created the row first —
                // merge into it on the next save; this request's data is a
                // subset or near-subset of what that tab holds.
                debugging('msteamsecp: concurrent watch_progress insert for occ ' . $occ->id, DEBUG_DEVELOPER);
            }
        }

        $pct = $duration > 0 ? min(100.0, ($seconds / $duration) * 100) : 0.0;

        // Grant credit when the threshold is reached and not already credited.
        $credit_granted = $DB->record_exists('msteamsecp_attendance', [
            'occurrenceid'   => $occ->id,
            'userid'         => $USER->id,
            'credit_granted' => 1,
        ]);

        require_once($CFG->dirroot . '/mod/msteamsecp/lib.php');
        $instance  = $DB->get_record('msteamsecp', ['id' => $cm->instance], '*', \MUST_EXIST);
        $threshold = (float) msteamsecp_recording_threshold($instance);

        if (!$credit_granted && $pct >= $threshold) {
            $result = mark_recording_complete::execute($cm->id, $occ->id, $pct);
            $credit_granted = !empty($result['success']);
        }

        return [
            'pct'             => round($pct, 1),
            'watched_seconds' => $seconds,
            'credit_granted'  => $credit_granted,
        ];
    }

    /**
     * Return value description.
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'pct'             => new external_value(PARAM_FLOAT, 'Unique watch percentage after merging'),
            'watched_seconds' => new external_value(PARAM_INT,   'Unique seconds watched after merging'),
            'credit_granted'  => new external_value(PARAM_BOOL,  'Whether the user now has completion credit'),
        ]);
    }

    // -------------------------------------------------------------------------
    // Range helpers
    // -------------------------------------------------------------------------

    /**
     * Validate raw decoded JSON into a clean list of [start, end] float pairs
     * clamped to [0, duration] (when duration is known).
     *
     * @param mixed $raw
     * @param float $duration  0 = unknown, no upper clamp
     * @return array
     */
    private static function clean_ranges($raw, float $duration): array {
        if (!is_array($raw)) {
            return [];
        }
        $clean = [];
        foreach (array_slice($raw, 0, self::MAX_RANGES) as $pair) {
            if (!is_array($pair) || count($pair) !== 2
                    || !is_numeric($pair[0]) || !is_numeric($pair[1])) {
                continue;
            }
            $start = max(0.0, (float) $pair[0]);
            $end   = (float) $pair[1];
            if ($duration > 0) {
                $end = min($end, $duration);
            }
            if ($end > $start) {
                $clean[] = [$start, $end];
            }
        }
        return $clean;
    }

    /**
     * Merge overlapping/adjacent ranges into a minimal sorted set.
     * Ranges within 1 second of each other are coalesced (the client tracks
     * whole seconds, so sub-second gaps are tracking artefacts, not unwatched
     * content).
     *
     * @param array $ranges  [[start, end], ...]
     * @return array
     */
    private static function merge_ranges(array $ranges): array {
        if (empty($ranges)) {
            return [];
        }
        usort($ranges, function($a, $b) {
            return $a[0] <=> $b[0];
        });
        $merged = [$ranges[0]];
        for ($i = 1; $i < count($ranges); $i++) {
            $last = &$merged[count($merged) - 1];
            if ($ranges[$i][0] <= $last[1] + 1) {
                $last[1] = max($last[1], $ranges[$i][1]);
            } else {
                $merged[] = $ranges[$i];
            }
            unset($last);
        }
        return $merged;
    }
}
