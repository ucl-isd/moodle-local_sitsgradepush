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

use DirectoryIterator;
use local_sitsgradepush\api\client_factory;
use local_sitsgradepush\api\iclient;
use local_sitsgradepush\api\irequest;
use local_sitsgradepush\assessment\assessment;
use local_sitsgradepush\submission\submissionfactory;

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

    /** @var string DB table for storing component grades from SITS */
    const TABLE_COMPONENT_GRADE = 'local_sitsgradepush_mab';

    /** @var string DB table for storing assessment mappings */
    const TABLE_ASSESSMENT_MAPPING = 'local_sitsgradepush_mapping';

    /** @var string DB table for storing grade transfer log */
    const TABLE_TRANSFER_LOG = 'local_sitsgradepush_tfr_log';

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
        'mabname' => 'MAB_NAME'
    ];

    /** @var string[] Allowed activity types */
    const ALLOWED_ACTIVITIES = ['assign', 'quiz', 'workshop', 'turnitintooltwo'];

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
                // Get component grades from SITS.
                foreach ($modocc as $occ) {
                    $request = $this->apiclient->build_request(self::GET_COMPONENT_GRADE, $occ);
                    $response = $this->apiclient->send_request($request);
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

            $records = $DB->get_records_sql($sql,
                array(
                'modcode' => $occ->mod_code, 'modocc' => $occ->mod_occ_mav,
                'academicyear' => $occ->mod_occ_year_code,
                'periodslotcode' => $occ->mod_occ_psl_code)
            );

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
            if ($data->reassessment == '1') {
                $record->reassessment = '1';
            }
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
     * @return array
     */
    public function get_student_from_sits(\stdClass $componentgrade, int $userid): array {
        $user = user_get_users_by_id([$userid]);
        $data = new \stdClass();
        $data->idnumber = $user[$userid]->idnumber;
        $data->mapcode = $componentgrade->mapcode;
        $data->mabseq = $componentgrade->mabseq;

        $request = $this->apiclient->build_request('getstudent', $data);

        return $this->apiclient->send_request($request);
    }

    /**
     * Push grade to SITS.
     *
     * @param assessment $assessment
     * @param int $userid
     * @return false|mixed
     * @throws \dml_exception
     * @throws \moodle_exception
     */
    public function push_grade_to_sits(assessment $assessment, int $userid) {
        // Check if it is a valid push.
        if ($data = $this->is_valid_for_pushing($assessment, $userid)) {
            // Check if last push was succeeded. Proceed if not succeeded.
            if (!$this->last_push_succeeded(self::PUSH_GRADE, $assessment->get_course_module()->id, $userid)) {
                // Get grade.
                $grade = $this->get_student_grade($assessment->get_course_module(), $userid);
                // Push if grade is found.
                if ($grade->grade) {
                    $data->marks = $grade->grade;
                    $data->grade = ''; // TODO: Where to get the grade?

                    $request = $this->apiclient->build_request(self::PUSH_GRADE, $data);
                    $response = $this->apiclient->send_request($request);

                    // Push submission log.
                    $this->push_submission_log_to_sits($assessment, $userid, $data);

                    // Save transfer log.
                    $this->save_transfer_log(self::PUSH_GRADE, $data, $userid, $request, $response);

                    return $response;
                }
            }
        }

        return false;
    }

    /**
     * Push submission log to SITS.
     *
     * @param assessment $assessment
     * @param int $userid
     * @param \stdClass $data
     * @return false|mixed
     * @throws \coding_exception
     * @throws \dml_exception
     * @throws \moodle_exception
     */
    public function push_submission_log_to_sits(assessment $assessment, int $userid, \stdClass $data) {
        // Check if last push was succeeded. Proceed if not succeeded.
        if (!$this->last_push_succeeded(self::PUSH_SUBMISSION_LOG, $assessment->get_course_module()->id, $userid)) {
            // Create the submission object.
            $submission = submissionfactory::get_submission($assessment->get_course_module(), $userid);
            // Push if student has submission.
            if ($submission->get_submission_data()) {
                $request = $this->apiclient->build_request(self::PUSH_SUBMISSION_LOG, $data, $submission);
                $response = $this->apiclient->send_request($request);

                // Save push log.
                $this->save_transfer_log(self::PUSH_SUBMISSION_LOG, $data, $userid, $request, $response);
                return $response;
            }
        }

        return false;
    }

    /**
     * Check if the push is valid.
     *
     * @param assessment $assessment
     * @param int $userid
     * @return false|\stdClass
     * @throws \dml_exception
     */
    public function is_valid_for_pushing(assessment $assessment, int $userid) {
        $assessmentinfo = 'CMID: ' .$assessment->get_course_module()->id . ', USERID: ' . $userid;

        // Check mapping.
        if (!$mapping = $this->get_assessment_mapping($assessment->get_course_module()->id)) {
            // TODO: log it somewhere later.
            $errormessage = 'No valid mapping or component grade. ' . $assessmentinfo;
            return false;
        }

        // Get SPR_CODE from SITS.
        if (!$student = $this->get_student_from_sits($mapping, $userid)) {
            // TODO: log it somewhere later.
            $errormessage = 'Cannot get student from sits. ' . $assessmentinfo;
            return false;
        }

        // Build the required data.
        $data = new \stdClass();
        $data->assessmentmappingid = $mapping->id;
        $data->coursemoduleid = $mapping->coursemoduleid;
        $data->componentgradeid = $mapping->componentgradeid;
        $data->mapcode = $mapping->mapcode;
        $data->mabseq = $mapping->mabseq;
        $data->sprcode = $student[0]['SPR_CODE'];
        $data->academicyear = $mapping->academicyear;
        $data->pslcode = $mapping->periodslotcode;
        $data->reassessment = $mapping->reassessment;
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
        $sql = "SELECT *
                FROM {" . self::TABLE_TRANSFER_LOG . "}
                WHERE type = :type AND userid = :userid AND coursemoduleid = :coursemoduleid
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
                $data->lastgradepushresult = '-';
                $data->lastgradepushtime = '-';
                $data->lastsublogpushresult = '-';
                $data->lastsublogpushtime = '-';

                // Get grade push status.
                if ($gradepushstatus = $this->get_transfer_log(self::PUSH_GRADE, $coursemodule->id, $student->id)) {
                    $response = json_decode($gradepushstatus->response);
                    $data->lastgradepushresult = $response->message;
                    $data->lastgradepushtime = date('Y-m-d H:i:s', $gradepushstatus->timecreated);
                }

                // Get submission log push status.
                if ($gradepushstatus = $this->get_transfer_log(self::PUSH_SUBMISSION_LOG, $coursemodule->id, $student->id)) {
                    $response = json_decode($gradepushstatus->response);
                    $data->lastsublogpushresult = $response->message;
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

        return $assessmentdata;
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
     * Save grade push log.
     *
     * @param string $type
     * @param \stdClass $mapping
     * @param int $userid
     * @param irequest $request
     * @param array $response
     * @return void
     * @throws \dml_exception
     */
    private function save_transfer_log(string $type, \stdClass $mapping, int $userid, irequest $request, array $response) {
        global $USER, $DB;
        $insert = new \stdClass();
        $insert->type = $type;
        $insert->userid = $userid;
        $insert->assessmentmappingid = $mapping->assessmentmappingid;
        $insert->coursemoduleid = $mapping->coursemoduleid;
        $insert->componentgradeid = $mapping->componentgradeid;
        $insert->requestbody = $request->get_request_body();
        $insert->response = json_encode($response);
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
                if ($response->code === '0') {
                    return true;
                }
            }
        }

        return false;
    }
}
