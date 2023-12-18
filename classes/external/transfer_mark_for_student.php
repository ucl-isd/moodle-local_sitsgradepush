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
namespace local_sitsgradepush\external;

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_single_structure;
use core_external\external_value;
use local_sitsgradepush\manager;

/**
 * External API for transfer mark for a student.
 *
 * @package    local_sitsgradepush
 * @copyright  2023 onwards University College London {@link https://www.ucl.ac.uk/}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @author     Alex Yeung <k.yeung@ucl.ac.uk>
 */
class transfer_mark_for_student extends external_api {
    /**
     * Returns description of method parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters() {
        return new external_function_parameters([
            'assessmentmappingid' => new external_value(PARAM_INT, 'Assessment mapping ID', VALUE_REQUIRED),
            'userid' => new external_value(PARAM_INT, 'User ID', VALUE_REQUIRED),
        ]);
    }

    /**
     * Returns description of method result value.
     *
     * @return \external_single_structure
     */
    public static function execute_returns() {
        return new external_single_structure([
            'success' => new external_value(PARAM_BOOL, 'Result of request', VALUE_REQUIRED),
            'message' => new external_value(PARAM_TEXT, 'Error message', VALUE_OPTIONAL),
        ]);
    }

    /**
     * Transfer marks and submission logs for a student.
     *
     * @param int $assessmentmappingid
     * @param int $userid
     *
     * @return array
     */
    public static function execute(int $assessmentmappingid, int $userid) {
        global $DB;
        try {
            $params = self::validate_parameters(
                self::execute_parameters(),
                [
                    'assessmentmappingid' => $assessmentmappingid,
                    'userid' => $userid,
                ]
            );

            $manager = manager::get_manager();

            // Get assessment mapping.
            $mapping = $DB->get_record(manager::TABLE_ASSESSMENT_MAPPING, ['id' => $params['assessmentmappingid']]);

            if (empty($mapping)) {
                throw new \moodle_exception('error:assessmentmapping', 'local_sitsgradepush', '', $params['assessmentmappingid']);
            }

            // Check if user has permission to transfer marks.
            if (!has_capability('local/sitsgradepush:pushgrade', \context_course::instance($mapping->courseid))) {
                throw new \moodle_exception('error:pushgradespermission', 'local_sitsgradepush');
            }

            // Get assessment mapping.
            $assessmentmapping = $manager->get_assessment_mappings($mapping->coursemoduleid, $mapping->componentgradeid);

            if (!$manager->push_grade_to_sits($assessmentmapping, $params['userid'])) {
                throw new \moodle_exception('error:marks_transfer_failed', 'local_sitsgradepush');
            }

            if (!$manager->push_submission_log_to_sits($assessmentmapping, $params['userid'])) {
                throw new \moodle_exception('error:submission_log_transfer_failed', 'local_sitsgradepush');
            }

            return [
                'success' => true,
                'message' => get_string('marks_transferred_successfully', 'local_sitsgradepush'),
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'message' => $e->getMessage(),
            ];
        }
    }
}
