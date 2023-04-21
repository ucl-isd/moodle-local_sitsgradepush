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
 * Class for Turnitin assignment submission.
 *
 * @package    local_sitsgradepush
 * @copyright  2023 onwards University College London {@link https://www.ucl.ac.uk/}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @author     Alex Yeung <k.yeung@ucl.ac.uk>
 */
class turnitintooltwo extends submission {
    /**
     * Get hand in datetime.
     *
     * @return string
     */
    public function get_handin_datetime(): string {
        if ($this->submissiondata) {
            return $this->get_iso8601_datetime($this->submissiondata->submission_modified);
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
        if (!$turnitintooltwo = $DB->get_record('turnitintooltwo', ['id' => $this->coursemodule->instance])) {
            throw new \moodle_exception('Turnitin assignment not found, id: ' . $this->coursemodule->instance);
        }

        $this->modinstance = $turnitintooltwo;
    }

    /**
     * Set submission.
     *
     * @return void
     * @throws \dml_exception
     * @throws \moodle_exception
     */
    protected function set_submission_data() {
        $this->submissiondata = $this->get_turnitintooltwo_submission();
    }

    /**
     * Return the latest Turnitin submission for a user.
     *
     * @return false|mixed|null
     * @throws \dml_exception
     */
    private function get_turnitintooltwo_submission() {
        global $DB;

        // Get student's submission.
        $submission = $DB->get_record(
            'turnitintooltwo_submissions',
            ['turnitintooltwoid' => $this->coursemodule->instance, 'userid' => $this->userid],
        );

        if ($submission) {
            return $submission;
        }

        return null;
    }
}
