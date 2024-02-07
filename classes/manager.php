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

use core_course\customfield\course_handler;
use DirectoryIterator;
use local_sitsgradepush\api\client_factory;
use local_sitsgradepush\api\iclient;
use local_sitsgradepush\api\irequest;
use local_sitsgradepush\assessment\assessment;
use local_sitsgradepush\assessment\assessmentfactory;
use local_sitsgradepush\output\pushrecord;
use local_sitsgradepush\submission\submissionfactory;

defined('MOODLE_INTERNAL') || die;

require_once("$CFG->libdir/gradelib.php");
require_once("$CFG->dirroot/user/lib.php");

/**
 * Manager class which handles SITS grade push.
 *
 * @package    local_sitsgradepush
 * @copyright  2023 onwards University College London {@link https://www.ucl.ac.uk/}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @author     Alex Yeung <k.yeung@ucl.ac.uk>
 */
class manager {
    /** @var string Action identifier for get component grades */
    const GET_COMPONENT_GRADE = 'getcomponentgrade';

    /** @var string Action identifier for get student from SITS */
    const GET_STUDENT = 'getstudent';

    /** @var string Action identifier for get students from SITS */
    const GET_STUDENTS = 'getstudents';

    /** @var string Action identifier for pushing grades to SITS */
    const PUSH_GRADE = 'pushgrade';

    /** @var string Action identifier for pushing grades to SITS */
    const PUSH_SUBMISSION_LOG = 'pushsubmissionlog';

    /** @var string Action identifier for getting marking scheme from SITS */
    const GET_MARKING_SCHEMES = 'getmarkingschemes';

    /** @var string DB table for storing component grades from SITS */
    const TABLE_COMPONENT_GRADE = 'local_sitsgradepush_mab';

    /** @var string DB table for storing assessment mappings */
    const TABLE_ASSESSMENT_MAPPING = 'local_sitsgradepush_mapping';

    /** @var string DB table for storing grade transfer log */
    const TABLE_TRANSFER_LOG = 'local_sitsgradepush_tfr_log';

    /** @var string DB table for storing error log */
    const TABLE_ERROR_LOG = 'local_sitsgradepush_err_log';

    /** @var string DB table for storing push tasks */
    const TABLE_TASKS = 'local_sitsgradepush_tasks';

    /** @var string[] Fields mapping - Local DB component grades table fields to returning SITS fields */
    const MAPPING_COMPONENT_GRADE = [
        'modcode' => 'MOD_CODE',
        'modocc' => 'MAV_OCCUR',
        'academicyear' => 'AYR_CODE',
        'periodslotcode' => 'PSL_CODE',
        'mapcode' => 'MAP_CODE',
        'mabseq' => 'MAB_SEQ',
        'astcode' => 'AST_CODE',
        'mabperc' => 'MAB_PERC',
        'mabname' => 'MAB_NAME',
        'examroomcode' => 'APA_ROMC',
    ];

    /** @var string[] Allowed activity types */
    const ALLOWED_ACTIVITIES = ['assign', 'quiz', 'turnitintooltwo'];

    /** @var string Existing activity */
    const SOURCE_EXISTING_ACTIVITY = 'existing';

    /** @var null Manager instance */
    private static $instance = null;

    /** @var iclient|null API client for performing api calls */
    private ?iclient $apiclient = null;

    /** @var array Store any api errors */
    private array $apierrors = [];

    /**
     * Constructor.
     */
    private function __construct() {
        try {
            // Get the selected api client name.
            $clientname = get_config('local_sitsgradepush', 'apiclient');
            if (empty($clientname)) {
                throw new \moodle_exception('API client not set.');
            }

            // Get api client instance.
            $apiclient = client_factory::get_api_client($clientname);
            if ($apiclient instanceof iclient) {
                $this->apiclient = $apiclient;
            }
        } catch (\moodle_exception $e) {
            logger::log($e->getMessage());
            $this->apierrors[] = $e->getMessage();
        }
    }

    /**
     * Return the manager instance.
     *
     * @return manager|null
     */
    public static function get_manager(): ?manager {
        if (self::$instance == null) {
            self::$instance = new manager();
        }

        return self::$instance;
    }

    /**
     * Fetch component grades data (MAB) from SITS.
     *
     * @param array $modocc module occurrences of the current course.
     * @return bool
     */
    public function fetch_component_grades_from_sits(array $modocc): bool {
        try {
            if (!empty($modocc)) {
                foreach ($modocc as $occ) {
                    // Get the cache.
                    $key = implode('_',
                        [
                            cachemanager::CACHE_AREA_COMPONENTGRADES,
                            $occ->mod_code,
                            $occ->mod_occ_mav,
                            $occ->mod_occ_psl_code,
                            $occ->mod_occ_year_code,
                        ]
                    );

                    // Replace '/' with '_' for simple key.
                    $key = str_replace('/', '_', $key);

                    // This cache is not really used.
                    // It is only used to check if the component grades have been fetched for this module occurrence.
                    $cache = cachemanager::get_cache(cachemanager::CACHE_AREA_COMPONENTGRADES, $key);

                    // Skip if cache exists.
                    if (!empty($cache)) {
                        continue;
                    }

                    // Get component grades from SITS.
                    $request = $this->apiclient->build_request(self::GET_COMPONENT_GRADE, $occ);
                    $response = $this->apiclient->send_request($request);

                    // Check response.
                    $this->check_response($response, $request);

                    // Filter out unwanted component grades by marking scheme.
                    $response = $this->filter_out_invalid_component_grades($response);

                    // Set cache expiry to 30 days.
                    cachemanager::set_cache(cachemanager::CACHE_AREA_COMPONENTGRADES, $key, $response, 30 * 24 * 60 * 60);

                    // Save component grades to DB.
                    $this->save_component_grades($response);
                }

                return true;
            }
        } catch (\moodle_exception $e) {
            $this->apierrors[] = $e->getMessage();
        }

        return false;
    }

    /**
     * Fetch marking scheme data from SITS.
     *
     * @return array|void
     */
    public function fetch_marking_scheme_from_sits() {
        try {
            // Get marking scheme from SITS.
            $request = $this->apiclient->build_request(self::GET_MARKING_SCHEMES, null);
            $response = $this->apiclient->send_request($request);

            // Check response.
            $this->check_response($response, $request);

            return $response;
        } catch (\moodle_exception $e) {
            $this->apierrors[] = $e->getMessage();
        }
    }

    /**
     * Filter out invalid component grades data (MAB) from SITS.
     *
     * @param array $componentgrades
     * @return array
     * @throws \dml_exception
     */
    public function filter_out_invalid_component_grades(array $componentgrades): array {
        $filtered = [];

        $makingschemes = $this->fetch_marking_scheme_from_sits();
        if (!empty($makingschemes)) {
            foreach ($componentgrades as $componentgrade) {
                if ($makingschemes[$componentgrade['MKS_CODE']]['MKS_MARKS'] == 'Y' &&
                    $makingschemes[$componentgrade['MKS_CODE']]['MKS_IUSE'] == 'Y' &&
                    $makingschemes[$componentgrade['MKS_CODE']]['MKS_TYPE'] == 'A') {
                    $filtered[] = $componentgrade;
                }
            }
        }

        return $filtered;
    }

    /**
     * Get options for component grade dropdown list in activity's settings page.
     *
     * @param int $courseid
     * @param mixed $coursemoduleid
     * @return array
     * @throws \dml_exception
     */
    public function get_component_grade_options(int $courseid, mixed $coursemoduleid): array {
        // Get module occurrences from portico enrolments block.
        $modocc = \block_portico_enrolments\manager::get_modocc_mappings($courseid);

        // Fetch component grades from SITS.
        $this->fetch_component_grades_from_sits($modocc);
        $modocccomponentgrades = $this->get_local_component_grades($modocc);

        // Loop through each component grade for each module occurrence and build the options.
        foreach ($modocccomponentgrades as $occ) {
            foreach ($occ->componentgrades as $mab) {
                $mab->selected = '';

                // This component grade is mapped to this activity, so set selected.
                if (!empty($mab->coursemoduleid) && $mab->coursemoduleid == $coursemoduleid) {
                    $mab->selected = 'selected';
                }
                $mab->text = sprintf(
                    '%s-%s-%s-%s-%s %s',
                    $mab->modcode,
                    $mab->academicyear,
                    $mab->periodslotcode,
                    $mab->modocc,
                    $mab->mabseq,
                    $mab->mabname
                );
                $mab->value = $mab->id;
            }
        }

        return $modocccomponentgrades;
    }

    /**
     * Decode the module occurrence's module availability code, e.g. A6U, A7P.
     *
     * @param string $mav
     * @return array
     */
    public function get_decoded_mod_occ_mav(string $mav): array {
        $decoded = [];
        if (!empty($mav)) {
            // Get level.
            $decoded['level'] = substr($mav, 1, 1);
            // Get graduate type.
            $graduatetype = substr($mav, 2, 1);
            $decoded['graduatetype'] = match ($graduatetype) {
                'U' => 'UNDERGRADUATE',
                'P' => 'POSTGRADUATE',
                default => 'UNKNOWN',
            };
        }

        return $decoded;
    }

    /**
     * Get component grades from local DB.
     *
     * @param array $modocc module occurrences of the current course.
     * @return array Array of module occurrences with component grades.
     * @throws \dml_exception|\coding_exception
     */
    public function get_local_component_grades(array $modocc): array {
        global $DB;

        $moduledeliveries = [];
        foreach ($modocc as $occ) {
            $sql = "SELECT cg.*, am.coursemoduleid AS 'coursemoduleid', am.id AS 'assessmentmappingid'
                    FROM {" . self::TABLE_COMPONENT_GRADE . "} cg LEFT JOIN {" . self::TABLE_ASSESSMENT_MAPPING . "} am
                    ON cg.id = am.componentgradeid
                    WHERE cg.modcode = :modcode AND cg.modocc = :modocc AND cg.academicyear = :academicyear
                    AND cg.periodslotcode = :periodslotcode";

            $params = [
                'modcode' => $occ->mod_code,
                'modocc' => $occ->mod_occ_mav,
                'academicyear' => $occ->mod_occ_year_code,
                'periodslotcode' => $occ->mod_occ_psl_code,
            ];

            // Get AST codes.
            if ($astcodes = self::get_moodle_ast_codes()) {
                list($astcodessql, $astcodesparam) = $DB->get_in_or_equal($astcodes, SQL_PARAMS_NAMED, 'astcode');
                $sql .= " AND astcode {$astcodessql}";
                $params = array_merge($params, $astcodesparam);
            }

            // Get component grades for all potential assessment types.
            $records = $DB->get_records_sql($sql, $params);

            // Get ast codes that work with exam room code.
            $astcodesworkwithexamroomcodes = self::get_moodle_ast_codes_work_with_exam_room_code();

            // Get moodle exam room code.
            $examroomcode = get_config('local_sitsgradepush', 'moodle_exam_room_code');

            // Remove component grades that do not match the exam room code.
            if (!empty($astcodesworkwithexamroomcodes) && !empty($examroomcode)) {
                foreach ($records as $record) {
                    if (in_array($record->astcode, $astcodesworkwithexamroomcodes) && $record->examroomcode != $examroomcode) {
                        unset($records[$record->id]);
                    }
                }
            }

            // Create module delivery object with component grades data.
            $moduledelivery = new \stdClass();
            $moduledelivery->modcode = $occ->mod_code;
            $moduledelivery->modocc = $occ->mod_occ_mav;
            $moduledelivery->academicyear = $occ->mod_occ_year_code;
            $moduledelivery->periodslotcode = $occ->mod_occ_psl_code;
            $moduledelivery->modoccname = $occ->mod_occ_name;
            $moduledelivery->componentgrades = $records;

            // Get MAP code from the first record.
            if (!empty($records)) {
                $moduledelivery->mapcode = $records[array_key_first($records)]->mapcode;
            } else {
                $moduledelivery->mapcode = '';
            }

            // Get decoded module occurrence's MAV.
            $decodedmav = $this->get_decoded_mod_occ_mav($occ->mod_occ_mav);
            $moduledelivery->level = $decodedmav['level'];
            $moduledelivery->graduatetype = $decodedmav['graduatetype'];
            $moduledeliveries[] = $moduledelivery;
        }

        // Return module deliveries with component grades info.
        return $moduledeliveries;
    }

    /**
     * Save component grades from SITS to database.
     *
     * @param array $componentgrades
     * @return void
     * @throws \coding_exception
     * @throws \dml_exception
     */
    public function save_component_grades(array $componentgrades) {
        global $DB;

        if (!empty($componentgrades)) {
            $recordsinsert = [];
            foreach ($componentgrades as $componentgrade) {
                if ($record = $DB->get_record(self::TABLE_COMPONENT_GRADE, [
                    'modcode' => $componentgrade['MOD_CODE'],
                    'modocc' => $componentgrade['MAV_OCCUR'],
                    'academicyear' => $componentgrade['AYR_CODE'],
                    'periodslotcode' => $componentgrade['PSL_CODE'],
                    'mapcode' => $componentgrade['MAP_CODE'],
                    'mabseq' => $componentgrade['MAB_SEQ'],
                ])) {
                    // Update record if this component grade already exists.
                    $record->astcode = $componentgrade['AST_CODE'];
                    $record->mabperc = $componentgrade['MAB_PERC'];
                    $record->mabname = $componentgrade['MAB_NAME'];
                    $record->examroomcode = $componentgrade['APA_ROMC'];
                    $record->timemodified = time();

                    $DB->update_record(self::TABLE_COMPONENT_GRADE, $record);
                } else {
                    // Insert record if it's a new component grade.
                    $record = [];
                    foreach (self::MAPPING_COMPONENT_GRADE as $key => $value) {
                        $record[$key] = $componentgrade[$value];
                    }
                    $record['timecreated'] = time();
                    $record['timemodified'] = time();
                    $recordsinsert[] = $record;
                }
            }
            if (count($recordsinsert) > 0) {
                $DB->insert_records(self::TABLE_COMPONENT_GRADE, $recordsinsert);
            }
        }
    }

    /**
     * Save assessment mappings to database.
     *
     * @param \stdClass $data
     * @return void
     * @throws \coding_exception
     * @throws \dml_exception
     * @throws \moodle_exception
     */
    public function save_assessment_mappings(\stdClass $data): void {
        global $DB;
        // Remove any empty values.
        $componentgrades = array_filter($data->gradepushassessmentselect);

        // Validate component grades.
        $result = $this->validate_component_grades($componentgrades, $data->coursemodule);

        // Something went wrong, throw exception.
        if ($result->errormessages) {
            throw new \moodle_exception(implode('<br>', $result->errormessages));
        }

        // Delete existing mappings.
        if ($result->mappingtoremove) {
            foreach ($result->mappingtoremove as $mapping) {
                $DB->delete_records(self::TABLE_ASSESSMENT_MAPPING, ['id' => $mapping->id]);
            }
        }

        // Insert new mappings.
        if ($result->componentgradestomap) {
            foreach ($result->componentgradestomap as $componentgradeid) {
                $record = new \stdClass();
                $record->courseid = $data->course;
                $record->coursemoduleid = $data->coursemodule;
                $record->moduletype = $data->modulename;
                $record->componentgradeid = $componentgradeid;
                $record->timecreated = time();
                $record->timemodified = time();
                $DB->insert_record(self::TABLE_ASSESSMENT_MAPPING, $record);
            }
        }
    }

    /**
     * Save component grade mapping to database. Used by the select existing activity page.
     *
     * @param \stdClass $data
     * @return int|bool
     * @throws \dml_exception
     * @throws \moodle_exception
     */
    public function save_assessment_mapping(\stdClass $data): int|bool {
        global $DB;

        // Validate component grade.
        $this->validate_component_grade($data->componentgradeid, $data->coursemoduleid);

        if ($mapping = $this->is_component_grade_mapped($data->componentgradeid)) {
            // Checked in the above validation, the current mapping to this component grade
            // can be deleted as it does not have push records nor mapped to the current activity.
            $DB->delete_records(self::TABLE_ASSESSMENT_MAPPING, ['id' => $mapping->id]);
        }

        // Get the course module.
        $coursemodule = get_coursemodule_from_id('', $data->coursemoduleid);

        // Insert new mapping.
        $record = new \stdClass();
        $record->courseid = $coursemodule->course;
        $record->coursemoduleid = $coursemodule->id;
        $record->moduletype = $coursemodule->modname;
        $record->componentgradeid = $data->componentgradeid;
        $record->timecreated = time();
        $record->timemodified = time();

        return $DB->insert_record(self::TABLE_ASSESSMENT_MAPPING, $record);
    }

    /**
     * Lookup assessment mappings.
     *
     * @param int $cmid course module id
     * @param int|null $componentgradeid component grade id
     * @return mixed
     * @throws \dml_exception
     */
    public function get_assessment_mappings(int $cmid, int $componentgradeid = null): mixed {
        global $DB;

        $params = ['cmid' => $cmid];
        $where = '';
        if (!empty($componentgradeid)) {
            $params['componentgradeid'] = $componentgradeid;
            $where = 'AND am.componentgradeid = :componentgradeid';
        }

        $sql = "SELECT am.id, am.courseid, am.coursemoduleid, am.moduletype, am.componentgradeid, am.reassessment,
                       am.reassessmentseq, cg.modcode, cg.modocc, cg.academicyear, cg.periodslotcode, cg.mapcode,
                       cg.mabseq, cg.astcode, cg.mabname,
                       CONCAT(cg.modcode, '-', cg.academicyear, '-', cg.periodslotcode, '-', cg.modocc, '-',
                       cg.mabseq, ' ', cg.mabname) AS 'formattedname'
                FROM {" . self::TABLE_ASSESSMENT_MAPPING . "} am
                JOIN {" . self::TABLE_COMPONENT_GRADE . "} cg ON am.componentgradeid = cg.id
                WHERE am.coursemoduleid = :cmid $where";

        return ($componentgradeid) ? $DB->get_record_sql($sql, $params) : $DB->get_records_sql($sql, $params);
    }

    /**
     * Check if the activity is mapped to a component grade.
     *
     * @param int $cmid
     * @return bool
     * @throws \dml_exception
     */
    public function is_activity_mapped(int $cmid): bool {
        global $DB;
        return $DB->record_exists(self::TABLE_ASSESSMENT_MAPPING, ['coursemoduleid' => $cmid]);
    }

    /**
     * Check if the component grade is mapped to an activity.
     *
     * @param int $id
     * @return mixed
     * @throws \dml_exception
     */
    public function is_component_grade_mapped(int $id) {
        global $DB;
        return $DB->get_record(self::TABLE_ASSESSMENT_MAPPING, ['componentgradeid' => $id]);
    }

    /**
     * Return api errors.
     *
     * @return array
     */
    public function get_api_errors(): array {
        return $this->apierrors;
    }

    /**
     * Return list of api clients available.
     *
     * @return array
     * @throws \moodle_exception
     */
    public function get_api_client_list(): array {
        $dir = new DirectoryIterator(__DIR__ . '/../apiclients');
        $list = [];
        foreach ($dir as $fileinfo) {
            if ($fileinfo->isDir() && !$fileinfo->isDot()) {
                $client = client_factory::get_api_client($fileinfo->getFilename());
                $list[$fileinfo->getFilename()] = $client->get_client_name();
            }
        }

        return $list;
    }

    /**
     * Return allowed activity types for grade push.
     *
     * @return string[]
     */
    public function get_allowed_activities(): array {
        return self::ALLOWED_ACTIVITIES;
    }

    /**
     * Return course module data.
     *
     * @param int $id
     * @return false|mixed|\stdClass
     * @throws \dml_exception
     */
    public function get_course_module(int $id) {
        global $DB;
        return $DB->get_record("course_modules", ["id" => $id]);
    }

    /**
     * Get local component grade by id.
     *
     * @param int $id
     * @return false|mixed|\stdClass
     * @throws \dml_exception
     */
    public function get_local_component_grade_by_id(int $id) {
        global $DB;
        return $DB->get_record("local_sitsgradepush_mab", ['id' => $id]);
    }

    /**
     * Get student SPR_CODE from SITS.
     *
     * @param \stdClass $componentgrade
     * @param int $userid
     * @return mixed
     * @throws \coding_exception
     * @throws \dml_exception
     * @throws \moodle_exception
     */
    public function get_student_from_sits(\stdClass $componentgrade, int $userid): mixed {
        $studentspr = null;

        // Get user.
        $user = user_get_users_by_id([$userid]);

        // Get students for the component grade.
        $students = $this->get_students_from_sits($componentgrade);
        foreach ($students as $student) {
            if ($student['code'] == $user[$userid]->idnumber) {
                $studentspr = $student['spr_code'];
            }
        }

        return $studentspr;
    }

    /**
     * Get students for a grade component from SITS.
     *
     * @param \stdClass $componentgrade
     * @return \cache_application|\cache_session|\cache_store|mixed
     * @throws \coding_exception
     * @throws \dml_exception
     * @throws \moodle_exception
     */
    public function get_students_from_sits(\stdClass $componentgrade): mixed {
        // Stutalk Direct is not supported currently.
        if ($this->apiclient->get_client_name() == 'Stutalk Direct') {
            throw new \moodle_exception(
                get_string('error:multiplemappingsnotsupported', 'local_sitsgradepush', $this->apiclient->get_client_name()
            ));
        }

        // Try to get cache first.
        $key = implode('_', [cachemanager::CACHE_AREA_STUDENTSPR, $componentgrade->mapcode, $componentgrade->mabseq]);
        $students = cachemanager::get_cache(cachemanager::CACHE_AREA_STUDENTSPR, $key);

        // Cache found, return students.
        if (!empty($students)) {
            return $students;
        }

        // Build required data.
        $data = new \stdClass();
        $data->mapcode = $componentgrade->mapcode;
        $data->mabseq = $componentgrade->mabseq;

        // Build and send request.
        $request = $this->apiclient->build_request('getstudents', $data);
        $result = $this->apiclient->send_request($request);

        // Set cache if result is not empty.
        if (!empty($result)) {
            cachemanager::set_cache(
                cachemanager::CACHE_AREA_STUDENTSPR,
                $key,
                $result,
                strtotime('+30 days'),
            );
        }

        return $result;
    }

    /**
     * Push grade to SITS.
     *
     * @param \stdClass $assessmentmapping
     * @param int $userid
     * @param int|null $taskid
     * @return bool
     * @throws \dml_exception
     * @throws \moodle_exception
     */
    public function push_grade_to_sits(\stdClass $assessmentmapping, int $userid, int $taskid = null): bool {
        try {
            // Check if last push was succeeded, exit if succeeded.
            if ($this->last_push_succeeded($assessmentmapping->id, $userid, self::PUSH_GRADE)) {
                return false;
            }

            // Get required data for pushing.
            $data = $this->get_required_data_for_pushing($assessmentmapping, $userid);

            // Get grade.
            $grade = $this->get_student_grade($assessmentmapping->coursemoduleid, $userid);

            // Push if grade is found.
            if (isset($grade)) {
                $data->marks = $grade;
                $data->grade = ''; // TODO: Where to get the grade?

                $request = $this->apiclient->build_request(self::PUSH_GRADE, $data);
                $response = $this->apiclient->send_request($request);

                // Check response.
                $this->check_response($response, $request);

                // Save transfer log.
                $this->save_transfer_log(self::PUSH_GRADE, $assessmentmapping->id, $userid, $request, $response, $taskid);

                return true;
            }
        } catch (\moodle_exception $e) {
            $this->mark_push_as_failed(self::PUSH_GRADE, $assessmentmapping->id, $userid, $e);
            return false;
        }

        return false;
    }

    /**
     * Push submission log to SITS.
     *
     * @param \stdClass $assessmentmapping
     * @param int $userid
     * @param int|null $taskid
     * @return bool
     * @throws \dml_exception
     */
    public function push_submission_log_to_sits(\stdClass $assessmentmapping, int $userid, ?int $taskid = null): bool {
        try {
            // Check if submission log push is enabled.
            if (!get_config('local_sitsgradepush', 'sublogpush')) {
                return false;
            }

            // Check if last push was succeeded, exit if succeeded.
            if ($this->last_push_succeeded($assessmentmapping->id, $userid, self::PUSH_SUBMISSION_LOG)) {
                return false;
            }

            // Get required data for pushing.
            $data = $this->get_required_data_for_pushing($assessmentmapping, $userid);

            // Create the submission object.
            $submission = submissionfactory::get_submission($assessmentmapping->coursemoduleid, $userid);

            // Push if student has submission.
            if ($submission->get_submission_data()) {
                $request = $this->apiclient->build_request(self::PUSH_SUBMISSION_LOG, $data, $submission);
                $response = $this->apiclient->send_request($request);

                // Check response.
                $this->check_response($response, $request);

                // Save push log.
                $this->save_transfer_log(
                    self::PUSH_SUBMISSION_LOG, $assessmentmapping->id, $userid, $request, $response, $taskid);

                return true;
            }
        } catch (\moodle_exception $e) {
            $this->mark_push_as_failed(self::PUSH_SUBMISSION_LOG, $assessmentmapping->id, $userid, $taskid, $e);
            return false;
        }

        return false;
    }

    /**
     * Get required data for pushing.
     *
     * @param \stdClass $assessmentmapping
     * @param int $userid
     * @return \stdClass
     * @throws \coding_exception
     * @throws \dml_exception
     * @throws \moodle_exception
     */
    public function get_required_data_for_pushing (\stdClass $assessmentmapping, int $userid): \stdClass {
        global $USER;

        // Get SPR_CODE from SITS.
        $studentspr = $this->get_student_from_sits($assessmentmapping, $userid);

        // Build the required data.
        $data = new \stdClass();
        $data->mapcode = $assessmentmapping->mapcode;
        $data->mabseq = $assessmentmapping->mabseq;
        $data->sprcode = $studentspr;
        $data->academicyear = $assessmentmapping->academicyear;
        $data->pslcode = $assessmentmapping->periodslotcode;
        $data->reassessment = $assessmentmapping->reassessment;
        $data->source = sprintf(
            'moodle-course%s-activity%s-user%s',
            $assessmentmapping->courseid,
            $assessmentmapping->coursemoduleid,
            $USER->id
        );
        $data->srarseq = '001'; // Just a dummy reassessment sequence number for now.

        return $data;
    }

    /**
     * Return the last push logs for a given user id and assessment mapping id.
     *
     * @param int $assessmentmappingid
     * @param int $userid
     * @param string|null $type
     * @return array
     * @throws \dml_exception
     */
    public function get_transfer_logs (int $assessmentmappingid, int $userid, string $type = null): array {
        global $DB;

        // Initialize params.
        $params = [
            'assessmentmappingid' => $assessmentmappingid,
            'userid1' => $userid,
            'userid2' => $userid,
        ];

        // Filter by type if given.
        $bytype = '';
        if (!empty($type)) {
            $bytype = 'AND t1.type = :type';
            $params['type'] = $type;
        }

        // Get the latest record for each push type, e.g. grade push, submission log push.
        $sql = "SELECT t1.id, t1.type, t1.request, t1.response, t1.requestbody, t1.timecreated, t1.errlogid,
                errlog.errortype, errlog.message
                FROM {" . self::TABLE_TRANSFER_LOG . "} t1
                INNER JOIN (
                    SELECT type, assessmentmappingid, MAX(timecreated) AS latest_time
                    FROM {" . self::TABLE_TRANSFER_LOG . "}
                    WHERE userid = :userid1 AND assessmentmappingid = :assessmentmappingid
                    GROUP BY type) t2
                ON t1.type = t2.type AND t1.timecreated = t2.latest_time AND t1.assessmentmappingid = t2.assessmentmappingid
                LEFT JOIN {" . self::TABLE_ERROR_LOG . "} errlog ON t1.errlogid = errlog.id
                WHERE t1.userid = :userid2 {$bytype}";

        return $DB->get_records_sql($sql, $params);
    }

    /**
     * Get the assessment data.
     *
     * @param int $coursemoduleid
     * @param int|null $assessmentmappingid
     * @return array|\stdClass
     * @throws \coding_exception
     * @throws \dml_exception
     * @throws \moodle_exception
     */
    public function get_assessment_data(int $coursemoduleid, int $assessmentmappingid = null): array|\stdClass {
        // Get the assessment.
        $assessment = assessmentfactory::get_assessment($coursemoduleid);

        $assessmentdata = [];
        $assessmentdata['studentsnotrecognized'] = [];
        $students = $assessment->get_all_participants();
        $coursemodule = $assessment->get_course_module();

        // Get assessment mappings.
        $mappings = $this->get_assessment_mappings($coursemodule->id);
        if (empty($mappings)) {
            return [];
        }

        // Only process the mapping that matches the assessment mapping ID if assessmentmappingid is given.
        if ($assessmentmappingid) {
            $mappings = array_filter($mappings, function ($mapping) use ($assessmentmappingid) {
                return $mapping->id == $assessmentmappingid;
            });
        }

        // Fetch students from SITS.
        foreach ($mappings as $mapping) {
            $mabkey = $mapping->mapcode . '-' . $mapping->mabseq;
            $studentsfromsits[$mabkey] =
                array_column($this->get_students_from_sits($mapping), 'code');
            $assessmentdata['mappings'][$mabkey] = $mapping;
            $assessmentdata['mappings'][$mabkey]->markscount = 0;

            // Students here is all the participants in that assessment.
            foreach ($students as $key => $student) {
                $studentrecord = new pushrecord($student, $coursemodule->id, $mapping);
                // Add participants who have push records of this mapping or
                // are in the studentsfromsits array to the mapping's students array.
                if ($studentrecord->componentgrade == $mabkey || in_array($studentrecord->idnumber, $studentsfromsits[$mabkey])) {
                    $assessmentdata['mappings'][$mabkey]->students[] = $studentrecord;
                    if ($studentrecord->marks != '-' &&
                        !($studentrecord->isgradepushed && $studentrecord->lastgradepushresult === 'success')) {
                        $assessmentdata['mappings'][$mabkey]->markscount++;
                    }
                    unset($students[$key]);
                }
            }
        }

        // Remaining students are not valid for pushing.
        $invalidstudents = new \stdClass();
        $invalidstudents->formattedname = get_string('invalidstudents', 'local_sitsgradepush');
        $invalidstudents->students = [];
        foreach ($students as $student) {
            $invalidstudents->students[] = new pushrecord($student, $coursemodule->id);
        }
        $assessmentdata['invalidstudents'] = $invalidstudents;

        // Sort students for each mapping.
        foreach ($assessmentdata['mappings'] as $mapping) {
            if (!empty($mapping->students)) {
                $mapping->students = $this->sort_grade_push_history_table($mapping->students);
            }
        }

        // Only return the mapping that matches the assessment mapping ID if assessmentmappingid is given.
        if ($assessmentmappingid) {
            return reset($assessmentdata['mappings']);
        } else {
            return $assessmentdata;
        }
    }

    /**
     * Sort the data for grade push history table.
     *
     * @param array $data
     * @return mixed
     */
    private function sort_grade_push_history_table(array $data) {
        $tempdataarray = [];

        // Filter out different types of records.
        foreach ($data as $record) {
            if ($record->lastgradepushresult == 'failed' || $record->lastsublogpushresult == 'failed') {
                // Failed records.
                $tempdataarray['error'][] = $record;
            } else if (is_null($record->lastgradepushresult) && is_null($record->lastsublogpushresult)) {
                // Records that have not been pushed.
                $tempdataarray['notyetpushed'][] = $record;
            } else {
                // All other records.
                $tempdataarray['other'][] = $record;
            }
        }

        // Sort error records by lastgradepusherrortype and then by lastsublogpusherrortype.
        // As 'student SPR not found' error type has the highest negative number, this error type will be at the top.
        if (!empty($tempdataarray['error'])) {
            usort($tempdataarray['error'], function ($a, $b) {
                if ($b->lastgradepusherrortype == $a->lastgradepusherrortype) {
                    return $b->lastsublogpusherrortype <=> $a->lastsublogpusherrortype;
                }
                return $b->lastgradepusherrortype <=> $a->lastgradepusherrortype;
            });
        }

        // Merge all arrays.
        return array_merge($tempdataarray['error'] ?? [], $tempdataarray['other'] ?? [], $tempdataarray['notyetpushed'] ?? []);
    }

    /**
     * Check if this activity belongs to a current academic year course.
     *
     * @param int $courseid
     * @return bool
     * @throws \dml_exception
     */
    public function is_current_academic_year_activity(int $courseid): bool {
        // Get course custom fields.
        $customfields = [];
        $handler = course_handler::create();
        $data = $handler->get_instance_data($courseid, true);

        foreach ($data as $dta) {
            $customfields[$dta->get_field()->get('shortname')] = $dta->get_value();
        }

        // Course academic year not set.
        if (empty($customfields['course_year'])) {
            return false;
        }

        // Get configured late summer assessment end date.
        $lsaenddate = strtotime(get_config('block_lifecycle', 'late_summer_assessment_end_' . $customfields['course_year']));
        $lsaendateplusweekdelay = $lsaenddate + \block_lifecycle\manager::get_weeks_delay_in_seconds();

        // Course is not current academic year course.
        if (time() > $lsaendateplusweekdelay) {
            return false;
        }

        return true;
    }

    /**
     * Get moodle AST codes.
     *
     * @return array|false
     * @throws \dml_exception
     */
    public function get_moodle_ast_codes(): bool|array {
        $codes = get_config('local_sitsgradepush', 'moodle_ast_codes');
        if (!empty($codes)) {
            if ($codes = explode(',', $codes)) {
                return array_map('trim', $codes);
            }
        }

        return false;
    }

    /**
     * Get moodle AST codes work with exam room code.
     *
     * @return array|false
     * @throws \dml_exception
     */
    public function get_moodle_ast_codes_work_with_exam_room_code(): bool|array {
        $codes = get_config('local_sitsgradepush', 'moodle_ast_codes_exam_room');
        if (!empty($codes)) {
            if ($codes = explode(',', $codes)) {
                return array_map('trim', $codes);
            }
        }

        return false;
    }

    /**
     * Get user profile fields.
     *
     * @return array
     * @throws \dml_exception
     */
    public function get_user_profile_fields(): array {
        global $DB;

        // Get user profile fields from the database.
        return $DB->get_records('user_info_field', ['datatype' => 'text'], 'sortorder', 'id, shortname, name, description');
    }

    /**
     * Get export staff for requests.
     *
     * @return mixed|\stdClass|string
     * @throws \dml_exception
     */
    public function get_export_staff(): mixed {
        global $USER, $DB;

        // Get the source field from config.
        $userprofilefield = get_config('local_sitsgradepush', 'user_profile_field');

        // Find the field value.
        if (!empty($userprofilefield)) {
            if ($exportstaff = $DB->get_record('user_info_data', ['userid' => $USER->id, 'fieldid' => $userprofilefield], 'data')) {
                return $exportstaff->data;
            }
        }

        return '';
    }

    /**
     * Return formatted component grade name.
     *
     * @param int $componentgradeid
     * @return string
     * @throws \dml_exception
     */
    public function get_formatted_component_grade_name(int $componentgradeid): string {
        $formattedname = '';

        if ($componentgrade = $this->get_local_component_grade_by_id($componentgradeid)) {
            $formattedname = sprintf(
                '%s-%s-%s-%s-%s %s',
                $componentgrade->modcode,
                $componentgrade->academicyear,
                $componentgrade->periodslotcode,
                $componentgrade->modocc,
                $componentgrade->mabseq,
                $componentgrade->mabname
            );
        }

        return $formattedname;
    }

    /**
     * Check if any grade had been pushed for an assessment mapping.
     * @param int $assessmentmappingid
     * @return bool
     * @throws \dml_exception
     */
    public function has_grades_pushed(int $assessmentmappingid): bool {
        global $DB;
        return $DB->record_exists(self::TABLE_TRANSFER_LOG, ['assessmentmappingid' => $assessmentmappingid]);
    }

    /**
     * Validate component grades submitted from the form.
     *
     * @param array $componentgrades
     * @param int|null $coursemoduleid
     * @return \stdClass
     * @throws \coding_exception
     * @throws \dml_exception
     */
    public function validate_component_grades(array $componentgrades, int $coursemoduleid = null): \stdClass {
        $errormessages = [];
        $componentgradestomap = [];
        $mappingtoremove = [];
        $duplicatemappings = [];

        // Count the number of component grades have same map code.
        foreach ($componentgrades as $componentgradeid) {
            $componentgrade = $this->get_local_component_grade_by_id($componentgradeid);
            $duplicatemappings[$componentgrade->mapcode][] = $componentgrade;
        }

        // Check if more than one component grade with same map code is mapped to the same activity.
        foreach ($duplicatemappings as $mapcode => $componentgradesarray) {
            if (count($componentgradesarray) > 1) {
                $errormessages[] = get_string('error:duplicatemapping', 'local_sitsgradepush', $mapcode);
            }
        }

        // For newly created activity, no need to check existing mapping.
        if (!$coursemoduleid) {
            $componentgradestomap = $componentgrades;
        } else {
            // Get existing component grades mapping for the course module.
            $existingmapping = $this->get_assessment_mappings($coursemoduleid);

            if ($existingmapping) {
                // Extract mapped component grades.
                $mappedcomponentgrades = array_column($existingmapping, 'componentgradeid');

                foreach ($componentgrades as $componentgradeid) {
                    // Mapped component grade does not need to be mapped again.
                    if (!in_array($componentgradeid, $mappedcomponentgrades)) {
                        $componentgradestomap[] = $componentgradeid;
                    }
                }

                // Get the mappings that need to be removed.
                foreach ($existingmapping as $mapping) {
                    if (!in_array($mapping->componentgradeid, $componentgrades)) {
                        $mappingtoremove[] = $mapping;
                    }
                }
            } else {
                // No existing mapping, try to map all component grades.
                $componentgradestomap = $componentgrades;
            }
        }

        // Check if any component grade had been mapped to another activity.
        foreach ($componentgradestomap as $componentgradeid) {
            // Check if any component grade had been mapped to another activity.
            if ($this->is_component_grade_mapped($componentgradeid)) {
                $componentgradename = $this->get_formatted_component_grade_name($componentgradeid);
                $errormessages[] = get_string('error:componentgrademapped', 'local_sitsgradepush', $componentgradename);
            }
        }

        // Removing mapping is not allowed if there is any grade had been pushed.
        foreach ($mappingtoremove as $mapping) {
            if ($this->has_grades_pushed($mapping->id)) {
                $componentgradename = $this->get_formatted_component_grade_name($mapping->componentgradeid);
                $errormessages[] = get_string('error:componentgradepushed', 'local_sitsgradepush', $componentgradename);
            }
        }

        // Return result.
        $result = new \stdClass();
        $result->componentgradestomap = $componentgradestomap;
        $result->mappingtoremove = $mappingtoremove;
        $result->errormessages = $errormessages;

        return $result;
    }

    /**
     * Validate assessment mapping request from select existing activity page.
     *
     * @param int $componentgradeid
     * @param int $coursemoduleid
     * @return bool
     * @throws \dml_exception
     * @throws \moodle_exception
     */
    public function validate_component_grade(int $componentgradeid, int $coursemoduleid): bool {
        // Check if the component grade exists.
        if (!$componentgrade = $this->get_local_component_grade_by_id($componentgradeid)) {
            throw new \moodle_exception('error:mab_not_found', 'local_sitsgradepush', '', $componentgradeid);
        }

        // Check if the course module exists.
        if (!$coursemodule = $this->get_course_module($coursemoduleid)) {
            throw new \moodle_exception('error:coursemodulenotfound', 'local_sitsgradepush', '', $coursemoduleid);
        }

        // Do not allow mapping activity which is not from current academic year.
        if (!$this->is_current_academic_year_activity($coursemodule->course)) {
            throw new \moodle_exception('error:pastactivity', 'local_sitsgradepush');
        }

        // A component grade can only be mapped to one activity, so there is only one mapping record for each component grade.
        if (!empty($mapping = $this->is_component_grade_mapped($componentgradeid))) {
            // Check if this mapping has grades pushed.
            if ($this->has_grades_pushed($mapping->id)) {
                throw new \moodle_exception(
                    'error:mab_has_push_records',
                    'local_sitsgradepush',
                    '',
                    $componentgrade->mapcode . '-' . $componentgrade->mabseq
                );
            }

            // Nothing to update if the mapping is the same.
            if ($mapping->coursemoduleid == $coursemoduleid) {
                throw new \moodle_exception('error:no_update_for_same_mapping', 'local_sitsgradepush');
            }
        }

        // Get existing mappings for this activity.
        if ($existingmappings = $this->get_assessment_mappings($coursemoduleid)) {
            // Make sure it does not map to another component grade with same map code.
            foreach ($existingmappings as $existingmapping) {
                if ($existingmapping->mapcode == $componentgrade->mapcode) {
                    throw new \moodle_exception('error:same_map_code_for_same_activity', 'local_sitsgradepush');
                }
            }
        }

        return true;
    }

    /**
     * Get grade of an assessment for a student.
     *
     * @param int $coursemoduleid
     * @param int $userid
     * @param int|null $partid
     * @return string|null
     * @throws \coding_exception
     * @throws \moodle_exception
     */
    public function get_student_grade(int $coursemoduleid, int $userid, int $partid = null): ?string {
        $coursemodule = get_coursemodule_from_id('', $coursemoduleid);
        if (empty($coursemodule)) {
            throw new \moodle_exception('error:coursemodulenotfound', 'local_sitsgradepush', '', $coursemoduleid);
        }

        // Get the assessment object.
        $assessment = assessmentfactory::get_assessment($coursemoduleid);
        if (empty($assessment)) {
            throw new \moodle_exception('error:assessmentnotfound', 'local_sitsgradepush', '', $coursemoduleid);
        }

        // Get the grade.
        return $assessment->get_user_grade($userid, $partid);
    }

    /**
     * Get all course activities eligible for grade push.
     *
     * @param int $courseid Course id
     * @return array
     * @throws \moodle_exception
     */
    public function get_all_course_activities(int $courseid): array {
        $activities = [];
        foreach (self::ALLOWED_ACTIVITIES as $modname) {
            if (!empty($results = get_coursemodules_in_course($modname, $courseid))) {
                foreach ($results as $result) {
                    $assessemnt = assessmentfactory::get_assessment($result);
                    $activities[] = $assessemnt;
                }
            }
        }

        return $activities;
    }

    /**
     * Get data required for page update, e.g. progress bars, last transfer time.
     *
     * @param int $courseid
     * @param int $couresmoduleid
     * @return array
     * @throws \coding_exception
     * @throws \dml_exception
     */
    public function get_data_for_page_update($courseid, $couresmoduleid = 0): array {
        global $DB;
        $results = [];

        $conditions = [
            'courseid' => $courseid,
        ];

        if ($couresmoduleid) {
            $conditions['coursemoduleid'] = $couresmoduleid;
        }

        // Get assessment mappings.
        $mappings = $DB->get_records(self::TABLE_ASSESSMENT_MAPPING, $conditions);
        if (empty($mappings)) {
            return [];
        }

        foreach ($mappings as $mapping) {
            // Get assessment data.
            $assessmentdata = $this->get_assessment_data($mapping->coursemoduleid, $mapping->id);

            // Check if there is a pending / running task for this mapping.
            $task = taskmanager::get_pending_task_in_queue($mapping->id);
            $result = new \stdClass();
            $result->assessmentmappingid = $mapping->id;
            $result->courseid = $courseid;
            $result->coursemoduleid = $mapping->coursemoduleid;
            $result->markscount = $assessmentdata->markscount;
            $result->task = !empty($task) ? $task : null;
            $result->lasttransfertime = taskmanager::get_last_push_task_time($mapping->id);
            $results[] = $result;
        }

        return $results;
    }

    /**
     * Save transfer log.
     *
     * @param string $type
     * @param int $assessmentmappingid
     * @param int $userid
     * @param mixed $request
     * @param array $response
     * @param int|null $taskid
     * @param int|null $errorlogid
     * @return void
     * @throws \dml_exception
     */
    private function save_transfer_log(
        string $type, int $assessmentmappingid, int $userid, mixed $request, array $response, ?int $taskid, int $errorlogid = null)
    : void {
        global $USER, $DB;
        $insert = new \stdClass();
        $insert->type = $type;
        $insert->userid = $userid;
        $insert->assessmentmappingid = $assessmentmappingid;
        $insert->request = ($request instanceof irequest) ? $request->get_endpoint_url_with_params() : null;
        $insert->requestbody = ($request instanceof irequest) ? $request->get_request_body() : null;
        $insert->response = json_encode($response);
        $insert->errlogid = $errorlogid;
        $insert->taskid = $taskid;
        $insert->usermodified = $USER->id;
        $insert->timecreated = time();

        $DB->insert_record(self::TABLE_TRANSFER_LOG, $insert);
    }

    /**
     * Check if the last push was succeeded.
     *
     * @param int $assessmentmappingid
     * @param int $userid
     * @param string $pushtype
     * @return bool
     * @throws \dml_exception
     */
    private function last_push_succeeded(int $assessmentmappingid, int $userid, string $pushtype): bool {
        if ($log = $this->get_transfer_logs($assessmentmappingid, $userid, $pushtype)) {
            // Get the first element.
            $log = reset($log);
            if (!empty($log->response)) {
                $response = json_decode($log->response);
                // Last push was succeeded. No need to push again.
                if ($response->code == '0') {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Check response.
     *
     * @param mixed $response
     * @param irequest $request
     * @return void
     * @throws \coding_exception
     * @throws \dml_exception
     * @throws \moodle_exception
     */
    private function check_response(mixed $response, irequest $request): void {
        // Throw exception when response is empty.
        if (empty($response)) {
            // If request is get student, return a student not found message for the logger to identify the error.
            if ($request->get_request_name() == 'Get student') {
                $response = json_encode(['message' => 'student not found']);
            }

            // Log error.
            $errorlogid = logger::log_request_error(
                get_string(
                    'error:emptyresponse', 'local_sitsgradepush',
                    $request->get_request_name()
                ),
                $request,
                $response ?? null
            );

            // Add error log id to debug info.
            $debuginfo = $errorlogid ?: null;

            throw new \moodle_exception(
                'error:emptyresponse', 'local_sitsgradepush', '', $request->get_request_name(), $debuginfo
            );
        }
    }

    /**
     * Add failed transfer log.
     *
     * @param string $requestidentifier
     * @param int $assessmentmappingid
     * @param int $userid
     * @param int|null $taskid
     * @param \moodle_exception $exception
     * @return void
     * @throws \dml_exception
     */
    private function mark_push_as_failed(
        string $requestidentifier, int $assessmentmappingid, int $userid, ?int $taskid, \moodle_exception $exception): void {
        // Failed response.
        $response = [
            "code" => "-1",
            "message" => $exception->getMessage(),
        ];

        // Get error log id if any.
        $errorlogid = $exception->debuginfo ?: null;

        // Add failed transfer log.
        $this->save_transfer_log($requestidentifier, $assessmentmappingid, $userid, null, $response, $taskid, intval($errorlogid));
    }
}
