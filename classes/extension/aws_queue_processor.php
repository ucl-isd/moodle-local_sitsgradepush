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

namespace local_sitsgradepush\extension;

use core\clock;
use core\di;
use local_sitsgradepush\aws\sqs;
use local_sitsgradepush\logger;

/**
 * Parent class for queue processors.
 *
 * @package    local_sitsgradepush
 * @copyright  2024 onwards University College London {@link https://www.ucl.ac.uk/}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @author     Alex Yeung <k.yeung@ucl.ac.uk>
 */
abstract class aws_queue_processor {

    /** @var int Maximum number of messages to fetch per call. 10 is the highest number, limited by AWS */
    const MAX_MESSAGES = 10;

    /** @var int Visibility timeout in seconds */
    const VISIBILITY_TIMEOUT = 60;

    /** @var int Wait time in seconds */
    const WAIT_TIME_SECONDS = 5;

    /** @var string Message status - processed */
    const STATUS_PROCESSED = 'processed';

    /** @var string Message status - failed */
    const STATUS_FAILED = 'failed';

    /** @var string Message status - ignored */
    const STATUS_IGNORED = 'ignored';

    /** @var int Maximum number of batches */
    const MAX_BATCHES = 30;

    /** @var int Maximum number of messages to fetch */
    const MAX_MESSAGES_TO_PROCESS = 300;

    /** @var int Maximum execution time in seconds */
    const MAX_EXECUTION_TIME = 1800; // 30 minutes

    /** @var int Maximum number of attempts */
    const MAX_ATTEMPTS = 2;

    /**
     * Get the queue URL.
     *
     * @return string
     */
    abstract protected function get_queue_url(): string;

    /**
     * Process the message.
     *
     * @param array $messagebody AWS SQS message body
     * @return string Message processing status
     */
    abstract protected function process_message(array $messagebody): string;

    /**
     * Get the queue name.
     *
     * @return string
     */
    abstract protected function get_queue_name(): string;

    /**
     * Fetch messages from the queue.
     *
     * @param int $maxmessages Maximum number of messages to fetch
     * @param int $visibilitytimeout Visibility timeout in seconds
     * @param int $waittimeseconds Wait time in seconds
     * @return array
     */
    protected function fetch_messages(
        int $maxmessages = self::MAX_MESSAGES,
        int $visibilitytimeout = self::VISIBILITY_TIMEOUT,
        int $waittimeseconds = self::WAIT_TIME_SECONDS
    ): array {
        $sqs = new sqs();
        $result = $sqs->get_client()->receiveMessage([
            'QueueUrl' => $this->get_queue_url(),
            'MaxNumberOfMessages' => $maxmessages,
            'VisibilityTimeout' => $visibilitytimeout,
            'WaitTimeSeconds' => $waittimeseconds,
        ]);

        return $result->get('Messages') ?? [];
    }

    /**
     * Check should we process the message.
     *
     * @param string $messageid AWS SQS Message ID
     * @param array $messagebody AWS SQS Message body
     * @return bool True if message is processed already, false otherwise
     * @throws \dml_exception
     */
    protected function should_not_process_message(string $messageid, array $messagebody): bool {
        global $DB;

        try {
            // Skip if message received time + delay time is greater than current time.
            $delaytime = (int) (get_config('local_sitsgradepush', 'aws_delay_process_time') ?: 0);
            if (isset($messagebody['Timestamp']) &&
                strtotime($messagebody['Timestamp']) + $delaytime > di::get(clock::class)->time()) {
                mtrace("Skipping message due to delay time: {$messageid}");
                return true;
            }

            // Skip if message is already processed, ignored or exceeded maximum attempts.
            $sql = 'SELECT id
                    FROM {local_sitsgradepush_aws_log}
                    WHERE messageid = :messageid
                    AND (status = :processed OR status = :ignored OR attempts >= :attempts)';

            $handledmessages = $DB->record_exists_sql(
                $sql,
                [
                    'messageid' => $messageid,
                    'processed' => self::STATUS_PROCESSED,
                    'ignored' => self::STATUS_IGNORED,
                    'attempts' => self::MAX_ATTEMPTS,
                ]
            );
            if ($handledmessages) {
                mtrace("Skipping message due to already processed, ignored or exceeded maximum attempts: {$messageid}");
                return true;
            }

            return false;
        } catch (\Exception $e) {
            logger::log($e->getMessage(), null, 'Failed to check message status');
            mtrace("Skipping message due to exception: {$messageid}");
            return true;
        }
    }

    /**
     * Execute the queue processor with batch processing support
     *
     * @return void
     * @throws \Exception
     */
    public function execute(): void {
        try {
            $processedcount = 0;
            $batchnumber = 0;
            $starttime = time();

            do {
                // Check safety limits.
                if ($batchnumber >= self::MAX_BATCHES) {
                    mtrace("Maximum batch limit (" . self::MAX_BATCHES . ") reached");
                    break;
                }

                if ($processedcount >= self::MAX_MESSAGES_TO_PROCESS) {
                    mtrace("Maximum message limit (" . self::MAX_MESSAGES_TO_PROCESS . ") reached");
                    break;
                }

                $elapsedtime = time() - $starttime;
                if ($elapsedtime >= self::MAX_EXECUTION_TIME) {
                    mtrace("Maximum execution time (" . self::MAX_EXECUTION_TIME . " seconds) reached");
                    break;
                }

                // Fetch messages from the queue.
                $messages = $this->fetch_messages();
                if (empty($messages)) {
                    if ($batchnumber === 0) {
                        mtrace('No messages found.');
                    }
                    break;
                }

                $batchnumber++;
                mtrace(sprintf('Processing batch %d with %d messages...', $batchnumber, count($messages)));

                foreach ($messages as $message) {
                    try {
                        // Decode message body.
                        $messagebody = json_decode($message['Body'], true);
                        if (json_last_error() !== JSON_ERROR_NONE) {
                            throw new \Exception('Invalid JSON data: ' . json_last_error_msg());
                        }

                        if ($this->should_not_process_message($message['MessageId'], $messagebody)) {
                            continue;
                        }

                        $status = $this->process_message($messagebody);
                        $this->save_message_record($message, $this->get_queue_name(), $status);
                        $this->delete_message($message['ReceiptHandle']);
                        $processedcount++;
                    } catch (\Exception $e) {
                        logger::log($e->getMessage(), null, static::class . ' Processing Error');
                        $this->save_message_record($message, $this->get_queue_name(), self::STATUS_FAILED, $e->getMessage());
                    }
                }

            } while (!empty($messages));

            mtrace(sprintf('Completed processing %d messages in %d batches (%.2f seconds)',
                $processedcount,
                $batchnumber,
                time() - $starttime
            ));
        } catch (\Exception $e) {
            logger::log($e->getMessage(), null, static::class . ' Queue Error');
            throw $e;
        }
    }

    /**
     * Delete the message from the queue.
     *
     * @param string $receipthandle
     * @return void
     */
    protected function delete_message(string $receipthandle): void {
        $sqs = new sqs();
        $sqs->get_client()->deleteMessage([
            'QueueUrl' => $this->get_queue_url(),
            'ReceiptHandle' => $receipthandle,
        ]);
    }

    /**
     * Save message processing details to database
     *
     * @param array $message SQS message data
     * @param string $queuename Queue name
     * @param string $status Processing status
     * @param string|null $error Error message if any
     * @return bool|int Returns record ID on success, false on failure
     * @throws \dml_exception
     */
    protected function save_message_record(
        array $message,
        string $queuename,
        string $status = self::STATUS_PROCESSED,
        ?string $error = null
    ): bool|int {
        global $DB;

        try {
            // Check if message record already exists.
            $record = $DB->get_record('local_sitsgradepush_aws_log', ['messageid' => $message['MessageId']]);

            // Prepare data to save.
            $data = [
                'messageid' => $message['MessageId'],
                'status' => $status,
                'error_message' => $error,
                'timemodified' => time(),
                'queuename' => $queuename,
                'payload' => $message['Body'],
                'attempts' => $record ? $record->attempts + 1 : 1,
            ];

            // Update record if exists.
            if ($record) {
                $data['id'] = $record->id;
                $DB->update_record('local_sitsgradepush_aws_log', $data);
                return $record->id;
            }

            // Insert new record.
            return $DB->insert_record('local_sitsgradepush_aws_log', $data);
        } catch (\Exception $e) {
            logger::log($e->getMessage(), null, 'Failed to save message record');
            return false;
        }
    }
}
