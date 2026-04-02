<?php
/**
 * Course module viewed event for mod_msteamsecp.
 *
 * @package    mod_msteamsecp
 * @copyright  2026 Eyecare Partners
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_msteamsecp\event;

defined('MOODLE_INTERNAL') || die();

class course_module_viewed extends \core\event\course_module_viewed {

    /**
     * Init method.
     */
    protected function init() {
        $this->data['objecttable'] = 'msteamsecp';
        parent::init();
    }

    /**
     * Returns the name of the event.
     */
    public static function get_name(): string {
        return get_string('event_course_module_viewed', 'mod_msteamsecp');
    }

    /**
     * Returns description of what happened.
     */
    public function get_description(): string {
        return "The user with id '$this->userid' viewed the Teams meeting activity " .
               "with course module id '$this->contextinstanceid'.";
    }

    /**
     * Returns relevant URL.
     */
    public function get_url(): \moodle_url {
        return new \moodle_url('/mod/msteamsecp/view.php', ['id' => $this->contextinstanceid]);
    }
}
