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

use core\task\manager as coretaskmanager;
use core_enrol\hook\after_user_enrolled;
use local_sitsgradepush\task\process_extensions_new_enrolment;

/**
 * Hook callbacks to get the enrolment information.
 *
 * @package    local_sitsgradepush
 * @copyright  2024 onwards University College London {@link https://www.ucl.ac.uk/}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @author     Alex Yeung <k.yeung@ucl.ac.uk>
 */
class user_enrolment_callbacks {

    /**
     * Callback for the user_enrolment hook.
     *
     * @param after_user_enrolled $hook
     * @throws \dml_exception|\coding_exception
     */
    public static function process_extensions(after_user_enrolled $hook): void {
        global $DB;

        // Exit if extension is not enabled.
        if (!extensionmanager::is_extension_enabled()) {
            return;
        }

        $instance = $hook->get_enrolinstance();

        // Check if user is enrolling a gradable role in the course.
        if (!extensionmanager::user_is_enrolling_a_gradable_role($instance->roleid)) {
            return; // User is not enrolling a gradable role, exit early.
        }

        // Add user enrolment event to database.
        $event = new \stdClass();
        $event->courseid = $instance->courseid;
        $event->userid = $hook->get_userid();
        $event->timecreated = time();
        $DB->insert_record('local_sitsgradepush_enrol', $event);

        // Check if an ad-hoc task already exists for the course.
        if (!process_extensions_new_enrolment::adhoc_task_exists($instance->courseid)) {
            // Create a new ad-hoc task for the course.
            $task = new task\process_extensions_new_enrolment();
            $task->set_custom_data(['courseid' => $instance->courseid]);
            coretaskmanager::queue_adhoc_task($task);
        }
    }
}
