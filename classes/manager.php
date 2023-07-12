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

use cache;
use core_course\customfield\course_handler;
use DirectoryIterator;
use local_sitsgradepush\api\client_factory;
use local_sitsgradepush\api\iclient;
use local_sitsgradepush\api\irequest;
use local_sitsgradepush\assessment\assessment;
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
        'examroomcode' => 'APA_ROMC'
    ];

    /** @var string[] Allowed activity types */
    const ALLOWED_ACTIVITIES = ['assign', 'quiz', 'turnitintooltwo'];

    /** @var int Push task status - requested*/
    const PUSH_TASK_STATUS_REQUESTED = 0;

    /** @var int Push task status - queued*/
    const PUSH_TASK_STATUS_QUEUED = 1;

    /** @var int Push task status - processing*/
    const PUSH_TASK_STATUS_PROCESSING = 2;

    /** @var int Push task status - completed*/
    const PUSH_TASK_STATUS_COMPLETED = 3;

    /** @var int Push task status - failed*/
    const PUSH_TASK_STATUS_FAILED = -1;

    /** @var null Manager instance */
    private static $instance = null;

    /** @var iclient|null API client for performing api calls */
    private $apiclient = null;

    /** @var array Store any api errors */
    private $apierrors = [];

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
                    // Get component grades from SITS.
                    $request = $this->apiclient->build_request(self::GET_COMPONENT_GRADE, $occ);
                    $response = $this->apiclient->send_request($request);

                    // Check response.
                    $this->check_response($response, $request);

                    // Filter out unwanted component grades by marking scheme.
                    $response = $this->filter_out_invalid_component_grades($response);

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
     * @return array
     * @throws \dml_exception
     */
    public function get_component_grade_options(int $courseid): array {
        $options = [];
        // Get module occurrences from portico enrolments block.
        $modocc = \block_portico_enrolments\manager::get_modocc_mappings($courseid);

        // Fetch component grades from SITS.
        if ($this->fetch_component_grades_from_sits($modocc)) {
            // Get the updated records from local component grades table.
            $records = $this->get_local_component_grades($modocc);
            if (!empty($records)) {
                foreach ($records as $record) {
                    $option = new \stdClass();
                    $option->disabled = '';
                    $option->text = sprintf(
                        '%s-%s-%s-%s-%s %s',
                        $record->modcode,
                        $record->academicyear,
                        $record->periodslotcode,
                        $record->modocc,
                        $record->mabseq,
                        $record->mabname
                    );
                    $option->value = $record->id;
                    if (!empty($record->assessmentmappingid)) {
                        $option->disabled = 'disabled';
                    }
                    $options[] = $option;
                }
            }
        }

        return $options;
    }

    /**
     * Get component grades from local DB.
     *
     * @param array $modocc
     * @return array
     * @throws \dml_exception
     */
    public function get_local_component_grades(array $modocc): array {
        global $DB;

        $componentgrades = [];
        foreach ($modocc as $occ) {
            $sql = "SELECT cg.*, am.id AS 'assessmentmappingid'
                    FROM {" . self::TABLE_COMPONENT_GRADE . "} cg LEFT JOIN {" . self::TABLE_ASSESSMENT_MAPPING . "} am
                    ON cg.id = am.componentgradeid
                    WHERE cg.modcode = :modcode AND cg.modocc = :modocc AND cg.academicyear = :academicyear
                    AND cg.periodslotcode = :periodslotcode";

            $params = array(
                'modcode' => $occ->mod_code, 'modocc' => $occ->mod_occ_mav,
                'academicyear' => $occ->mod_occ_year_code,
                'periodslotcode' => $occ->mod_occ_psl_code);

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

            // Merge results for multiple module occurrences.
            $componentgrades = array_merge($componentgrades, $records);
        }

        return $componentgrades;
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
     * Save assessment mapping to database.
     *
     * @param \stdClass $data
     * @return void
     * @throws \dml_exception
     */
    public function save_assessment_mapping(\stdClass $data) {
        global $DB;

        if (!$this->is_activity_mapped($data->coursemodule)) {
            $record = new \stdClass();
            $record->courseid = $data->course;
            $record->coursemoduleid = $data->coursemodule;
            $record->moduletype = $data->modulename;
            $record->componentgradeid = $data->gradepushassessmentselect;
            $record->timecreated = time();
            $record->timemodified = time();

            $DB->insert_record(self::TABLE_ASSESSMENT_MAPPING, $record);
        }
    }

    /**
     * Lookup assessment mapping.
     *
     * @param int $cmid course module id
     * @return false|mixed|\stdClass
     * @throws \dml_exception
     */
    public function get_assessment_mapping(int $cmid) {
        global $DB;
        $sql = "SELECT am.id, am.courseid, am.coursemoduleid, am.moduletype, am.componentgradeid, am.reassessment,
                       am.reassessmentseq, cg.modcode, cg.modocc, cg.academicyear, cg.periodslotcode, cg.mapcode,
                       cg.mabseq, cg.astcode, cg.mabname
                FROM {" . self::TABLE_ASSESSMENT_MAPPING . "} am
                JOIN {" . self::TABLE_COMPONENT_GRADE . "} cg ON am.componentgradeid = cg.id
                WHERE am.coursemoduleid = :cmid";
        return $DB->get_record_sql($sql, ['cmid' => $cmid]);
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
     * @return bool
     * @throws \dml_exception
     */
    public function is_component_grade_mapped(int $id): bool {
        global $DB;
        return $DB->record_exists(self::TABLE_ASSESSMENT_MAPPING, ['componentgradeid' => $id]);
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
    public function get_student_from_sits(\stdClass $componentgrade, int $userid) {
        // Get user.
        $user = user_get_users_by_id([$userid]);

        // Try to get student spr from cache.
        $cache = cache::make('local_sitsgradepush', 'studentspr');
        $sprcodecachekey = 'studentspr_' . $componentgrade->mapcode . '_' . $user[$userid]->idnumber;
        $expirescachekey = 'expires_' . $componentgrade->mapcode . '_' . $user[$userid]->idnumber;
        $studentspr = $cache->get($sprcodecachekey);
        $expires = $cache->get($expirescachekey);

        // If cache is empty or expired, get student from SITS.
        if (empty($studentspr) || empty($expires) || time() >= $expires) {
            // Build required data.
            $data = new \stdClass();
            $data->idnumber = $user[$userid]->idnumber;
            $data->mapcode = $componentgrade->mapcode;
            $data->mabseq = $componentgrade->mabseq;

            // Build and send request.
            $request = $this->apiclient->build_request('getstudent', $data);
            $response = $this->apiclient->send_request($request);

            // Check response.
            $this->check_response($response, $request);

            // Save response to cache.
            $cache->set($sprcodecachekey, $response['SPR_CODE']);

            // Save expires to cache, expires in 30 days.
            $cache->set($expirescachekey, time() + 2592000);

            return $response['SPR_CODE'];
        } else {
            return $studentspr;
        }
    }

    /**
     * Push grade to SITS.
     *
     * @param assessment $assessment
     * @param int $userid
     * @return bool
     * @throws \dml_exception
     * @throws \moodle_exception
     */
    public function push_grade_to_sits(assessment $assessment, int $userid) {
        try {
            // Check if last push was succeeded, exit if succeeded.
            if ($this->last_push_succeeded(self::PUSH_GRADE, $assessment->get_course_module()->id, $userid)) {
                return false;
            }

            // Get required data for pushing.
            $data = $this->get_required_data_for_pushing($assessment, $userid);

            // Get grade.
            $grade = $this->get_student_grade($assessment->get_course_module(), $userid);

            // Push if grade is found.
            if ($grade->grade) {
                $data->marks = $grade->grade;
                $data->grade = ''; // TODO: Where to get the grade?

                $request = $this->apiclient->build_request(self::PUSH_GRADE, $data);
                $response = $this->apiclient->send_request($request);

                // Check response.
                $this->check_response($response, $request);

                // Save transfer log.
                $this->save_transfer_log(self::PUSH_GRADE, $assessment->get_course_module()->id, $userid, $request, $response);

                return true;
            }
        } catch (\moodle_exception $e) {
            $this->mark_push_as_failed(self::PUSH_GRADE, $assessment->get_course_module()->id, $userid, $e);
        }

        return false;
    }

    /**
     * Push submission log to SITS.
     *
     * @param assessment $assessment
     * @param int $userid
     * @return bool
     * @throws \dml_exception
     */
    public function push_submission_log_to_sits(assessment $assessment, int $userid): bool {
        try {
            // Check if submission log push is enabled.
            if (!get_config('local_sitsgradepush', 'sublogpush')) {
                return false;
            }

            // Check if last push was succeeded, exit if succeeded.
            if ($this->last_push_succeeded(self::PUSH_SUBMISSION_LOG, $assessment->get_course_module()->id, $userid)) {
                return false;
            }

            // Get required data for pushing.
            $data = $this->get_required_data_for_pushing($assessment, $userid);

            // Create the submission object.
            $submission = submissionfactory::get_submission($assessment->get_course_module(), $userid);

            // Push if student has submission.
            if ($submission->get_submission_data()) {
                $request = $this->apiclient->build_request(self::PUSH_SUBMISSION_LOG, $data, $submission);
                $response = $this->apiclient->send_request($request);

                // Check response.
                $this->check_response($response, $request);

                // Save push log.
                $this->save_transfer_log(
                    self::PUSH_SUBMISSION_LOG, $assessment->get_course_module()->id, $userid, $request, $response);

                return true;
            }
        } catch (\moodle_exception $e) {
            $this->mark_push_as_failed(self::PUSH_SUBMISSION_LOG, $assessment->get_course_module()->id, $userid, $e);
        }

        return false;
    }

    /**
     * Get required data for pushing.
     *
     * @param assessment $assessment
     * @param int $userid
     * @return \stdClass
     * @throws \coding_exception
     * @throws \dml_exception
     * @throws \moodle_exception
     */
    public function get_required_data_for_pushing (assessment $assessment, int $userid): \stdClass {
        global $USER;

        // Get assessment mapping.
        $assessmentinfo = 'CMID: ' .$assessment->get_course_module()->id . ', USERID: ' . $userid;

        // Check mapping.
        if (!$mapping = $this->get_assessment_mapping($assessment->get_course_module()->id)) {
            logger::log(get_string('error:assessmentmapping', 'local_sitsgradepush', $assessmentinfo));
            throw new \moodle_exception('error:assessmentisnotmapped', 'local_sitsgradepush', '', $assessmentinfo);
        }

        // Get SPR_CODE from SITS.
        $studentspr = $this->get_student_from_sits($mapping, $userid);

        // Build the required data.
        $data = new \stdClass();
        $data->mapcode = $mapping->mapcode;
        $data->mabseq = $mapping->mabseq;
        $data->sprcode = $studentspr;
        $data->academicyear = $mapping->academicyear;
        $data->pslcode = $mapping->periodslotcode;
        $data->reassessment = $mapping->reassessment;
        $data->source = sprintf(
            'moodle-course%s-activity%s-user%s',
            $assessment->get_course_module()->course,
            $assessment->get_course_module()->id,
            $USER->id
        );
        $data->srarseq = '001'; // Just a dummy reassessment sequence number for now.

        return $data;
    }

    /**
     * Return the last push log for a given grade push.
     *
     * @param string $type
     * @param int $coursemoduleid
     * @param int $userid
     * @return false|mixed
     * @throws \dml_exception
     */
    public function get_transfer_log (string $type, int $coursemoduleid, int $userid) {
        global $DB;
        $sql = "SELECT trflog.id, trflog.response, trflog.timecreated, errlog.errortype, errlog.message
                FROM {" . self::TABLE_TRANSFER_LOG . "} trflog LEFT JOIN
                {" . self::TABLE_ERROR_LOG . "} errlog  ON trflog.errlogid = errlog.id
                WHERE type = :type AND trflog.userid = :userid AND coursemoduleid = :coursemoduleid
                ORDER BY timecreated DESC LIMIT 1";
        return $DB->get_record_sql($sql, ['type' => $type, 'coursemoduleid' => $coursemoduleid, 'userid' => $userid]);
    }

    /**
     * Get the assessment data.
     *
     * @param assessment $assessment
     * @return array
     * @throws \coding_exception
     * @throws \dml_exception
     * @throws \moodle_exception
     */
    public function get_assessment_data(assessment $assessment) {
        $assessmentdata = [];
        $students = $assessment->get_all_participants();
        $coursemodule = $assessment->get_course_module();
        foreach ($students as $student) {
            $grade = $this->get_student_grade($coursemodule, $student->id);
            if (!empty($grade->grade)) {
                $data = new \stdClass();
                $data->userid = $student->id;
                $data->idnumber = $student->idnumber;
                $data->firstname = $student->firstname;
                $data->lastname = $student->lastname;
                $data->marks = $grade->grade;
                $data->handin_datetime = '-';
                $data->handin_status = '-';
                $data->export_staff = '-';
                $data->lastgradepushresult = null;
                $data->lastgradepusherrortype = null;
                $data->lastgradepushtime = '-';
                $data->lastsublogpushresult = null;
                $data->lastsublogpusherrortype = null;
                $data->lastsublogpushtime = '-';

                // Get grade push status.
                if ($gradepushstatus = $this->get_transfer_log(self::PUSH_GRADE, $coursemodule->id, $student->id)) {
                    $response = json_decode($gradepushstatus->response);
                    $errortype = $gradepushstatus->errortype ?: errormanager::ERROR_UNKNOWN;
                    $data->lastgradepushresult = ($response->code == '0') ? 'success' : 'failed';
                    $data->lastgradepusherrortype = ($response->code == '0') ? 0 : $errortype;
                    $data->lastgradepushtime = date('Y-m-d H:i:s', $gradepushstatus->timecreated);
                }

                // Get submission log push status.
                if ($gradepushstatus = $this->get_transfer_log(self::PUSH_SUBMISSION_LOG, $coursemodule->id, $student->id)) {
                    $response = json_decode($gradepushstatus->response);
                    $errortype = $gradepushstatus->errortype ?: errormanager::ERROR_UNKNOWN;
                    $data->lastsublogpushresult = ($response->code == '0') ? 'success' : 'failed';
                    $data->lastsublogpusherrortype = ($response->code == '0') ? 0 : $errortype;
                    $data->lastsublogpushtime = date('Y-m-d H:i:s', $gradepushstatus->timecreated);
                }

                // Get submission.
                $submission = submissionfactory::get_submission($coursemodule, $student->id);
                if ($submission->get_submission_data()) {
                    $data->handin_datetime = $submission->get_handin_datetime();
                    $data->handin_status = $submission->get_handin_status();
                    $data->export_staff = $submission->get_export_staff();
                }

                $assessmentdata[] = $data;
            }
        }

        // Sort data.
        return $this->sort_grade_push_history_table($assessmentdata);
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
    public function get_moodle_ast_codes() {
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
    public function get_moodle_ast_codes_work_with_exam_room_code() {
        $codes = get_config('local_sitsgradepush', 'moodle_ast_codes_exam_room');
        if (!empty($codes)) {
            if ($codes = explode(',', $codes)) {
                return array_map('trim', $codes);
            }
        }

        return false;
    }

    /**
     * Schedule push task.
     *
     * @param int $coursemoduleid
     * @return bool|int
     * @throws \dml_exception
     */
    public function schedule_push_task(int $coursemoduleid) {
        global $DB, $USER;

        // Check course module exists.
        if (!$DB->record_exists('course_modules', ['id' => $coursemoduleid])) {
            throw new \moodle_exception('error:coursemodulenotfound', 'local_sitsgradepush');
        }

        // Check if course module has been mapped to an assessment component.
        if (!$DB->record_exists('local_sitsgradepush_mapping', ['coursemoduleid' => $coursemoduleid])) {
            throw new \moodle_exception('error:assessmentisnotmapped', 'local_sitsgradepush');
        }

        // Check if there is already in one of the following status: added, queued, processing.
        if (self::get_pending_task_in_queue($coursemoduleid)) {
            throw new \moodle_exception('error:duplicatedtask', 'local_sitsgradepush');
        }

        $task = new \stdClass();
        $task->userid = $USER->id;
        $task->timescheduled = time();
        $task->coursemoduleid = $coursemoduleid;

        return $DB->insert_record('local_sitsgradepush_tasks', $task);
    }

    /**
     * Get push task in status requested, queued or processing for a course module.
     *
     * @param int $coursemoduleid
     * @return \stdClass|bool
     * @throws \coding_exception
     * @throws \dml_exception
     */
    public function get_pending_task_in_queue(int $coursemoduleid) {
        global $DB;
        $sql = 'SELECT *
                FROM {local_sitsgradepush_tasks}
                WHERE coursemoduleid = :coursemoduleid AND status IN (:status1, :status2, :status3)
                ORDER BY id DESC';
        $params = [
            'coursemoduleid' => $coursemoduleid,
            'status1' => self::PUSH_TASK_STATUS_REQUESTED,
            'status2' => self::PUSH_TASK_STATUS_QUEUED,
            'status3' => self::PUSH_TASK_STATUS_PROCESSING,
        ];

        if ($result = $DB->get_record_sql($sql, $params)) {
            switch ($result->status) {
                case self::PUSH_TASK_STATUS_REQUESTED:
                    $result->buttonlabel = get_string('task:status:requested', 'local_sitsgradepush');
                    break;
                case self::PUSH_TASK_STATUS_QUEUED:
                    $result->buttonlabel = get_string('task:status:queued', 'local_sitsgradepush');
                    break;
                case self::PUSH_TASK_STATUS_PROCESSING:
                    $result->buttonlabel = get_string('task:status:processing', 'local_sitsgradepush');
                    break;
            }

            return $result;
        } else {
            return false;
        }
    }

    /**
     * Get last finished push task for a course module.
     *
     * @param int $coursemoduleid
     * @return false|mixed
     * @throws \dml_exception
     */
    public function get_last_finished_push_task(int $coursemoduleid) {
        global $DB;
        // Get the last task for the course module.
        $sql = 'SELECT *
                FROM {local_sitsgradepush_tasks}
                WHERE coursemoduleid = :coursemoduleid AND status IN (:status1, :status2)
                ORDER BY id DESC
                LIMIT 1';

        $params = [
            'coursemoduleid' => $coursemoduleid,
            'status1' => self::PUSH_TASK_STATUS_COMPLETED,
            'status2' => self::PUSH_TASK_STATUS_FAILED,
        ];

        if ($task = $DB->get_record_sql($sql, $params)) {
            switch ($task->status) {
                case self::PUSH_TASK_STATUS_COMPLETED:
                    $task->statustext = get_string('task:status:completed', 'local_sitsgradepush');
                    break;
                case self::PUSH_TASK_STATUS_FAILED:
                    $task->statustext = get_string('task:status:failed', 'local_sitsgradepush');
                    break;
            }

            return $task;
        } else {
            return false;
        }
    }

    /**
     * Returns number of running tasks.
     *
     * @return int
     * @throws \dml_exception
     */
    public function get_number_of_running_tasks() {
        global $DB;
        return $DB->count_records('local_sitsgradepush_tasks', ['status' => self::PUSH_TASK_STATUS_PROCESSING]);
    }

    /**
     * Returns number of pending tasks.
     *
     * @param int $status
     * @param int $limit
     * @return array
     * @throws \dml_exception
     */
    public function get_push_tasks(int $status, int $limit) {
        global $DB;
        return $DB->get_records('local_sitsgradepush_tasks', ['status' => $status], 'timescheduled ASC', '*', 0, $limit);
    }

    /**
     * Update push task status.
     *
     * @param int $id
     * @param int $status
     * @param int|null $errlogid
     * @return void
     * @throws \dml_exception
     */
    public function update_push_task_status(int $id, int $status, int $errlogid = null) {
        global $DB;
        $task = $DB->get_record('local_sitsgradepush_tasks', ['id' => $id]);
        $task->status = $status;
        $task->timeupdated = time();
        $task->errlogid = $errlogid;
        $DB->update_record('local_sitsgradepush_tasks', $task);
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
    public function get_export_staff() {
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
     * Get grade of an assessment for a student.
     *
     * @param \stdClass $coursemodule
     * @param int $userid
     * @return \stdClass|null
     */
    private function get_student_grade(\stdClass $coursemodule, int $userid): ?\stdClass {
        // Return grade of the first grade item.
        if ($grade = grade_get_grades($coursemodule->course, 'mod', $coursemodule->modname, $coursemodule->instance, $userid)) {
            foreach ($grade->items as $item) {
                foreach ($item->grades as $grade) {
                    return $grade;
                }
            }
        }

        return null;
    }

    /**
     * Save transfer log.
     *
     * @param string $type
     * @param int $coursemoduleid
     * @param int $userid
     * @param mixed $request
     * @param array $response
     * @param int|null $errorlogid
     * @return void
     * @throws \dml_exception
     */
    private function save_transfer_log(
        string $type, int $coursemoduleid, int $userid, $request, array $response, int $errorlogid = null) {
        global $USER, $DB;
        $insert = new \stdClass();
        $insert->type = $type;
        $insert->userid = $userid;
        $insert->coursemoduleid = $coursemoduleid;
        $insert->request = ($request instanceof irequest) ? $request->get_endpoint_url_with_params() : null;
        $insert->requestbody = ($request instanceof irequest) ? $request->get_request_body() : null;
        $insert->response = json_encode($response);
        $insert->errlogid = $errorlogid;
        $insert->usermodified = $USER->id;
        $insert->timecreated = time();

        $DB->insert_record(self::TABLE_TRANSFER_LOG, $insert);
    }

    /**
     * Check if the last push was succeeded.
     *
     * @param string $pushtype
     * @param int $coursemoduleid
     * @param int $userid
     * @return bool
     * @throws \dml_exception
     */
    private function last_push_succeeded(string $pushtype, int $coursemoduleid, int $userid): bool {
        if ($log = $this->get_transfer_log($pushtype, $coursemoduleid, $userid)) {
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
    private function check_response($response, irequest $request) {
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
     * @param int $coursemoduleid
     * @param int $userid
     * @param \moodle_exception $exception
     * @return void
     * @throws \dml_exception
     */
    private function mark_push_as_failed(
        string $requestidentifier, int $coursemoduleid, int $userid, \moodle_exception $exception) {
        // Failed response.
        $response = [
            "code" => "-1",
            "message" => $exception->getMessage()
        ];

        // Get error log id if any.
        $errorlogid = $exception->debuginfo ?: null;

        // Add failed transfer log.
        $this->save_transfer_log($requestidentifier, $coursemoduleid, $userid, null, $response, $errorlogid);
    }
}
