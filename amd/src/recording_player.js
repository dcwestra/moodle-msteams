// This file is part of Moodle - http://moodle.org/
//
// @package    mod_msteamsecp
// @copyright  2026 Eyecare Partners
// @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later

/**
 * Recording player with watch-threshold completion tracking.
 *
 * Each player instance is independent — multiple players on the same page
 * (recurring append mode) each have their own threshold tracking and
 * completion indicator. Scrubbing forward is ignored via high-water mark.
 */
define(['core/ajax', 'core/notification'], function(Ajax) {

    'use strict';

    function init(videoId, cmId, occurrenceId, threshold) {
        var video = document.getElementById(videoId);
        if (!video) {
            return;
        }

        var completionAttempted = false;
        var maxPercentWatched   = 0;

        video.addEventListener('timeupdate', function() {
            if (!video.duration || video.duration === 0) {
                return;
            }

            var currentPercent = (video.currentTime / video.duration) * 100;

            // High-water mark — scrubbing forward doesn't count.
            if (currentPercent > maxPercentWatched) {
                maxPercentWatched = currentPercent;
            }

            if (!completionAttempted && maxPercentWatched >= threshold) {
                completionAttempted = true;
                markComplete(cmId, occurrenceId, maxPercentWatched);
            }
        });

        function markComplete(cmid, occurrenceid, percentWatched) {
            Ajax.call([{
                methodname: 'mod_msteamsecp_mark_recording_complete',
                args: {
                    cmid:            cmid,
                    occurrenceid:    occurrenceid,
                    percent_watched: percentWatched,
                },
                done: function(response) {
                    if (response.success) {
                        // Show per-occurrence completion indicator.
                        var indicator = document.getElementById(
                            'msteamsecp-completion-indicator-' + occurrenceid
                        );
                        if (indicator) {
                            indicator.textContent = M.util.get_string(
                                'recording_completion_granted', 'mod_msteamsecp'
                            );
                            indicator.className = 'alert alert-success mt-2';
                            indicator.style.display = 'block';
                        }
                    }
                },
                fail: function() {
                    // Silent — allow retry on next threshold crossing.
                    completionAttempted = false;
                },
            }]);
        }
    }

    return {init: init};
});
