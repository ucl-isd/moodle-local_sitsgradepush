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

use context_course;
use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_single_structure;
use core_external\external_value;
use local_sitsgradepush\manager;

/**
 * External API for mapping an assessment to an SITS assessment component (MAB).
 *
 * @package    local_sitsgradepush
 * @copyright  2023 onwards University College London {@link https://www.ucl.ac.uk/}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @author     Alex Yeung <k.yeung@ucl.ac.uk>
 */
class map_assessment extends external_api {
    /**
     * Returns description of method parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters() {
        return new external_function_parameters([
            'courseid' => new external_value(PARAM_INT, 'Coruse ID', VALUE_REQUIRED),
            'sourcetype' => new external_value(PARAM_TEXT, 'Source Type', VALUE_REQUIRED),
            'sourceid' => new external_value(PARAM_INT, 'Source ID', VALUE_REQUIRED),
            'mabid' => new external_value(PARAM_INT, 'Assessment Component ID', VALUE_REQUIRED),
            'partid' => new external_value(PARAM_INT, 'Assessment Part ID', VALUE_DEFAULT, null),
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
     * Map an assessment to an SITS assessment component (MAB).
     *
     * @param int $courseid
     * @param string $sourcetype
     * @param int $sourceid
     * @param int $mabid
     * @param int|null $partid
     * @return array
     */
    public static function execute(int $courseid, string $sourcetype, int $sourceid, int $mabid, ?int $partid = null) {
        try {
            if (!has_capability('local/sitsgradepush:mapassessment', context_course::instance($courseid))) {
                throw new \moodle_exception('error:mapassessment', 'local_sitsgradepush');
            }

            $params = self::validate_parameters(
                self::execute_parameters(),
                [
                    'courseid' => $courseid,
                    'sourcetype' => $sourcetype,
                    'sourceid' => $sourceid,
                    'mabid' => $mabid,
                    'partid' => $partid,
                ]
            );

            $manager = manager::get_manager();
            $data = new \stdClass();
            $data->courseid = $params['courseid'];
            $data->componentgradeid = $params['mabid'];
            $data->sourcetype = $params['sourcetype'];
            $data->sourceid = $params['sourceid'];
            $data->partid = $params['partid'];
            $manager->save_assessment_mapping($data);

            return [
                'success' => true,
                'message' => 'Assessment mapped successfully.',
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'message' => $e->getMessage(),
            ];
        }
    }
}
