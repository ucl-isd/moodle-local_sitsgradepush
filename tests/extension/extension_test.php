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

defined('MOODLE_INTERNAL') || die();
global $CFG;
require_once($CFG->dirroot . '/local/sitsgradepush/tests/fixtures/tests_data_provider.php');
require_once($CFG->dirroot . '/local/sitsgradepush/tests/extension/extension_common.php');

/**
 * Tests for the extension class.
 *
 * @package    local_sitsgradepush
 * @copyright  2024 onwards University College London {@link https://www.ucl.ac.uk/}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @author     Alex Yeung <k.yeung@ucl.ac.uk>
 */
final class extension_test extends extension_common {

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

        // Get user enrolment events. Only 2 is returned as the max attempts is set to 2.
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
}
