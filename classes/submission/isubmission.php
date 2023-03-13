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

namespace local_sitsgradepush\submission;

/**
 * Interface for submission.
 *
 * @package    local_sitsgradepush
 * @copyright  2023 onwards University College London {@link https://www.ucl.ac.uk/}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @author     Alex Yeung <k.yeung@ucl.ac.uk>
 */
interface isubmission {
    /**
     * Get Original due datetime.
     *
     * @package local_sitsgradepush
     * @return string
     */
    public function get_original_due_datetime(): string;

    /**
     * Get current due datetime.
     *
     * @package local_sitsgradepush
     * @return string
     */
    public function get_current_due_datetime(): string;

    /**
     * Get hand in datetime.
     *
     * @package local_sitsgradepush
     * @return string
     */
    public function get_handin_datetime(): string;

    /**
     * Get hand in status.
     *
     * @package local_sitsgradepush
     * @return string
     */
    public function get_handin_status(): string;

    /**
     * Get hand in, e.g. 'true' or 'false'.
     *
     * @package local_sitsgradepush
     * @return string
     */
    public function get_handed_in(): string;

    /**
     * Get handed in blank, e.g. 'true' or 'false'.
     *
     * @package local_sitsgradepush
     * @return string
     */
    public function get_handed_in_blank(): string;

    /**
     * Get permitted submission period.
     *
     * @package local_sitsgradepush
     * @return string
     */
    public function get_permitted_submission_period(): string;

    /**
     * Get export staff.
     *
     * @package local_sitsgradepush
     * @return string
     */
    public function get_export_staff(): string;

    /**
     * Get export timestamp.
     *
     * @package local_sitsgradepush
     * @return string
     */
    public function get_export_timestamp(): string;

    /**
     * Get export flow id.
     *
     * @package local_sitsgradepush
     * @return string
     */
    public function get_export_flow_id(): string;

    /**
     * Get number of items.
     *
     * @package local_sitsgradepush
     * @return string
     */
    public function get_no_of_items(): string;
}
