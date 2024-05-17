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

use grade_item;
use local_sitsgradepush\manager;

/**
 * Abstract class for activity assessment.
 *
 * @package    local_sitsgradepush
 * @copyright  2023 onwards University College London {@link https://www.ucl.ac.uk/}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @author     Alex Yeung <k.yeung@ucl.ac.uk>
 */
abstract class activity extends assessment {

    /** @var \stdClass Course module object */
    public \stdClass $coursemodule;

    /**
     * Constructor.
     *
     * @param \stdClass $coursemodule
     */
    public function __construct(\stdClass $coursemodule) {
        $this->coursemodule = $coursemodule;
        parent::__construct(assessmentfactory::SOURCETYPE_MOD, $coursemodule->id);
    }

    /**
     * Returns the assessment name.
     *
     * @return string
     */
    public function get_assessment_name(): string {
        return $this->sourceinstance->name;
    }

    /**
     * Returns the URL to the assessment page.
     *
     * @param bool $escape
     * @return string
     * @throws \moodle_exception
     */
    public function get_assessment_url(bool $escape): string {
        $url = new \moodle_url(
            '/mod/' . $this->get_module_name() . '/view.php',
            ['id' => $this->get_coursemodule_id()]
        );

        return $url->out($escape);
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
     * Get the course module object related to this assessment.
     *
     * @return \stdClass
     */
    public function get_course_module(): \stdClass {
        return $this->coursemodule;
    }

    /**
     * Get the type name of the assessment to display.
     *
     * @return string
     */
    public function get_display_type_name(): string {
        return get_module_types_names()[$this->coursemodule->modname];
    }

    /**
     * Get the module name. E.g. assign, quiz, turnitintooltwo.
     *
     * @return string
     */
    public function get_module_name(): string {
        return $this->coursemodule->modname;
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
     * Get all grade items related to this assessment.
     *
     * @return array
     */
    public function get_grade_items(): array {
        $gradeitems = grade_item::fetch_all([
            'itemtype' => $this->get_type(),
            'itemmodule' => $this->get_module_name(),
            'iteminstance' => $this->get_source_instance()->id,
            'courseid' => $this->get_course_id(),
        ]);

        return $gradeitems ?? [];
    }

    /**
     * Set the module instance.
     * @return void
     * @throws \dml_exception
     */
    protected function set_instance(): void {
        global $DB;
        $this->sourceinstance = $DB->get_record($this->coursemodule->modname, ['id' => $this->coursemodule->instance]);
    }
}
