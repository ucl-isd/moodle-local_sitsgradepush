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
 * Class for assignment submission.
 *
 * @package    local_sitsgradepush
 * @copyright  2023 onwards University College London {@link https://www.ucl.ac.uk/}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @author     Alex Yeung <k.yeung@ucl.ac.uk>
 */
class assign extends submission {
    /**
     * Get hand in datetime.
     *
     * @return string
     */
    public function get_handin_datetime(): string {
        if ($this->submissiondata) {
            return $this->get_iso8601_datetime($this->submissiondata->timemodified);
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
        if (!$assign = $DB->get_record('assign', ['id' => $this->coursemodule->instance])) {
            throw new \moodle_exception('Assign not found, id: ' . $this->coursemodule->instance);
        }

        $this->modinstance = $assign;
    }

    /**
     * Set submission.
     *
     * @return void
     * @throws \dml_exception
     * @throws \moodle_exception
     */
    protected function set_submission_data(): void {
        $this->submissiondata = $this->get_assign_submission();
    }

    /**
     * Return the assignment submission for a user.
     *
     * @return mixed|\stdClass
     * @throws \dml_exception
     * @throws \moodle_exception
     */
    private function get_assign_submission() {
        global $DB;

        // Get student's moodle assignment.
        $submission = $DB->get_record(
            'assign_submission',
            ['assignment' => $this->coursemodule->instance, 'userid' => $this->userid, 'status' => 'submitted', 'latest' => 1]
        );

        if ($submission) {
            return $submission;
        }

        return null;
    }
}
