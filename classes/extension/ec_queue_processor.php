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
use local_sitsgradepush\manager;

/**
 * EC queue processor.
 *
 * @package    local_sitsgradepush
 * @copyright  2025 onwards University College London {@link https://www.ucl.ac.uk/}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @author     Alex Yeung <k.yeung@ucl.ac.uk>
 */
class ec_queue_processor extends aws_queue_processor {

    /** @var string QUEUE_NAME */
    const QUEUE_NAME = 'EC';

    /** @var string EVENT_STATUS_COMPLETE */
    const EVENT_STATUS_COMPLETE = 'COMPLETE';

    /** @var string DECISION_TYPE_DECISION  */
    const DECISION_TYPE_DECISION = 'DECISION';

    /** @var string PROCESS_STATUS_PROCESSED */
    const PROCESS_STATUS_PROCESSED = 'P';

    /** @var string PROCESS_STATUS_DELETED */
    const PROCESS_STATUS_DELETED = 'D';

    /**
     * Get the queue URL.
     *
     * @return string
     * @throws \dml_exception
     */
    protected function get_queue_url(): string {
        return get_config('local_sitsgradepush', 'aws_ec_sqs_queue_url');
    }

    /**
     * Process the aws EC message.
     *
     * @param array $messagebody EC message data body
     *
     * @throws \coding_exception
     * @throws \moodle_exception
     * @throws \dml_exception
     */
    protected function process_message(array $messagebody): string {
        $ec = new ec();
        $ec->set_properties_from_aws_message($messagebody['Message']);

        // Check if we should ignore the message.
        if ($this->should_ignore_message($ec)) {
            return self::STATUS_IGNORED;
        }

        // Get the EC/DAP data from API. We cannot guarantee that the EC/DAP data updated has the latest new due date.
        // For example, a student may have multiple ECs, we need to know the latest new due date among all ECs / DAPs.
        if ($ec->get_data_source() === extension::DATASOURCE_AWS) {
            $mabidentifier = $ec->get_mab_identifier();

            // Check if MAB identifier matches expected pattern (e.g., AMER0038A4UG-001).
            if (empty($mabidentifier) || !preg_match('/^[A-Z0-9]+-\d{3}$/', $mabidentifier)) {
                // Empty MAB identifier or doesn't match expected pattern.
                throw new moodle_exception(
                    'error:invalid_mab_identifier',
                    'local_sitsgradepush',
                    '',
                    ['mabidentifier' => $ec->get_mab_identifier(), 'studentcode' => $ec->get_student_code()]
                );
            }

            $mab = explode('-', $mabidentifier);

            // Set EC data from API.
            $students = manager::get_manager()->get_students_from_sits(
                (object) ['mapcode' => $mab[0], 'mabseq' => $mab[1]],
                true,
                2,
                $ec->get_student_code()
            );

            if (empty($students)) {
                throw new \moodle_exception(
                    'error:student_not_found_from_api',
                    'local_sitsgradepush',
                    '',
                    ['mabidentifier' => $ec->get_mab_identifier(), 'studentcode' => $ec->get_student_code()]
                );
            }
            $ec->set_properties_from_get_students_api(reset($students));
        }

        // Process the extension.
        $ec->process_extension($ec->get_mappings_by_mab($ec->get_mab_identifier()));

        return self::STATUS_PROCESSED;
    }

    /**
     * Check if we should ignore the message.
     *
     * @param ec $ec
     * @return bool
     */
    protected function should_ignore_message(ec $ec): bool {
        $eventdata = $ec->get_event_data();
        $ecs = $eventdata->extenuating_circumstances;

        // Ignore EC event for the following conditions:
        // 1. The request status is not COMPLETE.
        // 2. The decision type is not DECISION.
        // 3. The process status is not 'D' (deleted) or 'P' (processed).
        if ($ecs->request->status !== self::EVENT_STATUS_COMPLETE ||
            $ecs->request->decision_type !== self::DECISION_TYPE_DECISION ||
            !($ecs->process_status === self::PROCESS_STATUS_DELETED || $ecs->process_status === self::PROCESS_STATUS_PROCESSED)) {
            return true;
        }

        // If there are no changes, we should ignore the message.
        if (empty($ec->get_extension_changes())) {
            return true;
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
