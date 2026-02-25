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

use core\clock;
use core\di;
use local_sitsgradepush\assessment\assessment;
use local_sitsgradepush\assessment\assessmentfactory;
use local_sitsgradepush\extension\ec;
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
    /** @var string DB table for storing overrides */
    const TABLE_OVERRIDES = 'local_sitsgradepush_overrides';

    /** @var string Extension name for SORA */
    const EXTENSION_SORA = 'SORA';

    /** @var string Extension name for EC */
    const EXTENSION_EC = 'EC';

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

        // Check if the AST code is eligible for RAA extension.
        if (!self::is_ast_code_eligible_for_raa($mapping->astcode)) {
            return;
        }

        // Process SORA extension for each student.
        foreach ($students as $student) {
            try {
                $sora = new sora();
                $sora->set_properties_from_get_students_api($student);
                $sora->process_extension([$mapping]);
            } catch (\Exception $e) {
                $studentcode = $student['association']['supplementary']['student_code'] ?? '';
                logger::log($e->getMessage(), null, "Mapping ID: $mapping->id, Student Idnumber: $studentcode");
            }
        }
    }

    /**
     * Update EC extension for students in a mapping using the SITS get students API as the data source.
     *
     * @param \stdClass $mapping Assessment component mapping ID.
     * @param array $students Students data from the SITS get students API.
     * @return void
     * @throws \dml_exception|\moodle_exception
     */
    public static function update_ec_for_mapping(\stdClass $mapping, array $students): void {
        // Nothing to do if the extension is not enabled for the mapping.
        if ($mapping->enableextension !== '1') {
            return;
        }

        // If no students returned from SITS, nothing to do.
        if (empty($students)) {
            return;
        }

        // Process EC extension.
        foreach ($students as $student) {
            try {
                $ec = new ec();
                $ec->set_mabidentifier($mapping->mapcode . '-' . $mapping->mabseq);
                $ec->set_properties_from_get_students_api($student);
                $ec->process_extension([$mapping]);
            } catch (\Exception $e) {
                $studentcode = $student['association']['supplementary']['student_code'] ?? '';
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
     * Get the configured deadline group prefix.
     * Returns empty string if the setting is not configured, which disables the deadline group feature.
     *
     * @return string
     */
    public static function get_deadline_group_prefix(): string {
        return get_config('local_sitsgradepush', 'deadlinegroup_prefix') ?: '';
    }

    /**
     * Check if the AST code is eligible for RAA extension.
     *
     * @param string $astcode AST code.
     * @return bool
     */
    public static function is_ast_code_eligible_for_raa(string $astcode): bool {
        $ineligible = get_config('local_sitsgradepush', 'raa_ineligible_ast_codes');
        if (empty($ineligible)) {
            return true;
        }

        $codes = array_map('trim', explode(',', $ineligible));
        return !in_array($astcode, $codes);
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

            // Delete all SORA overrides for the deleted mapping.
            $assessment->delete_sora_overrides_for_mapping($deletedmapping);
        } catch (\Exception $e) {
            logger::log($e->getMessage(), null, "Deleted Mapping: " . json_encode($deletedmapping));
        }
    }

    /**
     * Check if the extension is eligible for the Moodle source.
     *
     * @param assessment $assessment
     * @param bool $duedatecheck Check if the due date is in the future.
     * @return bool
     */
    public static function is_source_extension_eligible(assessment $assessment, bool $duedatecheck = true): bool {
        $primarycheck = self::is_extension_enabled() &&
            $assessment->is_extension_supported() &&
            $assessment->is_valid_for_extension()->valid;

        return $duedatecheck ? $primarycheck && $assessment->get_end_date() > di::get(clock::class)->time() : $primarycheck;
    }

    /**
     * Delete EC overrides for a mapped Moodle assessment.
     *
     * @param int $mapid SITS mapping ID.
     *
     * @return void
     * @throws \dml_exception
     */
    public static function delete_ec_overrides(int $mapid): void {
        // Get EC overrides by SITS mapping ID.
        $backups = self::get_mt_overrides(['mapid' => $mapid, 'extensiontype' => self::EXTENSION_EC, 'restored_by' => null]);

        // Nothing to do if there are no EC overrides.
        if (empty($backups)) {
            return;
        }

        try {
            // Get Moodle assessment.
            $assessment = [];
            foreach ($backups as $backup) {
                if (empty($assessment[$backup->cmid])) {
                    $assessment[$backup->cmid] =
                        assessmentfactory::get_assessment(assessmentfactory::SOURCETYPE_MOD, $backup->cmid);
                }
                $assessment[$backup->cmid]->delete_ec_override($backup);
            }
        } catch (\Exception $e) {
            logger::log($e->getMessage(), null, "delete_ec_overrides: mapping ID: $mapid");
        }
    }

    /**
     * Get records in marks transfer overrides table.
     *
     * @param array $conditions
     * @return mixed
     * @throws \dml_exception
     */
    public static function get_mt_overrides(array $conditions): array {
        global $DB;
        return $DB->get_records(self::TABLE_OVERRIDES, $conditions);
    }

    /**
     * Get active user marks transfer overrides by mapid.
     *
     * @param int $mapid
     * @param int $cmid
     * @param string $extensiontype
     * @param int $userid
     * @return \stdClass|false
     * @throws \dml_exception
     */
    public static function get_active_user_mt_overrides_by_mapid(
        int $mapid,
        int $cmid,
        string $extensiontype,
        int $userid,
    ): \stdClass|false {
        global $DB;
        $sql = "SELECT *
                FROM {" . self::TABLE_OVERRIDES . "}
                WHERE mapid = :mapid
                  AND cmid = :cmid
                  AND extensiontype = :extensiontype
                  AND userid = :userid
                  AND restored_by IS NULL";

        $params = [
            'mapid' => $mapid,
            'cmid' => $cmid,
            'userid' => $userid,
            'extensiontype' => $extensiontype,
        ];
        return $DB->get_record_sql($sql, $params);
    }
}
