<?php
/**
 * Scheduled task: runs the post-event processor every 15 minutes.
 *
 * @package    mod_msteamsecp
 * @copyright  2026 Eyecare Partners
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_msteamsecp\task;

defined('MOODLE_INTERNAL') || die();

use mod_msteamsecp\sync\post_event_processor;

class process_events extends \core\task\scheduled_task {

    public function get_name(): string {
        return get_string('task_process_events', 'mod_msteamsecp');
    }

    public function execute(): void {
        mtrace('mod_msteamsecp: starting post-event processing at ' . userdate(time()));

        try {
            $processor = new post_event_processor();
            $processor->run();
        } catch (\Throwable $e) {
            mtrace('mod_msteamsecp: post-event processor failed: ' . $e->getMessage());
            throw $e;
        }

        mtrace('mod_msteamsecp: post-event processing complete.');
    }
}
