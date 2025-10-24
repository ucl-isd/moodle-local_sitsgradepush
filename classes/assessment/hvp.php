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
 * Class for hvp (mod_hvp plugin, not core H5P) assessment.
 * Note that the default for "Grade > Maximum grade" when HVP is added to a course is 10.
 * If it is to be a candidate for marks transfer, this needs to be set to 100 by the teacher.
 * Otherwise it will not be shown as a source for marks transfer.
 *
 * @package    local_sitsgradepush
 * @copyright  2024 onwards University College London {@link https://www.ucl.ac.uk/}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @author     David Watson <david-watson@ucl.ac.uk>
 */
class hvp extends activity {
    /**
     * Get all participants.
     *
     * @return array
     */
    public function get_all_participants(): array {
        return self::get_gradeable_enrolled_users_with_capability('mod/hvp:view');
    }

    /**
     * Get the start date of the assessment.
     *
     * @return int|null
     */
    public function get_start_date(): ?int {
        // Activity does not have a start date.
        return null;
    }

    /**
     * Get the end date of the assessment.
     *
     * @return int|null
     */
    public function get_end_date(): ?int {
        // Activity does not have an end date.
        return null;
    }
}
