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

    /** @var string DB table for storing extension tiers */
    const TABLE_EXTENSION_TIERS = 'local_sitsgradepush_ext_tiers';

    /** @var string Extension name for SORA */
    const EXTENSION_SORA = 'SORA';

    /** @var string Extension name for EC */
    const EXTENSION_EC = 'EC';

    /** @var array CSV headers for extension tiers */
    const EXTENSION_TIERS_CSV_HEADERS = [
        'Assessment Type',
        'Tier',
        'Extension Type',
        'Extension Value',
        'Extension Unit',
        'Break Value',
        'Enabled',
    ];

    /** @var string RAA extension type for time per hour */
    const RAA_EXTENSION_TYPE_TIME_PER_HOUR = 'time_per_hour';

    /** @var string RAA extension type for time */
    const RAA_EXTENSION_TYPE_TIME = 'time';

    /** @var string RAA extension type for days */
    const RAA_EXTENSION_TYPE_DAYS = 'days';

    /** @var string RAA extension unit for minutes */
    const RAA_EXTENSION_UNIT_MINUTES = 'minutes';

    /** @var string RAA extension unit for hours */
    const RAA_EXTENSION_UNIT_HOURS = 'hours';

    /** @var string RAA extension unit for days */
    const RAA_EXTENSION_UNIT_DAYS = 'days';

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

    /**
     * Parse and validate CSV content for extension tiers.
     *
     * @param string $content CSV file content.
     * @return array Validated tier data.
     * @throws \moodle_exception
     */
    public static function parse_extension_tiers_csv(string $content): array {
        // Check if content is empty.
        if (empty($content)) {
            throw new \moodle_exception('tier:error:emptycsvfile', 'local_sitsgradepush');
        }

        // Create CSV reader.
        $importid = \csv_import_reader::get_new_iid('sitsgradepush_extension_tiers');
        $csvreader = new \csv_import_reader($importid, 'sitsgradepush_extension_tiers');

        // Load CSV content.
        $readcount = $csvreader->load_csv_content($content, 'utf-8', 'comma');

        if ($readcount === false) {
            $error = $csvreader->get_error();
            throw new \moodle_exception('tier:error:csvloadfailed', 'local_sitsgradepush', '', $error);
        }

        // Initialize CSV reader.
        $csvreader->init();

        // Get and validate headers.
        $headers = $csvreader->get_columns();
        $expectedheaders = self::EXTENSION_TIERS_CSV_HEADERS;

        if ($headers !== $expectedheaders) {
            $csvreader->close();
            $actual = implode(', ', $headers);
            $expected = implode(', ', $expectedheaders);
            throw new \moodle_exception(
                'tier:error:invalidcsvheaders',
                'local_sitsgradepush',
                '',
                "Expected: [$expected] but got: [$actual]"
            );
        }

        $rows = [];
        $csvlinenumber = 1;

        // Process each row.
        while ($row = $csvreader->next()) {
            $csvlinenumber++;

            // Skip rows where the first column (assessment type) is empty.
            if (empty($row[0]) || trim($row[0]) === '') {
                continue;
            }

            // Convert empty strings to null for proper validation.
            $data = [
                'assessmenttype' => trim($row[0]),
                'tier' => trim($row[1]),
                'extensiontype' => trim($row[2]),
                'extensionvalue' => (!empty($row[3]) && trim($row[3]) !== '') ? (int) $row[3] : null,
                'extensionunit' => (!empty($row[4]) && trim($row[4]) !== '') ? trim($row[4]) : null,
                'breakvalue' => (!empty($row[5]) && trim($row[5]) !== '') ? (int) $row[5] : null,
                'enabled' => ($row[6] === '0') ? 0 : 1,
            ];

            // Validate the row.
            self::validate_tier_row($data, $csvlinenumber);

            $rows[] = $data;
        }

        // Close the CSV reader.
        $csvreader->close();

        if (empty($rows)) {
            throw new \moodle_exception('tier:error:emptycsvfile', 'local_sitsgradepush');
        }

        return $rows;
    }

    /**
     * Import extension tier data.
     *
     * @param array $data Tier data to import.
     * @param string $mode Import mode ('replace' or 'update').
     * @return void
     * @throws \dml_exception
     */
    public static function import_extension_tiers(array $data, string $mode): void {
        global $DB;

        $time = di::get(clock::class)->time();

        if ($mode === 'replace') {
            // Delete all existing records.
            $DB->delete_records(self::TABLE_EXTENSION_TIERS);
        }

        foreach ($data as $row) {
            $record = new \stdClass();
            $record->assessmenttype = $row['assessmenttype'];
            $record->tier = $row['tier'];
            $record->extensiontype = $row['extensiontype'];
            $record->extensionvalue = $row['extensionvalue'];
            $record->extensionunit = $row['extensionunit'];
            $record->breakvalue = $row['breakvalue'];
            $record->enabled = $row['enabled'] ?? 1;
            $record->timemodified = $time;

            if ($mode === 'replace') {
                $record->timecreated = $time;
                $DB->insert_record(self::TABLE_EXTENSION_TIERS, $record);
            } else if ($mode === 'update') {
                // Check if record exists.
                $existing = $DB->get_record(self::TABLE_EXTENSION_TIERS, [
                    'assessmenttype' => $record->assessmenttype,
                    'tier' => $record->tier,
                ]);

                if ($existing) {
                    $record->id = $existing->id;
                    $record->timecreated = $existing->timecreated;
                    $DB->update_record(self::TABLE_EXTENSION_TIERS, $record);
                } else {
                    $record->timecreated = $time;
                    $DB->insert_record(self::TABLE_EXTENSION_TIERS, $record);
                }
            }
        }
    }

    /**
     * Get all extension tier configurations.
     *
     * @param array $filters Optional filters.
     * @return array
     * @throws \dml_exception
     */
    public static function get_all_extension_tiers(array $filters = []): array {
        global $DB;
        return $DB->get_records(self::TABLE_EXTENSION_TIERS, $filters, 'assessmenttype ASC, tier ASC');
    }

    /**
     * Export extension tiers to CSV format.
     *
     * @return string CSV content.
     * @throws \dml_exception
     */
    public static function export_extension_tiers_csv(): string {
        $tiers = self::get_all_extension_tiers();

        // Build CSV content.
        $csvdata = [];
        $csvdata[] = self::EXTENSION_TIERS_CSV_HEADERS;

        foreach ($tiers as $tier) {
            $csvdata[] = [
                $tier->assessmenttype,
                $tier->tier,
                $tier->extensiontype,
                $tier->extensionvalue ?? '',
                $tier->extensionunit ?? '',
                $tier->breakvalue ?? '',
                $tier->enabled,
            ];
        }

        // Convert to CSV string.
        $csvoutput = fopen('php://temp', 'r+');
        foreach ($csvdata as $row) {
            fputcsv($csvoutput, $row);
        }
        rewind($csvoutput);
        $csv = stream_get_contents($csvoutput);
        fclose($csvoutput);

        return $csv;
    }

    /**
     * Get extension tier by assessment type and tier number.
     *
     * @param string $assessmenttype
     * @param int $tier
     * @param int|null $enabled Optional filter by enabled status.
     * @return \stdClass|null
     * @throws \dml_exception
     */
    public static function get_extension_tier_by_assessment_and_tier(
        string $assessmenttype,
        int $tier,
        ?int $enabled = null
    ): ?\stdClass {
        global $DB;
        $params = [
            'assessmenttype' => $assessmenttype,
            'tier' => $tier,
        ];

        if ($enabled !== null) {
            $params['enabled'] = $enabled;
        }

        $tier = $DB->get_record(self::TABLE_EXTENSION_TIERS, $params);

        return !empty($tier) ? $tier : null;
    }

    /**
     * Validate a tier row from CSV.
     *
     * @param array $data Row data.
     * @param int $linenumber Line number in CSV.
     * @return void
     * @throws \moodle_exception
     */
    protected static function validate_tier_row(array &$data, int $linenumber): void {
        $line = "Line $linenumber: ";

        // Validate assessment type.
        if (empty($data['assessmenttype'])) {
            throw new \moodle_exception('tier:error:emptyassessmenttype', 'local_sitsgradepush', '', $line);
        }

        // Validate tier.
        if (!in_array($data['tier'], [1, 2, 3])) {
            throw new \moodle_exception('tier:error:invalidtier', 'local_sitsgradepush', '', $line);
        }

        // Validate extension type.
        if (!in_array($data['extensiontype'], ['time', 'time_per_hour', 'days'])) {
            throw new \moodle_exception('tier:error:invalidextensiontype', 'local_sitsgradepush', '', $line);
        }

        // Extension type specific validation.
        switch ($data['extensiontype']) {
            case 'days':
                // Must have extension value.
                if (empty($data['extensionvalue']) || $data['extensionvalue'] <= 0) {
                    throw new \moodle_exception('tier:error:extensionvaluerequired', 'local_sitsgradepush', '', $line);
                }
                // Cannot have break value.
                if (!empty($data['breakvalue'])) {
                    throw new \moodle_exception('tier:error:daysnobreak', 'local_sitsgradepush', '', $line);
                }
                // Auto-set unit to days.
                $data['extensionunit'] = 'days';
                break;

            case 'time':
                // Must use minutes or hours.
                if (!in_array($data['extensionunit'], ['minutes', 'hours'])) {
                    throw new \moodle_exception('tier:error:timeunitinvalid', 'local_sitsgradepush', '', $line);
                }
                // Must have extension value.
                if (empty($data['extensionvalue']) || $data['extensionvalue'] <= 0) {
                    throw new \moodle_exception('tier:error:extensionvaluerequired', 'local_sitsgradepush', '', $line);
                }
                // Cannot have break value.
                if (!empty($data['breakvalue'])) {
                    throw new \moodle_exception('tier:error:timenobreak', 'local_sitsgradepush', '', $line);
                }
                break;

            case 'time_per_hour':
                // Must have either extension value or break value.
                $hasextension = !empty($data['extensionvalue']) && $data['extensionvalue'] > 0;
                $hasbreak = !empty($data['breakvalue']) && $data['breakvalue'] > 0;

                if (!$hasextension && !$hasbreak) {
                    throw new \moodle_exception('tier:error:timeperhouronevalue', 'local_sitsgradepush', '', $line);
                }
                // Auto-set unit to minutes.
                $data['extensionunit'] = 'minutes';
                break;
        }
    }
}
