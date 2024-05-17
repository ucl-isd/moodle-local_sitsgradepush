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
 * Abstract class for gradebook assessments.
 *
 * @package    local_sitsgradepush
 * @copyright  2023 onwards University College London {@link https://www.ucl.ac.uk/}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @author     Alex Yeung <k.yeung@ucl.ac.uk>
 */
abstract class gradebook extends assessment {

    /**
     * Get all participants.
     *
     * @return array
     */
    public function get_all_participants(): array {
        global $CFG;
        require_once($CFG->dirroot . '/grade/lib.php');
        return get_gradable_users($this->get_course_id());
    }

    /**
     * Get assessment name.
     *
     * @return string
     */
    public function get_assessment_name(): string {
        return $this->get_source_instance()->get_name();
    }

    /**
     * Get course id.
     *
     * @return int
     */
    public function get_course_id(): int {
        return $this->get_source_instance()->courseid;
    }

    /**
     * Get user grade.
     *
     * @param int $userid
     * @param int|null $partid
     * @return array|null
     */
    public function get_user_grade(int $userid, int $partid = null): ?array {
        // Get user grade for this assessment.
        $grade = $this->get_source_instance()->get_final($userid);
        if ($grade && $grade->finalgrade) {
            $manager = manager::get_manager();
            $formattedmarks = $manager->get_formatted_marks($this->get_course_id(), (float)$grade->finalgrade);
            $equivalentgrade = $this->get_equivalent_grade_from_mark((float)$grade->finalgrade);
            return [$grade->finalgrade, $equivalentgrade, $formattedmarks];
        } else {
            return null;
        }
    }
}
