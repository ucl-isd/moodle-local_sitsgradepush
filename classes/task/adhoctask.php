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
use local_sitsgradepush\logger;
use local_sitsgradepush\manager;
use local_sitsgradepush\taskmanager;

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
        try {
            // Get task data.
            $data = $this->get_custom_data();

            // Run task.
            taskmanager::run_task($data->taskid);
            taskmanager::send_email_notification($data->taskid, true);
        } catch (\Exception $e) {
            // Log error.
            $errlogid = logger::log('Push task failed: ' . $e->getMessage());

            // Update task status.
            taskmanager::update_task_status($data->taskid, taskmanager::PUSH_TASK_STATUS_FAILED, $errlogid);

            // Email the user.
            taskmanager::send_email_notification($data->taskid, false);
        }
    }
}
