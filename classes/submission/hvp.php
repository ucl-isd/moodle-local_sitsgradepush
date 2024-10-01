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
class hvp extends submission {
    /**
     * Get hand in datetime.
     *
     * @return string
     */
    public function get_handin_datetime(): string {
        // We cannot provide a hand in time as we don't have one.
        // See notes under set_submission_data() below.
        return "";
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
     *
     * @return void
     */
    protected function set_submission_data(): void {
        // We don't have the time submitted stored in the mod_hvp local tables so can't use that.
        // The timecreated and timemodified fields are not set in grade_grades either.
        // There is a timemodified in mdl_grade_items_history, but we can't rely on "loggeduser" being our user ID.
        // So we can't set any data for hand in time here.
    }
}
