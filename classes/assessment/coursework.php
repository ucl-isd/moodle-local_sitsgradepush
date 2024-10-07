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
 * Class for coursework plugin (mod_coursework) assessment.
 *
 * @package    local_sitsgradepush
 * @copyright  2024 onwards University College London {@link https://www.ucl.ac.uk/}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @author     David Watson <david-watson@ucl.ac.uk>
 */
class coursework extends activity {

    /**
     * Get all participants.
     * @see \mod_coursework\models\coursework::get_students() which we don't use as it returns objects.
     * @return \stdClass[]
     */
    public function get_all_participants(): array {
        $context = \context_module::instance($this->coursemodule->id);
        $modinfo = get_fast_modinfo($this->get_course_id());
        $cm = $modinfo->get_cm($this->coursemodule->id);
        $info = new \core_availability\info_module($cm);

        $users = get_enrolled_users($context, 'mod/coursework:submit');
        return $info->filter_user_list($users);
    }

    /**
     * Get the start date of this assessment.
     *
     * @return int|null
     */
    public function get_start_date(): ?int {
        return $this->sourceinstance->startdate > 0 ? $this->sourceinstance->startdate : null;
    }

    /**
     * Get the end date of this assessment.
     *
     * @return int|null
     */
    public function get_end_date(): ?int {
        return $this->sourceinstance->deadline > 0 ? $this->sourceinstance->deadline : null;
    }

    /**
     * Is the underlying course module instance grade push eligible?
     * E.g. a "practice" lesson is not.
     * @return bool
     */
    public function grade_push_eligible(): bool {
        // For coursework, we always return true here.
        return true;
    }
}
