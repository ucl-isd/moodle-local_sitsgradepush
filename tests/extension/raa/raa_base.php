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

namespace local_sitsgradepush\extension\raa;

use local_sitsgradepush\extension_common;
use local_sitsgradepush\extensionmanager;
use local_sitsgradepush\manager;
use local_sitsgradepush\tests_data_provider;

defined('MOODLE_INTERNAL') || die();
global $CFG;
require_once($CFG->dirroot . '/local/sitsgradepush/tests/extension/extension_common.php');
require_once($CFG->dirroot . '/local/sitsgradepush/tests/fixtures/tests_data_provider.php');

/**
 * Base class for RAA extension tests.
 *
 * @package    local_sitsgradepush
 * @copyright  2025 onwards University College London {@link https://www.ucl.ac.uk/}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @author     Alex Yeung <k.yeung@ucl.ac.uk>
 */
class raa_base extends extension_common {
    /** @var array Test assessment types */
    public array $testassessmenttypes = ['CN01', 'HD05', 'ED03'];

    /**
     * Get test student data with Moodle user ID.
     *
     * @param string $astcode The fixture name (e.g. 'CN01', 'ED03', 'ED03_tier2').
     * @param int|null $userid The Moodle user ID. Defaults to student1.
     * @return array The test student data.
     */
    protected function get_test_student_data(string $astcode = 'CN01', ?int $userid = null): array {
        $student = tests_data_provider::get_sora_testing_student_data($astcode);
        $student['moodleuserid'] = $userid ?? $this->student1->id;
        return $student;
    }

    /**
     * Setup test student data for RAA.
     *
     * @param \stdClass $mab The MAB object.
     * @return void
     */
    protected function setup_test_student_data(\stdClass $mab): void {
        $manager = manager::get_manager();

        // Set API client with test student data.
        $apiclient = $this->get_apiclient_for_testing(false, [$this->get_test_student_data($mab->astcode)]);
        tests_data_provider::set_protected_property($manager, 'apiclient', $apiclient);

        // This will cache the student data for the MAB.
        $manager->get_students_from_sits($mab, true, 2);
    }

    /**
     * Create a test assignment.
     *
     * @param int $courseid The course ID.
     * @param int $startdate The start date timestamp.
     * @param int $enddate The end date timestamp.
     * @return object The assignment object.
     */
    protected function create_assignment(int $courseid, int $startdate, int $enddate): object {
        $assign = $this->getDataGenerator()->create_module('assign', [
            'course' => $courseid,
            'name' => 'Test Assignment',
            'allowsubmissionsfromdate' => $startdate,
            'duedate' => $enddate,
        ]);

        // Enrol the student to the course.
        $this->getDataGenerator()->enrol_user($this->student1->id, $courseid, 'student');

        return $assign;
    }

    /**
     * Create a test quiz.
     *
     * @param int $courseid The course ID.
     * @param int $startdate The start date timestamp.
     * @param int $enddate The end date timestamp.
     * @param int|null $timelimit The time limit in seconds.
     * @return object The quiz object.
     */
    protected function create_quiz(int $courseid, int $startdate, int $enddate, ?int $timelimit = null): object {
        $quiz = $this->getDataGenerator()->create_module('quiz', [
            'course' => $courseid,
            'name' => 'Test Quiz',
            'timeopen' => $startdate,
            'timeclose' => $enddate,
            'timelimit' => $timelimit,
        ]);

        // Enrol the student to the course.
        $this->getDataGenerator()->enrol_user($this->student1->id, $courseid, 'student');

        return $quiz;
    }

    /**
     * Create a test lesson.
     *
     * @param int $courseid The course ID.
     * @param int $startdate The start date timestamp.
     * @param int $enddate The end date timestamp.
     * @param int|null $timelimit The time limit in seconds.
     * @return object The lesson object.
     */
    protected function create_lesson(int $courseid, int $startdate, int $enddate, ?int $timelimit = null): object {
        $lesson = $this->getDataGenerator()->create_module('lesson', [
            'course' => $courseid,
            'name' => 'Test Lesson',
            'available' => $startdate,
            'deadline' => $enddate,
            'timelimit' => $timelimit,
            'practice' => 0,
        ]);

        // Enrol the student to the course.
        $this->getDataGenerator()->enrol_user($this->student1->id, $courseid, 'student');

        return $lesson;
    }

    /**
     * Create a past year course.
     *
     * @return object The course object.
     */
    protected function create_past_year_course(): object {
        $course = $this->getDataGenerator()->create_course(
            ['shortname' => 'C2', 'customfields' => [
                ['shortname' => 'course_year', 'value' => $this->clock->now()->modify('-1 year')->format('Y')],
            ]]
        );

        // Enrol the student to the course.
        $this->getDataGenerator()->enrol_user($this->student1->id, $course->id, 'student');

        return $course;
    }

    /**
     * Process all mappings for RAA.
     *
     * @return void
     */
    protected function process_all_mappings_for_sora(): void {
        $mappings = manager::get_manager()->get_assessment_mappings_by_courseid($this->course1->id);

        foreach ($mappings as $mapping) {
            $student = $this->get_test_student_data($mapping->astcode);
            extensionmanager::update_sora_for_mapping($mapping, [$student]);
        }
    }

    /**
     * Delete all mappings for the course
     */
    protected function delete_all_mappings(): void {
        $mappings = manager::get_manager()->get_assessment_mappings_by_courseid($this->course1->id);
        foreach ($mappings as $mapping) {
            manager::get_manager()->remove_mapping($this->course1->id, $mapping->id);
        }
    }


    /**
     * Check if feedback tracker plugin is installed.
     *
     * @return bool True if feedback tracker helper class exists.
     */
    protected function is_feedback_tracker_installed(): bool {
        return class_exists('report_feedback_tracker\local\helper');
    }

    /**
     * Create a SITS mapping and setup test student data.
     *
     * @param \stdClass $mab The MAB object.
     * @param int $courseid The course ID.
     * @param \stdClass $module The module object.
     * @param string $modtype The module type (e.g. 'quiz', 'assign', 'lesson').
     * @param int $reassess The reassessment flag.
     * @return bool|int The mapping ID.
     */
    protected function create_module_mapping(
        \stdClass $mab,
        int $courseid,
        \stdClass $module,
        string $modtype,
        int $reassess = 0
    ): bool|int {
        $mappingid = $this->insert_mapping(
            $mab->id,
            $courseid,
            $module,
            $modtype,
            $reassess
        );
        $this->setup_test_student_data($mab);
        return $mappingid;
    }
}
