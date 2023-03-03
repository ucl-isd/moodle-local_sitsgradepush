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
    public static function get_manager() {
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
     * Lookup assessment mapping
     *
     * @param int $cmid course module id
     * @return false|mixed|\stdClass
     * @throws \dml_exception
     */
    public function get_assessment_mapping(int $cmid) {
        global $DB;
        return $DB->get_record(self::TABLE_ASSESSMENT_MAPPING, ['coursemoduleid' => $cmid]);
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
     * @param \stdClass $grade
     * @return mixed
     */
    public function get_student_from_sits(\stdClass $componentgrade, \stdClass $grade) {
        $data = new \stdClass();
        $data->idnumber = $grade->idnumber;
        $data->mapcode = $componentgrade->mapcode;
        $data->mabseq = $componentgrade->mabseq;

        $request = $this->apiclient->build_request('getstudent', $data);
        return $this->apiclient->send_request($request);
    }

    /**
     * Push grade to SITS.
     *
     * @param int $coursemoduleid
     * @param \stdClass $grade
     * @return mixed
     * @throws \dml_exception
     * @throws \moodle_exception
     */
    public function push_grade_to_sits(int $coursemoduleid, \stdClass $grade) {
        // Check mapping.
        if (!$mapping = $this->get_assessment_mapping($coursemoduleid)) {
            throw new \moodle_exception('This activity is not mapped to a component grade');
        }

        // Check component grade.
        if (!$componentgrade = $this->get_local_component_grade_by_id($mapping->componentgradeid)) {
            throw new \moodle_exception('Cannot find component grade id: ' . $mapping->componentgradeid);
        }

        // Get SPR_CODE from SITS.
        if (!$student = $this->get_student_from_sits($componentgrade, $grade)) {
            throw new \moodle_exception('Cannot get student from sits.');
        }

        // Build the request data.
        $requestdata = new \stdClass();
        $requestdata->mapcode = $componentgrade->mapcode;
        $requestdata->mabseq = $componentgrade->mabseq;
        $requestdata->sprcode = $student[0]['SPR_CODE'];
        $requestdata->academicyear = $componentgrade->academicyear;
        $requestdata->pslcode = $componentgrade->periodslotcode;
        $requestdata->reassessment = $mapping->reassessment;
        $requestdata->srarseq = '001'; // Just a dummy reassessment sequence number for now.
        $requestdata->marks = $grade->marks;
        $requestdata->grade = ''; // TODO: Where to get the grade?

        $request = $this->apiclient->build_request(self::PUSH_GRADE, $requestdata);
        $response = $this->apiclient->send_request($request);

        // Save transfer log.
        $this->save_transfer_log($mapping, $grade, $response);

        return $response;
    }

    /**
     * Return the last push log for a given grade push.
     *
     * @param int $coursemoduleid
     * @param int $userid
     * @return false|mixed
     * @throws \dml_exception
     */
    public function get_grade_push_status (int $coursemoduleid, int $userid) {
        global $DB;
        $sql = "SELECT *
                FROM {" . self::TABLE_TRANSFER_LOG . "}
                WHERE userid = :userid AND coursemoduleid = :coursemoduleid
                ORDER BY timecreated DESC LIMIT 1";
        return $DB->get_record_sql($sql, ['coursemoduleid' => $coursemoduleid, 'userid' => $userid]);
    }

    /**
     * Save grade push log.
     *
     * @param \stdClass $mapping
     * @param \stdClass $grade
     * @param array $response
     * @return void
     * @throws \dml_exception
     */
    private function save_transfer_log(\stdClass $mapping, \stdClass $grade, array $response) {
        global $USER, $DB;
        $insert = new \stdClass();
        $insert->userid = $grade->userid;
        $insert->assessmentmappingid = $mapping->id;
        $insert->coursemoduleid = $mapping->coursemoduleid;
        $insert->componentgradeid = $mapping->componentgradeid;
        $insert->marks = $grade->marks;
        $insert->grade = ''; // TODO: Where to get the grade?
        $insert->responsecode = $response['code'];
        $insert->usermodified = $USER->id;
        $insert->timecreated = time();

        $DB->insert_record(self::TABLE_TRANSFER_LOG, $insert);
    }
}
