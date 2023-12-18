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
 * External API for getting assessments update for page updates.
 *
 * @package    local_sitsgradepush
 * @copyright  2023 onwards University College London {@link https://www.ucl.ac.uk/}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @author     Alex Yeung <k.yeung@ucl.ac.uk>
 */
class get_assessments_update extends external_api {
    /**
     * Returns description of method parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters() {
        return new external_function_parameters([
            'courseid' => new external_value(PARAM_INT, 'Course ID', VALUE_REQUIRED),
            'coursemoduleid' => new external_value(PARAM_INT, 'Course module ID', VALUE_DEFAULT, 0),
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
            'assessments' => new external_value(PARAM_RAW, 'Assessments Latest Status', VALUE_REQUIRED),
            'message' => new external_value(PARAM_TEXT, 'Error message', VALUE_OPTIONAL),
        ]);
    }

    /**
     * Get the tasks progresses for a given course.
     *
     * @param int $courseid
     * @param int $coursemoduleid
     *
     * @return array
     */
    public static function execute(int $courseid, int $coursemoduleid = 0) {
        try {
            // Validate parameters.
            $params = self::validate_parameters(
                self::execute_parameters(),
                [
                    'courseid' => $courseid,
                    'coursemoduleid' => $coursemoduleid,
                ]
            );

            // Get updates.
            if (empty($assessments = manager::get_manager()
                ->get_data_for_page_update($params['courseid'], $params['coursemoduleid']))) {
                $assessments = [];
            }

            return [
                'success' => true,
                'assessments' => json_encode($assessments),
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'message' => $e->getMessage(),
            ];
        }
    }
}
