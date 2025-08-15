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
use local_sitsgradepush\event\assessment_mapped;

defined('MOODLE_INTERNAL') || die();
require_once(__DIR__ . '/base_test_class.php');

/**
 * Tests for the observer class.
 *
 * @package    local_sitsgradepush
 * @copyright  2025 onwards University College London {@link https://www.ucl.ac.uk/}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @author     Alex Yeung <k.yeung@ucl.ac.uk>
 */
final class observer_test extends base_test_class {

    /** @var \stdClass $course Test course */
    private \stdClass $course;

    /** @var \testing_data_generator Test data generator */
    private \testing_data_generator $dg;

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
        $this->dg = $this->getDataGenerator();

        // Set a frozen clock for testing.
        $this->clock = $this->mock_clock_with_frozen(strtotime('2025-08-13 09:00:00'));

        // Set default configurations.
        set_config('fetch_scn_enabled', '1', 'local_sitsgradepush');

        // Create test course.
        $this->course = $this->dg->create_course();
    }

    /**
     * Tear down the test.
     * @return void
     */
    protected function tearDown(): void {
        parent::tearDown();
        $managerreflection = new \ReflectionClass(manager::class);
        $instance = $managerreflection->getProperty('instance');
        $instance->setAccessible(true);
        $instance->setValue(null, null);
    }

    /**
     * Test that assessment_mapped event triggers fetch_candidate_numbers_task adhoc task.
     *
     * @covers \local_sitsgradepush_observer::assessment_mapped
     * @covers \local_sitsgradepush\taskmanager::add_fetch_candidate_numbers_task
     * @return void
     */
    public function test_assessment_mapped_creates_fetch_candidate_numbers_task(): void {
        $this->setup_observer_test();

        // Trigger the assessment mapped event.
        $this->trigger_assessment_mapped_event();

        // Verify task was created.
        $this->assert_task_count(1, 'Expected exactly one fetch_candidate_numbers_task to be created');
        $this->assert_task_has_correct_course_id();
    }

    /**
     * Test that assessment_mapped event does not create task when fetch_scn_enabled is disabled.
     *
     * @covers \local_sitsgradepush_observer::assessment_mapped
     * @covers \local_sitsgradepush\taskmanager::add_fetch_candidate_numbers_task
     * @return void
     */
    public function test_assessment_mapped_no_task_when_disabled(): void {
        // Disable the feature.
        set_config('fetch_scn_enabled', '0', 'local_sitsgradepush');

        $this->setup_observer_test();
        $this->trigger_assessment_mapped_event();

        // Verify no task was created.
        $this->assert_task_count(0, 'No fetch_candidate_numbers_task should be created when feature is disabled');
    }

    /**
     * Test that assessment_mapped event does not create task when course sync cache exists.
     *
     * @covers \local_sitsgradepush_observer::assessment_mapped
     * @covers \local_sitsgradepush\taskmanager::add_fetch_candidate_numbers_task
     * @return void
     */
    public function test_assessment_mapped_no_create_task_with_sync_cache(): void {
        // Set sync cache to prevent duplicate tasks.
        scnmanager::get_instance()->set_course_sync_cache($this->course->id, $this->clock->time());

        $this->setup_observer_test();
        $this->trigger_assessment_mapped_event();

        // Verify no task was created due to sync cache.
        $this->assert_task_count(0, 'No fetch_candidate_numbers_task should be created when sync cache exists');
    }

    /**
     * Test that assessment_mapped event creates task when sync cache has expired.
     *
     * @covers \local_sitsgradepush_observer::assessment_mapped
     * @covers \local_sitsgradepush\taskmanager::add_fetch_candidate_numbers_task
     * @return void
     */
    public function test_assessment_mapped_creates_task_when_cache_expired(): void {
        // Set sync cache with the current time.
        scnmanager::get_instance()->set_course_sync_cache($this->course->id, $this->clock->time());

        // Advance the clock by 2 hours to simulate cache expiration (cache expires after 1 hour).
        $this->clock->bump(2 * HOURSECS);

        $this->setup_observer_test();
        $this->trigger_assessment_mapped_event();

        // Verify task was created because cache has expired.
        $this->assert_task_count(1, 'Expected fetch_candidate_numbers_task to be created when sync cache has expired');
        $this->assert_task_has_correct_course_id();
    }

    /**
     * Set up common test dependencies for observer tests.
     * @return void
     */
    private function setup_observer_test(): void {
        global $DB;

        // Clear any existing adhoc tasks.
        $DB->delete_records('task_adhoc');

        // Create mock component grade record.
        $mabrecord = new \stdClass();
        $mabrecord->id = 456;
        $mabrecord->mapcode = 'TEST001A6UH';
        $mabrecord->mabseq = '001';

        // Mock manager to avoid dependencies.
        $mockmanager = $this->getMockBuilder(manager::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['get_local_component_grade_by_id'])
            ->getMock();
        $mockmanager->method('get_local_component_grade_by_id')->willReturn($mabrecord);

        // Set manager instance.
        $managerreflection = new \ReflectionClass(manager::class);
        $instance = $managerreflection->getProperty('instance');
        $instance->setAccessible(true);
        $instance->setValue(null, $mockmanager);
    }

    /**
     * Trigger an assessment mapped event.
     * @return void
     */
    private function trigger_assessment_mapped_event(): void {
        $eventdata = [
            'context' => \context_course::instance($this->course->id),
            'other' => [
                'courseid' => $this->course->id,
                'mappingid' => 123,
                'mabid' => 456,
            ],
        ];

        $event = assessment_mapped::create($eventdata);
        $event->trigger();
    }

    /**
     * Assert the number of fetch_candidate_numbers_task adhoc tasks.
     * @param int $expectedcount
     * @param string $message
     * @return void
     */
    private function assert_task_count(int $expectedcount, string $message): void {
        global $DB;
        $adhoctasks = $DB->get_records('task_adhoc', ['classname' => '\\local_sitsgradepush\\task\\fetch_candidate_numbers_task']);
        $this->assertCount($expectedcount, $adhoctasks, $message);
    }

    /**
     * Assert that the task has correct course ID in custom data.
     * @return void
     */
    private function assert_task_has_correct_course_id(): void {
        global $DB;
        $adhoctasks = $DB->get_records('task_adhoc', ['classname' => '\\local_sitsgradepush\\task\\fetch_candidate_numbers_task']);
        $task = reset($adhoctasks);
        $customdata = json_decode($task->customdata, true);
        $this->assertEquals($this->course->id, $customdata['courseid'], 'Task should have correct course ID');
    }
}
