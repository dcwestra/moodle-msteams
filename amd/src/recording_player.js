// This file is part of Moodle - http://moodle.org/
//
// @package    mod_msteamsecp
// @copyright  2026 Eyecare Partners
// @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later

/**
 * Recording player with persistent watch-time completion tracking.
 *
 * Seeking is never blocked. Completion credit is earned by accumulating
 * unique watch time: each second of the recording counts once, no matter
 * how many times it is replayed, and seeking over a section does not count
 * the skipped gap. This lets learners skip meeting breaks and dead time.
 *
 * Progress is persistent: watched ranges are saved to the server every ~30
 * seconds of playback (and on pause / video end / tab hide), merged
 * server-side, and seeded back into the player on the next visit, so long
 * recordings can be watched across multiple sittings. Playback resumes at
 * the last saved position. Credit is granted server-side the moment the
 * merged unique watch time reaches the threshold percentage.
 *
 * Each player instance is independent — multiple players on the same page
 * (recurring append mode) each track and save their own watch time.
 */
define(['core/ajax'], function(Ajax) {

    'use strict';

    var SAVE_INTERVAL_MS = 30000;

    function init(videoId, cmId, occurrenceId, threshold, hasCredit, initialRanges, resumePosition) {
        var video = document.getElementById(videoId);
        if (!video) {
            return;
        }

        // Resume where the learner left off — credit holders included.
        if (resumePosition && resumePosition > 5) {
            var resume = function() {
                if (video.duration && resumePosition < video.duration - 5) {
                    video.currentTime = resumePosition;
                }
            };
            if (video.readyState >= 1) {
                resume();
            } else {
                video.addEventListener('loadedmetadata', resume);
            }
        }

        if (hasCredit) {
            // Credit already earned — nothing to track or save.
            return;
        }

        var granted          = false;
        var saving           = false;
        var thresholdSaved   = false;
        var watchedSecs      = {}; // second index -> true, once actually played
        var watchedCount     = 0;
        var lastSavedCount   = 0;
        var lastTime         = null; // playback continuity anchor

        function markRange(from, to) {
            for (var s = Math.floor(from); s <= Math.floor(to); s++) {
                if (!watchedSecs[s]) {
                    watchedSecs[s] = true;
                    watchedCount++;
                }
            }
        }

        // Seed with ranges already saved on the server. A range [a, b) covers
        // whole seconds a .. b-1.
        if (initialRanges && initialRanges.length) {
            for (var i = 0; i < initialRanges.length; i++) {
                markRange(initialRanges[i][0], initialRanges[i][1] - 1);
            }
        }
        lastSavedCount = watchedCount;

        // Rebuild minimal [start, end) ranges from the watched-seconds map.
        function buildRanges() {
            var secs = Object.keys(watchedSecs).map(Number).sort(function(a, b) {
                return a - b;
            });
            var ranges = [];
            var start = null;
            var prev = null;
            for (var i = 0; i < secs.length; i++) {
                if (start === null) {
                    start = secs[i];
                } else if (secs[i] > prev + 1) {
                    ranges.push([start, prev + 1]);
                    start = secs[i];
                }
                prev = secs[i];
            }
            if (start !== null) {
                ranges.push([start, prev + 1]);
            }
            return ranges;
        }

        function updateProgressText(pct) {
            var el = document.getElementById('msteamsecp-progress-' + occurrenceId);
            if (el) {
                el.textContent = M.util.get_string('recording_progress', 'mod_msteamsecp', pct);
            }
        }

        function onGranted() {
            granted = true;
            clearInterval(timer);
            var indicator = document.getElementById(
                'msteamsecp-completion-indicator-' + occurrenceId
            );
            if (indicator) {
                indicator.textContent = M.util.get_string(
                    'recording_completion_granted', 'mod_msteamsecp'
                );
                indicator.className = 'alert alert-success mt-2';
                indicator.style.display = 'block';
            }
        }

        function save() {
            if (granted || saving || watchedCount === lastSavedCount) {
                return;
            }
            saving = true;
            var countAtSave = watchedCount;
            Ajax.call([{
                methodname: 'mod_msteamsecp_save_watch_progress',
                args: {
                    cmid:         cmId,
                    occurrenceid: occurrenceId,
                    ranges:       JSON.stringify(buildRanges()),
                    duration:     video.duration || 0,
                    position:     video.currentTime || 0,
                },
                done: function(response) {
                    saving = false;
                    lastSavedCount = countAtSave;
                    updateProgressText(response.pct);
                    if (response.credit_granted) {
                        onGranted();
                    }
                },
                fail: function() {
                    // Silent — unsaved progress is retained locally and will be
                    // included in the next save attempt.
                    saving = false;
                    thresholdSaved = false;
                },
            }]);
        }

        // A seek breaks playback continuity — the jumped-over gap must not count.
        video.addEventListener('seeking', function() {
            lastTime = null;
        });

        video.addEventListener('timeupdate', function() {
            if (granted || !video.duration || video.seeking) {
                return;
            }

            var t = video.currentTime;

            // Count only small forward steps of continuous playback (timeupdate
            // fires ~4x/sec, so normal-speed steps are well under 2 seconds).
            if (lastTime !== null && t > lastTime && t - lastTime < 2) {
                markRange(lastTime, t);
            }
            lastTime = t;

            // Save immediately when the local estimate crosses the threshold —
            // the server merges, recomputes, and grants authoritatively.
            var pct = (watchedCount / Math.ceil(video.duration)) * 100;
            if (!thresholdSaved && pct >= threshold) {
                thresholdSaved = true;
                save();
            }
        });

        video.addEventListener('pause', save);
        video.addEventListener('ended', save);
        document.addEventListener('visibilitychange', function() {
            if (document.visibilityState === 'hidden') {
                save();
            }
        });

        var timer = setInterval(function() {
            if (!video.paused) {
                save();
            }
        }, SAVE_INTERVAL_MS);
    }

    return {init: init};
});
