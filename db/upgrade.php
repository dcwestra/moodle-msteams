<?php
/**
 * Upgrade steps for mod_msteamsecp.
 *
 * @package    mod_msteamsecp
 * @copyright  2026 Eyecare Partners
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
defined('MOODLE_INTERNAL') || die();

function xmldb_msteamsecp_upgrade($oldversion) {
    global $DB;
    $dbman = $DB->get_manager();

    if ($oldversion < 2026032200) {
        // Add msteamsecp_coorganisers table.
        $table = new xmldb_table('msteamsecp_coorganisers');
        $table->add_field('id',          XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE);
        $table->add_field('instanceid',  XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL);
        $table->add_field('userid',      XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL);
        $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_key('primary',     XMLDB_KEY_PRIMARY, ['id']);
        $table->add_key('fk_instance', XMLDB_KEY_FOREIGN, ['instanceid'], 'msteamsecp', ['id']);
        $table->add_key('fk_user',     XMLDB_KEY_FOREIGN, ['userid'],     'user',        ['id']);
        $table->add_index('idx_instance_user', XMLDB_INDEX_UNIQUE, ['instanceid', 'userid']);

        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        upgrade_mod_savepoint(true, 2026032200, 'msteamsecp');
    }

    if ($oldversion < 2026032300) {
        $table = new xmldb_table('msteamsecp');

        // If lobby_bypass exists as an int (from v1.0/v1.1), drop it and
        // re-add it as a char field to hold the Graph lobbyBypassScope string.
        // If it was already dropped (v1.2.0 interim), just add the char version.
        $field_old = new xmldb_field('lobby_bypass');
        if ($dbman->field_exists($table, $field_old)) {
            $dbman->drop_field($table, $field_old);
        }

        $field_new = new xmldb_field('lobby_bypass', XMLDB_TYPE_CHAR, '40', null, XMLDB_NOTNULL, null, 'coorganizers');
        if (!$dbman->field_exists($table, $field_new)) {
            $dbman->add_field($table, $field_new);
        }

        upgrade_mod_savepoint(true, 2026032300, 'msteamsecp');
    }

    if ($oldversion < 2026032401) {
        // Migrate the default_lobby_bypass plugin config value from the old
        // int checkbox (1/0) or old string values to the current valid set.
        $valid_scopes = [
            'organizer', 'invited', 'organization', 'organizationExcludingGuests',
            'organizationAndFederated', 'everyone',
        ];
        $current = get_config('mod_msteamsecp', 'default_lobby_bypass');
        if ($current === false || !in_array($current, $valid_scopes, true)) {
            set_config('default_lobby_bypass', 'organizer', 'mod_msteamsecp');
        }

        // Also migrate any existing lobby_bypass values on meeting instances.
        if ($dbman->field_exists(new xmldb_table('msteamsecp'), new xmldb_field('lobby_bypass'))) {
            $DB->execute(
                "UPDATE {msteamsecp} SET lobby_bypass = 'organizer'
                  WHERE lobby_bypass NOT IN (
                    'organizer','invited','organization',
                    'organizationExcludingGuests','organizationAndFederated',
                    'everyone'
                  )"
            );
        }

        upgrade_mod_savepoint(true, 2026032401, 'msteamsecp');
    }

    if ($oldversion < 2026040100) {
        // v1.4.0 — delegated OAuth token support.
        // Tokens are stored in mdl_config_plugins — no schema changes needed.
        upgrade_mod_savepoint(true, 2026040100, 'msteamsecp');
    }

    return true;
}
