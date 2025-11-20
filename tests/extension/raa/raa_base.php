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
    /**
     * Set up the test.
     *
     * @return void
     */
    protected function setUp(): void {
        parent::setUp();

        // Setup extension tiers.
        $this->create_extension_tiers();
    }

    /**
     * Get test student data with Moodle user ID.
     *
     * @return array The test student data.
     */
    protected function get_test_student_data(): array {
        $student = tests_data_provider::get_sora_testing_student_data();
        $student['moodleuserid'] = $this->student1->id;
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
        $apiclient = $this->get_apiclient_for_testing(false, [$this->get_test_student_data()]);
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
        $student = $this->get_test_student_data();

        foreach ($mappings as $mapping) {
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
     * Load and insert extension tier records from JSON fixture file.
     *
     * @param string|null $assessmenttype Optional filter to only load tiers for specific assessment type.
     * @return void
     * @throws \dml_exception
     */
    protected function create_extension_tiers(?string $assessmenttype = null): void {
        global $DB, $CFG;

        $jsonfile = $CFG->dirroot . '/local/sitsgradepush/tests/fixtures/extension_tiers.json';
        $jsondata = file_get_contents($jsonfile);
        $tiers = json_decode($jsondata, true);

        foreach ($tiers as $tierdata) {
            // Skip if filtering by assessment type and this tier doesn't match.
            if ($assessmenttype !== null && $tierdata['assessmenttype'] !== $assessmenttype) {
                continue;
            }

            $tier = new \stdClass();
            $tier->assessmenttype = $tierdata['assessmenttype'];
            $tier->tier = $tierdata['tier'];
            $tier->extensiontype = $tierdata['extensiontype'];
            $tier->extensionvalue = $tierdata['extensionvalue'];
            $tier->extensionunit = $tierdata['extensionunit'];
            $tier->breakvalue = $tierdata['breakvalue'];
            $tier->enabled = $tierdata['enabled'];
            $tier->timecreated = $this->clock->time();
            $tier->timemodified = $this->clock->time();

            $DB->insert_record('local_sitsgradepush_ext_tiers', $tier);
        }
    }
}
