<?php
/**
 * Backup task for mod_msteamsecp.
 *
 * @package    mod_msteamsecp
 * @copyright  2026 Eyecare Partners
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/mod/msteamsecp/backup/moodle2/backup_msteamsecp_stepslib.php');

class backup_msteamsecp_activity_task extends backup_activity_task {

    /**
     * No specific settings for this activity.
     */
    protected function define_my_settings() {
    }

    /**
     * Define the backup steps.
     */
    protected function define_my_steps() {
        $this->add_step(new backup_msteamsecp_activity_structure_step(
            'msteamsecp_structure', 'msteamsecp.xml'
        ));
    }

    /**
     * Encode content links.
     */
    public static function encode_content_links($content) {
        global $CFG;

        $base = preg_quote($CFG->wwwroot, '/');

        // Link to activity view.
        $pattern     = "/$base\/mod\/msteamsecp\/view\.php\?id=([0-9]+)/";
        $replacement = '$@MSTEAMSECP*$1@$';
        $content     = preg_replace($pattern, $replacement, $content);

        return $content;
    }
}
