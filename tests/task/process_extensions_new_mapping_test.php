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
use mod_quiz\event\group_override_updated;

defined('MOODLE_INTERNAL') || die();
global $CFG;
require_once($CFG->dirroot . '/local/sitsgradepush/tests/fixtures/tests_data_provider.php');
require_once($CFG->dirroot . '/local/sitsgradepush/tests/extension/extension_common.php');

/**
 * Tests for process_extensions_new_mapping adhoc task deduplication.
 *
 * @package    local_sitsgradepush
 * @copyright  2026 onwards University College London {@link https://www.ucl.ac.uk/}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @author     Alex Yeung <k.yeung@ucl.ac.uk>
 */
final class process_extensions_new_mapping_test extends extension_common {
    /** @var string Task classname for process_extensions_new_mapping. */
    const TASK_CLASSNAME = '\\local_sitsgradepush\\task\\process_extensions_new_mapping';

    /**
     * Test that triggering the same group override event twice results in only one queued task.
     * Deduplication in taskmanager::add_process_extensions_for_new_mapping_adhoc_task
     * calls adhoc_task_exists before queueing, preventing duplicate pending tasks.
     *
     * @covers \local_sitsgradepush\task\process_extensions_new_mapping::adhoc_task_exists
     * @return void
     */
    public function test_duplicate_group_override_events_queue_only_one_task(): void {
        global $DB;

        set_config('deadlinegroup_prefix', self::DLG_PREFIX, 'local_sitsgradepush');

        // Create a quiz with a SITS mapping.
        $mab = $DB->get_record('local_sitsgradepush_mab', ['mapcode' => 'LAWS0024A6UF', 'mabseq' => '001']);
        $mappingid = (int)$this->insert_mapping($mab->id, $this->course1->id, $this->quiz1, 'quiz');

        // Create DLG group and override.
        $groupid = $this->create_deadline_group('DLG-A');
        $overrideid = $this->create_quiz_group_override($this->quiz1->id, $groupid, null, $this->clock->time() + HOURSECS);

        $DB->delete_records('task_adhoc', ['classname' => self::TASK_CLASSNAME]);

        // Trigger the same event twice (simulating two rapid override updates).
        $eventparams = [
            'context' => \context_module::instance($this->quiz1->cmid),
            'objectid' => $overrideid,
            'other' => ['quizid' => $this->quiz1->id, 'groupid' => $groupid],
        ];
        group_override_updated::create($eventparams)->trigger();
        group_override_updated::create($eventparams)->trigger();

        // Assert only one task was queued despite two events.
        $tasks = $DB->get_records('task_adhoc', ['classname' => self::TASK_CLASSNAME]);
        $this->assertCount(1, $tasks, 'Only one task should be queued even when the event fires twice.');
        $task = reset($tasks);
        $customdata = json_decode($task->customdata);
        $this->assertEquals($mappingid, $customdata->mapid);
    }
}
