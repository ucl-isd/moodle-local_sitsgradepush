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
 * Class for hvp (mod_hvp plugin, not core H5P) submission.
 *
 * @package    local_sitsgradepush
 * @copyright  2024 onwards University College London {@link https://www.ucl.ac.uk/}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @author     David Watson <david-watson@ucl.ac.uk>
 */
class hvp extends submission {
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
        if (!$instance = $DB->get_record('hvp', ['id' => $this->coursemodule->instance])) {
            throw new \moodle_exception(
                'error:coursemodulenotfound', 'local_sitsgradepush', '', null,
                "Instance ID: " . $this->coursemodule->instance
            );
        }

        $this->modinstance = $instance;
    }

    /**
     * Set submission.
     * The submission log transfer will be skipped without this data.
     * {@see \local_sitsgradepush\manager::push_submission_log_to_sits()}
     * We get the item time from the gradebook.  (We don't have submissions stored in the mod_hvp local tables).
     * @return void
     * @see
     */
    protected function set_submission_data(): void {
        $grades = grade_get_grades(
            $this->coursemodule->course->id, 'mod', $this->coursemodule->modname, $this->modinstance->id, $this->userid
        );
        $gradedsubmissions = [];
        foreach ($grades->items as $item) {
            foreach ($item->grades as $grade) {
                if ($grade->grade) {
                    if (is_numeric($grade->grade)) {
                        $gradedsubmissions[] = $grade;
                    }
                }
                if (count($gradedsubmissions) > 1) {
                    // We don't expect more than one graded submission per user - mod_hvp records latest grade only.
                    // If there is more than one, our code is incorrect (maybe mod_hvp has changed).
                    throw new \coding_exception("Unexpected multiple grades found for user " . $this->userid);
                }
            }
        }
        if (count($gradedsubmissions) === 1) {
            $gradedtime = reset($gradedsubmissions)->dategraded;
            if ($gradedtime) {
                $this->submissiondata = (object)['timesubmitted' => $gradedtime];
            }
        }
    }
}
