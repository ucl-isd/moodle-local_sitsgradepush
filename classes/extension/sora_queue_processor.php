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

use core\exception\moodle_exception;
use local_sitsgradepush\extension\models\raa_event_message;
use local_sitsgradepush\extension\models\raa_required_provisions;

/**
 * SORA queue processor.
 *
 * @package    local_sitsgradepush
 * @copyright  2024 onwards University College London {@link https://www.ucl.ac.uk/}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @author     Alex Yeung <k.yeung@ucl.ac.uk>
 */
class sora_queue_processor extends aws_queue_processor {
    /** @var string QUEUE_NAME */
    const QUEUE_NAME = 'SORA';

    /**
     * Get the queue URL.
     *
     * @return string
     * @throws \dml_exception
     */
    protected function get_queue_url(): string {
        return get_config('local_sitsgradepush', 'aws_sora_sqs_queue_url');
    }

    /**
     * Process the aws SORA message.
     *
     * @param array $messagebody SORA message data body
     *
     * @throws \coding_exception
     * @throws \moodle_exception
     * @throws \dml_exception
     */
    protected function process_message(array $messagebody): array {
        $sora = new sora();
        $sora->set_properties_from_aws_message($messagebody['Message']);
        $astcode = $sora->raarequiredprovisions?->get_assessment_type_code();

        // Extract event timestamp from AWS message.
        $eventtimestamp = isset($messagebody['Timestamp'])
            ? $this->clock->now()->modify($messagebody['Timestamp'])->getTimestamp()
            : null;

        // Check if we should ignore the message.
        $ignoreresult = $this->should_ignore_message($sora, $eventtimestamp, $astcode);
        if ($ignoreresult !== false) {
            return [
                'status' => self::STATUS_IGNORED,
                'studentcode' => $sora->get_student_code(),
                'astcode' => $astcode,
                'eventtimestamp' => $eventtimestamp,
                'ignore_reason' => $ignoreresult,
            ];
        }

        // Get all mappings for the student.
        $mappings = $sora->get_mappings_by_userid($sora->get_userid(), $astcode);
        $sora->process_extension($mappings);

        return [
            'status' => self::STATUS_PROCESSED,
            'studentcode' => $sora->get_student_code(),
            'astcode' => $astcode,
            'eventtimestamp' => $eventtimestamp,
            'ignore_reason' => null,
        ];
    }

    /**
     * Check if we should ignore the message.
     *
     * @param sora $sora
     * @param int|null $eventtimestamp
     * @param string|null $astcode
     * @return string|false Returns ignore reason string if should ignore, false otherwise
     */
    protected function should_ignore_message(sora $sora, ?int $eventtimestamp, ?string $astcode): string|false {
        // Special case: should not ignore if RAA status has changed.
        // RAA type could be non-RAPAS.
        $raaeventmsg = $sora->get_raa_event_message();
        if ($raaeventmsg->has_status_changed()) {
            return false;
        }

        // RAPAS is the AAA record type that links to ARP records with extension data for each assessment type.
        // Skip if it is not RAPAS.
        if ($raaeventmsg->get_type_code() !== sora::RAA_MESSAGE_TYPE_RAPAS) {
            $messagetype = $raaeventmsg->get_type_code() ?? 'NULL';
            return "SORA message type is not RAPAS (type: {$messagetype})";
        }

        // If there are no changes, we should ignore the message.
        if (!$raaeventmsg->has_changes()) {
            return 'No changes detected in the message';
        }

        // Check for out-of-order messages.
        $outofordermessage = $this->is_message_out_of_order(
            $astcode,
            $sora->get_student_code(),
            $eventtimestamp
        );

        if ($outofordermessage !== false) {
            return $outofordermessage;
        }

        return false;
    }

    /**
     * Check if message is out of order by comparing with latest processed message timestamp.
     *
     * @param string|null $astcode
     * @param string $studentcode
     * @param int|null $eventtimestamp
     * @return string|false Returns ignore reason string if out of order, false otherwise
     */
    protected function is_message_out_of_order(
        ?string $astcode,
        string $studentcode,
        ?int $eventtimestamp
    ): string|false {
        global $DB;

        // If no timestamp or student code, cannot determine order, process it.
        if (empty($eventtimestamp) || empty($studentcode)) {
            return false;
        }

        // Query for the latest processed message for this student and assessment type in SORA queue.
        $sql = "SELECT MAX(eventtimestamp) as latesttimestamp
                FROM {local_sitsgradepush_aws_log}
                WHERE queuename = :queuename
                AND studentcode = :studentcode
                AND status = :processed
                AND eventtimestamp IS NOT NULL";

        $params = [
            'queuename' => self::QUEUE_NAME,
            'studentcode' => $studentcode,
            'processed' => self::STATUS_PROCESSED,
        ];

        // Handle null astcode with IS NULL instead of parameter binding.
        if (is_null($astcode)) {
            $sql .= " AND astcode IS NULL";
        } else {
            $sql .= " AND astcode = :astcode";
            $params['astcode'] = $astcode;
        }

        $result = $DB->get_record_sql($sql, $params);

        // If there is a later message already processed, ignore this one.
        if ($result && $result->latesttimestamp > $eventtimestamp) {
            $currentts = date('Y-m-d H:i:s', $eventtimestamp);
            $latestts = date('Y-m-d H:i:s', $result->latesttimestamp);
            return sprintf(
                'Out-of-order message for student %s. Current timestamp: %s, Latest processed: %s',
                $studentcode,
                $currentts,
                $latestts
            );
        }

        return false;
    }

    /**
     * Get the queue name.
     *
     * @return string
     */
    protected function get_queue_name(): string {
        return self::QUEUE_NAME;
    }
}
