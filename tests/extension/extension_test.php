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

use local_sitsgradepush\extension\ec;
use local_sitsgradepush\extension\sora;

defined('MOODLE_INTERNAL') || die();
global $CFG;
require_once($CFG->dirroot . '/local/sitsgradepush/tests/fixtures/tests_data_provider.php');
require_once($CFG->dirroot . '/local/sitsgradepush/tests/base_test_class.php');

/**
 * Tests for the extension class.
 *
 * @package    local_sitsgradepush
 * @copyright  2024 onwards University College London {@link https://www.ucl.ac.uk/}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @author     Alex Yeung <k.yeung@ucl.ac.uk>
 */
final class extension_test extends base_test_class {

    /** @var \stdClass $course1 Default test course 1 */
    private \stdClass $course1;

    /** @var \stdClass Default test student 1 */
    private \stdClass $student1;

    /** @var \stdClass Default test assignment 1 */
    private \stdClass $assign1;

    /** @var \stdClass Default test quiz 1*/
    private \stdClass $quiz1;

    /**
     * Set up the test.
     *
     * @return void
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();

        // Set admin user.
        $this->setAdminUser();

        // Get data generator.
        $dg = $this->getDataGenerator();

        // Set Easikit API client.
        set_config('apiclient', 'easikit', 'local_sitsgradepush');

        // Setup testing environment.
        set_config('late_summer_assessment_end_' . date('Y'), date('Y-m-d', strtotime('+2 month')), 'block_lifecycle');

        // Enable the extension.
        set_config('extension_enabled', '1', 'local_sitsgradepush');

        // Create a custom category and custom field.
        $dg->create_custom_field_category(['name' => 'CLC']);
        $dg->create_custom_field(['category' => 'CLC', 'shortname' => 'course_year']);

        // Create test courses.
        $this->course1 = $dg->create_course(
            ['shortname' => 'C1', 'customfields' => [
                ['shortname' => 'course_year', 'value' => date('Y')],
            ]]);
        $this->student1 = $dg->create_user(['idnumber' => '12345678']);
        $dg->enrol_user($this->student1->id, $this->course1->id);

        $assessmentstartdate = strtotime('+1 day');
        $assessmentenddate = strtotime('+2 days');

        // Create test assignment 1.
        $this->assign1 = $dg->create_module('assign',
            [
                'name' => 'Test Assignment 1',
                'course' => $this->course1->id,
                'allowsubmissionsfromdate' => $assessmentstartdate,
                'duedate' => $assessmentenddate,
            ]
        );

        // Create test quiz 1.
        $this->quiz1 = $dg->create_module(
            'quiz',
            [
                'course' => $this->course1->id,
                'name' => 'Test Quiz 1',
                'timeopen' => $assessmentstartdate,
                'timelimit' => 60,
                'timeclose' => $assessmentenddate,
            ]
        );

        // Set up the SITS grade push.
        $this->setup_sitsgradepush();
    }

    /**
     * Test no overrides for mapping without extension enabled.
     *
     * @covers \local_sitsgradepush\extension\extension::parse_event_json
     * @covers \local_sitsgradepush\extension\ec::process_extension
     * @return void
     * @throws \dml_exception
     * @throws \moodle_exception
     */
    public function test_no_overrides_for_mapping_without_extension_enabled(): void {
        global $DB;
        // Disable the extension.
        set_config('extension_enabled', '0', 'local_sitsgradepush');

        // Set up the EC event data.
        $message = $this->setup_for_ec_testing('LAWS0024A6UF', '001', $this->assign1, 'assign');

        // Process the extension.
        $ec = new ec();
        $ec->set_properties_from_aws_message($message);
        $ec->process_extension($ec->get_mappings_by_mab($ec->get_mab_identifier()));

        $override = $DB->get_record('assign_overrides', ['assignid' => $this->assign1->id, 'userid' => $this->student1->id]);
        $this->assertEmpty($override);
    }

    /**
     * Test the EC extension process for moodle assignment.
     *
     * @covers \local_sitsgradepush\extension\extension::parse_event_json
     * @covers \local_sitsgradepush\extension\ec::process_extension
     * @covers \local_sitsgradepush\extension\extension::get_mappings_by_mab
     * @covers \local_sitsgradepush\assessment\assign::apply_ec_extension
     * @return void
     * @throws \dml_exception
     * @throws \moodle_exception
     */
    public function test_ec_process_extension_assign(): void {
        global $DB;

        // Set up the EC event data.
        $message = $this->setup_for_ec_testing('LAWS0024A6UF', '001', $this->assign1, 'assign');

        // Process the extension by passing the JSON event data.
        $ec = new ec();
        $ec->set_properties_from_aws_message($message);
        $ec->process_extension($ec->get_mappings_by_mab($ec->get_mab_identifier()));

        // Calculate the new deadline.
        // Assume EC is using a new deadline without time. Extract the time part.
        $time = date('H:i:s', $this->assign1->duedate);
        // Get the new date and time.
        $newduedate = strtotime($ec->get_new_deadline() . ' ' . $time);

        $override = $DB->get_record('assign_overrides', ['assignid' => $this->assign1->id, 'userid' => $this->student1->id]);
        $this->assertNotEmpty($override);

        // Check the new deadline is set correctly.
        $this->assertEquals($newduedate, $override->duedate);
    }

    /**
     * Test the EC extension process for moodle quiz.
     *
     * @covers \local_sitsgradepush\extension\ec::process_extension
     * @covers \local_sitsgradepush\extension\extension::get_mappings_by_mab
     * @covers \local_sitsgradepush\assessment\quiz::apply_ec_extension
     * @return void
     * @throws \dml_exception
     * @throws \moodle_exception
     */
    public function test_ec_process_extension_quiz(): void {
        global $DB;

        // Set up the EC event data.
        $message = $this->setup_for_ec_testing('LAWS0024A6UF', '002', $this->quiz1, 'quiz');

        // Process the extension by passing the JSON event data.
        $ec = new ec();
        $ec->set_properties_from_aws_message($message);
        $ec->process_extension($ec->get_mappings_by_mab($ec->get_mab_identifier()));

        // Calculate the new deadline.
        // Assume EC is using a new deadline without time. Extract the time part.
        $time = date('H:i:s', $this->quiz1->timeclose);

        // Get the new date and time.
        $newtimeclose = strtotime($ec->get_new_deadline() . ' ' . $time);

        $override = $DB->get_record('quiz_overrides', ['quiz' => $this->quiz1->id, 'userid' => $this->student1->id]);
        $this->assertNotEmpty($override);

        // Check the new deadline is set correctly.
        $this->assertEquals($newtimeclose, $override->timeclose);
    }

    /**
     * Test SORA extension process.
     *
     * @covers \local_sitsgradepush\extension\extension::get_mappings_by_userid
     * @covers \local_sitsgradepush\extension\sora::process_extension
     * @covers \local_sitsgradepush\extension\sora::get_sora_group_id
     * @covers \local_sitsgradepush\assessment\assign::apply_sora_extension
     * @covers \local_sitsgradepush\assessment\quiz::apply_sora_extension
     * @throws \coding_exception
     * @throws \dml_exception
     * @throws \moodle_exception
     */
    public function test_sora_process_extension(): void {
        global $DB;

        // Insert mappings for SORA.
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
     * Test the update SORA extension for students in a mapping.
     *
     * @covers \local_sitsgradepush\extensionmanager::update_sora_for_mapping
     * @return void
     * @throws \dml_exception|\coding_exception|\ReflectionException|\moodle_exception
     */
    public function test_update_sora_for_mapping(): void {
        global $DB;

        // Set up the SORA extension.
        $this->setup_for_sora_testing();
        $manager = manager::get_manager();
        $apiclient = $this->get_apiclient_for_testing(
            false,
            [['code' => 12345678, 'assessment' => ['sora_assessment_duration' => 20, 'sora_rest_duration' => 5]]]
        );
        tests_data_provider::set_protected_property($manager, 'apiclient', $apiclient);

        // Process all mappings for SORA.
        $mappings = $manager->get_assessment_mappings_by_courseid($this->course1->id);
        foreach ($mappings as $mapping) {
            $students = $manager->get_students_from_sits($mapping);
            extensionmanager::update_sora_for_mapping($mapping, $students);
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
    }

    /**
     * Test the user is enrolling a gradable role.
     *
     * @covers \local_sitsgradepush\extensionmanager::user_is_enrolling_a_gradable_role
     * @return void
     */
    public function test_user_is_enrolling_a_gradable_role(): void {
        global $CFG;

        // Test when role is gradable.
        $CFG->gradebookroles = '1,2,3';
        $roleid = 2;
        $result = extensionmanager::user_is_enrolling_a_gradable_role($roleid);
        $this->assertTrue($result);

        // Test when role is not gradable.
        $roleid = 4;
        $result = extensionmanager::user_is_enrolling_a_gradable_role($roleid);
        $this->assertFalse($result);

        // Test when gradebookroles is null.
        $CFG->gradebookroles = null;
        $roleid = 1;
        $result = extensionmanager::user_is_enrolling_a_gradable_role($roleid);
        $this->assertFalse($result);
    }

    /**
     * Test get user enrolment events.
     *
     * @covers \local_sitsgradepush\extensionmanager::get_user_enrolment_events
     * @return void
     * @throws \coding_exception
     * @throws \dml_exception
     */
    public function test_get_user_enrolment_events(): void {
        global $DB;

        // Create user enrolment events.
        $events = [];
        for ($i = 0; $i < 3; $i++) {
            $event = new \stdClass();
            $event->courseid = 1;
            $event->userid = $i + 1;
            $event->attempts = $i;
            $event->timecreated = time();
            $events[] = $event;
        }
        $DB->insert_records('local_sitsgradepush_enrol', $events);

        // Get user enrolment events.
        $result = extensionmanager::get_user_enrolment_events(1);
        $this->assertCount(2, $result);
    }

    /**
     * Test is_extension_enabled method.
     *
     * @covers \local_sitsgradepush\extensionmanager::is_extension_enabled
     * @return void
     * @throws \dml_exception
     */
    public function test_is_extension_enabled(): void {
        // Test when extension is enabled in config.
        set_config('extension_enabled', '1', 'local_sitsgradepush');
        $result = extensionmanager::is_extension_enabled();
        $this->assertTrue($result);

        // Test when extension is disabled in config.
        set_config('extension_enabled', '0', 'local_sitsgradepush');
        $result = extensionmanager::is_extension_enabled();
        $this->assertFalse($result);
    }

    /**
     * Set up the environment for EC testing.
     *
     * @param string $mapcode The map code.
     * @param string $mabseq The MAB sequence number.
     * @param \stdClass $assessment The assessment object.
     * @param string $modtype The module type.
     *
     * @return string|false
     * @throws \dml_exception
     */
    protected function setup_for_ec_testing(string $mapcode, string $mabseq, \stdClass $assessment, string $modtype): string|bool {
        global $DB;
        $mabid = $DB->get_field('local_sitsgradepush_mab', 'id', ['mapcode' => $mapcode, 'mabseq' => $mabseq]);
        $this->insert_mapping($mabid, $this->course1->id, $assessment, $modtype);

        // Load the EC event data.
        $ecjson = tests_data_provider::get_ec_event_data();
        $message = json_decode($ecjson, true);

        // Set the new deadline.
        $newdeadline = strtotime('+3 days');
        $message['identifier'] = sprintf('%s-%s', $mapcode, $mabseq);
        $message['new_deadline'] = date('Y-m-d', $newdeadline);

        return json_encode($message);
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

    /**
     * Set up the SITS grade push.
     *
     * @return void
     * @throws \dml_exception|\coding_exception
     */
    private function setup_sitsgradepush(): void {
        // Insert MABs.
        tests_data_provider::import_sitsgradepush_grade_components();
    }

    /**
     * Insert a test mapping.
     *
     * @param int $mabid
     * @param int $courseid
     * @param \stdClass $assessment
     * @param string $modtype
     * @return bool|int
     * @throws \dml_exception
     */
    private function insert_mapping(int $mabid, int $courseid, \stdClass $assessment, string $modtype): bool|int {
        global $DB;

        return $DB->insert_record('local_sitsgradepush_mapping', [
            'courseid' => $courseid,
            'sourceid' => $assessment->cmid,
            'sourcetype' => 'mod',
            'moduletype' => $modtype,
            'componentgradeid' => $mabid,
            'reassessment' => 0,
            'enableextension' => extensionmanager::is_extension_enabled() ? 1 : 0,
            'timecreated' => time(),
            'timemodified' => time(),
        ]);
    }
}
