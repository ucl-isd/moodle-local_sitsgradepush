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

    /** @var string Route for messages carrying a single required provision to apply directly. */
    const ROUTE_PROVISION = 'provision';

    /** @var string Route for messages reporting an RAA approval status change. */
    const ROUTE_STATUS = 'status';

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
        $raaeventmsg = $sora->get_raa_event_message();

        // Extract event timestamp from AWS message.
        $eventtimestamp = $this->get_event_timestamp($messagebody);

        // The message carries its own microsecond timestamp, which is the only reliable way to order
        // messages published within the same second. Fall back to the coarser SQS envelope time.
        $eventtimeus = $raaeventmsg->get_event_time_microseconds()
            ?? ($eventtimestamp !== null ? $eventtimestamp * 1000000 : null);

        // Decide how the message should be handled, if at all.
        $route = $this->get_processing_route($raaeventmsg);

        // Only provision messages are tied to a single assessment type, status messages are student wide.
        $astcode = $route === self::ROUTE_PROVISION ? $raaeventmsg->get_required_provisions()?->get_assessment_type_code() : null;

        $result = [
            'studentcode' => $sora->get_student_code(),
            'astcode' => $astcode,
            'eventtimestamp' => $eventtimestamp,
            'eventtimeus' => $eventtimeus,
        ];

        // Check if we should ignore the message.
        $ignoreresult = $this->should_ignore_message($sora, $route, $eventtimeus, $astcode);
        if ($ignoreresult !== false) {
            return $result + [
                'status' => self::STATUS_IGNORED,
                'ignore_reason' => $ignoreresult,
            ];
        }

        if ($route === self::ROUTE_PROVISION) {
            // Apply the provision carried by the message to the mappings of that assessment type.
            $sora->process_extension($sora->get_mappings_by_userid($sora->get_userid(), $astcode));
        } else {
            // The approval status crossed the boundary, refetch the student data from SITS to act on.
            $sora->process_status_change($sora->get_mappings_by_userid($sora->get_userid()));
        }

        return $result + [
            'status' => self::STATUS_PROCESSED,
            'ignore_reason' => null,
        ];
    }

    /**
     * Work out how the message should be processed.
     *
     * A RAPAS message carrying exactly one required provision for a known assessment type holds
     * everything needed to apply an extension, so it is processed from the message data. Any other
     * message is only actionable when it reports the RAA approval status crossing the approved
     * boundary, which is handled from the SITS API instead.
     *
     * @param raa_event_message $raaeventmsg
     * @return string|null One of the ROUTE_* constants, or null if the message is not actionable.
     */
    protected function get_processing_route(raa_event_message $raaeventmsg): ?string {
        if ($raaeventmsg->qualifies_for_provision_processing()) {
            return self::ROUTE_PROVISION;
        }

        if ($raaeventmsg->crosses_approved_boundary()) {
            return self::ROUTE_STATUS;
        }

        return null;
    }

    /**
     * Check if we should ignore the message.
     *
     * @param sora $sora
     * @param string|null $route One of the ROUTE_* constants, or null if the message is not actionable.
     * @param int|null $eventtimeus Event time of the message in microseconds since the epoch.
     * @param string|null $astcode
     * @return string|false Returns ignore reason string if should ignore, false otherwise
     */
    protected function should_ignore_message(sora $sora, ?string $route, ?int $eventtimeus, ?string $astcode): string|false {
        $raaeventmsg = $sora->get_raa_event_message();

        // If there are no changes, we should ignore the message.
        if (!$raaeventmsg->has_changes()) {
            return 'No changes detected in the message';
        }

        // The message does not carry a usable provision and does not change the approval status.
        if ($route === null) {
            $messagetype = $raaeventmsg->get_type_code() ?? 'NULL';
            return "No actionable RAA change in the message (type: {$messagetype})";
        }

        // Nothing can be applied without knowing which student the message is about.
        if (empty($sora->get_student_code())) {
            return 'Missing student code in the message';
        }

        // Check for out-of-order messages.
        $outofordermessage = $this->is_message_out_of_order(
            $astcode,
            $sora->get_student_code(),
            $eventtimeus
        );

        if ($outofordermessage !== false) {
            return $outofordermessage;
        }

        return false;
    }

    /**
     * Check if message is out of order by comparing with latest processed message timestamp.
     *
     * The comparison is made in microseconds because messages for the same student are routinely
     * published within the same second, which a second-precision comparison cannot separate.
     *
     * @param string|null $astcode
     * @param string $studentcode
     * @param int|null $eventtimeus Event time of the message in microseconds since the epoch.
     * @return string|false Returns ignore reason string if out of order, false otherwise
     */
    protected function is_message_out_of_order(
        ?string $astcode,
        string $studentcode,
        ?int $eventtimeus
    ): string|false {
        global $DB;

        // If no timestamp or student code, cannot determine order, process it.
        if (empty($eventtimeus) || empty($studentcode)) {
            return false;
        }

        // Query for the latest processed message for this student and assessment type in SORA queue.
        // Messages logged before the microsecond time was recorded cannot be ordered, so are skipped.
        $sql = "SELECT MAX(eventtimeus) as latesttimeus
                FROM {local_sitsgradepush_aws_log}
                WHERE queuename = :queuename
                AND studentcode = :studentcode
                AND status = :processed
                AND eventtimeus IS NOT NULL";

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
        if ($result && $result->latesttimeus > $eventtimeus) {
            return sprintf(
                'Out-of-order message for student %s. Current timestamp: %s, Latest processed: %s',
                $studentcode,
                $this->format_microsecond_time($eventtimeus),
                $this->format_microsecond_time((int) $result->latesttimeus)
            );
        }

        return false;
    }

    /**
     * Format a microsecond timestamp for display in an ignore reason.
     *
     * @param int $eventtimeus Microseconds since the epoch.
     * @return string
     */
    protected function format_microsecond_time(int $eventtimeus): string {
        return date('Y-m-d H:i:s', intdiv($eventtimeus, 1000000)) . '.' . sprintf('%06d', $eventtimeus % 1000000);
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
