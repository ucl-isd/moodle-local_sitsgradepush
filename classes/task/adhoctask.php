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

use core\task\adhoc_task;
use local_sitsgradepush\assessment\assessmentfactory;
use local_sitsgradepush\logger;
use local_sitsgradepush\manager;

/**
 * Ad-hoc task to push grades to SITS.
 *
 * @package    local_sitsgradepush
 * @copyright  2023 onwards University College London {@link https://www.ucl.ac.uk/}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @author     Alex Yeung <k.yeung@ucl.ac.uk>
 */
class adhoctask extends adhoc_task {

    /**
     * Return name of the task.
     *
     * @return string
     * @throws \coding_exception
     */
    public function get_name() {
        return get_string('task:adhoctask', 'local_sitsgradepush');
    }

    /**
     * Execute the task.
     */
    public function execute() {
        global $DB;
        try {
            // Get the manager.
            $manager = manager::get_manager();

            // Get task data.
            $data = $this->get_custom_data();

            // Get the task.
            if (!$task = $DB->get_record('local_sitsgradepush_tasks', ['id' => $data->taskid])) {
                throw new \moodle_exception('error:tasknotfound', 'local_sitsgradepush');
            }

            // Get the course module.
            if (!$coursemodule = get_coursemodule_from_id(null, $task->coursemoduleid)) {
                throw new \moodle_exception('error:coursemodulenotfound', 'local_sitsgradepush');
            }

            // Log start.
            mtrace(date('Y-m-d H:i:s', time()) . ' : ' . 'Processing push task [#' . $data->taskid . ']');

            // Update task status to processing.
            $manager->update_push_task_status($task->id, manager::PUSH_TASK_STATUS_PROCESSING);

            // Get assessment.
            $assessment = assessmentfactory::get_assessment($coursemodule);
            if ($studentswithgrade = $manager->get_assessment_data($assessment)) {
                foreach ($studentswithgrade as $student) {
                    $manager->push_grade_to_sits($assessment, $student->userid);
                    $manager->push_submission_log_to_sits($assessment, $student->userid);
                }
            }

            // Log complete.
            mtrace(date('Y-m-d H:i:s', time()) . ' : ' . 'Completed push task [#' . $data->taskid . ']');

            // Update task status.
            $manager->update_push_task_status($task->id, manager::PUSH_TASK_STATUS_COMPLETED);
        } catch (\Exception $e) {
            // Log error.
            $errlogid = logger::log($e->getMessage());

            // Update task status.
            $manager->update_push_task_status($task->id, manager::PUSH_TASK_STATUS_FAILED, $errlogid);
        }
    }
}
