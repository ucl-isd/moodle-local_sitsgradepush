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

namespace local_sitsgradepush\assessment;

/**
 * Interface for assessment.
 *
 * @package    local_sitsgradepush
 * @copyright  2023 onwards University College London {@link https://www.ucl.ac.uk/}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @author     Alex Yeung <k.yeung@ucl.ac.uk>
 */
interface iassessment {

    /**
     * Check if the assessment can be mapped to MAB.
     *
     * @param int|\stdClass $mab
     * @return bool
     */
    public function can_map_to_mab(int|\stdClass $mab): bool;

    /**
     * Get all participants of the assessment.
     *
     * @package local_sitsgradepush
     * @return array
     */
    public function get_all_participants(): array;

    /**
     * Return the assessment name.
     *
     * @package local_sitsgradepush
     * @return string
     */
    public function get_assessment_name(): string;

    /**
     * Return the URL to the assessment page.
     *
     * @param bool $escape
     * @return string
     */
    public function get_assessment_transfer_history_url(bool $escape): string;

    /**
     * Return the URL to the assessment page.
     *
     * @param bool $escape
     * @return string
     */
    public function get_assessment_url(bool $escape): string;

    /**
     * Get the course id.
     *
     * @return int
     */
    public function get_course_id(): int;

    /**
     * Get the type name of the assessment to display.
     *
     * @return string
     */
    public function get_display_type_name(): string;

    /**
     * Get the source id.
     *
     * @return int
     */
    public function get_id(): int;

    /**
     * Get the source type.
     *
     * @return string
     */
    public function get_type(): string;

    /**
     * Get all grade items of the assessment.
     *
     * @return array
     */
    public function get_grade_items(): array;

    /**
     * Get the grade of a user.
     *
     * @param int $userid
     * @param int|null $partid
     * @return array|null
     * @package local_sitsgradepush
     */
    public function get_user_grade(int $userid, ?int $partid = null): ?array;

    /**
     * Get the start date of this assessment.
     *
     * @return int|null
     */
    public function get_start_date(): ?int;

    /**
     * Get the end date of this assessment.
     *
     * @return int|null
     */
    public function get_end_date(): ?int;
}
