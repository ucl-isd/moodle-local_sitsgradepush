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

use local_sitsgradepush\extension\models\cdd_event_message;
use local_sitsgradepush\extensionmanager;
use local_sitsgradepush\manager;

/**
 * Combined due date (CDD) queue processor.
 *
 * On every CDD update event the extension data in the message is never used. The processor always
 * re-fetches the authoritative student and combined due date data from the SITS API and re-applies it.
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
        $message = new cdd_event_message($messagebody['Message']);
        $sprcode = $message->get_sprcode();
        $studentcode = $message->get_student_code();

        // Extract event timestamp from AWS message.
        $eventtimestamp = isset($messagebody['Timestamp'])
            ? $this->clock->now()->modify($messagebody['Timestamp'])->getTimestamp()
            : null;

        // Resolve the MAB (component grade) row identified by the message.
        $mab = $this->get_mab_from_message($message);
        if (empty($mab)) {
            return [
                'status' => self::STATUS_IGNORED,
                'studentcode' => $studentcode,
                'eventtimestamp' => $eventtimestamp,
                'ignore_reason' => 'No matching MAB found for the message',
            ];
        }

        // The MAB identifier is used both for the out-of-order guard scope and mapping lookups.
        $mabidentifier = $mab->mapcode . '-' . $mab->mabseq;

        // Check for out-of-order messages, scoped to the student and module.
        $outofordermessage = $this->is_message_out_of_order($mabidentifier, $studentcode, $eventtimestamp);
        if ($outofordermessage !== false) {
            return [
                'status' => self::STATUS_IGNORED,
                'studentcode' => $studentcode,
                'astcode' => $mabidentifier,
                'eventtimestamp' => $eventtimestamp,
                'ignore_reason' => $outofordermessage,
            ];
        }

        // Re-fetch the students for the MAB and match the student by student programme route code.
        $students = manager::get_manager()->get_students_from_sits($mab, true, 2);
        $student = $this->find_student_by_sprcode($students, $sprcode);
        if (empty($student)) {
            return [
                'status' => self::STATUS_IGNORED,
                'studentcode' => $studentcode,
                'astcode' => $mabidentifier,
                'eventtimestamp' => $eventtimestamp,
                'ignore_reason' => 'Student not found in the MAB student list',
            ];
        }

        // Re-fetch the combined due date authoritatively (never cached). When no record is returned for the
        // student, fall back to a minimal record so the combined due date is treated as removed.
        $cddrecords = manager::get_manager()->get_combined_due_dates_from_sits($mab, $sprcode);
        $cddrecord = $cddrecords[$sprcode] ?? ['student_programme_route_code' => $sprcode];

        // Apply the combined due date across all mappings for the MAB.
        $cdd = new cdd();
        $cdd->set_properties_from_cdd_api($cddrecord);
        $mappings = $cdd->get_mappings_by_mab($mabidentifier);
        $cdd->process_extension($mappings);

        // Fallback when the student has no combined due date. The stale combined due date override is
        // already removed by process_extension(), so re-apply EC and SORA from the fresh API data.
        if (!$cdd->has_combined_due_date()) {
            foreach ($mappings as $mapping) {
                $fullmapping = manager::get_manager()->get_mab_and_map_info_by_mapping_id($mapping->id);
                if (empty($fullmapping)) {
                    continue;
                }
                extensionmanager::update_sora_for_mapping($fullmapping, [$student]);
                extensionmanager::update_ec_for_mapping($fullmapping, [$student]);
            }
        }

        return [
            'status' => self::STATUS_PROCESSED,
            'studentcode' => $studentcode,
            'astcode' => $mabidentifier,
            'eventtimestamp' => $eventtimestamp,
            'ignore_reason' => null,
        ];
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
     * Resolve the MAB (component grade) row identified by the event message.
     *
     * @param cdd_event_message $message
     * @return \stdClass|false The MAB row, or false if no matching MAB is found.
     * @throws \dml_exception
     */
    protected function get_mab_from_message(cdd_event_message $message): \stdClass|false {
        global $DB;

        return $DB->get_record(manager::TABLE_COMPONENT_GRADE, [
            'modcode' => $message->get_module_code(),
            'modocc' => $message->get_module_occurrence(),
            'academicyear' => $message->get_academic_year_code(),
            'periodslotcode' => $message->get_period_slot_code(),
            'mabseq' => $message->get_module_sequence(),
        ]);
    }

    /**
     * Find a student in the get students API response by student programme route code.
     *
     * @param array $students Students data from the SITS get students API.
     * @param string $sprcode Student programme route code.
     * @return array|null The matching student, or null if not found.
     */
    protected function find_student_by_sprcode(array $students, string $sprcode): ?array {
        foreach ($students as $student) {
            if (($student['association']['supplementary']['student_programme_route_code'] ?? '') === $sprcode) {
                return $student;
            }
        }

        return null;
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

        // Query for the latest processed message for this student and module in the CDD queue.
        // The MAB identifier is stored in the astcode column to scope ordering per module.
        $sql = "SELECT MAX(eventtimestamp) as latesttimestamp
                FROM {local_sitsgradepush_aws_log}
                WHERE queuename = :queuename
                AND studentcode = :studentcode
                AND astcode = :astcode
                AND status = :processed
                AND eventtimestamp IS NOT NULL";

        $result = $DB->get_record_sql($sql, [
            'queuename' => self::QUEUE_NAME,
            'studentcode' => $studentcode,
            'astcode' => $mabidentifier,
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
