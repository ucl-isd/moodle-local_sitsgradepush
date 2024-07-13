<?php
// This file is part of Moodle - http://moodle.org/
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
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

namespace local_sitsgradepush\tests\fixtures;

use local_sitsgradepush\cachemanager;
use ReflectionClass;
use xmldb_table;

/**
 * Class tests_data_provider, used to provide data for tests.
 *
 * @package    local_sitsgradepush
 * @copyright  2024 onwards University College London {@link https://www.ucl.ac.uk/}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @author     Alex Yeung <k.yeung@ucl.ac.uk>
 */
class tests_data_provider {

    /**
     * Return SITS marking scheme data.
     *
     * @return mixed
     */
    public static function get_sits_marking_scheme_data() {
        global $CFG;
        $markingschemedata = file_get_contents($CFG->dirroot . "/local/sitsgradepush/tests/fixtures/sits_marking_scheme.json");
        return json_decode($markingschemedata, true);
    }

    /**
     * Return SITS component grades data.
     *
     * @return mixed
     */
    public static function get_sits_component_grades_data() {
        global $CFG;
        $componentgradesdata = file_get_contents($CFG->dirroot . "/local/sitsgradepush/tests/fixtures/sits_component_grades.json");
        return json_decode($componentgradesdata, true);
    }

    /**
     * Return test data for testing method sort_grade_push_history_table.
     *
     * @return mixed
     */
    public static function get_sort_grade_push_history_table_data() {
        global $CFG;
        $data = file_get_contents(
          $CFG->dirroot . "/local/sitsgradepush/tests/fixtures/test_sort_grade_push_history_table.json"
        );
        return json_decode($data, true);
    }

    /**
     * Set marking scheme data cache.
     *
     * @return void
     */
    public static function set_marking_scheme_data() {
        global $CFG;
        $markingschemedata = file_get_contents($CFG->dirroot . "/local/sitsgradepush/tests/fixtures/sits_marking_scheme.json");
        $markingschemedata = json_decode($markingschemedata, true);
        cachemanager::set_cache(cachemanager::CACHE_AREA_MARKINGSCHEMES, 'markingschemes', $markingschemedata, 3600);
    }

    /**
     * Import SITS component grades data into the database.
     *
     * @return void
     * @throws \coding_exception
     * @throws \dml_exception
     */
    public static function import_sitsgradepush_grade_components() {
        global $CFG, $DB;
        $mab = file_get_contents($CFG->dirroot . "/local/sitsgradepush/tests/fixtures/local_sitsgradepush_mab.json");
        $mab = json_decode($mab, true);
        $DB->insert_records('local_sitsgradepush_mab', $mab);
    }

    /**
     * Create MIM tables.
     *
     * @return void
     * @throws \ddl_exception
     */
    public static function create_mim_tables() {
        global $CFG, $DB;

        // Create MIM tables.
        $dbman = $DB->get_manager();

        // Remove the prefix from DB manager.
        $dbman->generator->prefix = "";

        // Get all tables.
        $tables = $DB->get_tables(false);

        $table = new xmldb_table('sits_moduleoccurence_mapping');
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('mod_occ_bdo_id', XMLDB_TYPE_CHAR, '50', null, null, null, null);
        $table->add_field('mod_code', XMLDB_TYPE_CHAR, '12', null, null, null, null);
        $table->add_field('reg_status', XMLDB_TYPE_CHAR, '3', null, null, null, 'APP');
        $table->add_field('vle_idnumber', XMLDB_TYPE_CHAR, '255', null, null, null, null);
        $table->add_field('vle_courseid', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
        $table->add_field('mapping_action', XMLDB_TYPE_CHAR, '3', null, null, null, null);
        $table->add_field('group_import', XMLDB_TYPE_CHAR, '3', null, null, null, 'NA');
        $table->add_field('last_updated', XMLDB_TYPE_DATETIME, null, null, null, null, null);

        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);

        if (!isset($tables['sits_moduleoccurence_mapping'])) {
            $dbman->create_table($table);
        }

        $table = new xmldb_table('sits_moduleoccurence');
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('mod_occ_bdo_id', XMLDB_TYPE_CHAR, '50', null, null, null, null);
        $table->add_field('mod_inst_bdo_id', XMLDB_TYPE_CHAR, '50', null, null, null, null);
        $table->add_field('mod_code', XMLDB_TYPE_CHAR, '12', null, null, null, null);
        $table->add_field('mod_occ_psl_code', XMLDB_TYPE_CHAR, '6', null, null, null, null);
        $table->add_field('mod_occ_mav', XMLDB_TYPE_CHAR, '6', null, null, null, null);
        $table->add_field('mod_occ_year_code', XMLDB_TYPE_CHAR, '12', null, null, null, null);
        $table->add_field('mod_occ_name', XMLDB_TYPE_TEXT, null, null, null, null, null);

        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);

        if (!isset($tables['sits_moduleoccurence_mapping'])) {
            $dbman->create_table($table);
        }

        $dbman->generator->prefix = $CFG->prefix;
    }

    /**
     * Import data into MIM tables.
     *
     * @return void
     * @throws \coding_exception
     * @throws \dml_exception
     */
    public static function import_data_into_mim_tables() {
        global $CFG, $DB;

        // Get the course id.
        $courseid = $DB->get_field('course', 'id', ['shortname' => 'C1']);

        self::set_protected_property($DB, 'prefix', "");

        // Insert sits mapping statuses data.
        $sitsmoduleoccurencemappings =
          file_get_contents($CFG->dirroot . "/local/sitsgradepush/tests/fixtures/sits_moduleoccurence_mapping.json");
        $sitsmoduleoccurencemappings = json_decode($sitsmoduleoccurencemappings, true);

        foreach ($sitsmoduleoccurencemappings as &$sitsmoduleoccurencemapping) {
            $sitsmoduleoccurencemapping['vle_courseid'] = $courseid;
        }

        $DB->insert_records('sits_moduleoccurence_mapping', $sitsmoduleoccurencemappings);

        // Insert sits module occurrences data.
        $sitsmoduleoccurences =
          file_get_contents($CFG->dirroot . "/local/sitsgradepush/tests/fixtures/sits_moduleoccurence.json");

        $sitsmoduleoccurences = json_decode($sitsmoduleoccurences, true);

        // Set cache.
        foreach ($sitsmoduleoccurences as $sitsmoduleoccurence) {
            $key = implode('_',
              [
                cachemanager::CACHE_AREA_COMPONENTGRADES,
                $sitsmoduleoccurence['mod_code'],
                $sitsmoduleoccurence['mod_occ_mav'],
                $sitsmoduleoccurence['mod_occ_psl_code'],
                $sitsmoduleoccurence['mod_occ_year_code'],
              ]
            );
            // Replace '/' with '_' for simple key.
            $key = str_replace('/', '_', $key);
            cachemanager::set_cache(cachemanager::CACHE_AREA_COMPONENTGRADES, $key, 'dummy_response', 3600);
        }

        $DB->insert_records('sits_moduleoccurence', $sitsmoduleoccurences);

        // Set the prefix back.
        self::set_protected_property($DB, 'prefix', $CFG->prefix);
    }

    /**
     * Tear down MIM tables.
     *
     * @return void
     * @throws \ddl_exception
     * @throws \ddl_table_missing_exception
     */
    public static function tear_down_mim_tables() {
        global $CFG, $DB;

        $dbman = $DB->get_manager();
        $dbman->generator->prefix = "";
        self::set_protected_property($DB, 'prefix', "");

        // Get all tables.
        $tables = $DB->get_tables(false);

        $table = new xmldb_table('sits_moduleoccurence');
        if (isset($tables['sits_moduleoccurence'])) {
            $dbman->drop_table($table);
        }

        $table = new xmldb_table('sits_moduleoccurence_mapping');
        if (isset($tables['sits_moduleoccurence_mapping'])) {
            $dbman->drop_table($table);
        }

        $dbman->generator->prefix = $CFG->prefix;
        self::set_protected_property($DB, 'prefix', $CFG->prefix);
    }

    /**
     * Set a protected property.
     *
     * @param  object|string  $obj
     * @param  string  $prop
     * @param  mixed  $val
     *
     * @return void
     * @throws \ReflectionException
     */
    public static function set_protected_property(object|string $obj, string $prop, mixed $val): void {
        $reflection = new ReflectionClass($obj);
        $property = $reflection->getProperty($prop);
        $property->setValue($obj, $val);
    }
}
