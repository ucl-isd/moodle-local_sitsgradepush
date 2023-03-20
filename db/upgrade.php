<?php
// This file is part of Moodle - https://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

/**
 * Plugin upgrade steps are defined here.
 *
 * @package     local_sitsgradepush
 * @category    upgrade
 * @copyright   2023 onwards University College London {@link https://www.ucl.ac.uk/}
 * @license     https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @author      Alex Yeung <k.yeung@ucl.ac.uk>
 */

/**
 * Execute local_sitsgradepush upgrade from the given old version.
 *
 * @param int $oldversion
 * @return bool
 */
function xmldb_local_sitsgradepush_upgrade($oldversion) {
    global $DB;
    $dbman = $DB->get_manager();

    if ($oldversion < 2022030902) {

        // Define field marks to be dropped from local_sitsgradepush_tfr_log.
        $table = new xmldb_table('local_sitsgradepush_tfr_log');

        $field = new xmldb_field('marks');
        // Conditionally launch drop field marks.
        if ($dbman->field_exists($table, $field)) {
            $dbman->drop_field($table, $field);
        }

        $field = new xmldb_field('grade');
        // Conditionally launch drop field grade.
        if ($dbman->field_exists($table, $field)) {
            $dbman->drop_field($table, $field);
        }

        $index = new xmldb_index('coursemoduleid_responsecode_idx', XMLDB_INDEX_NOTUNIQUE, ['coursemoduleid', 'responsecode']);
        // Conditionally launch drop index coursemoduleid_responsecode_idx.
        if ($dbman->index_exists($table, $index)) {
            $dbman->drop_index($table, $index);
        }

        $index = new xmldb_index('am_rc_idx', XMLDB_INDEX_NOTUNIQUE, ['assessmentmappingid', 'responsecode']);
        // Conditionally launch drop index am_rc_idx.
        if ($dbman->index_exists($table, $index)) {
            $dbman->drop_index($table, $index);
        }

        $field = new xmldb_field('responsecode');
        // Conditionally launch drop field responsecode.
        if ($dbman->field_exists($table, $field)) {
            $dbman->drop_field($table, $field);
        }

        $field = new xmldb_field('type', XMLDB_TYPE_CHAR, '20', null, XMLDB_NOTNULL, null, null, 'id');
        // Conditionally launch add field type.
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        $field = new xmldb_field('requestbody', XMLDB_TYPE_TEXT, null, null, null, null, null, 'componentgradeid');
        // Conditionally launch add field requestbody.
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        $field = new xmldb_field('response', XMLDB_TYPE_TEXT, null, null, null, null, null, 'requestbody');
        // Conditionally launch add field response.
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        // Sitsgradepush savepoint reached.
        upgrade_plugin_savepoint(true, 2022030902, 'local', 'sitsgradepush');
    }

    if ($oldversion < 2022032701) {

        // Define field examroomcode to be added to local_sitsgradepush_mab.
        $table = new xmldb_table('local_sitsgradepush_mab');
        $field = new xmldb_field('examroomcode', XMLDB_TYPE_CHAR, '30', null, null, null, null, 'mabname');

        // Conditionally launch add field examroomcode.
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        // Sitsgradepush savepoint reached.
        upgrade_plugin_savepoint(true, 2022032701, 'local', 'sitsgradepush');
    }

    return true;
}
