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

use local_sitsgradepush\manager;

/**
 * Class for unknown assessment.
 * This is a placeholder for assessments that are not found, possibly due to deletion of the mapped activity.
 *
 * @package    local_sitsgradepush
 * @copyright  2025 onwards University College London {@link https://www.ucl.ac.uk/}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @author     Alex Yeung <k.yeung@ucl.ac.uk>
 */
class unknownassessment extends assessment {
    /**
     * No participants for unknown assessment.
     *
     * @return array
     */
    public function get_all_participants(): array {
        return [];
    }

    /**
     * Return the assessment name.
     * No assessment name for unknown assessment.
     *
     * @return string
     */
    public function get_assessment_name(): string {
        return '';
    }

    /**
     * Return the URL to the assessment page.
     * No assessment URL for unknown assessment.
     *
     * @param bool $escape
     * @return string
     */
    public function get_assessment_url(bool $escape): string {
        return '';
    }

    /**
     * Get the course id.
     *
     * @return int
     */
    public function get_course_id(): int {
        return 0;
    }

    /**
     * Get the display type name.
     * No display type name for unknown assessment.
     *
     * @return string
     */
    public function get_display_type_name(): string {
        return '';
    }

    /**
     * Get grade items.
     * No grade items for unknown assessment.
     *
     * @return array
     */
    public function get_grade_items(): array {
        return [];
    }

    /**
     * Get user grade.
     * No user grade for unknown assessment.
     *
     * @param int $userid
     * @param int|null $partid
     * @return array|null
     */
    public function get_user_grade(int $userid, ?int $partid = null): ?array {
        return null;
    }

    /**
     * Set the source instance.
     * No source instance for unknown assessment.
     *
     * @return void
     */
    protected function set_instance(): void {
        $this->sourceinstance = null;
    }
}
