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

namespace local_sitsgradepush;

use ReflectionClass;

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

        // Skip if the table is not empty.
        if ($DB->count_records('local_sitsgradepush_mab') > 0) {
            return;
        }
        $mab = file_get_contents($CFG->dirroot . "/local/sitsgradepush/tests/fixtures/local_sitsgradepush_mab.json");
        $mab = json_decode($mab, true);
        $DB->insert_records('local_sitsgradepush_mab', $mab);
    }

    /**
     * Return the testing module occurrences data.
     *
     * @param int $courseid
     *
     * @return array
     * @throws \dml_exception
     */
    public static function get_modocc_data(int $courseid): array {
        global $DB;
        $course = $DB->get_record('course', ['id' => $courseid]);

        // Return nothing for test course 2.
        if ($course->shortname == 'C2') {
            return [];
        }

        // Get module occurrences data from JSON file.
        $modoccs = file_get_contents(__DIR__ . "/sits_moduleoccurence.json");
        $modoccsarray = json_decode($modoccs);

        // Set cache, so the get component grade API is not called.
        foreach ($modoccsarray as $modocc) {
            $key = implode(
                '_',
                [
                    cachemanager::CACHE_AREA_COMPONENTGRADES,
                    $modocc->mod_code,
                    $modocc->mod_occ_mav,
                    $modocc->mod_occ_psl_code,
                    $modocc->mod_occ_year_code,
                ]
            );
            // Replace '/' with '_' for simple key.
            $key = str_replace('/', '_', $key);
            cachemanager::set_cache(cachemanager::CACHE_AREA_COMPONENTGRADES, $key, 'dummy_response', 3600);
        }

        return $modoccsarray;
    }

    /**
     * Get the behat test students' data.
     *
     * @param  string $mapcode
     * @param  string $mabseq
     *
     * @return array
     */
    public static function get_behat_test_students_response(string $mapcode, string $mabseq): array {
        $students = file_get_contents(__DIR__ . "/behat_test_students.json");
        $students = json_decode($students, true);
        $students = $students[$mapcode][$mabseq];

        return !empty($students) ? $students : [];
    }

    /**
     * Get behat push grade response.
     *
     * @return array
     * @throws \coding_exception
     */
    public static function get_behat_push_grade_response(): array {
        return ["code" => 0, "message" => get_string('msg:gradepushsuccess', 'sitsapiclient_easikit')];
    }

    /**
     * Get behat push submission log response.
     *
     * @return array
     * @throws \coding_exception
     */
    public static function get_behat_submission_log_response(): array {
        return ["code" => 0, "message" => get_string('msg:submissionlogpushsuccess', 'sitsapiclient_easikit')];
    }

    /**
     * Get the EC event data.
     *
     * @return string
     */
    public static function get_ec_event_data(): string {
        return file_get_contents(__DIR__ . "/ec/ec_event_data.json");
    }

    /**
     * Get the SORA event data.
     *
     * @param string $astcode
     * @return string
     */
    public static function get_sora_event_data(string $astcode = 'CN01'): string {
        return file_get_contents(__DIR__ . "/raa/raa_event_data_{$astcode}.json");
    }

    /**
     * Get the SORA event data with non-approved status.
     * This event is received when a RAA status changed from approved to non-approved.
     *
     * @param bool $approved
     * @return string
     */
    public static function get_sora_event_data_status(bool $approved): string {
        if ($approved) {
            return file_get_contents(__DIR__ . "/raa/raa_event_data_status_approved.json");
        }
        return file_get_contents(__DIR__ . "/raa/raa_event_data_status_non_approved.json");
    }

    /**
     * Get the SORA testing student data.
     *
     * @param string $assessmenttype
     * @return array
     */
    public static function get_sora_testing_student_data(string $assessmenttype = 'CN01'): array {
        return json_decode(file_get_contents(__DIR__ . "/raa/raa_test_students_{$assessmenttype}.json"), true);
    }

    /**
     * Get the EC testing student data.
     *
     * @param string $stdnum
     * @return array
     */
    public static function get_ec_testing_student_data(string $stdnum = ''): array {
        return json_decode(file_get_contents(__DIR__ . "/ec/ec_test_students{$stdnum}.json"), true);
    }

    /**
     * Get the testing student data with both RAA and EC extensions.
     *
     * @return array
     */
    public static function get_test_students_with_both_extensions(): array {
        return json_decode(file_get_contents(__DIR__ . "/test_students_with_both_extensions.json"), true);
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
