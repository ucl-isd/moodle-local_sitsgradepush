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

defined('MOODLE_INTERNAL') || die();
global $CFG;
require_once($CFG->dirroot . '/local/sitsgradepush/tests/fixtures/tests_data_provider.php');
require_once($CFG->dirroot . '/local/sitsgradepush/tests/extension/extension_common.php');

/**
 * Tests for the observer deadline group methods.
 *
 * @package    local_sitsgradepush
 * @copyright  2026 onwards University College London {@link https://www.ucl.ac.uk/}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @author     Alex Yeung <k.yeung@ucl.ac.uk>
 */
final class observer_deadline_group_test extends extension_common {
    /** @var string Task classname for process_extensions_new_mapping. */
    const TASK_CLASSNAME = '\\local_sitsgradepush\\task\\process_extensions_new_mapping';

    /**
     * Set up the test.
     *
     * @return void
     */
    public function setUp(): void {
        parent::setUp();
        set_config('deadlinegroup_prefix', self::DLG_PREFIX, 'local_sitsgradepush');
    }

    /**
     * Data provider for test_group_override_changed_queues_task.
     *
     * @return array
     */
    public static function group_override_changed_provider(): array {
        return [
            'assign created' => ['assign', \mod_assign\event\group_override_created::class],
            'assign updated' => ['assign', \mod_assign\event\group_override_updated::class],
            'quiz created' => ['quiz', \mod_quiz\event\group_override_created::class],
            'quiz updated' => ['quiz', \mod_quiz\event\group_override_updated::class],
            'lesson created' => ['lesson', \mod_lesson\event\group_override_created::class],
            'lesson updated' => ['lesson', \mod_lesson\event\group_override_updated::class],
        ];
    }

    /**
     * Test group_override_changed queues a task when a DLG group override is created or updated.
     *
     * @dataProvider group_override_changed_provider
     * @covers \local_sitsgradepush_observer::group_override_changed
     * @param string $modtype The module type ('assign', 'quiz', or 'lesson').
     * @param string $eventclass The fully qualified event class name.
     * @return void
     */
    public function test_group_override_changed_queues_task(string $modtype, string $eventclass): void {
        global $DB;

        $activity = $this->{$modtype . '1'};
        $mappingid = $this->create_mapping($activity, $modtype);
        $groupid = $this->create_deadline_group('DLG-A');
        $overrideid = $this->create_dlg_override($modtype, $activity->id, $groupid);

        $DB->delete_records('task_adhoc');

        $event = $eventclass::create([
            'context' => \context_module::instance($activity->cmid),
            'objectid' => $overrideid,
            'other' => $this->get_event_other($modtype, $activity->id, $groupid),
        ]);
        $event->trigger();

        $this->assert_task_queued_for_mapping($mappingid);
    }

    /**
     * Test group_override_changed does not queue a task when the override is for a non-DLG group.
     *
     * @covers \local_sitsgradepush_observer::group_override_changed
     * @return void
     */
    public function test_group_override_changed_non_dlg_group_no_task(): void {
        global $DB;

        $this->create_mapping($this->quiz1, 'quiz');

        // Create a non-DLG group (no DLG- prefix).
        $group = new \stdClass();
        $group->courseid = $this->course1->id;
        $group->name = 'Regular-Group';
        $groupid = groups_create_group($group);
        $overrideid = $this->create_quiz_group_override($this->quiz1->id, $groupid, null, $this->clock->time() + HOURSECS);

        $DB->delete_records('task_adhoc');

        $event = \mod_quiz\event\group_override_created::create([
            'context' => \context_module::instance($this->quiz1->cmid),
            'objectid' => $overrideid,
            'other' => ['quizid' => $this->quiz1->id, 'groupid' => $groupid],
        ]);
        $event->trigger();

        $tasks = $DB->get_records('task_adhoc', ['classname' => self::TASK_CLASSNAME]);
        $this->assertEmpty($tasks, 'No task should be queued for a non-DLG group override.');
    }

    /**
     * Test deadline_group_member_changed queues a task when a student is added or removed from a DLG group.
     *
     * @covers \local_sitsgradepush_observer::deadline_group_member_changed
     * @return void
     */
    public function test_deadline_group_member_changed_queues_task(): void {
        global $DB;

        $mappingid = $this->create_mapping($this->quiz1, 'quiz');
        $groupid = $this->create_deadline_group('DLG-A');
        $this->create_quiz_group_override($this->quiz1->id, $groupid, null, $this->clock->time() + HOURSECS);

        $DB->delete_records('task_adhoc');

        // Adding a student to the group fires core\event\group_member_added.
        groups_add_member($groupid, $this->student1->id);
        $this->assert_task_queued_for_mapping($mappingid);

        $DB->delete_records('task_adhoc');

        // Removing the student fires core\event\group_member_removed.
        groups_remove_member($groupid, $this->student1->id);
        $this->assert_task_queued_for_mapping($mappingid);
    }

    /**
     * Test deadline_group_member_changed does not queue a task when the group is not a DLG group.
     *
     * @covers \local_sitsgradepush_observer::deadline_group_member_changed
     * @return void
     */
    public function test_deadline_group_member_added_non_dlg_group_no_task(): void {
        global $DB;

        $this->create_mapping($this->quiz1, 'quiz');

        // Create a non-DLG group.
        $group = new \stdClass();
        $group->courseid = $this->course1->id;
        $group->name = 'Regular-Group';
        $groupid = groups_create_group($group);
        $this->create_quiz_group_override($this->quiz1->id, $groupid, null, $this->clock->time() + HOURSECS);

        $DB->delete_records('task_adhoc');

        // Adding a student to a non-DLG group should not trigger task queueing.
        groups_add_member($groupid, $this->student1->id);

        $tasks = $DB->get_records('task_adhoc', ['classname' => self::TASK_CLASSNAME]);
        $this->assertEmpty($tasks, 'No task should be queued when member added to a non-DLG group.');
    }

    /**
     * Test deadline_group_deleted queues a task when a DLG group is deleted.
     *
     * @covers \local_sitsgradepush_observer::deadline_group_deleted
     * @return void
     */
    public function test_deadline_group_deleted_queues_task(): void {
        global $DB;

        $mappingid = $this->create_mapping($this->quiz1, 'quiz');
        $groupid = $this->create_deadline_group('DLG-A');
        $this->create_quiz_group_override($this->quiz1->id, $groupid, null, $this->clock->time() + HOURSECS);

        $DB->delete_records('task_adhoc');

        // Deleting the group fires \core\event\group_deleted with the record snapshot.
        groups_delete_group($groupid);

        $this->assert_task_queued_for_mapping($mappingid);
    }

    /**
     * Test deadline_group_deleted does not queue a task when a non-DLG group is deleted.
     *
     * @covers \local_sitsgradepush_observer::deadline_group_deleted
     * @return void
     */
    public function test_deadline_group_deleted_non_dlg_no_task(): void {
        global $DB;

        $this->create_mapping($this->quiz1, 'quiz');

        // Create a non-DLG group.
        $group = new \stdClass();
        $group->courseid = $this->course1->id;
        $group->name = 'Regular-Group';
        $groupid = groups_create_group($group);
        $this->create_quiz_group_override($this->quiz1->id, $groupid, null, $this->clock->time() + HOURSECS);

        $DB->delete_records('task_adhoc');

        groups_delete_group($groupid);

        $tasks = $DB->get_records('task_adhoc', ['classname' => self::TASK_CLASSNAME]);
        $this->assertEmpty($tasks, 'No task should be queued when a non-DLG group is deleted.');
    }

    /**
     * Create a SITS mapping for the given activity in the test course.
     *
     * @param object $activity The activity instance (e.g. assign1, quiz1, lesson1).
     * @param string $type The activity type (e.g. 'assign', 'quiz', 'lesson').
     * @return int The mapping ID.
     */
    private function create_mapping(object $activity, string $type): int {
        global $DB;
        $mab = $DB->get_record('local_sitsgradepush_mab', ['mapcode' => 'LAWS0024A6UF', 'mabseq' => '001']);
        return (int)$this->insert_mapping($mab->id, $this->course1->id, $activity, $type);
    }

    /**
     * Create a group override for the given module type with a future end date.
     *
     * @param string $modtype The module type ('assign', 'quiz', or 'lesson').
     * @param int $instanceid The module instance ID.
     * @param int $groupid The group ID.
     * @return int The inserted override ID.
     */
    private function create_dlg_override(string $modtype, int $instanceid, int $groupid): int {
        $enddate = $this->clock->time() + HOURSECS;
        return match ($modtype) {
            'assign' => $this->create_assign_group_override($instanceid, $groupid, $enddate),
            'quiz'   => $this->create_quiz_group_override($instanceid, $groupid, null, $enddate),
            'lesson' => $this->create_lesson_group_override($instanceid, $groupid, null, $enddate),
        };
    }

    /**
     * Build the 'other' array for a group override event.
     *
     * @param string $modtype The module type ('assign', 'quiz', or 'lesson').
     * @param int $instanceid The module instance ID.
     * @param int $groupid The group ID.
     * @return array The event other data.
     */
    private function get_event_other(string $modtype, int $instanceid, int $groupid): array {
        return match ($modtype) {
            'assign' => ['assignid' => $instanceid, 'groupid' => $groupid],
            'quiz'   => ['quizid'   => $instanceid, 'groupid' => $groupid],
            'lesson' => ['lessonid' => $instanceid, 'groupid' => $groupid],
        };
    }

    /**
     * Assert exactly one process_extensions_new_mapping task is queued for the given mapping ID.
     *
     * @param int $mappingid The expected mapping ID in the task custom data.
     * @return void
     */
    private function assert_task_queued_for_mapping(int $mappingid): void {
        global $DB;
        $tasks = $DB->get_records('task_adhoc', ['classname' => self::TASK_CLASSNAME]);
        $this->assertCount(1, $tasks, 'Exactly one process_extensions_new_mapping task should be queued.');
        $task = reset($tasks);
        $customdata = json_decode($task->customdata);
        $this->assertEquals($mappingid, $customdata->mapid, 'Task should reference the correct mapping ID.');
    }
}
