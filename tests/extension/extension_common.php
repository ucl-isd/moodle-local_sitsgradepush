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

use core\clock;

defined('MOODLE_INTERNAL') || die();
global $CFG;
require_once($CFG->dirroot . '/local/sitsgradepush/tests/fixtures/tests_data_provider.php');
require_once($CFG->dirroot . '/local/sitsgradepush/tests/base_test_class.php');

/**
 * Base class for extension tests.
 *
 * @package    local_sitsgradepush
 * @copyright  2024 onwards University College London {@link https://www.ucl.ac.uk/}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @author     Alex Yeung <k.yeung@ucl.ac.uk>
 */
class extension_common extends base_test_class {
    /** @var \stdClass $course1 Default test course 1 */
    protected \stdClass $course1;

    /** @var \stdClass Default test student 1 */
    protected \stdClass $student1;

    /** @var \stdClass Default test assignment 1 */
    protected \stdClass $assign1;

    /** @var \stdClass Default test quiz 1*/
    protected \stdClass $quiz1;

    /** @var \stdClass Default test coursework 1*/
    protected ?\stdClass $coursework1;

    /** @var clock $clock */
    protected readonly clock $clock;

    /**
     * Set up the test.
     *
     * @return void
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();

        // Set admin user.
        $this->setAdminUser();

        // Get data generator.
        $dg = $this->getDataGenerator();

        // Mock the clock.
        $this->clock = $this->mock_clock_with_frozen(strtotime('2025-02-10 09:00:00')); // Current time 2025-02-10 09:00:00.

        // Set Easikit API client.
        set_config('apiclient', 'easikit', 'local_sitsgradepush');

        // Setup testing environment.
        set_config('late_summer_assessment_end_2025', '2025-11-30', 'block_lifecycle');

        // Enable the extension.
        set_config('extension_enabled', '1', 'local_sitsgradepush');

        // Create a custom category and custom field.
        $dg->create_custom_field_category(['name' => 'CLC']);
        $dg->create_custom_field(['category' => 'CLC', 'shortname' => 'course_year']);

        // Create test courses.
        $this->course1 = $dg->create_course(
            ['shortname' => 'C1', 'customfields' => [
                ['shortname' => 'course_year', 'value' => $this->clock->now()->format('Y')],
            ]]
        );
        $this->student1 = $dg->create_user(['idnumber' => '12345678']);
        $dg->enrol_user($this->student1->id, $this->course1->id, 'student');

        $assessmentstartdate = strtotime('2025-02-17 09:00:00'); // Start date: 2025-02-17 09:00:00.
        $assessmentenddate = strtotime('2025-02-17 12:00:00'); // End date: 2025-02-17 12:00:00.

        // Create test assignment 1.
        $this->assign1 = $dg->create_module(
            'assign',
            [
                'name' => 'Test Assignment 1',
                'course' => $this->course1->id,
                'allowsubmissionsfromdate' => $assessmentstartdate,
                'duedate' => $assessmentenddate,
            ]
        );

        // Create test quiz 1.
        $this->quiz1 = $dg->create_module(
            'quiz',
            [
                'course' => $this->course1->id,
                'name' => 'Test Quiz 1',
                'timeopen' => $assessmentstartdate,
                'timeclose' => $assessmentenddate,
            ]
        );

        $courseworkpluginexists = \core_component::get_component_directory('mod_coursework');
        // Create test coursework 1 if coursework is installed.
        $this->coursework1 = $courseworkpluginexists
            ? $dg->create_module('coursework',
                [
                    'name' => 'Test Coursework 1',
                    'course' => $this->course1->id,
                    'startdate' => $assessmentstartdate,
                    'deadline' => $assessmentenddate,
                    'personaldeadlineenabled' => 1,
                ]
            )
            : null;

        // Set up the SITS grade push.
        $this->setup_sitsgradepush();
    }

    /**
     * Set up the SITS grade push.
     *
     * @return void
     * @throws \dml_exception|\coding_exception
     */
    protected function setup_sitsgradepush(): void {
        // Insert MABs.
        tests_data_provider::import_sitsgradepush_grade_components();
    }

    /**
     * Insert a test mapping.
     *
     * @param int $mabid
     * @param int $courseid
     * @param \stdClass $assessment
     * @param string $modtype
     * @param int $reassess
     * @return bool|int
     * @throws \dml_exception
     */
    protected function insert_mapping(
        int $mabid,
        int $courseid,
        \stdClass $assessment,
        string $modtype,
        int $reassess = 0
    ): bool|int {
        global $DB;

        return $DB->insert_record('local_sitsgradepush_mapping', [
            'courseid' => $courseid,
            'sourceid' => $assessment->cmid,
            'sourcetype' => 'mod',
            'moduletype' => $modtype,
            'componentgradeid' => $mabid,
            'reassessment' => $reassess,
            'enableextension' => extensionmanager::is_extension_enabled() ? 1 : 0,
            'timecreated' => $this->clock->now()->modify('-3 days')->getTimestamp(),
            'timemodified' => $this->clock->now()->modify('-3 days')->getTimestamp(),
        ]);
    }

    /**
     * Delete all mappings for the course
     */
    protected function delete_all_mappings(): void {
        $mappings = manager::get_manager()->get_assessment_mappings_by_courseid($this->course1->id);
        foreach ($mappings as $mapping) {
            manager::get_manager()->remove_mapping($this->course1->id, $mapping->id);
        }
    }

    /**
     * Create a past year course
     *
     * @return object The course object
     */
    protected function create_past_year_course(): object {
        return $this->getDataGenerator()->create_course(
            ['shortname' => 'C2', 'customfields' => [
                ['shortname' => 'course_year', 'value' => $this->clock->now()->modify('-1 year')->format('Y')],
            ]]
        );
    }
}
