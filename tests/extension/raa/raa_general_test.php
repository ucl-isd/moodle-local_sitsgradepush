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

use local_sitsgradepush\extension\aws_queue_processor;
use local_sitsgradepush\extension\sora;
use local_sitsgradepush\extension\sora_queue_processor;
use local_sitsgradepush\extensionmanager;
use local_sitsgradepush\tests_data_provider;

defined('MOODLE_INTERNAL') || die();
global $CFG;
require_once($CFG->dirroot . '/local/sitsgradepush/tests/extension/raa/raa_base.php');

/**
 * General tests for the RAA override.
 *
 * @package    local_sitsgradepush
 * @copyright  2024 onwards University College London {@link https://www.ucl.ac.uk/}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @author     Alex Yeung <k.yeung@ucl.ac.uk>
 */
final class raa_general_test extends raa_base {
    /** @var int Timestamp for earlier message (T1) */
    private int $earliertimestamp;

    /** @var int Timestamp for later message (T2) */
    private int $latertimestamp;

    /**
     * Set up the test.
     *
     * @return void
     */
    public function setUp(): void {
        parent::setUp();

        // Define timestamps for message ordering tests.
        $this->earliertimestamp = $this->clock->now()->modify('-2 minute')->getTimestamp();
        $this->latertimestamp = $this->clock->now()->modify('-1 minute')->getTimestamp();
    }

    /**
     * Test that earlier SORA messages are ignored when later message already processed.
     *
     * @covers \local_sitsgradepush\extension\sora_queue_processor::process_message
     * @covers \local_sitsgradepush\extension\sora_queue_processor::is_message_out_of_order
     * @covers \local_sitsgradepush\extension\sora_queue_processor::should_ignore_message
     * @return void
     * @throws \dml_exception
     * @throws \ReflectionException
     */
    public function test_earlier_message_ignored_when_later_already_processed(): void {
        global $DB;

        $astcode = 'CN01';

        // Create and process later message first (T2).
        $messaget2 = $this->create_sora_aws_message_for_ordering(
            $this->student1->idnumber,
            $this->latertimestamp,
            $astcode
        );
        $processor = new sora_queue_processor();
        $resultt2 = $this->call_process_message($processor, $messaget2);

        // Save the result to database (simulate full flow).
        $this->save_message_to_aws_log($messaget2, $resultt2);

        // Create and process earlier message (T1) with same assessment type.
        $messaget1 = $this->create_sora_aws_message_for_ordering(
            $this->student1->idnumber,
            $this->earliertimestamp,
            $astcode
        );
        $resultt1 = $this->call_process_message($processor, $messaget1);

        // Save the result to database.
        $this->save_message_to_aws_log($messaget1, $resultt1);

        // Assertions.
        $records = $DB->get_records(
            'local_sitsgradepush_aws_log',
            ['studentcode' => $this->student1->idnumber, 'astcode' => $astcode],
            'eventtimestamp ASC'
        );

        $this->assertCount(2, $records);

        // T1 (earlier) should be ignored.
        $t1 = array_shift($records);
        $this->assertEquals($this->earliertimestamp, $t1->eventtimestamp);
        $this->assertEquals(aws_queue_processor::STATUS_IGNORED, $t1->status);
        $this->assertEquals($astcode, $t1->astcode);

        // T2 (later) should be processed.
        $t2 = array_shift($records);
        $this->assertEquals($this->latertimestamp, $t2->eventtimestamp);
        $this->assertEquals(aws_queue_processor::STATUS_PROCESSED, $t2->status);
        $this->assertEquals($astcode, $t2->astcode);
    }

    /**
     * Test that later SORA messages are processed when earlier message already processed.
     *
     * @covers \local_sitsgradepush\extension\sora_queue_processor::process_message
     * @covers \local_sitsgradepush\extension\sora_queue_processor::is_message_out_of_order
     * @covers \local_sitsgradepush\extension\sora_queue_processor::should_ignore_message
     * @return void
     * @throws \dml_exception
     * @throws \ReflectionException
     */
    public function test_later_message_processed_when_earlier_already_processed(): void {
        global $DB;

        $astcode = 'CN01';

        // Create and process earlier message first (T1).
        $messaget1 = $this->create_sora_aws_message_for_ordering(
            $this->student1->idnumber,
            $this->earliertimestamp,
            $astcode
        );
        $processor = new sora_queue_processor();
        $resultt1 = $this->call_process_message($processor, $messaget1);

        // Save the result to database (simulate full flow).
        $this->save_message_to_aws_log($messaget1, $resultt1);

        // Create and process later message (T2) with same assessment type.
        $messaget2 = $this->create_sora_aws_message_for_ordering(
            $this->student1->idnumber,
            $this->latertimestamp,
            $astcode
        );
        $resultt2 = $this->call_process_message($processor, $messaget2);

        // Save the result to database.
        $this->save_message_to_aws_log($messaget2, $resultt2);

        // Assertions.
        $records = $DB->get_records(
            'local_sitsgradepush_aws_log',
            ['studentcode' => $this->student1->idnumber, 'astcode' => $astcode],
            'eventtimestamp ASC'
        );

        $this->assertCount(2, $records);

        // T1 (earlier) should be processed.
        $t1 = array_shift($records);
        $this->assertEquals($this->earliertimestamp, $t1->eventtimestamp);
        $this->assertEquals(aws_queue_processor::STATUS_PROCESSED, $t1->status);
        $this->assertEquals($astcode, $t1->astcode);

        // T2 (later) should also be processed.
        $t2 = array_shift($records);
        $this->assertEquals($this->latertimestamp, $t2->eventtimestamp);
        $this->assertEquals(aws_queue_processor::STATUS_PROCESSED, $t2->status);
        $this->assertEquals($astcode, $t2->astcode);
    }

    /**
     * Test that messages for different assessment types are processed independently.
     *
     * @covers \local_sitsgradepush\extension\sora_queue_processor::process_message
     * @covers \local_sitsgradepush\extension\sora_queue_processor::is_message_out_of_order
     * @return void
     */
    public function test_different_assessment_types_processed_independently(): void {
        global $DB;

        $astcode1 = 'CN01';
        $astcode2 = 'ED03';
        $processor = new sora_queue_processor();

        // Process later message for assessment type 1 (T2).
        $messaget2ast1 = $this->create_sora_aws_message_for_ordering(
            $this->student1->idnumber,
            $this->latertimestamp,
            $astcode1
        );
        $resultt2ast1 = $this->call_process_message($processor, $messaget2ast1);
        $this->save_message_to_aws_log($messaget2ast1, $resultt2ast1);

        // Process earlier message for assessment type 2 (T1) - should be processed, not ignored.
        $messaget1ast2 = $this->create_sora_aws_message_for_ordering(
            $this->student1->idnumber,
            $this->earliertimestamp,
            $astcode2
        );
        $resultt1ast2 = $this->call_process_message($processor, $messaget1ast2);
        $this->save_message_to_aws_log($messaget1ast2, $resultt1ast2);

        // Assertions for assessment type 2 - should be processed even though it has earlier timestamp.
        $recordsast2 = $DB->get_records(
            'local_sitsgradepush_aws_log',
            ['studentcode' => $this->student1->idnumber, 'astcode' => $astcode2],
            'eventtimestamp ASC'
        );
        $this->assertCount(1, $recordsast2);

        $t1ast2 = reset($recordsast2);
        $this->assertEquals(aws_queue_processor::STATUS_PROCESSED, $t1ast2->status);
    }

    /**
     * Test that RAA is not processed for ineligible assessment types.
     *
     * @covers \local_sitsgradepush\extensionmanager::update_sora_for_mapping
     * @covers \local_sitsgradepush\extensionmanager::is_ast_code_eligible_for_raa
     * @return void
     */
    public function test_raa_not_processed_for_ineligible_ast_code(): void {
        global $DB;

        $astcode = 'CN01';

        // Set CN01 as ineligible for RAA extension.
        set_config('raa_ineligible_ast_codes', $astcode, 'local_sitsgradepush');

        // Create a mapping for the assignment with the ineligible AST code.
        $mab = $DB->get_record('local_sitsgradepush_mab', ['mapcode' => 'LAWS0024A6UF', 'mabseq' => '002']);
        $this->insert_mapping($mab->id, $this->course1->id, $this->assign1, 'assign');
        $this->setup_test_student_data($mab);

        // Process all mappings for SORA.
        $this->process_all_mappings_for_sora();

        // Verify no override was created.
        $this->assertEmpty($DB->get_records('assign_overrides'));
    }

    /**
     * Create AWS SORA message array for testing message ordering.
     *
     * @param string $studentcode Student code.
     * @param int $timestamp Unix timestamp.
     * @param string $astcode Assessment type code.
     * @return array AWS message structure.
     */
    private function create_sora_aws_message_for_ordering(string $studentcode, int $timestamp, string $astcode): array {
        return [
            'Message' => json_encode([
                'entity' => [
                    'person_sora' => [
                        'person' => ['student_code' => $studentcode],
                        'type' => ['code' => sora::RAA_MESSAGE_TYPE_RAPAS],
                        'required_provisions' => [
                            [
                                'provision_tier' => 'TIER1',
                                'no_dys_ext' => '5',
                                'no_hrs_ext' => null,
                                'add_exam_time' => null,
                                'rest_brk_add_time' => null,
                                'asmnt_type_code' => $astcode,
                                'accessibility_assessment_status' => '5',
                            ],
                        ],
                    ],
                ],
                'changes' => ['no_dys_ext'],
            ]),
            'Timestamp' => $this->clock->now()->setTimestamp($timestamp)->format('Y-m-d\TH:i:s\Z'),
        ];
    }

    /**
     * Call protected process_message method using reflection.
     *
     * @param sora_queue_processor $processor
     * @param array $message
     * @return array
     * @throws \ReflectionException
     */
    private function call_process_message(sora_queue_processor $processor, array $message): array {
        $reflection = new \ReflectionClass($processor);
        $method = $reflection->getMethod('process_message');
        $method->setAccessible(true);
        return $method->invokeArgs($processor, [$message]);
    }

    /**
     * Simulate saving message result to AWS log database.
     *
     * @param array $message AWS message.
     * @param array $result Processing result.
     * @return void
     * @throws \dml_exception
     */
    private function save_message_to_aws_log(array $message, array $result): void {
        global $DB;

        $messagebody = json_decode($message['Message'], true);
        $studentcode = $messagebody['entity']['person_sora']['person']['student_code'] ?? null;
        $timestamp = isset($message['Timestamp']) ? $this->clock->now()->modify($message['Timestamp'])->getTimestamp() : null;

        $record = new \stdClass();
        $record->queuename = 'SORA';
        $record->messageid = 'test-message-' . uniqid();
        $record->studentcode = $studentcode;
        $record->astcode = $result['astcode'] ?? null;
        $record->eventtimestamp = $timestamp;
        $record->status = $result['status'];
        $record->attempts = 1;
        $record->payload = $message['Message'];
        $record->error_message = null;
        $record->ignore_reason = $result['ignore_reason'] ?? null;
        $record->timemodified = $this->clock->time();

        $DB->insert_record('local_sitsgradepush_aws_log', $record);
    }
}
