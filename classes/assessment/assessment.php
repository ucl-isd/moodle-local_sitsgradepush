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
 * Parent class for assessment.
 *
 * @package    local_sitsgradepush
 * @copyright  2023 onwards University College London {@link https://www.ucl.ac.uk/}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @author     Alex Yeung <k.yeung@ucl.ac.uk>
 */
abstract class assessment implements iassessment {

    /** @var string Grade failed */
    const GRADE_FAIL = 'F';

    /** @var \stdClass Course module object */
    public \stdClass $coursemodule;

    /** @var \stdClass Module instance object */
    public \stdClass $moduleinstance;

    /**
     * Constructor.
     *
     * @param \stdClass $coursemodule
     * @throws \dml_exception
     */
    public function __construct(\stdClass $coursemodule) {
        $this->coursemodule = $coursemodule;
        $this->set_module_instance();
    }

    /**
     * Return the assessment name.
     *
     * @return string
     */
    public function get_assessment_name(): string {
        return $this->moduleinstance->name;
    }

    /**
     * Get the course module object related to this assessment.
     *
     * @return \stdClass
     */
    public function get_course_module(): \stdClass {
        return $this->coursemodule;
    }

    /**
     * Get the course module id.
     *
     * @return int
     */
    public function get_coursemodule_id(): int {
        return $this->coursemodule->id;
    }

    /**
     * Get the course id.
     *
     * @return int
     */
    public function get_course_id(): int {
        return $this->coursemodule->course;
    }

    /**
     * Get the course id.
     *
     * @return string
     */
    public function get_module_type(): string {
        return get_module_types_names()[$this->coursemodule->modname];
    }

    /**
     * Get the grade of a user.
     *
     * @param int $userid
     * @param int|null $partid
     * @return array|null
     */
    public function get_user_grade(int $userid, int $partid = null): ?array {
        $result = null;
        if ($grade = grade_get_grades(
            $this->coursemodule->course, 'mod', $this->coursemodule->modname, $this->coursemodule->instance, $userid)) {
            foreach ($grade->items as $item) {
                foreach ($item->grades as $grade) {
                    if ($grade->grade) {
                        if (is_numeric($grade->grade)) {
                            $manager = manager::get_manager();
                            $formattedmarks = $manager->get_formatted_marks($this->coursemodule->course, (float)$grade->grade);
                            $equivalentgrade = $this->get_equivalent_grade_from_mark((float)$grade->grade);
                            return [$grade->grade, $equivalentgrade, $formattedmarks];
                        }
                    }
                }
            }
        }

        return $result;
    }

    /**
     * Get the equivalent grade for a given mark.
     *
     * @param float $marks
     * @return string|null
     */
    protected function get_equivalent_grade_from_mark(float $marks): ?string {
        $equivalentgrade = null;
        if ($marks == 0) {
            $equivalentgrade = self::GRADE_FAIL;
        }
        return $equivalentgrade;
    }

    /**
     * Set the module instance.
     * @return void
     * @throws \dml_exception
     */
    protected function set_module_instance(): void {
        global $DB;
        $this->moduleinstance = $DB->get_record($this->coursemodule->modname, ['id' => $this->coursemodule->instance]);
    }
}
