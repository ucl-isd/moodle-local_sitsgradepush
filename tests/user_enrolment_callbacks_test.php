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
require_once(__DIR__ . '/base_test_class.php');

/**
 * Tests for the user_enrolment_callbacks class.
 *
 * @package    local_sitsgradepush
 * @copyright  2025 onwards University College London {@link https://www.ucl.ac.uk/}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @author     Alex Yeung <k.yeung@ucl.ac.uk>
 */
final class user_enrolment_callbacks_test extends base_test_class {
    /** @var string Task classname constant */
    private const TASK_CLASSNAME = '\\local_sitsgradepush\\task\\fetch_candidate_numbers_task';

    /** @var \stdClass $course Test course */
    private \stdClass $course;

    /** @var \stdClass $user Test user */
    private \stdClass $user;

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

        // Set a frozen clock for testing.
        $this->clock = $this->mock_clock_with_frozen(strtotime('2025-08-13 09:00:00'));

        // Set default configurations.
        set_config('fetch_scn_enabled', '1', 'local_sitsgradepush');

        // Create test data.
        $dg = $this->getDataGenerator();
        $this->course = $dg->create_course();
        $this->user = $dg->create_user();
    }

    /**
     * Test that user enrollment creates fetch_candidate_numbers_task adhoc task.
     *
     * @covers \local_sitsgradepush\user_enrolment_callbacks::runs_callbacks
     * @covers \local_sitsgradepush\taskmanager::add_fetch_candidate_numbers_task
     * @return void
     */
    public function test_user_enrolled_creates_fetch_candidate_numbers_task(): void {
        $this->clear_adhoc_tasks();

        $this->trigger_user_enrolled_hook();

        $this->assert_task_count(1, 'Expected exactly one fetch_candidate_numbers_task to be created');
        $this->assert_task_has_correct_course_id();
    }

    /**
     * Test that user enrollment does not create task when fetch_scn_enabled is disabled.
     *
     * @covers \local_sitsgradepush\user_enrolment_callbacks::runs_callbacks
     * @covers \local_sitsgradepush\taskmanager::add_fetch_candidate_numbers_task
     * @return void
     */
    public function test_user_enrolled_no_task_when_disabled(): void {
        // Disable the feature.
        set_config('fetch_scn_enabled', '0', 'local_sitsgradepush');

        $this->clear_adhoc_tasks();
        $this->trigger_user_enrolled_hook();

        $this->assert_task_count(0, 'No fetch_candidate_numbers_task should be created when feature is disabled');
    }

    /**
     * Test that user enrollment does not create task when course sync cache exists.
     *
     * @covers \local_sitsgradepush\user_enrolment_callbacks::runs_callbacks
     * @covers \local_sitsgradepush\taskmanager::add_fetch_candidate_numbers_task
     * @return void
     */
    public function test_user_enrolled_no_create_task_with_sync_cache(): void {
        // Set sync cache to prevent duplicate tasks.
        scnmanager::get_instance()->set_course_sync_cache($this->course->id, $this->clock->time());

        $this->clear_adhoc_tasks();
        $this->trigger_user_enrolled_hook();

        $this->assert_task_count(0, 'No fetch_candidate_numbers_task should be created when sync cache exists');
    }

    /**
     * Test that user enrollment creates task when sync cache has expired.
     *
     * @covers \local_sitsgradepush\user_enrolment_callbacks::runs_callbacks
     * @covers \local_sitsgradepush\taskmanager::add_fetch_candidate_numbers_task
     * @return void
     */
    public function test_user_enrolled_creates_task_when_cache_expired(): void {
        // Set sync cache with the current time.
        scnmanager::get_instance()->set_course_sync_cache($this->course->id, $this->clock->time());

        // Advance the clock by 2 hours to simulate cache expiration (cache expires after 1 hour).
        $this->clock->bump(2 * HOURSECS);

        $this->clear_adhoc_tasks();
        $this->trigger_user_enrolled_hook();

        $this->assert_task_count(1, 'Expected fetch_candidate_numbers_task to be created when sync cache has expired');
        $this->assert_task_has_correct_course_id();
    }

    /**
     * Clear any existing adhoc tasks.
     * @return void
     */
    private function clear_adhoc_tasks(): void {
        global $DB;
        $DB->delete_records('task_adhoc');
    }

    /**
     * Trigger user enrollment which should create the hook and call the callback.
     * @return void
     */
    private function trigger_user_enrolled_hook(): void {
        // Use the data generator's enrol_user method to simulate real enrollment.
        // This will trigger the actual hook and callback automatically.
        $dg = $this->getDataGenerator();
        $dg->enrol_user($this->user->id, $this->course->id, 'student');
    }

    /**
     * Assert the number of fetch_candidate_numbers_task adhoc tasks.
     * @param int $expectedcount
     * @param string $message
     * @return void
     */
    private function assert_task_count(int $expectedcount, string $message): void {
        global $DB;
        $adhoctasks = $DB->get_records('task_adhoc', ['classname' => self::TASK_CLASSNAME]);
        $this->assertCount($expectedcount, $adhoctasks, $message);
    }

    /**
     * Assert that the task has correct course ID in custom data.
     * @return void
     */
    private function assert_task_has_correct_course_id(): void {
        global $DB;
        $adhoctasks = $DB->get_records('task_adhoc', ['classname' => self::TASK_CLASSNAME]);
        $task = reset($adhoctasks);
        $customdata = json_decode($task->customdata, true);
        $this->assertEquals($this->course->id, $customdata['courseid'], 'Task should have correct course ID');
    }
}
