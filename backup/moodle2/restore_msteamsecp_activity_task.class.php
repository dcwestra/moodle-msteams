<?php
/**
 * Restore task for mod_msteamsecp.
 *
 * @package    mod_msteamsecp
 * @copyright  2026 Eyecare Partners
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/mod/msteamsecp/backup/moodle2/restore_msteamsecp_stepslib.php');

class restore_msteamsecp_activity_task extends restore_activity_task {

    /**
     * No specific settings.
     */
    protected function define_my_settings() {
    }

    /**
     * Define restore steps.
     */
    protected function define_my_steps() {
        $this->add_step(new restore_msteamsecp_activity_structure_step(
            'msteamsecp_structure', 'msteamsecp.xml'
        ));
    }

    /**
     * Decode content links.
     */
    public static function define_decode_contents() {
        $contents = [];
        $contents[] = new restore_decode_content('msteamsecp', ['intro'], 'msteamsecp');
        return $contents;
    }

    /**
     * Define decode rules.
     */
    public static function define_decode_rules() {
        $rules = [];
        $rules[] = new restore_decode_rule(
            'MSTEAMSECP',
            '/mod/msteamsecp/view.php?id=$1',
            'msteamsecp'
        );
        return $rules;
    }

    /**
     * No restore log rules needed.
     */
    public static function define_restore_log_rules() {
        return [];
    }

    /**
     * No course log rules needed.
     */
    public static function define_restore_log_rules_for_course() {
        return [];
    }
}
