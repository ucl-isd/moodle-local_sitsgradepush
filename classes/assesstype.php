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

namespace local_sitsgradepush;

use core_plugin_manager;
use local_assess_type\assess_type;
use local_sitsgradepush\assessment\assessmentfactory;

/**
 * Assessment type class for assessment categorization.
 *
 * @package     local_sitsgradepush
 * @copyright   2024 onwards University College London {@link https://www.ucl.ac.uk/}
 * @license     https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @author      Alex Yeung <k.yeung@ucl.ac.uk>
 */
class assesstype {

    /**
     * Update assessment type and lock status.
     *
     * @param int|\stdClass $mapping Mapping record ID or object.
     * @param string $action Action to perform.
     *
     * @throws \dml_exception
     */
    public static function update_assess_type(int|\stdClass $mapping, string $action): void {
        global $DB;

        try {
            if (!self::is_assess_type_installed()) {
                return;
            }

            // Get mapping record if it is not already an object.
            if (is_int($mapping)) {
                $mapping = $DB->get_record(manager::TABLE_ASSESSMENT_MAPPING, ['id' => $mapping]);
            }

            // Mapping is not found. It should not happen.
            if (empty($mapping)) {
                return;
            }

            // Mapped assessment is a course module, set grdade item ID to 0.
            if ($mapping->sourcetype === assessmentfactory::SOURCETYPE_MOD) {
                $cmid = $mapping->sourceid;
                $gradeitemid = 0;
            } else {
                // Mapped assessment is a gradebook item or category, set course module ID to 0.
                $cmid = 0;
                $assessment = assessmentfactory::get_assessment($mapping->sourcetype, $mapping->sourceid);
                $gradeitems = $assessment->get_grade_items();
                $gradeitemid = $gradeitems[0]->id;
            }

            // Set assessment type and lock status.
            if ($action === 'lock') {
                assess_type::update_type($mapping->courseid, assess_type::ASSESS_TYPE_SUMMATIVE, $cmid, $gradeitemid, 1);
            } else if ($action === 'unlock') {
                assess_type::update_type($mapping->courseid, assess_type::ASSESS_TYPE_SUMMATIVE, $cmid, $gradeitemid, 0);
            }
        } catch (\Exception $e) {
            logger::log('Failed to update assessment type and lock status.', null, null, $e->getMessage());
        }
    }

    /**
     * Check if the assessment type plugin is installed.
     *
     * @return bool
     */
    public static function is_assess_type_installed(): bool {
        // Check if the assessment type plugin is installed.
        return (bool)core_plugin_manager::instance()->get_plugin_info(
          'local_assess_type'
        );
    }
}
