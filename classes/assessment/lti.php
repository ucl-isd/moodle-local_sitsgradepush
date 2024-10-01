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
 * Class for LTI assessment.
 *
 * @package    local_sitsgradepush
 * @copyright  2024 onwards University College London {@link https://www.ucl.ac.uk/}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @author     David Watson <david-watson@ucl.ac.uk>
 */
class lti extends activity {

    /**
     * Get all participants.
     *
     * @return array
     */
    public function get_all_participants(): array {
        global $DB, $CFG;
        // For LTI_SETTING_ constants.
        require_once("$CFG->dirroot/mod/lti/locallib.php");

        $context = \context_module::instance($this->coursemodule->id);
        $capability = 'mod/lti:view';

        // Which type of LTI tool is this?
        $typeid = $this->sourceinstance->typeid;
        if (!$typeid) {
            $tool = lti_get_tool_by_url_match($this->sourceinstance->toolurl, $this->sourceinstance->course);
            if ($tool) {
                $typeid = $tool->id;
            }
        }

        // Has this tool been configured to accept grades globally or not?
        $acceptgradestool = $DB->get_field(
            'lti_types_config', 'value', ['typeid' => $typeid, 'name' => 'acceptgrades']
        );
        if ($acceptgradestool == LTI_SETTING_ALWAYS) {
            return get_enrolled_users($context, $capability);
        } else if ($acceptgradestool == LTI_SETTING_DELEGATE) {
            // Whether or not grades are accepted is determined at course level.
            return $this->sourceinstance->instructorchoiceacceptgrades == LTI_SETTING_ALWAYS
                ? get_enrolled_users($context, $capability)
                : [];
        }
        return [];
    }

    /**
     * Get the start date of this assessment.
     *
     * @return int|null
     */
    public function get_start_date(): ?int {
        // This activity does not have a start date.
        return null;
    }

    /**
     * Get the end date of this assessment.
     *
     * @return int|null
     */
    public function get_end_date(): ?int {
        // This activity does not have a start date.
        return null;
    }
}
