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
     * Test no SORA override for non-exam assessments.
     *
     * @covers \local_sitsgradepush\assessment\assign::is_exam
     * @covers \local_sitsgradepush\assessment\quiz::is_exam
     * @return void
     * @throws \coding_exception
     * @throws \dml_exception
     * @throws \moodle_exception
     */
    public function test_no_sora_override_for_non_exam_assessment(): void {
        global $DB;

        // Set up the SORA overrides.
        $this->setup_for_sora_testing();

        $startdate = strtotime('+1 hour');
        $enddate = strtotime('+7 days');

        // Modify assignment so that open duration more than 5 hours, i.e. not exam.
        $DB->update_record('assign', (object) [
            'id' => $this->assign1->id,
            'allowsubmissionsfromdate' => $startdate,
            'duedate' => $enddate,
        ]);

        // Modify quiz so that time limit more than 5 hours, i.e. not exam.
        $DB->update_record('quiz', (object) [
            'id' => $this->quiz1->id,
            'timelimit' => 2880, // 48 hours.
        ]);

        // Get mappings.
        $mappings = manager::get_manager()->get_assessment_mappings_by_courseid($this->course1->id);
        $sora = new sora();
        $sora->set_properties_from_get_students_api(tests_data_provider::get_sora_testing_student_data());
        $sora->process_extension($mappings);

        // Test no SORA override for the assignment.
        $override = $DB->record_exists('assign_overrides', ['assignid' => $this->assign1->id]);
        $this->assertFalse($override);

        // Test no SORA override for the quiz.
        $override = $DB->record_exists('quiz_overrides', ['quiz' => $this->quiz1->id]);
        $this->assertFalse($override);
    }

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
        global $DB;

        // Set up the SORA overrides.
        $this->setup_for_sora_testing();

        // Modify assignment so that open duration more than 5 hours, i.e. not exam.
        $DB->update_record('assign', (object) [
            'id' => $this->assign1->id,
            'duedate' => strtotime('-1 day'),
        ]);

        // Modify quiz so that time limit more than 5 hours, i.e. not exam.
        $DB->update_record('quiz', (object) [
            'id' => $this->quiz1->id,
            'timeclose' => strtotime('-1 day'),
        ]);

        // Get mappings.
        $mappings = manager::get_manager()->get_assessment_mappings_by_courseid($this->course1->id);
        $sora = new sora();
        $sora->set_properties_from_get_students_api(tests_data_provider::get_sora_testing_student_data());
        $sora->process_extension($mappings);

        // Test no SORA override for the assignment.
        $override = $DB->record_exists('assign_overrides', ['assignid' => $this->assign1->id]);
        $this->assertFalse($override);

        // Test no SORA override for the quiz.
        $override = $DB->record_exists('quiz_overrides', ['quiz' => $this->quiz1->id]);
        $this->assertFalse($override);
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
        global $DB;

        // Set up the SORA overrides.
        $this->setup_for_sora_testing();

        // Process the extension by passing the JSON event data.
        $sora = new sora();
        $sora->set_properties_from_aws_message(tests_data_provider::get_sora_event_data());
        $sora->process_extension($sora->get_mappings_by_userid($sora->get_userid()));

        // Test SORA override group exists.
        $groupid = $DB->get_field('groups', 'id', ['name' => $sora->get_extension_group_name()]);
        $this->assertNotEmpty($groupid);

        // Test user is added to the SORA group.
        $groupmember = $DB->get_record('groups_members', ['groupid' => $groupid, 'userid' => $this->student1->id]);
        $this->assertNotEmpty($groupmember);

        // Test group override set in the assignment.
        $override = $DB
            ->get_record('assign_overrides', ['assignid' => $this->assign1->id, 'userid' => null, 'groupid' => $groupid]);
        $this->assertEquals($override->groupid, $groupid);

        // Test group override set in the quiz.
        $override = $DB->get_record('quiz_overrides', ['quiz' => $this->quiz1->id, 'userid' => null, 'groupid' => $groupid]);
        $this->assertEquals($override->groupid, $groupid);
    }

    /**
     * Test the update SORA extension for students in a mapping with extension off.
     *
     * @covers \local_sitsgradepush\extensionmanager::update_sora_for_mapping
     * @return void
     * @throws \dml_exception|\coding_exception
     */
    public function test_update_sora_for_mapping_with_extension_off(): void {
        global $DB;

        // Set extension disabled.
        set_config('extension_enabled', '0', 'local_sitsgradepush');

        // The mapping inserted should be extension disabled.
        $this->setup_for_sora_testing();

        // Get mappings.
        $mappings = manager::get_manager()->get_assessment_mappings_by_courseid($this->course1->id);
        $mapping = reset($mappings);
        // Process SORA extension for each mapping.
        extensionmanager::update_sora_for_mapping($mapping, []);

        // Check error log.
        $errormessage = get_string('error:extension_not_enabled_for_mapping', 'local_sitsgradepush', $mapping->id);
        $sql = "SELECT * FROM {local_sitsgradepush_err_log} WHERE message = :message AND data = :data";
        $params = ['message' => $errormessage, 'data' => "Mapping ID: $mapping->id"];
        $log = $DB->get_record_sql($sql, $params);
        $this->assertNotEmpty($log);
    }

    /**
     * Test the update SORA override for students in a mapping.
     * It also tests the SORA override using the student data from assessment API
     * and the SORA override group is deleted when the mapping is removed.
     *
     * @covers \local_sitsgradepush\extensionmanager::update_sora_for_mapping
     * @covers \local_sitsgradepush\extensionmanager::delete_sora_overrides
     * @covers \local_sitsgradepush\extensionmanager::get_default_sora_groups_ids_in_course
     * @covers \local_sitsgradepush\manager::get_assessment_mappings_by_courseid
     * @return void
     * @throws \dml_exception|\coding_exception|\ReflectionException|\moodle_exception
     */
    public function test_update_sora_for_mapping(): void {
        global $DB;

        // Set up the SORA extension.
        $this->setup_for_sora_testing();
        $manager = manager::get_manager();

        // Process all mappings for SORA.
        $mappings = $manager->get_assessment_mappings_by_courseid($this->course1->id);
        foreach ($mappings as $mapping) {
            extensionmanager::update_sora_for_mapping($mapping, [tests_data_provider::get_sora_testing_student_data()]);
        }

        // Test SORA override group exists.
        $groupid = $DB->get_field('groups', 'id', ['name' => sora::SORA_GROUP_PREFIX . '25']);
        $this->assertNotEmpty($groupid);

        // Test user is added to the SORA group.
        $groupmember = $DB->get_record('groups_members', ['groupid' => $groupid, 'userid' => $this->student1->id]);
        $this->assertNotEmpty($groupmember);

        // Test group override set in the assignment.
        $override = $DB
            ->get_record('assign_overrides', ['assignid' => $this->assign1->id, 'userid' => null, 'groupid' => $groupid]);
        $this->assertEquals($override->groupid, $groupid);

        // Test group override set in the quiz.
        $override = $DB->get_record('quiz_overrides', ['quiz' => $this->quiz1->id, 'userid' => null, 'groupid' => $groupid]);
        $this->assertEquals($override->groupid, $groupid);

        // Delete all mappings.
        foreach ($mappings as $mapping) {
            manager::get_manager()->remove_mapping($this->course1->id, $mapping->id);
        }

        // Test SORA override group is deleted in the assignment.
        $override = $DB->record_exists('assign_overrides', ['assignid' => $this->assign1->id]);
        $this->assertFalse($override);

        // Test SORA override group is deleted in the quiz.
        $override = $DB->record_exists('quiz_overrides', ['quiz' => $this->quiz1->id]);
        $this->assertFalse($override);
    }

    /**
     * Set up the environment for SORA testing.
     * @return void
     * @throws \dml_exception
     */
    protected function setup_for_sora_testing(): void {
        global $DB;
        $mabid = $DB->get_field('local_sitsgradepush_mab', 'id', ['mapcode' => 'LAWS0024A6UF', 'mabseq' => '001']);
        $this->insert_mapping($mabid, $this->course1->id, $this->assign1, 'assign');

        $mabid = $DB->get_field('local_sitsgradepush_mab', 'id', ['mapcode' => 'LAWS0024A6UF', 'mabseq' => '002']);
        $this->insert_mapping($mabid, $this->course1->id, $this->quiz1, 'quiz');
    }
}
