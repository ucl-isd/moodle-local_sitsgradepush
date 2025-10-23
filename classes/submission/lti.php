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
 * Class for coursework plugin (mod_coursework) submission.
 *
 * @package    local_sitsgradepush
 * @copyright  2024 onwards University College London {@link https://www.ucl.ac.uk/}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @author     David Watson <david-watson@ucl.ac.uk>
 */
class lti extends submission {
    /**
     * Get hand in datetime.
     *
     * @return string
     */
    public function get_handin_datetime(): string {
        if ($this->submissiondata->datesubmitted ?? null) {
            return $this->get_iso8601_datetime($this->submissiondata->datesubmitted);
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
        if (!$instance = $DB->get_record('lti', ['id' => $this->coursemodule->instance])) {
            throw new \moodle_exception(
                'error:coursemodulenotfound',
                'local_sitsgradepush',
                '',
                null,
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

        // User may have multiple attempts.
        // In that case we return the most recent attempt details.
        // The activity type just writes the most recent attempt to the gradebook.
        // When adding LTI to a course, teacher is not given the option (as in Quiz or Lesson) of use highest mark, average etc.
        $params = ['ltiid' => $this->modinstance->id, 'userid' => $this->userid];
        $attempts = $DB->get_records(
            'lti_submission',
            $params,
            'datesubmitted DESC',
            '*',
            0,
            1
        );
        if (!empty($attempts)) {
            $attempt = reset($attempts);
            if ($attempt->datesubmitted) {
                $this->submissiondata = $attempt;
            }
        }
    }
}
