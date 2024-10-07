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
        if (!self::grade_push_eligible()) {
            // If this is a "Practice Lesson" it does not appear in gradebook.
            return [];
        }
        $context = \context_module::instance($this->coursemodule->id);
        return get_enrolled_users($context, 'mod/lesson:view');
    }

    /**
     * Get the start date of this assessment.
     *
     * @return int|null
     */
    public function get_start_date(): ?int {
        return self::grade_push_eligible() && $this->sourceinstance->available > 0
            ? $this->sourceinstance->available : null;
    }

    /**
     * Get the end date of this assessment.
     *
     * @return int|null
     */
    public function get_end_date(): ?int {
        return self::grade_push_eligible() && $this->sourceinstance->deadline > 0
            ? $this->sourceinstance->deadline : null;
    }


    /**
     * Is the underlying course module instance grade push eligible?
     * E.g. a "practice" lesson is not.
     * @return bool
     */
    public function grade_push_eligible(): bool {
        // A "practice" lesson is not eligible (so will not be shown under "Select source").
       return !$this->sourceinstance->practice;
    }
}
