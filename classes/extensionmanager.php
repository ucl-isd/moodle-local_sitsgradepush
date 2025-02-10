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

use local_sitsgradepush\assessment\assessmentfactory;
use local_sitsgradepush\extension\extension;
use local_sitsgradepush\extension\sora;
use local_sitsgradepush\task\process_extensions_new_enrolment;

/**
 * Manager class for extension related operations.
 *
 * @package    local_sitsgradepush
 * @copyright  2024 onwards University College London {@link https://www.ucl.ac.uk/}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @author     Alex Yeung <k.yeung@ucl.ac.uk>
 */
class extensionmanager {

    /**
     * Update SORA extension for students in a mapping using the SITS get students API as the data source.
     *
     * @param \stdClass $mapping Assessment component mapping ID.
     * @param array $students Students data from the SITS get students API.
     * @return void
     * @throws \dml_exception|\moodle_exception
     */
    public static function update_sora_for_mapping(\stdClass $mapping, array $students): void {
        // Nothing to do if the extension is not enabled for the mapping.
        if ($mapping->enableextension !== '1') {
            return;
        }

        // If no students returned from SITS, nothing to do.
        if (empty($students)) {
            return;
        }

        // Process SORA extension for each student or the specified student if user id is provided.
        foreach ($students as $student) {
            try {
                $sora = new sora();
                $sora->set_properties_from_get_students_api($student);
                $sora->process_extension([$mapping]);
            } catch (\Exception $e) {
                $studentcode = $student["code"] ?? '';
                logger::log($e->getMessage(), null, "Mapping ID: $mapping->id, Student Idnumber: $studentcode");
            }
        }
    }

    /**
     * Check if the extension is enabled.
     *
     * @return bool
     * @throws \dml_exception
     */
    public static function is_extension_enabled(): bool {
        return get_config('local_sitsgradepush', 'extension_enabled') == '1';
    }

    /**
     * Check if the user is enrolling a gradable role.
     *
     * @param int $roleid Role ID.
     * @return bool
     */
    public static function user_is_enrolling_a_gradable_role(int $roleid): bool {
        global $CFG;

        $gradebookroles = !empty($CFG->gradebookroles) ? explode(',', $CFG->gradebookroles) : [];

        return in_array($roleid, $gradebookroles);
    }

    /**
     * Get the user enrolment events stored for a course.
     *
     * @param int $courseid Course ID.
     * @return array
     * @throws \dml_exception
     */
    public static function get_user_enrolment_events(int $courseid): array {
        global $DB;
        $sql = "SELECT ue.*
                FROM {local_sitsgradepush_enrol} ue
                WHERE ue.courseid = :courseid AND ue.attempts < :maxattempts";

        return $DB->get_records_sql(
            $sql,
            [
                'courseid' => $courseid,
                'maxattempts' => process_extensions_new_enrolment::MAX_ATTEMPTS,
            ],
            limitnum: process_extensions_new_enrolment::BATCH_LIMIT
        );
    }

    /**
     * Delete SORA overrides for a Moodle assessment.
     *
     * @param \stdClass $deletedmapping
     * @return void
     * @throws \dml_exception
     */
    public static function delete_sora_overrides(\stdClass $deletedmapping): void {
        try {
            // Get Moodle assessment.
            $assessment = assessmentfactory::get_assessment($deletedmapping->sourcetype, $deletedmapping->sourceid);

            // Nothing to do if the module type is not supported.
            if (!extension::is_module_supported($assessment->get_module_name())) {
                return;
            }

            // Delete all SORA overrides for the assessment.
            $assessment->delete_all_sora_overrides();
        } catch (\Exception $e) {
            logger::log($e->getMessage(), null, "Deleted Mapping: " . json_encode($deletedmapping));
        }
    }
}
