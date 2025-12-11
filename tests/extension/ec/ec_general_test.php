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

namespace local_sitsgradepush\extension\ec;

use local_sitsgradepush\extension\aws_queue_processor;
use local_sitsgradepush\extension\ec_queue_processor;
use local_sitsgradepush\extensionmanager;
use local_sitsgradepush\manager;

defined('MOODLE_INTERNAL') || die();
global $CFG;
require_once($CFG->dirroot . '/local/sitsgradepush/tests/extension/ec/ec_base.php');

/**
 * General tests for the EC extension.
 *
 * @package    local_sitsgradepush
 * @copyright  2025 onwards University College London {@link https://www.ucl.ac.uk/}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @author     Alex Yeung <k.yeung@ucl.ac.uk>
 */
final class ec_general_test extends ec_base {
    /**
     * Test process_message method
     *
     * @covers \local_sitsgradepush\extension\ec_queue_processor::process_message
     * @covers \local_sitsgradepush\extension\ec_queue_processor::should_ignore_message
     * @covers \local_sitsgradepush\extension\ec::process_extension
     * @return void
     */
    public function test_ec_queue_processor_process_message(): void {
        global $DB;

        $assign = $this->setup_common_test_data();
        $eventdata = file_get_contents(__DIR__ . '/../../fixtures/ec_event_data.json');
        $processor = new ec_queue_processor();
        $method = $this->get_accessible_method($processor, 'process_message');

        // Test case 1: Process a valid message.
        $result = $method->invoke($processor, ['Message' => $eventdata]);
        $this->assertEquals(aws_queue_processor::STATUS_PROCESSED, $result['status']);

        $override = $DB->get_record('assign_overrides', ['assignid' => $assign->id, 'userid' => $this->student1->id]);
        $this->assertNotEmpty($override);
        $this->assertEquals(strtotime('2025-02-27 12:00'), $override->duedate);

        // Test case 2: Test message that should be ignored.
        $ignoredmessage = json_decode($eventdata, true);
        $ignoredmessage['entity']['student_extenuating_circumstances']['extenuating_circumstances']['request']['status'] =
            'PENDING';
        $ignoredmessage['entity']['student_extenuating_circumstances']['extenuating_circumstances']['request']['decision_type'] =
            'PANEL';

        $result = $method->invoke($processor, ['Message' => json_encode($ignoredmessage)]);
        $this->assertEquals(aws_queue_processor::STATUS_IGNORED, $result['status']);

        // Test case 3: Test exception when student not found.
        $manager = $this->createMock(manager::class);
        $manager->method('get_students_from_sits')->willReturn([]);
        $this->set_manager_instance($manager);
        $this->expectException(\moodle_exception::class);
        $method->invoke($processor, ['Message' => $eventdata]);
    }

    /**
     * Test deleted DAP event processing.
     *
     * @covers \local_sitsgradepush\extension\ec::is_deleted_event
     * @covers \local_sitsgradepush\extension\ec::handle_deleted_event
     * @covers \local_sitsgradepush\extension\ec::set_properties_from_aws_message
     * @covers \local_sitsgradepush\extension\ec::process_extension
     * @covers \local_sitsgradepush\extension\ec_queue_processor::process_message
     * @return void
     */
    public function test_deleted_dap_event(): void {
        global $DB;
        $assign = $this->setup_common_test_data();

        // Process initial DAP extension message.
        $eventdata = file_get_contents(__DIR__ . '/../../fixtures/ec_event_data.json');
        $processor = new ec_queue_processor();
        $method = $this->get_accessible_method($processor, 'process_message');
        $result = $method->invoke($processor, ['Message' => $eventdata]);

        // Verify override was created with DAP identifier.
        $this->assertEquals(aws_queue_processor::STATUS_PROCESSED, $result['status']);
        $this->assertNotEmpty($DB->get_record('assign_overrides', ['assignid' => $assign->id, 'userid' => $this->student1->id]));

        $overridebackup = $DB->get_record('local_sitsgradepush_overrides', [
            'mapid' => $this->mappingid,
            'userid' => $this->student1->id,
            'extensiontype' => extensionmanager::EXTENSION_EC,
        ]);
        $this->assertNotEmpty($overridebackup);
        $this->assertEquals('DAP-ABCDE07-001', $overridebackup->requestidentifier);

        // Process deleted DAP event with empty EC data.
        $deletedmessage = file_get_contents(__DIR__ . '/../../fixtures/deleted_dap_event_data.json');
        $this->setup_mock_manager_with_empty_ec($this->student1->id);
        $result = $method->invoke($processor, ['Message' => $deletedmessage]);

        // Verify override was deleted and backup marked as restored.
        $this->assertEquals(aws_queue_processor::STATUS_PROCESSED, $result['status']);
        $this->assertFalse($DB->get_record('assign_overrides', ['assignid' => $assign->id, 'userid' => $this->student1->id]));

        $overridebackup = $DB->get_record('local_sitsgradepush_overrides', [
            'mapid' => $this->mappingid,
            'userid' => $this->student1->id,
            'extensiontype' => extensionmanager::EXTENSION_EC,
        ]);

        // Verify restored fields are set.
        $this->assertNotEmpty($overridebackup->restored_by);
        $this->assertNotEmpty($overridebackup->timerestored);
    }

    /**
     * Test deleted EC event processing.
     *
     * @covers \local_sitsgradepush\extension\ec::is_deleted_event
     * @covers \local_sitsgradepush\extension\ec::handle_deleted_event
     * @covers \local_sitsgradepush\extension\ec::set_properties_from_aws_message
     * @covers \local_sitsgradepush\extension\ec::process_extension
     * @covers \local_sitsgradepush\extension\ec_queue_processor::process_message
     * @return void
     */
    public function test_deleted_ec_event(): void {
        global $DB;
        $assign = $this->setup_common_test_data();

        // Set student2 in mock manager.
        $this->setup_mock_manager(json_decode(file_get_contents(__DIR__ . '/../../fixtures/ec_test_student2.json'), true));

        // Process initial EC extension message.
        $eceventdata = file_get_contents(__DIR__ . '/../../fixtures/ec_event_data_ec_identifier.json');
        $processor = new ec_queue_processor();
        $method = $this->get_accessible_method($processor, 'process_message');
        $result = $method->invoke($processor, ['Message' => $eceventdata]);

        // Verify override was created with EC identifier.
        $this->assertEquals(aws_queue_processor::STATUS_PROCESSED, $result['status']);
        $this->assertNotEmpty($DB->get_record('assign_overrides', ['assignid' => $assign->id, 'userid' => $this->student2->id]));

        $overridebackup = $DB->get_record('local_sitsgradepush_overrides', [
            'mapid' => $this->mappingid,
            'userid' => $this->student2->id,
            'extensiontype' => extensionmanager::EXTENSION_EC,
        ]);
        $this->assertNotEmpty($overridebackup);
        $this->assertEquals('EC-BCDEA08-005', $overridebackup->requestidentifier);

        // Process deleted EC event with empty EC data.
        $deletedecmessage = file_get_contents(__DIR__ . '/../../fixtures/deleted_ec_event_data.json');
        $this->setup_mock_manager_with_empty_ec($this->student2->id);
        $result = $method->invoke($processor, ['Message' => $deletedecmessage]);

        // Verify override was deleted and backup marked as restored.
        $this->assertEquals(aws_queue_processor::STATUS_PROCESSED, $result['status']);
        $this->assertFalse($DB->get_record('assign_overrides', ['assignid' => $assign->id, 'userid' => $this->student2->id]));

        $overridebackup = $DB->get_record('local_sitsgradepush_overrides', [
            'mapid' => $this->mappingid,
            'userid' => $this->student2->id,
            'extensiontype' => extensionmanager::EXTENSION_EC,
        ]);

        // Verify restored fields are set.
        $this->assertNotEmpty($overridebackup->restored_by);
        $this->assertNotEmpty($overridebackup->timerestored);
    }
}
