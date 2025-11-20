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
class coursework extends submission {
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
        if (!$instance = $DB->get_record('coursework', ['id' => $this->coursemodule->instance])) {
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

        // We don't expect there will be more than one submission per user, but check that here and throw exception if so.
        // We use authorid to identify the user being graded, since userid may be the user who submitted on their behalf.
        $submissions = $DB->get_records_sql(
            "SELECT id, userid as submittedby, authorid as userid, timecreated, timemodified, finalisedstatus,
                    manualsrscode, createdby, lastupdatedby, allocatableid, allocatableuser, allocatablegroup,
                    allocatabletype, firstpublished, lastpublished, timesubmitted
                    FROM {coursework_submissions}
                    WHERE courseworkid = :courseworkid AND authorid = :gradeduserid",
            ['courseworkid' => $this->modinstance->id, 'gradeduserid' => $this->userid],
            0,
            2
        );
        $countrecords = count($submissions);
        if ($countrecords > 1) {
            throw new \coding_exception("Unexpected multiple attempts or grades found for user " . $this->userid);
        } else if ($countrecords == 1) {
            $this->submissiondata = reset($submissions);
        }
    }
}
