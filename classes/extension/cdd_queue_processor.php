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

/**
 * Combined due date (CDD) queue processor.
 *
 * On every CDD update event the extension data in the message is never used. The processor always
 * re-fetches the authoritative combined due date data from the SITS API and re-applies it.
 *
 * @package    local_sitsgradepush
 * @copyright  2026 onwards University College London {@link https://www.ucl.ac.uk/}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @author     Alex Yeung <k.yeung@ucl.ac.uk>
 */
class cdd_queue_processor extends aws_queue_processor {
    /** @var string QUEUE_NAME */
    const QUEUE_NAME = 'CDD';

    /**
     * Get the queue URL.
     *
     * @return string
     * @throws \dml_exception
     */
    protected function get_queue_url(): string {
        return get_config('local_sitsgradepush', 'aws_cdd_sqs_queue_url');
    }

    /**
     * Process the aws combined due date message.
     *
     * @param array $messagebody Combined due date message data body.
     * @return array
     * @throws \coding_exception
     * @throws \moodle_exception
     * @throws \dml_exception
     */
    protected function process_message(array $messagebody): array {
        $cdd = new cdd();
        $cdd->set_properties_from_aws_message($messagebody['Message'] ?? '');

        // Extract event timestamp from AWS message, e.g. 2026-07-01T14:43:14.863Z.
        $eventtimestamp = strtotime($messagebody['Timestamp'] ?? '') ?: null;

        $result = [
            'studentcode' => $cdd->get_student_code(),
            'mabidentifier' => $cdd->get_mab_identifier(),
            'eventtimestamp' => $eventtimestamp,
            'ignore_reason' => null,
        ];

        // Check if we should ignore the message.
        $shouldignoreresult = $this->should_ignore_message($cdd, $eventtimestamp);
        if ($shouldignoreresult !== false) {
            $result['status'] = self::STATUS_IGNORED;
            $result['ignore_reason'] = $shouldignoreresult;
            return $result;
        }

        $mappings = $cdd->get_mappings_by_mab($cdd->get_mab_identifier());
        $cdd->process_extension($mappings);
        $result['status'] = self::STATUS_PROCESSED;

        return $result;
    }

    /**
     * Check if we should ignore the message.
     *
     * @param cdd $cdd Combined due date object.
     * @param int|null $eventtimestamp Event timestamp.
     * @return string|false Returns ignore reason string if should ignore, false otherwise.
     * @throws \dml_exception
     */
    protected function should_ignore_message(
        cdd $cdd,
        ?int $eventtimestamp
    ): string|false {
        // Check for out-of-order messages, scoped to the student and module.
        return $this->is_message_out_of_order($cdd->get_mab_identifier(), $cdd->get_student_code(), $eventtimestamp);
    }

    /**
     * Get the queue name.
     *
     * @return string
     */
    protected function get_queue_name(): string {
        return self::QUEUE_NAME;
    }

    /**
     * Check if the message is out of order by comparing with the latest processed message timestamp.
     *
     * @param string $mabidentifier MAB identifier, e.g. CCME0158A6UF-001.
     * @param string $studentcode Student code.
     * @param int|null $eventtimestamp Event timestamp.
     * @return string|false Returns ignore reason string if out of order, false otherwise.
     * @throws \dml_exception
     */
    protected function is_message_out_of_order(string $mabidentifier, string $studentcode, ?int $eventtimestamp): string|false {
        global $DB;

        // If no timestamp or student code, cannot determine order, process it.
        if (empty($eventtimestamp) || empty($studentcode)) {
            return false;
        }

        // Query for the latest processed message for this student and MAB in the CDD queue.
        $sql = "SELECT MAX(eventtimestamp) as latesttimestamp
                FROM {local_sitsgradepush_aws_log}
                WHERE queuename = :queuename
                AND studentcode = :studentcode
                AND mabidentifier = :mabidentifier
                AND status = :processed
                AND eventtimestamp IS NOT NULL";

        $result = $DB->get_record_sql($sql, [
            'queuename' => self::QUEUE_NAME,
            'studentcode' => $studentcode,
            'mabidentifier' => $mabidentifier,
            'processed' => self::STATUS_PROCESSED,
        ]);

        // If there is a later message already processed, ignore this one.
        if ($result && $result->latesttimestamp > $eventtimestamp) {
            return sprintf(
                'Out-of-order message for student %s. Current timestamp: %s, Latest processed: %s',
                $studentcode,
                date('Y-m-d H:i:s', $eventtimestamp),
                date('Y-m-d H:i:s', $result->latesttimestamp)
            );
        }

        return false;
    }
}
