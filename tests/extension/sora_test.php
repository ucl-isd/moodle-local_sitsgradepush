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

use local_sitsgradepush\extension\sora;

defined('MOODLE_INTERNAL') || die();
global $CFG;
require_once($CFG->dirroot . '/local/sitsgradepush/tests/fixtures/tests_data_provider.php');
require_once($CFG->dirroot . '/local/sitsgradepush/tests/extension/extension_common.php');

/**
 * Tests for the SORA override.
 *
 * @package    local_sitsgradepush
 * @copyright  2024 onwards University College London {@link https://www.ucl.ac.uk/}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @author     Alex Yeung <k.yeung@ucl.ac.uk>
 */
final class sora_test extends extension_common {
    /**
     * Test no SORA override for past assessments.
     *
     * @covers \local_sitsgradepush\extension\sora::set_properties_from_get_students_api
     * @return void
     * @throws \coding_exception
     * @throws \dml_exception
     * @throws \moodle_exception
     */
    public function test_no_sora_override_for_past_assessment(): void {
        // Set up the SORA overrides.
        $this->setup_for_sora_testing();

        // Make assessments past due.
        $this->set_assessment_to_past_due();

        // Get mappings and process extension.
        $mappings = manager::get_manager()->get_assessment_mappings_by_courseid($this->course1->id);
        $sora = new sora();
        $sora->set_properties_from_get_students_api(tests_data_provider::get_sora_testing_student_data());
        $sora->process_extension($mappings);

        // Verify no overrides were created.
        $this->assert_no_overrides_exist();
    }

    /**
     * Test SORA override using data from AWS message.
     *
     * @covers \local_sitsgradepush\extension\extension::get_mappings_by_userid
     * @covers \local_sitsgradepush\extension\sora::process_extension
     * @covers \local_sitsgradepush\extension\sora::get_sora_group_id
     * @covers \local_sitsgradepush\extension\sora::set_properties_from_aws_message
     * @covers \local_sitsgradepush\assessment\assign::apply_sora_extension
     * @covers \local_sitsgradepush\assessment\quiz::apply_sora_extension
     * @throws \coding_exception
     * @throws \dml_exception
     * @throws \moodle_exception
     */
    public function test_sora_process_extension_from_aws(): void {
        // Set up the SORA overrides.
        $this->setup_for_sora_testing();

        // Process the extension by passing the JSON event data.
        $sora = new sora();
        $sora->set_properties_from_aws_message(tests_data_provider::get_sora_event_data());
        $sora->process_extension($sora->get_mappings_by_userid($sora->get_userid()));

        // Verify overrides were created correctly.
        $this->assert_overrides_exist($sora, 25);
    }

    /**
     * Test the update SORA extension for students in a mapping with extension off.
     *
     * @covers \local_sitsgradepush\extensionmanager::update_sora_for_mapping
     * @return void
     * @throws \dml_exception|\coding_exception
     */
    public function test_update_sora_for_mapping_with_extension_off(): void {
        // Set extension disabled.
        set_config('extension_enabled', '0', 'local_sitsgradepush');

        // The mapping inserted should be extension disabled.
        $this->setup_for_sora_testing();

        // Process all mappings for SORA.
        $this->process_all_mappings_for_sora();

        // Verify no overrides were created.
        $this->assert_no_overrides_exist();
    }

    /**
     * Test the update SORA override for students in a mapping.
     * It also tests the SORA override using the student data from assessment API
     * and the SORA override group is deleted when the mapping is removed.
     *
     * @covers \local_sitsgradepush\extensionmanager::update_sora_for_mapping
     * @covers \local_sitsgradepush\extensionmanager::delete_sora_overrides
     * @covers \local_sitsgradepush\manager::get_assessment_mappings_by_courseid
     * @return void
     * @throws \dml_exception|\coding_exception|\ReflectionException|\moodle_exception
     */
    public function test_update_sora_for_mapping(): void {
        // Set up the SORA extension.
        $this->setup_for_sora_testing();

        // Process all mappings for SORA.
        $this->process_all_mappings_for_sora();

        // Verify overrides were created correctly.
        $sora = new sora();
        $this->assert_overrides_exist($sora, 25);

        // Delete all mappings.
        $this->delete_all_mappings();

        // Verify overrides were removed.
        $this->assert_no_overrides_exist();
    }

    /**
     * Test time limit extension for quiz.
     *
     * @covers \local_sitsgradepush\assessment\quiz::apply_sora_extension
     * @return void
     * @throws \dml_exception
     * @throws \moodle_exception
     */
    public function test_time_limit_extension_for_quiz(): void {
        global $DB;

        // Set up SORA overrides and find mapping.
        $this->setup_for_sora_testing();
        $mapping = $DB->get_record('local_sitsgradepush_mapping', ['sourceid' => $this->quiz1->cmid, 'moduletype' => 'quiz']);

        foreach ([60, 180] as $timelimit) {
            // The quiz's duration is 3 hours. Update quiz time limit to different values.
            $DB->update_record('quiz', [
                'id' => $this->quiz1->id,
                'timelimit' => $timelimit * MINSECS,
            ]);

            // Process extension and get override.
            extensionmanager::update_sora_for_mapping($mapping, [tests_data_provider::get_sora_testing_student_data()]);
            $override = $DB->get_record('quiz_overrides', ['quiz' => $this->quiz1->id, 'userid' => null]);

            // When the time limit is 60 minutes, the time close should not be overridden
            // as the new time limit is less than the quiz's duration.
            // When the time limit is 180 minutes, the time close should be 25 minutes after the original time close
            // as the new time limit is more than the quiz's duration.
            $expectedtimelimit = ($timelimit + 25) * MINSECS;
            $expectedtimeclose = $timelimit === 180 ? $this->quiz1->timeclose + 25 * MINSECS : null;

            // Assertions.
            $this->assertEquals($expectedtimelimit, $override->timelimit);
            $this->assertEquals($expectedtimeclose, $override->timeclose);
        }
    }

    /**
     * Test SORA extension for reassessment.
     *
     * @covers \local_sitsgradepush\extension\extension::get_mappings_by_userid
     * @return void
     * @throws \dml_exception
     * @throws \moodle_exception
     */
    public function test_sora_extension_for_reassessment(): void {
        // Create a past year course with assignment.
        $course = $this->create_past_year_course();
        $assign = $this->create_future_assignment($course->id);

        // Add a reassessment mapping.
        $this->setup_reassessment_mapping($course->id, $assign);

        // Test update SORA from aws can handle reassessment.
        $sora = new sora();
        $sora->set_properties_from_aws_message(tests_data_provider::get_sora_event_data());
        $sora->process_extension($sora->get_mappings_by_userid($sora->get_userid()));

        // Verify override was created for the assignment.
        $this->assert_assignment_override_exists($sora, $assign, 25);
    }

    /**
     * Set up the environment for SORA testing.
     * @return void
     * @throws \dml_exception
     */
    protected function setup_for_sora_testing(): void {
        global $DB;
        $mab1 = $DB->get_record('local_sitsgradepush_mab', ['mapcode' => 'LAWS0024A6UF', 'mabseq' => '001']);
        $this->insert_mapping($mab1->id, $this->course1->id, $this->assign1, 'assign');

        $mab2 = $DB->get_record('local_sitsgradepush_mab', ['mapcode' => 'LAWS0024A6UF', 'mabseq' => '002']);
        $this->insert_mapping($mab2->id, $this->course1->id, $this->quiz1, 'quiz');

        $manager = manager::get_manager();
        $apiclient = $this->get_apiclient_for_testing(false, [tests_data_provider::get_sora_testing_student_data()]);
        tests_data_provider::set_protected_property($manager, 'apiclient', $apiclient);
        $manager->get_students_from_sits($mab1);
        $manager->get_students_from_sits($mab2);
    }

    /**
     * Set assignment and quiz to past due dates
     */
    protected function set_assessment_to_past_due(): void {
        global $DB;

        // Make the assignment a past assessment.
        $DB->update_record('assign', (object) [
            'id' => $this->assign1->id,
            'allowsubmissionsfromdate' => $this->clock->now()->modify('-3 days')->getTimestamp(),
            'duedate' => $this->clock->now()->modify('-2 days')->getTimestamp(),
        ]);

        // Make the quiz a past assessment.
        $DB->update_record('quiz', (object) [
            'id' => $this->quiz1->id,
            'timeopen' => $this->clock->now()->modify('-3 days')->getTimestamp(),
            'timeclose' => $this->clock->now()->modify('-2 days')->getTimestamp(),
        ]);
    }

    /**
     * Process all mappings for SORA
     */
    protected function process_all_mappings_for_sora(): void {
        $mappings = manager::get_manager()->get_assessment_mappings_by_courseid($this->course1->id);
        foreach ($mappings as $mapping) {
            extensionmanager::update_sora_for_mapping($mapping, [tests_data_provider::get_sora_testing_student_data()]);
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
     * Assert that no overrides exist for assignment and quiz
     */
    protected function assert_no_overrides_exist(): void {
        global $DB;

        // Test no SORA override for the assignment.
        $override = $DB->record_exists('assign_overrides', ['assignid' => $this->assign1->id]);
        $this->assertFalse($override);

        // Test no SORA override for the quiz.
        $override = $DB->record_exists('quiz_overrides', ['quiz' => $this->quiz1->id]);
        $this->assertFalse($override);
    }

    /**
     * Assert that overrides exist for both assignment and quiz
     *
     * @param sora $sora The SORA extension object
     * @param int $minutes The number of minutes for the extension
     */
    protected function assert_overrides_exist(sora $sora, int $minutes): void {
        $this->assert_assignment_override_exists($sora, $this->assign1, $minutes);
        $this->assert_quiz_override_exists($sora, $this->quiz1, $minutes);
    }

    /**
     * Assert that an assignment override exists
     *
     * @param sora $sora The SORA extension object
     * @param object $assign The assignment object
     * @param int $minutes The number of minutes for the extension
     */
    protected function assert_assignment_override_exists(sora $sora, object $assign, int $minutes): void {
        global $DB;

        // Test SORA override group exists.
        $groupid = $DB->get_field('groups', 'id', ['name' => $sora->get_extension_group_name($assign->cmid, $minutes)]);
        $this->assertNotEmpty($groupid);

        // Test user is added to the SORA group.
        $groupmember = $DB->get_record('groups_members', ['groupid' => $groupid, 'userid' => $this->student1->id]);
        $this->assertNotEmpty($groupmember);

        // Test group override set in the assignment.
        $override = $DB->get_record('assign_overrides', ['assignid' => $assign->id, 'userid' => null, 'groupid' => $groupid]);
        $this->assertEquals($override->groupid, $groupid);
    }

    /**
     * Assert that a quiz override exists
     *
     * @param sora $sora The SORA extension object
     * @param object $quiz The quiz object
     * @param int $minutes The number of minutes for the extension
     */
    protected function assert_quiz_override_exists(sora $sora, object $quiz, int $minutes): void {
        global $DB;

        // Test SORA override group exists.
        $groupid = $DB->get_field('groups', 'id', ['name' => $sora->get_extension_group_name($quiz->cmid, $minutes)]);
        $this->assertNotEmpty($groupid);

        // Test user is added to the SORA group.
        $groupmember = $DB->get_record('groups_members', ['groupid' => $groupid, 'userid' => $this->student1->id]);
        $this->assertNotEmpty($groupmember);

        // Test group override set in the quiz.
        $override = $DB->get_record('quiz_overrides', ['quiz' => $quiz->id, 'userid' => null, 'groupid' => $groupid]);
        $this->assertEquals($override->groupid, $groupid);
    }

    /**
     * Create a past year course
     *
     * @return object The course object
     */
    protected function create_past_year_course(): object {
        return $this->getDataGenerator()->create_course(
            ['shortname' => 'C2', 'customfields' => [
                ['shortname' => 'course_year', 'value' => $this->clock->now()->modify('-1 year')->format('Y')],
            ]]);
    }

    /**
     * Create a future assignment
     *
     * @param int $courseid The course ID
     * @return object The assignment object
     */
    protected function create_future_assignment(int $courseid): object {
        $assign = $this->getDataGenerator()->create_module('assign', [
            'course' => $courseid,
            'name' => 'Reassessment Assignment',
            'allowsubmissionsfromdate' => $this->clock->now()->modify('+1 days')->getTimestamp(),
            'duedate' => $this->clock->now()->modify('+2 days')->getTimestamp(),
        ]);

        // Enrol the student to the course.
        $this->getDataGenerator()->enrol_user($this->student1->id, $courseid, 'student');

        return $assign;
    }

    /**
     * Setup a reassessment mapping
     *
     * @param int $courseid The course ID
     * @param object $assign The assignment object
     * @return object The mapping object
     */
    protected function setup_reassessment_mapping(int $courseid, object $assign): object {
        global $DB;

        // Add a reassessment mapping.
        $mab = $DB->get_record('local_sitsgradepush_mab', ['mapcode' => 'LAWS0024A6UF', 'mabseq' => '001']);
        $mappingid = $this->insert_mapping($mab->id, $courseid, $assign, 'assign', 1);
        $mapping = $DB->get_record('local_sitsgradepush_mapping', ['id' => $mappingid]);

        $manager = manager::get_manager();
        $apiclient = $this->get_apiclient_for_testing(false, [tests_data_provider::get_sora_testing_student_data()]);
        tests_data_provider::set_protected_property($manager, 'apiclient', $apiclient);
        $manager->get_students_from_sits($mab);

        return $mapping;
    }
}
