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

namespace local_sitsgradepush\submission;

/**
 * Class for lesson submission.
 *
 * @package    local_sitsgradepush
 * @copyright  2024 onwards University College London {@link https://www.ucl.ac.uk/}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @author     David Watson <david-watson@ucl.ac.uk>
 */
class lesson extends submission {
    /**
     * Get hand in datetime.
     *
     * @return string
     */
    public function get_handin_datetime(): string {
        if ($this->submissiondata) {
            return $this->get_iso8601_datetime($this->submissiondata->timesubmitted);
        } else {
            return "";
        }
    }

    /**
     * Set the module instance.
     *
     * @return void
     * @throws \dml_exception
     * @throws \moodle_exception
     */
    protected function set_module_instance() {
        global $DB;
        if (!$instance = $DB->get_record('lesson', ['id' => $this->coursemodule->instance])) {
            throw new \moodle_exception(
                'error:coursemodulenotfound', 'local_sitsgradepush', '', null,
                "Instance ID: " . $this->coursemodule->instance
            );
        }

        $this->modinstance = $instance;
    }

    /**
     * Set submission.
     *
     * @return void
     * @throws \dml_exception
     * @throws \moodle_exception
     */
    protected function set_submission_data(): void {
        global $DB;
        if ($this->modinstance->practice) {
            // If this is a "Practice Lesson" it does not appear in gradebook.
            return;
        }

        // User may have multiple attempts if "Retakes allowed" is set to yes.
        // In that case the grade may be the set to "Use maximum" or "Use mean".
        // If it's "Use maximum" we return the details for the highest graded attempt here.
        // If it's set to "Use mean", or retakes are set to not allowed, return the most recent attempt details held.

        $params = ['lessonid' => $this->modinstance->id, 'userid' => $this->userid];

        if ($this->modinstance->retake && $this->modinstance->usemaxgrade) {
            $maxgrade = $DB->get_field_sql(
                "SELECT MAX(grade) FROM {lesson_grades} WHERE lessonid = :lessonid AND userid = :userid",
                $params
            );
            if ($maxgrade !== false) {
                $params['maxgrade'] = $maxgrade;
                $mostrecentmaxgradetime = $DB->get_field_sql(
                "SELECT MAX(completed) FROM {lesson_grades}
                      WHERE lessonid = :lessonid AND userid = :userid AND grade = :maxgrade",
                    $params
                );
                $this->submissiondata = (object)['timesubmitted' => $mostrecentmaxgradetime];
            }
        } else {
            // We want the mean grade, or retakes is set to not allowed, so just use most recent submit time.
            $mostrecentgradetime = $DB->get_field_sql(
                "SELECT MAX(completed) FROM {lesson_grades}
                      WHERE lessonid = :lessonid AND userid = :userid",
                $params
            );
            $this->submissiondata = (object)['timesubmitted' => $mostrecentgradetime];
        }
    }
}
