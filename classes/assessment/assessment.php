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
 * Parent class for assessment.
 *
 * @package    local_sitsgradepush
 * @copyright  2023 onwards University College London {@link https://www.ucl.ac.uk/}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @author     Alex Yeung <k.yeung@ucl.ac.uk>
 */
abstract class assessment implements iassessment {
    /** @var string Assessment name */
    public $assessmentname;

    /** @var \stdClass Course module object */
    public $coursemodule;

    /**
     * Set assessment name.
     *
     * @return void
     */
    abstract protected function set_assessment_name();

    /**
     * Constructor.
     *
     * @param \stdClass $coursemodule
     */
    public function __construct(\stdClass $coursemodule) {
        $this->coursemodule = $coursemodule;
        $this->set_assessment_name();
    }

    /**
     * Return the assessment name.
     *
     * @return string
     */
    public function get_assessment_name(): string {
        return $this->assessmentname;
    }

    /**
     * Get the course module object related to this assessment.
     *
     * @return \stdClass
     */
    public function get_course_module(): \stdClass {
        return $this->coursemodule;
    }
}
