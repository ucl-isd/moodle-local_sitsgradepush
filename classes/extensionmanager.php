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
use local_sitsgradepush\extension\cdd;
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

    /** @var string Extension name for combined due date */
    const EXTENSION_CDD = 'CDD';

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
     * Update combined due date (CDD) extension for students in a mapping.
     * The mapping's student list from the SITS get students API drives processing, so the combined
     * due date is only applied to students who are actually in the mapping.
     *
     * @param \stdClass $mapping Assessment component mapping information including MAB info.
     * @param array $students Students data from the SITS get students API.
     * @return array Student codes of the students handled by the combined due date.
     * @throws \dml_exception|\moodle_exception
     */
    public static function update_cdd_for_mapping(\stdClass $mapping, array $students): array {
        // Nothing to do if the combined due date feature is not enabled.
        if (!self::is_cdd_enabled()) {
            return [];
        }

        // Nothing to do if the extension is not enabled for the mapping.
        if ($mapping->enableextension !== '1') {
            return [];
        }

        // Nothing to do if there are no students in the mapping.
        if (empty($students)) {
            return [];
        }

        // Nothing to do if the combined due date API returned no records.
        $cddrecords = manager::get_manager()->get_combined_due_dates_from_sits($mapping);
        if (empty($cddrecords)) {
            return [];
        }

        // Build a lookup of combined due date records keyed by student code. The student code is the
        // part of the student programme route code before the slash, e.g. 12345678/1 has code 12345678.
        $cddrecordsbycode = [];
        foreach ($cddrecords as $cddrecord) {
            $studentcode = explode('/', $cddrecord['student_programme_route_code'] ?? '')[0];
            if ($studentcode !== '') {
                $cddrecordsbycode[$studentcode] = $cddrecord;
            }
        }

        // Process CDD extension for each student in the mapping that has a combined due date record.
        $handledstudents = [];
        foreach ($students as $student) {
            $studentcode = $student['association']['supplementary']['student_code'] ?? '';

            // Skip students without a combined due date record.
            if ($studentcode === '' || !isset($cddrecordsbycode[$studentcode])) {
                continue;
            }

            try {
                $cdd = new cdd();
                $cdd->set_properties_from_cdd_api($cddrecordsbycode[$studentcode]);
                $cdd->process_extension([$mapping]);

                // If no combined due date data for this student, active CDD override exists should be removed
                // in above process_extension() call.
                // Collect the student code if the student is handled by the combined due date.
                if ($cdd->has_combined_due_date()) {
                    $handledstudents[] = $cdd->get_student_code();
                }
            } catch (\Exception $e) {
                logger::log($e->getMessage(), null, "Mapping ID: $mapping->id, Student code: $studentcode");
            }
        }

        return $handledstudents;
    }

    /**
     * Filter out students already handled by the combined due date from a SITS students list.
     *
     * @param array $students Students data from the SITS get students API.
     * @param array $cddhandledstudentcodes Student codes of students handled by the combined due date.
     * @return array The students not handled by the combined due date.
     */
    public static function filter_out_cdd_handled_students(array $students, array $cddhandledstudentcodes): array {
        // Nothing to filter if no students were handled by the combined due date.
        if (empty($cddhandledstudentcodes)) {
            return $students;
        }

        return array_filter($students, function ($student) use ($cddhandledstudentcodes) {
            $studentcode = $student['association']['supplementary']['student_code'] ?? '';
            return !in_array($studentcode, $cddhandledstudentcodes);
        });
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
     * Check if the combined due date (CDD) feature is enabled.
     *
     * @return bool
     * @throws \dml_exception
     */
    public static function is_cdd_enabled(): bool {
        return self::is_extension_enabled() && get_config('local_sitsgradepush', 'cdd_enabled') == '1';
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
        $primarycheck = self::is_extension_enabled() && $assessment->is_extension_supported();

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
        self::delete_overrides_by_type($mapid, self::EXTENSION_EC);
    }

    /**
     * Delete CDD overrides for a mapped Moodle assessment.
     *
     * @param int $mapid SITS mapping ID.
     *
     * @return void
     * @throws \dml_exception
     */
    public static function delete_cdd_overrides(int $mapid): void {
        self::delete_overrides_by_type($mapid, self::EXTENSION_CDD);
    }

    /**
     * Check if the user has an active CDD override for a mapping.
     *
     * @param int $mapid SITS mapping ID.
     * @param int $cmid Course module ID.
     * @param int $userid Moodle user ID.
     * @return bool
     * @throws \dml_exception
     */
    public static function user_has_active_cdd_override(int $mapid, int $cmid, int $userid): bool {
        return !empty(self::get_active_user_mt_overrides_by_mapid($mapid, $cmid, self::EXTENSION_CDD, $userid));
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

    /**
     * Delete overrides of a given extension type for a mapped Moodle assessment.
     *
     * @param int $mapid SITS mapping ID.
     * @param string $extensiontype The extension type, e.g. EC, CDD.
     *
     * @return void
     * @throws \dml_exception
     */
    private static function delete_overrides_by_type(int $mapid, string $extensiontype): void {
        // Get overrides of the extension type by SITS mapping ID.
        $overrides = self::get_mt_overrides(['mapid' => $mapid, 'extensiontype' => $extensiontype, 'restored_by' => null]);

        // Nothing to do if there are no overrides.
        if (empty($overrides)) {
            return;
        }

        // Delete each override, continuing on error so one bad record does not block the rest.
        $assessment = [];
        foreach ($overrides as $override) {
            try {
                if (empty($assessment[$override->cmid])) {
                    $assessment[$override->cmid] =
                        assessmentfactory::get_assessment(assessmentfactory::SOURCETYPE_MOD, $override->cmid);
                }
                $assessment[$override->cmid]->delete_user_override($override);
            } catch (\Exception $e) {
                logger::log($e->getMessage(), null, "delete_overrides_by_type ($extensiontype): mapping ID: $mapid");
            }
        }
    }
}
