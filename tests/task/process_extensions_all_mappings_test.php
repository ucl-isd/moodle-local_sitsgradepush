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

namespace local_sitsgradepush\task;

use local_sitsgradepush\extension_common;
use local_sitsgradepush\manager;
use local_sitsgradepush\tests_data_provider;

defined('MOODLE_INTERNAL') || die();
global $CFG;
require_once($CFG->dirroot . '/local/sitsgradepush/tests/fixtures/tests_data_provider.php');
require_once($CFG->dirroot . '/local/sitsgradepush/tests/extension/extension_common.php');

/**
 * Tests for the process_extensions_all_mappings adhoc task.
 *
 * @package    local_sitsgradepush
 * @copyright  2026 onwards University College London {@link https://www.ucl.ac.uk/}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @author     Alex Yeung <k.yeung@ucl.ac.uk>
 * @covers     \local_sitsgradepush\task\process_extensions_all_mappings
 */
final class process_extensions_all_mappings_test extends extension_common {
    /**
     * Set up the test.
     *
     * @return void
     */
    protected function setUp(): void {
        parent::setUp();

        // Set up mock manager with students having both extensions.
        $this->setup_mock_manager(
            tests_data_provider::get_test_students_with_both_extensions()
        );
    }

    /**
     * Tear down the test.
     *
     * @return void
     */
    public function tearDown(): void {
        parent::tearDown();
        $this->reset_manager_instance();
    }

    /**
     * Test execute with RAA extension type only.
     *
     * @covers \local_sitsgradepush\task\process_extensions_all_mappings::execute
     * @return void
     */
    public function test_execute_raa_only(): void {
        $mab = $this->get_mab_by_mapcode('LAWS0024A6UF', '002');
        $this->insert_mapping($mab->id, $this->course1->id, $this->assign1, 'assign');

        $this->run_task($this->course1->id, 'raa');

        $this->assert_raa_overrides_exist($this->assign1->id);
        $this->assert_ec_overrides_empty($this->assign1->id);
    }

    /**
     * Test execute with EC extension type only.
     *
     * @covers \local_sitsgradepush\task\process_extensions_all_mappings::execute
     * @return void
     */
    public function test_execute_ec_only(): void {
        $mab = $this->get_mab_by_mapcode('LAWS0024A6UF', '002');
        $this->insert_mapping($mab->id, $this->course1->id, $this->assign1, 'assign');

        $this->run_task($this->course1->id, 'ec');

        $this->assert_ec_overrides_exist($this->assign1->id);
        $this->assert_raa_overrides_empty($this->assign1->id);
    }

    /**
     * Test execute with both extension types.
     *
     * @covers \local_sitsgradepush\task\process_extensions_all_mappings::execute
     * @return void
     */
    public function test_execute_both_types(): void {
        $mab = $this->get_mab_by_mapcode('LAWS0024A6UF', '002');
        $this->insert_mapping($mab->id, $this->course1->id, $this->assign1, 'assign');

        $this->run_task($this->course1->id, 'both');

        $this->assert_ec_overrides_exist($this->assign1->id);
        $this->assert_raa_overrides_exist($this->assign1->id);
    }

    /**
     * Test execute filters by course ID.
     *
     * @covers \local_sitsgradepush\task\process_extensions_all_mappings::execute
     * @return void
     */
    public function test_execute_filters_by_courseid(): void {
        [$course2, $assign2] = $this->create_second_course_with_assignment();

        $mab1 = $this->get_mab_by_mapcode('LAWS0024A6UF', '002');
        $mab2 = $this->get_mab_by_mapcode('MSIN0047A7PF', '002');

        $this->insert_mapping($mab1->id, $this->course1->id, $this->assign1, 'assign');
        $this->insert_mapping($mab2->id, $course2->id, $assign2, 'assign');

        $this->run_task($this->course1->id, 'both');

        $this->assert_overrides_exist($this->assign1->id);
        $this->assert_overrides_empty($assign2->id);
    }

    /**
     * Test execute processes all courses when courseid is 0.
     *
     * @covers \local_sitsgradepush\task\process_extensions_all_mappings::execute
     * @return void
     */
    public function test_execute_all_courses(): void {
        [$course2, $assign2] = $this->create_second_course_with_assignment();

        $mab1 = $this->get_mab_by_mapcode('LAWS0024A6UF', '002');
        $mab2 = $this->get_mab_by_mapcode('MSIN0047A7PF', '002');

        $this->insert_mapping($mab1->id, $this->course1->id, $this->assign1, 'assign');
        $this->insert_mapping($mab2->id, $course2->id, $assign2, 'assign');

        $this->run_task(0, 'both');

        $this->assert_overrides_exist($this->assign1->id);
        $this->assert_overrides_exist($assign2->id);
    }

    /**
     * Test no follow-up task queued when under batch limit.
     *
     * @covers \local_sitsgradepush\task\process_extensions_all_mappings::execute
     * @return void
     */
    public function test_execute_no_followup_under_batch_limit(): void {
        global $DB;

        $mab = $this->get_mab_by_mapcode('LAWS0024A6UF', '002');

        // Insert 5 mappings (well under the 30 batch limit).
        for ($i = 0; $i < 5; $i++) {
            $assign = $this->getDataGenerator()->create_module('assign', [
                'name' => "Test Assignment Batch $i",
                'course' => $this->course1->id,
                'duedate' => strtotime('2025-02-17 12:00:00'),
            ]);
            $this->insert_mapping($mab->id, $this->course1->id, $assign, 'assign');
        }

        $task = new process_extensions_all_mappings();
        $task->set_custom_data((object)[
            'courseid' => $this->course1->id,
            'extensiontype' => 'both',
            'lastprocessedid' => 0,
        ]);
        $task->execute();

        // Verify no follow-up task was queued.
        $followup = $DB->get_records('task_adhoc', [
            'classname' => '\\local_sitsgradepush\\task\\process_extensions_all_mappings',
        ]);
        $this->assertEmpty($followup);
    }

    /**
     * Test follow-up task queued when over batch limit.
     *
     * @covers \local_sitsgradepush\task\process_extensions_all_mappings::execute
     * @return void
     */
    public function test_execute_queues_followup_over_batch_limit(): void {
        global $DB;

        $mab = $this->get_mab_by_mapcode('LAWS0024A6UF', '002');

        // Insert 32 mappings (over the 30 batch limit).
        for ($i = 0; $i < 32; $i++) {
            $assign = $this->getDataGenerator()->create_module('assign', [
                'name' => "Test Assignment Batch $i",
                'course' => $this->course1->id,
                'duedate' => strtotime('2025-02-17 12:00:00'),
            ]);
            $this->insert_mapping($mab->id, $this->course1->id, $assign, 'assign');
        }

        $task = new process_extensions_all_mappings();
        $task->set_custom_data((object)[
            'courseid' => $this->course1->id,
            'extensiontype' => 'both',
            'lastprocessedid' => 0,
        ]);
        $task->execute();

        // Verify a follow-up task was queued.
        $followup = $DB->get_records('task_adhoc', [
            'classname' => '\\local_sitsgradepush\\task\\process_extensions_all_mappings',
        ]);
        $this->assertCount(1, $followup);
    }

    /**
     * Test execute continues processing after a mapping failure.
     *
     * @covers \local_sitsgradepush\task\process_extensions_all_mappings::execute
     * @return void
     */
    public function test_execute_continues_on_mapping_failure(): void {
        global $DB;

        [$course2, $assign2] = $this->create_second_course_with_assignment();

        $mab1 = $this->get_mab_by_mapcode('LAWS0024A6UF', '002');
        $mab2 = $this->get_mab_by_mapcode('MSIN0047A7PF', '002');

        // Insert two mappings.
        $this->insert_mapping($mab1->id, $this->course1->id, $this->assign1, 'assign');
        $this->insert_mapping($mab2->id, $course2->id, $assign2, 'assign');

        // Get the first mapping.
        $mapping1 = $DB->get_record('local_sitsgradepush_mapping', ['courseid' => $this->course1->id], 'id');
        $firstmapid = $mapping1->id;

        // Get the second mapping.
        $mapping2 = $DB->get_record('local_sitsgradepush_mapping', ['courseid' => $course2->id], 'id');
        $secondmapdata = manager::get_manager()->get_mab_and_map_info_by_mapping_id($mapping2->id);
        $studentdata = tests_data_provider::get_test_students_with_both_extensions();
        $this->reset_manager_instance();

        // Mock the manager to return false for the first mapping, causing an exception.
        // Return valid data for the second mapping.
        $mockmanager = $this->getMockBuilder(manager::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['get_mab_and_map_info_by_mapping_id', 'get_students_from_sits'])
            ->getMock();

        $mockmanager->method('get_mab_and_map_info_by_mapping_id')
            ->willReturnCallback(function ($id) use ($firstmapid, $secondmapdata) {
                if ($id == $firstmapid) {
                    return false;
                }
                return $secondmapdata;
            });

        $mockmanager->method('get_students_from_sits')
            ->willReturnCallback(function ($id) use ($firstmapid, $studentdata) {
                if ($id == $firstmapid) {
                    return [];
                }
                return $studentdata;
            });

        $this->set_manager_instance($mockmanager);
        $this->run_task(0, 'both');

        // Verify an error was logged for the first mapping.
        $errors = $DB->get_records('local_sitsgradepush_err_log');
        $this->assertNotEmpty($errors);

        $errorlogged = false;
        foreach ($errors as $error) {
            if (str_contains($error->data ?? '', "Mapping ID: {$firstmapid}")) {
                $errorlogged = true;
                break;
            }
        }
        $this->assertTrue($errorlogged, 'Error should be logged for the failed mapping.');

        // Verify the second mapping was processed successfully.
        $this->assert_overrides_exist($assign2->id);
    }

    /**
     * Setup mock manager with student data.
     *
     * @param array $students The student data to return.
     * @return void
     */
    protected function setup_mock_manager(array $students = []): void {
        $mockmanager = $this->getMockBuilder(manager::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['get_students_from_sits'])
            ->getMock();
        $mockmanager->method('get_students_from_sits')
            ->willReturn($students);

        $this->set_manager_instance($mockmanager);
    }

    /**
     * Set manager singleton instance via reflection.
     *
     * @param manager|null $manager The manager instance to set.
     * @return void
     */
    protected function set_manager_instance(?manager $manager): void {
        $managerreflection = new \ReflectionClass(manager::class);
        $instance = $managerreflection->getProperty('instance');
        $instance->setAccessible(true);
        $instance->setValue(null, $manager);
    }

    /**
     * Reset manager singleton instance.
     *
     * @return void
     */
    protected function reset_manager_instance(): void {
        $this->set_manager_instance(null);
    }

    /**
     * Get MAB record by mapcode and mabseq.
     *
     * @param string $mapcode The mapcode.
     * @param string $mabseq The mabseq.
     * @return object The MAB record.
     */
    protected function get_mab_by_mapcode(string $mapcode, string $mabseq): object {
        global $DB;
        return $DB->get_record('local_sitsgradepush_mab', ['mapcode' => $mapcode, 'mabseq' => $mabseq]);
    }

    /**
     * Create and execute task with given parameters.
     *
     * @param int $courseid The course ID.
     * @param string $extensiontype The extension type.
     * @param int $lastprocessedid The last processed ID.
     * @return void
     */
    protected function run_task(int $courseid, string $extensiontype, int $lastprocessedid = 0): void {
        $task = new process_extensions_all_mappings();
        $task->set_custom_data((object)[
            'courseid' => $courseid,
            'extensiontype' => $extensiontype,
            'lastprocessedid' => $lastprocessedid,
        ]);
        $task->execute();
    }

    /**
     * Assert RAA group overrides exist for assignment.
     *
     * @param int $assignid The assignment ID.
     * @return void
     */
    protected function assert_raa_overrides_exist(int $assignid): void {
        global $DB;
        $overrides = $DB->get_records('assign_overrides', ['assignid' => $assignid, 'userid' => null]);
        $this->assertNotEmpty($overrides, 'RAA group overrides should exist.');
    }

    /**
     * Assert no RAA group overrides exist for assignment.
     *
     * @param int $assignid The assignment ID.
     * @return void
     */
    protected function assert_raa_overrides_empty(int $assignid): void {
        global $DB;
        $overrides = $DB->get_records('assign_overrides', ['assignid' => $assignid, 'userid' => null]);
        $this->assertEmpty($overrides, 'RAA group overrides should not exist.');
    }

    /**
     * Assert EC user overrides exist for assignment.
     *
     * @param int $assignid The assignment ID.
     * @return void
     */
    protected function assert_ec_overrides_exist(int $assignid): void {
        global $DB;
        $overrides = $DB->get_records('assign_overrides', ['assignid' => $assignid, 'groupid' => null]);
        $this->assertNotEmpty($overrides, 'EC user overrides should exist.');
    }

    /**
     * Assert no EC user overrides exist for assignment.
     *
     * @param int $assignid The assignment ID.
     * @return void
     */
    protected function assert_ec_overrides_empty(int $assignid): void {
        global $DB;
        $overrides = $DB->get_records('assign_overrides', ['assignid' => $assignid, 'groupid' => null]);
        $this->assertEmpty($overrides, 'EC user overrides should not exist.');
    }

    /**
     * Assert any overrides exist for assignment.
     *
     * @param int $assignid The assignment ID.
     * @return void
     */
    protected function assert_overrides_exist(int $assignid): void {
        global $DB;
        $overrides = $DB->get_records('assign_overrides', ['assignid' => $assignid]);
        $this->assertNotEmpty($overrides, 'Overrides should exist for assignment.');
    }

    /**
     * Assert no overrides exist for assignment.
     *
     * @param int $assignid The assignment ID.
     * @return void
     */
    protected function assert_overrides_empty(int $assignid): void {
        global $DB;
        $overrides = $DB->get_records('assign_overrides', ['assignid' => $assignid]);
        $this->assertEmpty($overrides, 'No overrides should exist for assignment.');
    }

    /**
     * Create a second course with assignment and enrolments.
     *
     * @return array Array containing course and assignment objects.
     */
    protected function create_second_course_with_assignment(): array {
        $course2 = $this->getDataGenerator()->create_course([
            'shortname' => 'C2',
            'customfields' => [['shortname' => 'course_year', 'value' => $this->clock->now()->format('Y')]],
        ]);

        $this->getDataGenerator()->enrol_user($this->student2->id, $course2->id, 'student');

        $assign2 = $this->getDataGenerator()->create_module('assign', [
            'name' => 'Test Assignment 2',
            'course' => $course2->id,
            'duedate' => strtotime('2025-02-17 12:00:00'),
        ]);

        return [$course2, $assign2];
    }
}
