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

        // Create and process later message first (T2).
        $messaget2 = $this->create_sora_aws_message_for_ordering($this->student1->idnumber, $this->latertimestamp);
        $processor = new sora_queue_processor();
        $resultt2 = $this->call_process_message($processor, $messaget2);

        // Save the result to database (simulate full flow).
        $this->save_message_to_aws_log($messaget2, $resultt2);

        // Create and process earlier message (T1).
        $messaget1 = $this->create_sora_aws_message_for_ordering($this->student1->idnumber, $this->earliertimestamp);
        $resultt1 = $this->call_process_message($processor, $messaget1);

        // Save the result to database.
        $this->save_message_to_aws_log($messaget1, $resultt1);

        // Assertions.
        $records = $DB->get_records(
            'local_sitsgradepush_aws_log',
            ['studentcode' => $this->student1->idnumber],
            'eventtimestamp ASC'
        );

        $this->assertCount(2, $records);

        // T1 (earlier) should be ignored.
        $t1 = array_shift($records);
        $this->assertEquals($this->earliertimestamp, $t1->eventtimestamp);
        $this->assertEquals(aws_queue_processor::STATUS_IGNORED, $t1->status);

        // T2 (later) should be processed.
        $t2 = array_shift($records);
        $this->assertEquals($this->latertimestamp, $t2->eventtimestamp);
        $this->assertEquals(aws_queue_processor::STATUS_PROCESSED, $t2->status);
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

        // Create and process earlier message first (T1).
        $messaget1 = $this->create_sora_aws_message_for_ordering($this->student1->idnumber, $this->earliertimestamp);
        $processor = new sora_queue_processor();
        $resultt1 = $this->call_process_message($processor, $messaget1);

        // Save the result to database (simulate full flow).
        $this->save_message_to_aws_log($messaget1, $resultt1);

        // Create and process later message (T2).
        $messaget2 = $this->create_sora_aws_message_for_ordering($this->student1->idnumber, $this->latertimestamp);
        $resultt2 = $this->call_process_message($processor, $messaget2);

        // Save the result to database.
        $this->save_message_to_aws_log($messaget2, $resultt2);

        // Assertions.
        $records = $DB->get_records(
            'local_sitsgradepush_aws_log',
            ['studentcode' => $this->student1->idnumber],
            'eventtimestamp ASC'
        );

        $this->assertCount(2, $records);

        // T1 (earlier) should be processed.
        $t1 = array_shift($records);
        $this->assertEquals($this->earliertimestamp, $t1->eventtimestamp);
        $this->assertEquals(aws_queue_processor::STATUS_PROCESSED, $t1->status);

        // T2 (later) should also be processed.
        $t2 = array_shift($records);
        $this->assertEquals($this->latertimestamp, $t2->eventtimestamp);
        $this->assertEquals(aws_queue_processor::STATUS_PROCESSED, $t2->status);
    }

    /**
     * Create AWS SORA message array for testing message ordering.
     *
     * @param string $studentcode
     * @param int $timestamp Unix timestamp
     * @return array AWS message structure
     */
    private function create_sora_aws_message_for_ordering(string $studentcode, int $timestamp): array {
        return [
            'Message' => json_encode([
                'entity' => [
                    'person_sora' => [
                        'person' => ['student_code' => $studentcode],
                        'type' => ['code' => sora::SORA_MESSAGE_TYPE_RAPXR],
                        'extra_duration' => '00:35',
                        'rest_duration' => '00:00',
                    ],
                ],
                'changes' => ['extra_duration'],
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
     * @param array $message AWS message
     * @param array $result Processing result
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
