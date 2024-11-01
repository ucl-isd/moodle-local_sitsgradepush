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

    /** @var int Maximum number of batches */
    const MAX_BATCHES = 30;

    /** @var int Maximum number of messages to fetch */
    const MAX_MESSAGES_TO_PROCESS = 300;

    /** @var int Maximum execution time in seconds */
    const MAX_EXECUTION_TIME = 1800; // 30 minutes

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
     * @return void
     */
    abstract protected function process_message(array $messagebody): void;

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
     * Check if message is already processed.
     *
     * @param string $messageid AWS SQS Message ID
     * @return bool True if message is processed already, false otherwise
     * @throws \dml_exception
     */
    protected function is_processed_message(string $messageid): bool {
        global $DB;

        try {
            // Allow processing if message has not been processed successfully.
            return $DB->record_exists(
                'local_sitsgradepush_aws_log',
                ['messageid' => $messageid, 'status' => self::STATUS_PROCESSED]
            );
        } catch (\Exception $e) {
            logger::log($e->getMessage(), null, 'Failed to check message status');
            return false;
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
                        if ($this->is_processed_message($message['MessageId'])) {
                            mtrace("Skipping processed message: {$message['MessageId']}");
                            continue;
                        }
                        $data = json_decode($message['Body'], true);
                        if (json_last_error() !== JSON_ERROR_NONE) {
                            throw new \Exception('Invalid JSON data: ' . json_last_error_msg());
                        }
                        $this->process_message($data);
                        $this->save_message_record($message);
                        $this->delete_message($message['ReceiptHandle']);
                        $processedcount++;
                    } catch (\Exception $e) {
                        logger::log($e->getMessage(), null, static::class . ' Processing Error');
                        $this->save_message_record($message, self::STATUS_FAILED, $e->getMessage());
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
     * @param string $status Processing status
     * @param string|null $error Error message if any
     * @return bool|int Returns record ID on success, false on failure
     * @throws \dml_exception
     */
    protected function save_message_record(
        array $message,
        string $status = self::STATUS_PROCESSED,
        ?string $error = null
    ): bool|int {
        global $DB, $USER;

        try {
            $record = new \stdClass();
            $record->messageid = $message['MessageId'];
            $record->receipthandle = $message['ReceiptHandle'];
            $record->queueurl = $this->get_queue_url();
            $record->status = $status;
            $record->payload = $message['Body'];
            $record->error_message = $error;
            $record->timecreated = time();
            $record->usermodified = $USER->id;

            return $DB->insert_record('local_sitsgradepush_aws_log', $record);
        } catch (\Exception $e) {
            logger::log($e->getMessage(), null, 'Failed to save message record');
            return false;
        }
    }
}
