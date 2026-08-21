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

namespace local_sitsgradepush\extension\cdd;

use local_sitsgradepush\extension\aws_queue_processor;
use local_sitsgradepush\extension\cdd;
use local_sitsgradepush\extension\cdd_queue_processor;
use local_sitsgradepush\extensionmanager;
use local_sitsgradepush\tests_data_provider;

defined('MOODLE_INTERNAL') || die();
global $CFG;
require_once($CFG->dirroot . '/local/sitsgradepush/tests/extension/cdd/cdd_base.php');

/**
 * General tests for the combined due date (CDD) extension and queue processor.
 *
 * @package    local_sitsgradepush
 * @copyright  2026 onwards University College London {@link https://www.ucl.ac.uk/}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @author     Alex Yeung <k.yeung@ucl.ac.uk>
 */
final class cdd_general_test extends cdd_base {
    /**
     * Test properties are set from a combined due date API record.
     *
     * @covers \local_sitsgradepush\extension\cdd::set_properties_from_cdd_api
     * @return void
     */
    public function test_set_properties_from_cdd_api(): void {
        $cdd = new cdd();
        $cdd->set_properties_from_cdd_api(tests_data_provider::get_cdd_api_record());

        $this->assertEquals('12345678', $cdd->get_student_code());
        $this->assertEquals($this->student1->id, $cdd->get_userid());
        $this->assertEquals('LAWS0024A6UF-001', $cdd->get_mab_identifier());
        $this->assertTrue($cdd->has_combined_due_date());
        $this->assertEquals('2025-02-27', $cdd->get_new_deadline());
        $this->assertEquals(cdd::DATASOURCE_API, $cdd->get_data_source());
    }

    /**
     * Test the effective dataset uses the re-assessment entry with the highest sequence number.
     *
     * @covers \local_sitsgradepush\extension\cdd::set_properties_from_cdd_api
     * @covers \local_sitsgradepush\extension\cdd::get_effective_dataset
     * @return void
     */
    public function test_effective_dataset_uses_highest_reassessment(): void {
        $cdd = new cdd();
        $cdd->set_properties_from_cdd_api(tests_data_provider::get_cdd_api_record_reassessment());

        // The re-assessment entry with sequence 2 (2025-03-20) is the effective dataset.
        $this->assertTrue($cdd->has_combined_due_date());
        $this->assertEquals('2025-03-20', $cdd->get_new_deadline());

        // The MAB identifier still comes from the top level record.
        $this->assertEquals('LAWS0024A6UF-001', $cdd->get_mab_identifier());
    }

    /**
     * Test a gated out record, i.e. one with no day based extension, has no combined due date.
     *
     * @covers \local_sitsgradepush\extension\cdd::set_properties_from_cdd_api
     * @covers \local_sitsgradepush\extension\cdd::has_combined_due_date
     * @return void
     */
    public function test_gated_out_record_has_no_combined_due_date(): void {
        $cdd = new cdd();
        $cdd->set_properties_from_cdd_api(tests_data_provider::get_cdd_api_record_gated_out());

        $this->assertFalse($cdd->has_combined_due_date());
        $this->assertEquals('', $cdd->get_new_deadline());
    }

    /**
     * Test properties are set from an AWS event message, including re-assessment normalisation.
     *
     * @covers \local_sitsgradepush\extension\cdd::set_properties_from_aws_message
     * @return void
     */
    public function test_set_properties_from_aws_message(): void {
        $cdd = new cdd();
        $cdd->set_properties_from_aws_message(tests_data_provider::get_cdd_aws_event());

        $this->assertEquals('12345678', $cdd->get_student_code());
        $this->assertEquals('LAWS0024A6UF-001', $cdd->get_mab_identifier());
        $this->assertTrue($cdd->has_combined_due_date());

        // The single re-assessment object is normalised to a list and becomes the effective dataset.
        $this->assertEquals('2025-03-05', $cdd->get_new_deadline());
        $this->assertEquals(cdd::DATASOURCE_AWS, $cdd->get_data_source());
    }

    /**
     * Test an AWS message with no combined due date entity throws an exception.
     *
     * @covers \local_sitsgradepush\extension\cdd::set_properties_from_aws_message
     * @return void
     */
    public function test_aws_message_missing_entity_throws(): void {
        $message = json_decode(tests_data_provider::get_cdd_aws_event(), true);
        unset($message['entity']['combined_new_due_date']);

        $cdd = new cdd();
        $this->expectException(\moodle_exception::class);
        $cdd->set_properties_from_aws_message(json_encode($message));
    }

    /**
     * Test an AWS message with no student programme route code throws an exception.
     *
     * @covers \local_sitsgradepush\extension\cdd::set_properties_from_aws_message
     * @return void
     */
    public function test_aws_message_missing_sprcode_throws(): void {
        $message = json_decode(tests_data_provider::get_cdd_aws_event(), true);
        unset($message['entity']['combined_new_due_date']['student_programme_route_code']);

        $cdd = new cdd();
        $this->expectException(\moodle_exception::class);
        $cdd->set_properties_from_aws_message(json_encode($message));
    }

    /**
     * Test the queue processor processes a valid message and applies the combined due date.
     *
     * @covers \local_sitsgradepush\extension\cdd_queue_processor::process_message
     * @covers \local_sitsgradepush\extension\cdd_queue_processor::should_ignore_message
     * @return void
     */
    public function test_process_message_processed(): void {
        global $DB;

        $assign = $this->setup_common_test_data();
        $processor = new cdd_queue_processor();
        $method = $this->get_accessible_method($processor, 'process_message');

        $result = $method->invoke($processor, [
            'Message' => tests_data_provider::get_cdd_aws_event(),
            'Timestamp' => '2026-07-01T14:43:14.863Z',
        ]);

        $this->assertEquals(aws_queue_processor::STATUS_PROCESSED, $result['status']);
        $this->assertEquals('12345678', $result['studentcode']);
        $this->assertEquals('LAWS0024A6UF-001', $result['mabidentifier']);
        $this->assertEquals(strtotime('2026-07-01T14:43:14.863Z'), $result['eventtimestamp']);

        // The effective re-assessment deadline (2025-03-05) is applied at the original time of day.
        $override = $DB->get_record('assign_overrides', ['assignid' => $assign->id, 'userid' => $this->student1->id]);
        $this->assertNotEmpty($override);
        $this->assertEquals(strtotime('2025-03-05 12:00'), $override->duedate);

        // A combined due date override backup is recorded.
        $backup = $DB->get_record('local_sitsgradepush_overrides', [
            'mapid' => $this->mappingid,
            'userid' => $this->student1->id,
            'extensiontype' => extensionmanager::EXTENSION_CDD,
        ]);
        $this->assertNotEmpty($backup);
    }

    /**
     * Test the queue processor execute loop processes a message and records the MAB identifier.
     *
     * @covers \local_sitsgradepush\extension\aws_queue_processor::execute
     * @covers \local_sitsgradepush\extension\aws_queue_processor::save_message_record
     * @return void
     */
    public function test_execute_processes_injected_message(): void {
        global $DB;

        $assign = $this->setup_common_test_data();

        $testmessage = [
            'MessageId' => 'cdd-test-01',
            'ReceiptHandle' => 'test-receipt-handle',
            'Body' => json_encode([
                'Message' => tests_data_provider::get_cdd_aws_event(),
                // A timestamp before the frozen clock (2025-02-10) so the delay gate does not skip it.
                'Timestamp' => '2025-02-09T10:00:00Z',
            ]),
        ];

        // Partial mock to inject the test message and skip SQS operations.
        $processor = $this->getMockBuilder(cdd_queue_processor::class)
            ->onlyMethods(['fetch_messages', 'delete_message', 'get_queue_url'])
            ->getMock();
        $processor->method('fetch_messages')->willReturnOnConsecutiveCalls([$testmessage], []);

        $this->expectOutputRegex('/Processing batch.*Completed processing/s');
        $processor->execute();

        // The combined due date override was applied.
        $override = $DB->get_record('assign_overrides', ['assignid' => $assign->id, 'userid' => $this->student1->id]);
        $this->assertEquals(strtotime('2025-03-05 12:00'), $override->duedate);

        // The processed record stores the MAB identifier.
        $record = $DB->get_record('local_sitsgradepush_aws_log', ['messageid' => 'cdd-test-01']);
        $this->assertEquals(aws_queue_processor::STATUS_PROCESSED, $record->status);
        $this->assertEquals('LAWS0024A6UF-001', $record->mabidentifier);
    }

    /**
     * Test an out-of-order message is ignored.
     *
     * @covers \local_sitsgradepush\extension\cdd_queue_processor::is_message_out_of_order
     * @return void
     */
    public function test_process_message_out_of_order_ignored(): void {
        global $DB;

        $this->setup_common_test_data();

        // Insert a previously processed message with a later timestamp for the same student and MAB.
        $DB->insert_record('local_sitsgradepush_aws_log', (object)[
            'queuename' => cdd_queue_processor::QUEUE_NAME,
            'messageid' => 'later-message-01',
            'payload' => tests_data_provider::get_cdd_aws_event(),
            'studentcode' => '12345678',
            'mabidentifier' => 'LAWS0024A6UF-001',
            'eventtimestamp' => strtotime('2026-07-02T10:00:00Z'),
            'status' => aws_queue_processor::STATUS_PROCESSED,
            'attempts' => 1,
            'timecreated' => time(),
            'timemodified' => time(),
        ]);

        $processor = new cdd_queue_processor();
        $method = $this->get_accessible_method($processor, 'process_message');

        // This message has an earlier timestamp, so it should be ignored.
        $result = $method->invoke($processor, [
            'Message' => tests_data_provider::get_cdd_aws_event(),
            'Timestamp' => '2026-07-01T14:43:14.863Z',
        ]);

        $this->assertEquals(aws_queue_processor::STATUS_IGNORED, $result['status']);
        $this->assertNotEmpty($result['ignore_reason']);
    }

    /**
     * Test out-of-order detection processes messages that cannot be ordered.
     *
     * @covers \local_sitsgradepush\extension\cdd_queue_processor::is_message_out_of_order
     * @return void
     */
    public function test_is_message_out_of_order_missing_data(): void {
        $processor = new cdd_queue_processor();
        $method = $this->get_accessible_method($processor, 'is_message_out_of_order');

        // No timestamp means the order cannot be determined, so process it.
        $this->assertFalse($method->invoke($processor, 'LAWS0024A6UF-001', '12345678', null));

        // No student code means the order cannot be determined, so process it.
        $this->assertFalse($method->invoke($processor, 'LAWS0024A6UF-001', '', strtotime('2026-07-01T14:43:14Z')));
    }

    /**
     * Test a malformed MAB identifier returns no mappings.
     *
     * @covers \local_sitsgradepush\extension\extension::get_mappings_by_mab
     * @return void
     */
    public function test_get_mappings_by_mab_malformed(): void {
        $cdd = new cdd();

        $this->assertEmpty($cdd->get_mappings_by_mab(''));
        $this->assertEmpty($cdd->get_mappings_by_mab('LAWS0024A6UF'));
        $this->assertEmpty($cdd->get_mappings_by_mab('LAWS0024A6UF-'));
        $this->assertEmpty($cdd->get_mappings_by_mab('-001'));
    }
}
