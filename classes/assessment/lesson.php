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
 * Class for lesson assessment.
 *
 * @package    local_sitsgradepush
 * @copyright  2024 onwards University College London {@link https://www.ucl.ac.uk/}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @author     David Watson <david-watson@ucl.ac.uk>
 */
class lesson extends activity {

    /**
     * Get all participants.
     *
     * @return array
     */
    public function get_all_participants(): array {
        if ($this->sourceinstance->practice) {
            // If this is a "Practice Lesson" it does not appear in gradebook.
            return [];
        }
        $enrolledusers = get_enrolled_users($this->context, 'mod/lesson:view');
        // Filter out non-gradeable users e.g. teachers.
        $gradeableids = self::get_gradeable_user_ids();
        return array_filter($enrolledusers, function($u) use ($gradeableids) {
            return in_array($u->id, $gradeableids);
        });
    }

    /**
     * Get the start date of this assessment.
     *
     * @return int|null
     */
    public function get_start_date(): ?int {
        return !$this->sourceinstance->practice && $this->sourceinstance->available > 0
            ? $this->sourceinstance->available : null;
    }

    /**
     * Get the end date of this assessment.
     *
     * @return int|null
     */
    public function get_end_date(): ?int {
        return !$this->sourceinstance->practice && $this->sourceinstance->deadline > 0
            ? $this->sourceinstance->deadline : null;
    }

    /**
     * Check assessment is valid for mapping.
     *
     * @return \stdClass
     */
    public function check_assessment_validity(): \stdClass {
        if ($this->sourceinstance->practice) {
            return $this->set_validity_result(false, 'error:lesson_practice');
        }
        return parent::check_assessment_validity();
    }
}
