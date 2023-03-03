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
 * Class for assignment assessment.
 *
 * @package    local_sitsgradepush
 * @copyright  2023 onwards University College London {@link https://www.ucl.ac.uk/}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @author     Alex Yeung <k.yeung@ucl.ac.uk>
 */
class assign extends assessment {
    /** @var string DB table for storing quiz grades */
    const GRADE_TABLE = 'assign_grades';

    /**
     * Get all grades of the assignment.
     *
     * @return array
     * @throws \dml_exception
     */
    public function get_all_grades(): array {
        global $DB;
        $sql = "SELECT
                u.id AS 'userid',
                u.idnumber,
                u.firstname,
                u.lastname,
                ag.assignment AS 'assessmentid',
                ag.grade AS 'marks',
                ag.timemodified
                FROM {" . self::GRADE_TABLE . "} ag JOIN {user} u ON ag.userid = u.id
                WHERE ag.assignment = :id";
        return $DB->get_records_sql($sql, ['id' => $this->modinstanceid]);
    }

    /**
     * Set assessment name.
     *
     * @param int $id
     * @return void
     * @throws \dml_exception
     */
    protected function set_assessment_name(int $id) {
        global $DB;
        if ($assign = $DB->get_record('assign', ['id' => $id])) {
            $this->assessmentname = $assign->name;
        }
    }
}
