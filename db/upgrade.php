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

    if ($oldversion < 2023030902) {

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
        upgrade_plugin_savepoint(true, 2023030902, 'local', 'sitsgradepush');
    }

    if ($oldversion < 2023032800) {

        // Define table local_sitsgradepush_err_log to be created.
        $table = new xmldb_table('local_sitsgradepush_err_log');

        // Adding fields to table local_sitsgradepush_err_log.
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('message', XMLDB_TYPE_TEXT, null, null, XMLDB_NOTNULL, null, null);
        $table->add_field('requesturl', XMLDB_TYPE_CHAR, '255', null, null, null, null);
        $table->add_field('data', XMLDB_TYPE_TEXT, null, null, null, null, null);
        $table->add_field('userid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);

        // Adding keys to table local_sitsgradepush_err_log.
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);

        // Adding indexes to table local_sitsgradepush_err_log.
        $table->add_index('userid_idx', XMLDB_INDEX_NOTUNIQUE, ['userid']);

        // Conditionally launch create table for local_sitsgradepush_err_log.
        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        // Sitsgradepush savepoint reached.
        upgrade_plugin_savepoint(true, 2023032800, 'local', 'sitsgradepush');
    }

    if ($oldversion < 2023041700) {

        // Define field examroomcode to be added to local_sitsgradepush_mab.
        $table = new xmldb_table('local_sitsgradepush_mab');
        $field = new xmldb_field('examroomcode', XMLDB_TYPE_CHAR, '30', null, null, null, null, 'mabname');

        // Conditionally launch add field examroomcode.
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        // Define index type_idx (not unique) to be dropped form local_sitsgradepush_err_log.
        $table = new xmldb_table('local_sitsgradepush_err_log');
        $index = new xmldb_index('type_idx', XMLDB_INDEX_NOTUNIQUE, ['type']);

        // Conditionally launch drop index type_idx.
        if ($dbman->index_exists($table, $index)) {
            $dbman->drop_index($table, $index);
        }

        // Sitsgradepush savepoint reached.
        upgrade_plugin_savepoint(true, 2023041700, 'local', 'sitsgradepush');
    }

    if ($oldversion < 2023051200) {

        // Define index assessmentmappingid_idx (not unique) to be dropped form local_sitsgradepush_tfr_log.
        $table = new xmldb_table('local_sitsgradepush_tfr_log');
        $index = new xmldb_index('assessmentmappingid_idx', XMLDB_INDEX_NOTUNIQUE, ['assessmentmappingid']);

        // Conditionally launch drop index assessmentmappingid_idx.
        if ($dbman->index_exists($table, $index)) {
            $dbman->drop_index($table, $index);
        }

        $field = new xmldb_field('assessmentmappingid');

        // Conditionally launch drop field assessmentmappingid.
        if ($dbman->field_exists($table, $field)) {
            $dbman->drop_field($table, $field);
        }

        $field = new xmldb_field('componentgradeid');

        // Conditionally launch drop field componentgradeid.
        if ($dbman->field_exists($table, $field)) {
            $dbman->drop_field($table, $field);
        }

        // Sitsgradepush savepoint reached.
        upgrade_plugin_savepoint(true, 2023051200, 'local', 'sitsgradepush');
    }

    if ($oldversion < 2023051201) {

        // Define field errlogid to be added to local_sitsgradepush_tfr_log.
        $table = new xmldb_table('local_sitsgradepush_tfr_log');
        $field = new xmldb_field('errlogid', XMLDB_TYPE_INTEGER, '10', null, null, null, null, 'usermodified');

        // Conditionally launch add field errlogid.
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        // Sitsgradepush savepoint reached.
        upgrade_plugin_savepoint(true, 2023051201, 'local', 'sitsgradepush');
    }

    if ($oldversion < 2023051700) {

        // Define field response to be added to local_sitsgradepush_err_log.
        $table = new xmldb_table('local_sitsgradepush_err_log');
        $field = new xmldb_field('response', XMLDB_TYPE_TEXT, null, null, null, null, null, 'data');

        // Conditionally launch add field response.
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        // Sitsgradepush savepoint reached.
        upgrade_plugin_savepoint(true, 2023051700, 'local', 'sitsgradepush');
    }

    if ($oldversion < 2023051800) {

        // Define field request to be added to local_sitsgradepush_tfr_log.
        $table = new xmldb_table('local_sitsgradepush_tfr_log');
        $field = new xmldb_field('request', XMLDB_TYPE_TEXT, null, null, null, null, null, 'coursemoduleid');

        // Conditionally launch add field request.
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        // Sitsgradepush savepoint reached.
        upgrade_plugin_savepoint(true, 2023051800, 'local', 'sitsgradepush');
    }

    if ($oldversion < 2023052300) {

        // Define table local_sitsgradepush_tasks to be created.
        $table = new xmldb_table('local_sitsgradepush_tasks');

        // Adding fields to table local_sitsgradepush_tasks.
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('userid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('timescheduled', XMLDB_TYPE_INTEGER, '20', null, XMLDB_NOTNULL, null, null);
        $table->add_field('timeupdated', XMLDB_TYPE_INTEGER, '20', null, null, null, null);
        $table->add_field('status', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('coursemoduleid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('info', XMLDB_TYPE_TEXT, null, null, null, null, null);
        $table->add_field('errlogid', XMLDB_TYPE_INTEGER, '10', null, null, null, null);

        // Adding keys to table local_sitsgradepush_tasks.
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);

        // Adding indexes to table local_sitsgradepush_tasks.
        $table->add_index('idx_coursemoduleid', XMLDB_INDEX_NOTUNIQUE, ['coursemoduleid']);

        // Conditionally launch create table for local_sitsgradepush_tasks.
        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        // Sitsgradepush savepoint reached.
        upgrade_plugin_savepoint(true, 2023052300, 'local', 'sitsgradepush');
    }

    if ($oldversion < 2023060500) {

        // Define field errortype to be added to local_sitsgradepush_err_log.
        $table = new xmldb_table('local_sitsgradepush_err_log');
        $field = new xmldb_field('errortype', XMLDB_TYPE_INTEGER, '3', null, null, null, null, 'message');

        // Conditionally launch add field errortype.
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        // Sitsgradepush savepoint reached.
        upgrade_plugin_savepoint(true, 2023060500, 'local', 'sitsgradepush');
    }

    if ($oldversion < 2023103100) {

        // Define field assessmentmappingid to be added to local_sitsgradepush_tfr_log.
        $table = new xmldb_table('local_sitsgradepush_tfr_log');
        $field = new xmldb_field(
            'assessmentmappingid', XMLDB_TYPE_INTEGER, '10', null, null, null, null, 'coursemoduleid');

        // Conditionally launch add field assessmentmappingid.
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        // Patch transfer log.
        $DB->execute('
            UPDATE {local_sitsgradepush_tfr_log} t
            SET t.assessmentmappingid =
            (SELECT m.id FROM {local_sitsgradepush_mapping} m WHERE m.coursemoduleid = t.coursemoduleid)');

        // Launch change of nullability for field assessmentmappingid.
        $dbman->change_field_notnull($table, $field);

        // Sitsgradepush savepoint reached.
        upgrade_plugin_savepoint(true, 2023103100, 'local', 'sitsgradepush');
    }

    if ($oldversion < 2023110600) {

        // Define field coursemoduleid to be dropped from local_sitsgradepush_tfr_log.
        $table = new xmldb_table('local_sitsgradepush_tfr_log');
        $field = new xmldb_field('coursemoduleid');

        // Conditionally launch drop field coursemoduleid.
        if ($dbman->field_exists($table, $field)) {
            $dbman->drop_field($table, $field);
        }

        // Sitsgradepush savepoint reached.
        upgrade_plugin_savepoint(true, 2023110600, 'local', 'sitsgradepush');
    }

    if ($oldversion < 2023112400) {
        $table = new xmldb_table('local_sitsgradepush_tasks');

        // Define index idx_coursemoduleid (not unique) to be dropped form local_sitsgradepush_tasks.
        $index = new xmldb_index('idx_coursemoduleid', XMLDB_INDEX_NOTUNIQUE, ['coursemoduleid']);

        // Conditionally launch drop index idx_coursemoduleid.
        if ($dbman->index_exists($table, $index)) {
            $dbman->drop_index($table, $index);
        }

        // Define field assessmentmappingid to be added to local_sitsgradepush_tasks.
        $field = new xmldb_field('assessmentmappingid', XMLDB_TYPE_INTEGER, '10', null, null, null, null, 'status');

        // Conditionally launch add field assessmentmappingid.
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        // Patch task table.
        $tasks = $DB->get_records('local_sitsgradepush_tasks');
        if (!empty($tasks)) {
            foreach ($tasks as $task) {
                $mappings = $DB->get_records('local_sitsgradepush_mapping', ['coursemoduleid' => $task->coursemoduleid]);
                if (!empty($mappings)) {
                    foreach ($mappings as $mapping) {
                        $inserttask = new stdClass();
                        $inserttask->userid = $task->userid;
                        $inserttask->timescheduled = $task->timescheduled;
                        $inserttask->timeupdated = $task->timeupdated;
                        $inserttask->status = $task->status;
                        $inserttask->coursemoduleid = $task->coursemoduleid;
                        $inserttask->assessmentmappingid = $mapping->id;
                        $inserttask->info = $task->info;
                        $inserttask->errlogid = $task->errlogid;
                        $DB->insert_record('local_sitsgradepush_tasks', $inserttask);
                    }
                }
                $DB->delete_records('local_sitsgradepush_tasks', ['id' => $task->id]);
            }
        }

        // Launch change of nullability for field assessmentmappingid.
        $dbman->change_field_notnull($table, $field);

        // Define index assessmentmappingid_idx (not unique) to be added to local_sitsgradepush_tasks.
        $index = new xmldb_index('idx_assessmentmappingid', XMLDB_INDEX_NOTUNIQUE, ['assessmentmappingid']);

        // Conditionally launch add index idx_assessmentmappingid.
        if (!$dbman->index_exists($table, $index)) {
            $dbman->add_index($table, $index);
        }

        // Define field coursemoduleid to be dropped from local_sitsgradepush_tasks.
        $field = new xmldb_field('coursemoduleid');

        // Conditionally launch drop field coursemoduleid.
        if ($dbman->field_exists($table, $field)) {
            $dbman->drop_field($table, $field);
        }

        // Sitsgradepush savepoint reached.
        upgrade_plugin_savepoint(true, 2023112400, 'local', 'sitsgradepush');
    }

    if ($oldversion < 2023121900) {
        // Define field progress to be added to local_sitsgradepush_tasks.
        $table = new xmldb_table('local_sitsgradepush_tasks');
        $field = new xmldb_field('progress', XMLDB_TYPE_INTEGER, '3', null, null, null, null, 'assessmentmappingid');

        // Conditionally launch add field progress.
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        // Sitsgradepush savepoint reached.
        upgrade_plugin_savepoint(true, 2023121900, 'local', 'sitsgradepush');
    }

    return true;
}
