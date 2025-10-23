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
use core\task\manager as coretaskmanager;
use local_sitsgradepush\extension\ec;
use local_sitsgradepush\extension\sora;
use local_sitsgradepush\extensionmanager;
use local_sitsgradepush\logger;
use local_sitsgradepush\manager;

/**
 * Ad-hoc task to process extensions for new student enrolment in course.
 *
 * @package    local_sitsgradepush
 * @copyright  2024 onwards University College London {@link https://www.ucl.ac.uk/}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @author     Alex Yeung <k.yeung@ucl.ac.uk>
 */
class process_extensions_new_enrolment extends adhoc_task {
    /** @var int Number of students to process per batch. */
    const BATCH_LIMIT = 100;

    /** @var int Maximum retry attempts for a record. */
    const MAX_ATTEMPTS = 2;

    /**
     * Return name of the task.
     *
     * @return string
     * @throws \coding_exception
     */
    public function get_name() {
        return get_string('task:process_extensions_new_enrolment', 'local_sitsgradepush');
    }

    /**
     * Execute the task.
     *
     * @throws \dml_exception
     */
    public function execute() {
        global $DB;

        // Get task data.
        $courseid = $this->get_custom_data()->courseid;

        // Get all user enrolment events for the course.
        $userenrolments = extensionmanager::get_user_enrolment_events($courseid);

        // Fetch all mappings for the course with extension enabled.
        $manager = manager::get_manager();
        $mappings = $manager->get_assessment_mappings_by_courseid($courseid, true);

        // Delete the user enrolment events for that course if no mappings found.
        // Nothing we can do without mappings.
        // When there is a new mapping, the extensions will be processed by process_extensions_new_mapping task.
        if (empty($mappings)) {
            $DB->delete_records('local_sitsgradepush_enrol', ['courseid' => $courseid]);
        }

        // Process SORA extension for each mapping.
        foreach ($mappings as $mapping) {
            $studentsbycode = [];
            // Get fresh students data from SITS for the mapping.
            $students = $manager->get_students_from_sits($mapping, true, 2);

            // Create a map of students by student code.
            foreach ($students as $student) {
                $studentsbycode[$student['association']['supplementary']['student_code']] = $student;
            }

            // Process SORA extension for each user enrolment event.
            foreach ($userenrolments as $userenrolment) {
                try {
                    // Get user's student ID number.
                    $studentidnumber = $DB->get_field('user', 'idnumber', ['id' => $userenrolment->userid]);

                    // Check if the student's code exists in the pre-mapped list.
                    if (isset($studentsbycode[$studentidnumber])) {
                        // Process SORA extension.
                        $sora = new sora();
                        $sora->set_properties_from_get_students_api($studentsbycode[$studentidnumber]);
                        $sora->process_extension([$mapping]);

                        // Process EC extension.
                        $ec = new ec();
                        $ec->set_properties_from_get_students_api($studentsbycode[$studentidnumber]);
                        $ec->process_extension([$mapping]);

                        // Delete the student from the list to avoid duplicate processing.
                        unset($studentsbycode[$studentidnumber]);
                    }
                    // Delete the user enrolment event after processing.
                    $DB->delete_records('local_sitsgradepush_enrol', ['id' => $userenrolment->id]);
                } catch (\Exception $e) {
                    $userenrolment->attempts++;
                    $DB->update_record('local_sitsgradepush_enrol', $userenrolment);
                    logger::log($e->getMessage(), null, "User ID: $userenrolment->userid, Mapping ID: $mapping->id");
                }
            }
        }

        // Re-queue another ad-hoc task if there are more entries for this course.
        if (!empty(extensionmanager::get_user_enrolment_events($courseid))) {
            $nexttask = new self();
            $nexttask->set_custom_data(['courseid' => $courseid]);
            coretaskmanager::queue_adhoc_task($nexttask);
        }
    }

    /**
     * Check if an ad-hoc task already exists for the course.
     *
     * @param int $courseid
     * @return bool
     * @throws \dml_exception
     */
    public static function adhoc_task_exists(int $courseid): bool {
        global $DB;

        $sql = "SELECT id
        FROM {task_adhoc}
        WHERE " . $DB->sql_compare_text('classname') . " = ? AND " . $DB->sql_compare_text('customdata') . " = ?";
        $params = [
            '\\local_sitsgradepush\\task\\process_extensions_new_enrolment',
            json_encode(['courseid' => (string) $courseid]),
        ];

        return $DB->record_exists_sql($sql, $params);
    }
}
