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

use core\task\scheduled_task;
use local_sitsgradepush\manager;
use core\task\manager as task_manager;

/**
 * Scheduled task to process grade push requests and queue adhoc tasks.
 *
 * @package    local_sitsgradepush
 * @copyright  2023 onwards University College London {@link https://www.ucl.ac.uk/}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @author     Alex Yeung <k.yeung@ucl.ac.uk>
 */
class pushtask extends scheduled_task {

    /** @var int default max concurrent tasks allowed */
    const MAX_CONCURRENT_TASKS = 10;
    /**
     * Get a descriptive name for this task.
     *
     * @return string
     * @throws \coding_exception
     */
    public function get_name() : string {
        return get_string('task:pushtask:name', 'local_sitsgradepush');
    }

    /**
     * Run the scheduled task.
     *
     * @return void
     */
    public function execute() {
        // Get the manager.
        $manager = manager::get_manager();

        // Get the number of concurrent tasks allowed.
        if (!$concurrenttasksallowed = get_config('local_sitsgradepush', 'concurrent_running_tasks')) {
            $concurrenttasksallowed = self::MAX_CONCURRENT_TASKS;
        }

        // Get the number of tasks currently running.
        $runningtasks = $manager->get_number_of_running_tasks();

        if ($runningtasks >= $concurrenttasksallowed) {
            // Too many tasks running, exit.
            mtrace(date('Y-m-d H:i:s', time()) . ' : ' .
                'Too many tasks running (' . $runningtasks . '/' . $concurrenttasksallowed . ')');
            return;
        }

        // Get queued tasks.
        $tasks = $manager->get_push_tasks(manager::PUSH_TASK_STATUS_REQUESTED, $concurrenttasksallowed - $runningtasks);
        if (empty($tasks)) {
            // No tasks to run, exit.
            mtrace(date('Y-m-d H:i:s', time()) . ' : ' .
                'No tasks to run.');
            return;
        }

        $count = $runningtasks + 1;

        // Run the tasks.
        foreach ($tasks as $task) {
            // Add adhoc task.
            mtrace(date('Y-m-d H:i:s', time()) . ' : ' .
                'Spawning adhoc task [#' . $task->id . '] (' . $count . '/' . $concurrenttasksallowed . ')');

            $adhoctask = new adhoctask();
            $adhoctask->set_custom_data([
                'taskid' => $task->id,
            ]);
            task_manager::queue_adhoc_task($adhoctask);

            // Mark the task as queued.
            $manager->update_push_task_status($task->id, manager::PUSH_TASK_STATUS_QUEUED);

            $count++;
        }
    }
}
