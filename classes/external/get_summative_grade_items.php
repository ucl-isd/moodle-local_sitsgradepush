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
use core_external\external_multiple_structure;
use core_external\external_single_structure;
use core_external\external_value;
use local_sitsgradepush\manager;

/**
 * External API for getting summative grade items for a given course.
 *
 * @package    local_sitsgradepush
 * @copyright  2024 onwards University College London {@link https://www.ucl.ac.uk/}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @author     Alex Yeung <k.yeung@ucl.ac.uk>
 */
class get_summative_grade_items extends external_api {
    /**
     * Returns description of method parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters() {
        return new external_function_parameters([
            'courseid' => new external_value(PARAM_INT, 'Course ID', VALUE_REQUIRED),
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
            'gradeitems' => new external_multiple_structure(
              new external_single_structure([
                'id' => new external_value(PARAM_INT, 'Grade item ID', VALUE_REQUIRED),
                'categoryid' => new external_value(PARAM_INT, 'Grade item category ID', VALUE_REQUIRED),
                'itemname' => new external_value(PARAM_TEXT, 'Grade item name', VALUE_REQUIRED),
                'itemtype' => new external_value(PARAM_TEXT, 'Grade item type', VALUE_REQUIRED),
                'itemmodule' => new external_value(PARAM_TEXT, 'Grade item module', VALUE_REQUIRED),
                'iteminstance' => new external_value(PARAM_INT, 'Grade item instance', VALUE_REQUIRED),
                'itemnumber' => new external_value(PARAM_INT, 'Grade item number', VALUE_REQUIRED),
              ], 'Grade item', VALUE_REQUIRED), 'List of grade items', VALUE_REQUIRED),
            'message' => new external_value(PARAM_TEXT, 'Error message', VALUE_OPTIONAL),
        ]);
    }

    /**
     * Get summative grade items for a given course.
     *
     * @param int $courseid
     * @return array
     */
    public static function execute(int $courseid) {
        try {
            // Validate parameters.
            $params = self::validate_parameters(
                self::execute_parameters(),
                [
                    'courseid' => $courseid,
                ]
            );

            return [
                'success' => true,
                'gradeitems' => manager::get_manager()->get_all_summative_grade_items($params['courseid']),
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'gradeitems' => [],
                'message' => $e->getMessage(),
            ];
        }
    }
}
