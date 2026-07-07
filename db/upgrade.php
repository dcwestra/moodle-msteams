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

    if ($oldversion < 2026040200) {
        // v1.4.5 — in-plugin recording player with watch-threshold completion.
        $table = new xmldb_table('msteamsecp');

        // attendance_requirement — controls whether learner must attend one or all occurrences.
        $field = new xmldb_field('attendance_requirement', XMLDB_TYPE_CHAR, '10', null, XMLDB_NOTNULL, null, 'any');
        if (!$dbman->field_exists($table, $field)) {
            // Set existing rows to 'any' first to avoid NOT NULL constraint failure.
            $DB->execute("UPDATE {msteamsecp} SET attendance_requirement = 'any' WHERE attendance_requirement IS NULL OR attendance_requirement = ''");
            $dbman->add_field($table, $field);
        }

        // completion_attendance — whether attending a live session grants completion.
        $field = new xmldb_field('completion_attendance', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        // completion_attendance_pct — minimum attendance % required for credit.
        $field = new xmldb_field('completion_attendance_pct', XMLDB_TYPE_INTEGER, '3', null, XMLDB_NOTNULL, null, '0');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        // completion_recording — whether watching the recording grants completion.
        $field = new xmldb_field('completion_recording', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        // Correct any recording_behavior mismatches from previous versions.
        $DB->execute(
            "UPDATE {msteamsecp}
                SET recording_behavior = 'replace'
              WHERE attendance_requirement = 'any'
                AND recording_behavior = 'append'"
        );

        upgrade_mod_savepoint(true, 2026040200, 'msteamsecp');
    }

    if ($oldversion < 2026041300) {
        // v1.4.7 — Moodle internal calendar events, custom_completion class,
        // calendar API callbacks. No schema changes required.
        upgrade_mod_savepoint(true, 2026041300, 'msteamsecp');
    }

    if ($oldversion < 2026042400) {
        // v1.5.0 — No schema changes. New observers, backup support enabled,
        // activity completion calendar removal fix.
        upgrade_mod_savepoint(true, 2026042400, 'msteamsecp');
    }

    if ($oldversion < 2026042701) {
        // v1.5.1 — No schema changes. Bug fixes: recurring meeting last
        // occurrence dropped when co-organiser set; recurrence endDate
        // timezone correction; update_instance end-type normalisation.
        upgrade_mod_savepoint(true, 2026042701, 'msteamsecp');
    }

    if ($oldversion < 2026052600) {
        // v1.5.2 — No schema changes. Fix crash on meeting form submit caused
        // by invalid client-side required rule on date_time_selector fields.
        upgrade_mod_savepoint(true, 2026052600, 'msteamsecp');
    }

    if ($oldversion < 2026052700) {
        // v1.5.3 — No schema changes. Switch co-organiser autocomplete from
        // pre-loading all users to AJAX search; prevents page freeze on
        // large multi-tenant installs (IOMAD) when date picker dropdowns fire
        // change events that trigger Moodle's hideIf DOM traversal.
        upgrade_mod_savepoint(true, 2026052700, 'msteamsecp');
    }

    if ($oldversion < 2026052701) {
        // v1.5.4 — No schema changes. Merge two get_record() calls in
        // msteamsecp_get_coursemodule_info() into one to halve DB queries
        // on course pages with multiple activity instances.
        upgrade_mod_savepoint(true, 2026052701, 'msteamsecp');
    }

    if ($oldversion < 2026052800) {
        // v1.5.5 — Replace LMS user-ID-based co-organiser selection with
        // direct email entry. The coorganisers table gains an email column;
        // userid becomes nullable (existing records migrated); the user FK
        // and old unique index are removed.
        $table = new xmldb_table('msteamsecp_coorganisers');

        // Add email column.
        $field = new xmldb_field('email', XMLDB_TYPE_CHAR, '255', null, false, null, '');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        // Migrate existing rows — copy email from the linked LMS user.
        $rows = $DB->get_records_sql(
            "SELECT c.id, u.email
               FROM {msteamsecp_coorganisers} c
               JOIN {user} u ON u.id = c.userid
              WHERE (c.email IS NULL OR c.email = '') AND u.deleted = 0"
        );
        foreach ($rows as $row) {
            $DB->set_field('msteamsecp_coorganisers', 'email', $row->email, ['id' => $row->id]);
        }

        // Drop old unique index on (instanceid, userid) BEFORE modifying the column.
        $index = new xmldb_index('idx_instance_user', XMLDB_INDEX_UNIQUE, ['instanceid', 'userid']);
        if ($dbman->index_exists($table, $index)) {
            $dbman->drop_index($table, $index);
        }

        // Drop the foreign key on userid so NULL values are valid.
        $key = new xmldb_key('fk_user', XMLDB_KEY_FOREIGN, ['userid'], 'user', ['id']);
        if ($dbman->find_key_name($table, $key)) {
            $dbman->drop_key($table, $key);
        }

        // Make userid nullable.
        $field = new xmldb_field('userid', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
        $dbman->change_field_notnull($table, $field);

        // Add unique index on (instanceid, email).
        $index = new xmldb_index('idx_instance_email', XMLDB_INDEX_UNIQUE, ['instanceid', 'email']);
        if (!$dbman->index_exists($table, $index)) {
            $dbman->add_index($table, $index);
        }

        upgrade_mod_savepoint(true, 2026052800, 'msteamsecp');
    }

    if ($oldversion < 2026060200) {
        // v1.5.6 — No schema changes. Fix bi-weekly occurrence expansion
        // (expand_recurrence scanned 7*interval days per window, producing
        // weekly occurrences for bi-weekly meetings). Add attend-once filtering
        // to the LMS calendar visibility callback so learners see only their
        // next due occurrence, mirroring the Teams rolling-push behaviour.
        upgrade_mod_savepoint(true, 2026060200, 'msteamsecp');
    }

    if ($oldversion < 2026060800) {
        // v1.6.0 / v1.6.1 — No schema changes. Meeting edit now rebuilds
        // occurrences (fixing errant LMS calendar events and DST-shifted times),
        // retracts and resends learner Outlook invites, and recreates the service
        // account calendar event (workaround for Graph 405 on startDateTime PATCH).
        // Attendance denominator changed to scheduled duration so early joiners
        // and late stayers don't dilute the percentage. Threshold recalculation
        // runs on save. Custom completion switched to OR logic so live attendance
        // or recording watch either one grants completion. Video scrubbing
        // prevention fixed in the recording player.
        upgrade_mod_savepoint(true, 2026060800, 'msteamsecp');
    }

    if ($oldversion < 2026070500) {
        // v1.7.0 part 1 — No schema changes. Fixed dml_read_exception in
        // msteamsecp_recalculate_attendance_credit() (selected occ.course from
        // a table with no course column, breaking every activity re-save with
        // attendance completion enabled). Added the missing upload_recording.php
        // page for manual recording mode.
        upgrade_mod_savepoint(true, 2026070500, 'msteamsecp');
    }

    if ($oldversion < 2026070600) {
        // v1.7.0 part 2 — Recording player rework: seeking is never blocked;
        // completion credit is earned by accumulating unique watch time (each
        // second counts once; seeked-over gaps don't count). New per-activity
        // completion_recording_pct overrides the site-wide
        // recording_completion_threshold (blank/0 = site default) so recordings
        // containing meeting breaks or dead time can use a lower bar, letting
        // learners skip those sections and still earn credit.
        $table = new xmldb_table('msteamsecp');
        $field = new xmldb_field('completion_recording_pct', XMLDB_TYPE_INTEGER, '3',
            null, null, null, null, 'completion_recording');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        upgrade_mod_savepoint(true, 2026070600, 'msteamsecp');
    }

    if ($oldversion < 2026070700) {
        // v1.7.0 part 3 — Server-side persistent watch progress. Watched ranges
        // are saved every 30 seconds of playback (and on pause/tab-hide), merged
        // server-side, and re-seeded into the player on the next visit, so
        // learners can watch long recordings across multiple sittings. Playback
        // resumes at the last saved position. Credit is granted server-side the
        // moment accumulated unique watch time reaches the threshold.
        $table = new xmldb_table('msteamsecp_watch_progress');
        $table->add_field('id',              XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('instanceid',      XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('occurrenceid',    XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('userid',          XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('duration',        XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('watched_seconds', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('watched_ranges',  XMLDB_TYPE_TEXT,    null, null, null, null, null);
        $table->add_field('last_position',   XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('timecreated',     XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('timemodified',    XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_key('primary',       XMLDB_KEY_PRIMARY, ['id']);
        $table->add_key('fk_occurrence', XMLDB_KEY_FOREIGN, ['occurrenceid'], 'msteamsecp_occurrences', ['id']);
        $table->add_index('idx_occurrence_user', XMLDB_INDEX_UNIQUE,    ['occurrenceid', 'userid']);
        $table->add_index('idx_instance_user',   XMLDB_INDEX_NOTUNIQUE, ['instanceid', 'userid']);

        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        upgrade_mod_savepoint(true, 2026070700, 'msteamsecp');
    }

    return true;
}
