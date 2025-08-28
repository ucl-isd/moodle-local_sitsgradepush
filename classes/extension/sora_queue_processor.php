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
    protected function process_message(array $messagebody): string {
        $sora = new sora();
        $sora->set_properties_from_aws_message($messagebody['Message']);

        // Check if we should ignore the message.
        if ($this->should_ignore_message($sora)) {
            return self::STATUS_IGNORED;
        }

        // Get all mappings for the student.
        $mappings = $sora->get_mappings_by_userid($sora->get_userid());
        $sora->process_extension($mappings);

        return self::STATUS_PROCESSED;
    }

    /**
     * Check if we should ignore the message.
     *
     * @param sora $sora
     * @return bool
     */
    protected function should_ignore_message(sora $sora): bool {
        // As the assessment api only returns exam type SORA, we only process exam type SORA update from AWS.
        // RAPXR type is the new exam type code to replace EXAM type, so we also process RAPXR type SORA update.
        if ($sora->get_sora_message_type() !== sora::SORA_MESSAGE_TYPE_EXAM &&
            $sora->get_sora_message_type() !== sora::SORA_MESSAGE_TYPE_RAPXR) {
            return true;
        }

        // If there are no changes, we should ignore the message.
        if (empty($sora->get_extension_changes())) {
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
