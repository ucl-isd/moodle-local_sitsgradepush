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

use context_course;
use core_course\customfield\course_handler;
use DirectoryIterator;
use local_sitsgradepush\api\client_factory;
use local_sitsgradepush\api\iclient;
use local_sitsgradepush\api\irequest;
use local_sitsgradepush\assessment\assessment;
use local_sitsgradepush\assessment\assessmentfactory;
use local_sitsgradepush\extension\extension;
use local_sitsgradepush\output\pushrecord;
use local_sitsgradepush\submission\submissionfactory;

defined('MOODLE_INTERNAL') || die;

require_once("$CFG->libdir/gradelib.php");
require_once("$CFG->dirroot/user/lib.php");
require_once("$CFG->dirroot/local/sitsgradepush/tests/fixtures/tests_data_provider.php");

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
        'mkscode' => 'MKS_CODE',
    ];

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
     * @return void
     */
    public function fetch_component_grades_from_sits(array $modocc): void {
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

                try {
                    // Get component grades from SITS.
                    $request = $this->apiclient->build_request(self::GET_COMPONENT_GRADE, $occ);
                    $response = $this->apiclient->send_request($request);

                    // Check response.
                    $this->check_response($response, $request);

                    // Set cache expiry to 1 hour.
                    cachemanager::set_cache(cachemanager::CACHE_AREA_COMPONENTGRADES, $key, $response, HOURSECS);

                    // Save component grades to DB.
                    $this->save_component_grades($response);
                } catch (\moodle_exception $e) {
                    $this->apierrors[] = $e->getMessage();
                }
            }
        }
    }

    /**
     * Fetch marking scheme data from SITS.
     *
     * @return array|void
     */
    public function fetch_marking_scheme_from_sits() {
        try {
            $key = 'markingschemes';
            $cache = cachemanager::get_cache(cachemanager::CACHE_AREA_MARKINGSCHEMES, $key);

            // Return cache if exists.
            if (!empty($cache)) {
                return (array) $cache;
            }

            // Get marking scheme from SITS.
            $request = $this->apiclient->build_request(self::GET_MARKING_SCHEMES, null);
            $response = $this->apiclient->send_request($request);

            // Check response.
            $this->check_response($response, $request);

            // Set cache expiry to 1 hour.
            cachemanager::set_cache(cachemanager::CACHE_AREA_MARKINGSCHEMES, $key, $response, HOURSECS);

            return $response;
        } catch (\moodle_exception $e) {
            $this->apierrors[] = $e->getMessage();
        }
    }

    /**
     * Is the component grade's marking scheme supported.
     *
     * @param \stdClass $componentgrade
     * @return bool
     */
    public function is_marking_scheme_supported(\stdClass $componentgrade): bool {
        $makingschemes = $this->fetch_marking_scheme_from_sits();
        return ($makingschemes[$componentgrade->mkscode]['MKS_MARKS'] == 'Y' &&
            $makingschemes[$componentgrade->mkscode]['MKS_IUSE'] == 'Y' &&
            $makingschemes[$componentgrade->mkscode]['MKS_TYPE'] == 'A');
    }

    /**
     * Get all component grades for a given course.
     *
     * @param int $courseid
     * @return array
     * @throws \coding_exception
     * @throws \dml_exception
     */
    public function get_component_grade_options(int $courseid): array {
        // For phpunit test and behat test.
        if ((defined('PHPUNIT_TEST') && PHPUNIT_TEST) || defined('BEHAT_SITE_RUNNING')) {
            $modocc = tests_data_provider::get_modocc_data($courseid);
        } else {
            // Get module occurrences from portico enrolments block.
            $modocc = \block_portico_enrolments\manager::get_modocc_mappings($courseid);
        }

        // Fetch component grades from SITS.
        $this->fetch_component_grades_from_sits($modocc);
        $modocccomponentgrades = $this->get_local_component_grades($modocc);

        // Loop through each component grade for each module occurrence and build the options.
        foreach ($modocccomponentgrades as $occ) {
            foreach ($occ->componentgrades as $mab) {
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
            $sql = "SELECT cg.*
                    FROM {" . self::TABLE_COMPONENT_GRADE . "} cg
                    WHERE cg.modcode = :modcode AND cg.modocc = :modocc AND cg.academicyear = :academicyear
                    AND cg.periodslotcode = :periodslotcode";

            $params = [
                'modcode' => $occ->mod_code,
                'modocc' => $occ->mod_occ_mav,
                'academicyear' => $occ->mod_occ_year_code,
                'periodslotcode' => $occ->mod_occ_psl_code,
            ];

            // Get component grades for all potential assessment types.
            $records = $DB->get_records_sql($sql, $params);

            if (!empty($records)) {
                foreach ($records as $record) {
                    $record->unavailablereasons = '';
                    // Check if the component grade is valid for mapping.
                    [$valid, $unavailablereasons] = $this->is_component_grade_valid_for_mapping($record);
                    $record->available = $valid;
                    if (!$valid) {
                        $record->unavailablereasons = implode('<br>', $unavailablereasons);
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
     * Check if the component grade is valid for mapping.
     *
     * @param \stdClass $componentgrade
     * @return array
     * @throws \dml_exception|\coding_exception
     */
    public function is_component_grade_valid_for_mapping(\stdClass $componentgrade): array {
        // Get settings.
        $assessmenttypecodes = self::get_moodle_ast_codes();
        $assessmenttypecodeswithexamcode = self::get_moodle_ast_codes_work_with_exam_room_code();
        $examroomcode = get_config('local_sitsgradepush', 'moodle_exam_room_code');

        $valid = true;
        $unavailablereasons = [];

        // Check marking scheme.
        if (!$this->is_marking_scheme_supported($componentgrade)) {
            $valid = false;
            $unavailablereasons[] = get_string('error:mks_scheme_not_supported', 'local_sitsgradepush');
        }

        // Check assessment type codes.
        if (!empty($assessmenttypecodes) && !in_array($componentgrade->astcode, $assessmenttypecodes)) {
            $valid = false;
            $unavailablereasons[] = get_string('error:ast_code_not_supported', 'local_sitsgradepush', $componentgrade->astcode);
        }

        // Check assessment type codes that works with exam room code.
        if (!empty($assessmenttypecodeswithexamcode) && !empty($examroomcode)) {
            if (in_array($componentgrade->astcode, $assessmenttypecodeswithexamcode) &&
                $componentgrade->examroomcode != $examroomcode) {
                $valid = false;
                $unavailablereasons[] = get_string('error:ast_code_exam_room_code_not_matched', 'local_sitsgradepush');
            }
        }

        return [$valid, $unavailablereasons];
    }

    /**
     * Save component grades from SITS to database.
     *
     * @param array $componentgrades
     * @return void
     * @throws \coding_exception
     * @throws \dml_exception
     */
    public function save_component_grades(array $componentgrades): void {
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
                    $record->mkscode = $componentgrade['MKS_CODE'];
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
            if (!empty($recordsinsert)) {
                $DB->insert_records(self::TABLE_COMPONENT_GRADE, $recordsinsert);
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
        $assessment = $this->validate_component_grade(
          $data->componentgradeid,
          $data->sourcetype,
          $data->sourceid,
          $data->reassessment
        );

        if ($existingmapping = $this->is_component_grade_mapped($data->componentgradeid, $data->reassessment)) {
            // Checked in the above validation, the current mapping to this component grade
            // can be deleted as it does not have push records nor mapped to the current activity.
            $DB->delete_records(self::TABLE_ASSESSMENT_MAPPING, ['id' => $existingmapping->id]);
            assesstype::update_assess_type($existingmapping, assesstype::ACTION_UNLOCK);
        }

        // Insert new mapping.
        $record = new \stdClass();
        $record->courseid = $assessment->get_course_id();
        $record->sourcetype = $assessment->get_type();
        $record->sourceid = $assessment->get_id();
        if ($assessment->get_type() == assessmentfactory::SOURCETYPE_MOD) {
            $record->moduletype = $assessment->get_module_name();
        }
        $record->componentgradeid = $data->componentgradeid;
        $record->reassessment = $data->reassessment;
        $record->enableextension = (extensionmanager::is_extension_enabled() &&
            (isset($record->moduletype) && extension::is_module_supported($record->moduletype))) ? 1 : 0;
        $record->timecreated = time();
        $record->timemodified = time();

        $newmappingid = $DB->insert_record(self::TABLE_ASSESSMENT_MAPPING, $record);

        // Update assessment type of the mapped assessment for the assessment type plugin if it is installed.
        assesstype::update_assess_type($newmappingid, assesstype::ACTION_LOCK);

        return $newmappingid;
    }

    /**
     * Lookup assessment mappings.
     *
     * @param assessment $source
     * @param int|null $componentgradeid
     * @return mixed
     * @throws \dml_exception
     */
    public function get_assessment_mappings(assessment $source, ?int $componentgradeid = null): mixed {
        global $DB;

        $params = [
            'sourceid' => $source->get_id(),
            'sourcetype' => $source->get_type(),
        ];
        $where = '';
        if (!empty($componentgradeid)) {
            $params['componentgradeid'] = $componentgradeid;
            $where = 'AND am.componentgradeid = :componentgradeid';
        }

        $sql = "SELECT am.id, am.courseid, am.sourceid, am.sourcetype, am.moduletype, am.componentgradeid, am.reassessment,
                       am.reassessmentseq, cg.modcode, cg.modocc, cg.academicyear, cg.periodslotcode, cg.mapcode,
                       cg.mabseq, cg.astcode, cg.mabname,
                       CONCAT(cg.modcode, '-', cg.academicyear, '-', cg.periodslotcode, '-', cg.modocc, '-',
                       cg.mabseq, ' ', cg.mabname) AS formattedname
                FROM {" . self::TABLE_ASSESSMENT_MAPPING . "} am
                JOIN {" . self::TABLE_COMPONENT_GRADE . "} cg ON am.componentgradeid = cg.id
                WHERE am.sourceid = :sourceid AND am.sourcetype = :sourcetype $where";

        return ($componentgradeid) ? $DB->get_record_sql($sql, $params) : $DB->get_records_sql($sql, $params);
    }

    /**
     * Check if the component grade is mapped to an activity for a marks transfer type.
     * e.g. Re-assessment or normal assessment.
     *
     * @param int $id
     * @param int $reassess
     * @return mixed
     * @throws \dml_exception
     */
    public function is_component_grade_mapped(int $id, int $reassess): mixed {
        global $DB;
        return $DB->get_record(self::TABLE_ASSESSMENT_MAPPING, ['componentgradeid' => $id, 'reassessment' => $reassess]);
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
     * Get local component grade by id.
     *
     * @param int $id
     * @return false|mixed|\stdClass
     * @throws \dml_exception
     */
    public function get_local_component_grade_by_id(int $id): mixed {
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
        // Get user.
        $user = user_get_users_by_id([$userid]);

        // Get students for the component grade.
        $students = $this->get_students_from_sits($componentgrade);
        foreach ($students as $student) {
            if ($student['code'] == $user[$userid]->idnumber) {
                return $student;
            }
        }

        return null;
    }

    /**
     * Get students for a grade component from SITS.
     *
     * @param \stdClass $componentgrade
     * @param bool $refresh Refresh data from SITS.
     * @return \cache_application|\cache_session|\cache_store|mixed
     * @throws \coding_exception
     * @throws \dml_exception
     * @throws \moodle_exception
     */
    public function get_students_from_sits(\stdClass $componentgrade, bool $refresh = false): mixed {
        // Stutalk Direct is not supported currently.
        if ($this->apiclient->get_client_name() == 'Stutalk Direct') {
            throw new \moodle_exception(
              'error:multiplemappingsnotsupported',
              'local_sitsgradepush',
              '',
              $this->apiclient->get_client_name()
            );
        }

        // For behat test.
        if (defined('BEHAT_SITE_RUNNING')) {
            return tests_data_provider::get_behat_test_students_response($componentgrade->mapcode, $componentgrade->mabseq);
        }

        $key = implode('_', [cachemanager::CACHE_AREA_STUDENTSPR, $componentgrade->mapcode, $componentgrade->mabseq]);
        if ($refresh) {
            // Clear cache.
            cachemanager::purge_cache(cachemanager::CACHE_AREA_STUDENTSPR, $key);
        } else {
            // Try to get cache first.
            $students = cachemanager::get_cache(cachemanager::CACHE_AREA_STUDENTSPR, $key);
            if (!empty($students)) {
                return $students;
            }
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
                DAYSECS * 30
            );
        }

        return $result;
    }

    /**
     * Push grade to SITS.
     *
     * @param \stdClass $assessmentmapping
     * @param int $userid
     * @param \stdClass|null $task
     * @return bool
     * @throws \dml_exception
     * @throws \moodle_exception
     */
    public function push_grade_to_sits(\stdClass $assessmentmapping, int $userid, ?\stdClass $task = null): bool {
        try {
            // Get task id.
            $taskid = (!empty($task)) ? $task->id : null;

            // Check if last push was succeeded, exit if succeeded.
            if ($this->last_push_succeeded($assessmentmapping->id, $userid, self::PUSH_GRADE)) {
                return false;
            }

            // Get required data for pushing.
            $data = $this->get_required_data_for_pushing($assessmentmapping, $userid);

            // Get grade.
            [$rawmarks, $equivalentgrade] = $assessmentmapping->source->get_user_grade($userid);

            // Transfer marks through task, check task options.
            if ($task && !empty($task->options)) {
                $options = json_decode($task->options);
                // Records non-submission.
                if ($options->recordnonsubmission) {
                    // Get submission if the source is of type MOD, no submission for other types
                    // such as manual grade and category.
                    if ($assessmentmapping->source->get_type() == assessmentfactory::SOURCETYPE_MOD) {
                        $submission = submissionfactory::get_submission($assessmentmapping->source->get_coursemodule_id(), $userid);
                    }

                    // If no submission and no marks found, set rawmarks to 0 and equivalent grade to absent.
                    if (empty($rawmarks) && (!isset($submission) || !$submission->get_submission_data())) {
                        $rawmarks = 0;
                        $equivalentgrade = assessment::GRADE_ABSENT;
                    }
                }
            }

            // Push if grade is found.
            if (is_numeric($rawmarks) && $rawmarks >= 0) {
                $data->marks = $rawmarks;
                $data->grade = $equivalentgrade ?? '';

                $request = $this->apiclient->build_request(self::PUSH_GRADE, $data);
                if (defined('BEHAT_SITE_RUNNING')) {
                    $response = tests_data_provider::get_behat_push_grade_response();
                } else {
                    $response = $this->apiclient->send_request($request);
                }

                // Check response.
                $this->check_response($response, $request);

                // Save transfer log.
                $this->save_transfer_log(self::PUSH_GRADE, $assessmentmapping->id, $userid, $request, $response, $taskid);

                return true;
            }
        } catch (\moodle_exception $e) {
            $this->mark_push_as_failed(self::PUSH_GRADE, $assessmentmapping->id, $userid, $taskid, $e);
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

            // No submission log push for non moodle activities.
            if ($assessmentmapping->source->get_type() != assessmentfactory::SOURCETYPE_MOD) {
                return false;
            }

            // Check if last push was succeeded, exit if succeeded.
            if ($this->last_push_succeeded($assessmentmapping->id, $userid, self::PUSH_SUBMISSION_LOG)) {
                return false;
            }

            // Get required data for pushing.
            $data = $this->get_required_data_for_pushing($assessmentmapping, $userid);

            // Create the submission object.
            $submission = submissionfactory::get_submission($assessmentmapping->source->get_coursemodule_id(), $userid);

            // Push if student has submission.
            if ($submission->get_submission_data()) {
                $request = $this->apiclient->build_request(self::PUSH_SUBMISSION_LOG, $data, $submission);
                if (defined('BEHAT_SITE_RUNNING')) {
                    $response = tests_data_provider::get_behat_submission_log_response();
                } else {
                    $response = $this->apiclient->send_request($request);
                }

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
    public function get_required_data_for_pushing(\stdClass $assessmentmapping, int $userid): \stdClass {
        global $USER;

        // Get student information from SITS.
        $student = $this->get_student_from_sits($assessmentmapping, $userid);

        if (empty($student)) {
            throw new \moodle_exception('error:nostudentfoundformapping', 'local_sitsgradepush');
        }

        // Check resit number is not equal to 0 if it is a reassessment.
        if ($assessmentmapping->reassessment == 1 && $student['assessment']['resit_number'] == 0) {
            throw new \moodle_exception('error:resit_number_zero_for_reassessment', 'local_sitsgradepush');
        }

        // Build the required data.
        $data = new \stdClass();
        $data->mapcode = $assessmentmapping->mapcode;
        $data->mabseq = $assessmentmapping->mabseq;
        $data->sprcode = $student['spr_code'];
        $data->academicyear = $assessmentmapping->academicyear;
        $data->pslcode = $assessmentmapping->periodslotcode;
        $data->reassessment = $assessmentmapping->reassessment;
        $data->source = sprintf(
            'moodle-course%s-%s%s-user%s',
            $assessmentmapping->courseid,
            $assessmentmapping->sourcetype,
            $assessmentmapping->sourceid,
            $USER->id
        );
        $data->srarseq = $student['assessment']['resit_number'];

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
    public function get_transfer_logs(int $assessmentmappingid, int $userid, ?string $type = null): array {
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
                    GROUP BY type, assessmentmappingid) t2
                ON t1.type = t2.type AND t1.timecreated = t2.latest_time AND t1.assessmentmappingid = t2.assessmentmappingid
                LEFT JOIN {" . self::TABLE_ERROR_LOG . "} errlog ON t1.errlogid = errlog.id
                WHERE t1.userid = :userid2 {$bytype}";

        return $DB->get_records_sql($sql, $params);
    }

    /**
     * Get component grade details for a given assessment mapping ID.
     *
     * @param int $id Assessment mapping ID.
     *
     * @return false|mixed
     * @throws \dml_exception|\coding_exception
     */
    public function get_mab_and_map_info_by_mapping_id(int $id): mixed {
        global $DB;

        // Try to get the cache first.
        $key = 'map_mab_info_' . $id;
        $cache = cachemanager::get_cache(cachemanager::CACHE_AREA_MAPPING_MAB_INFO, $key);
        if (!empty($cache)) {
            return $cache;
        }

        // Define the SQL query for retrieving the information.
        $sql = "SELECT
                    am.id,
                    am.courseid,
                    am.sourceid,
                    am.sourcetype,
                    am.moduletype,
                    am.reassessment,
                    am.enableextension,
                    cg.id as mabid,
                    cg.mapcode,
                    cg.mabseq
                FROM {" . self::TABLE_COMPONENT_GRADE . "} cg
                INNER JOIN {" . self::TABLE_ASSESSMENT_MAPPING . "} am
                    ON cg.id = am.componentgradeid
                WHERE am.id = :id";

        // Fetch the record from the database.
        $mapmabinfo = $DB->get_record_sql($sql, ['id' => $id]);
        if (!empty($mapmabinfo)) {
            // Set the cache.
            cachemanager::set_cache(
                cachemanager::CACHE_AREA_MAPPING_MAB_INFO,
                $key,
                $mapmabinfo,
                DAYSECS * 30
            );
        }
        return $mapmabinfo;
    }

    /**
     * Get the assessment data.
     *
     * @param string $sourcetype
     * @param int $sourceid
     * @param int|null $assessmentmappingid
     * @return array|\stdClass
     * @throws \coding_exception
     * @throws \dml_exception
     * @throws \moodle_exception
     */
    public function get_assessment_data(string $sourcetype, int $sourceid, ?int $assessmentmappingid = null): array|\stdClass {
        // Get the assessment.
        $assessment = assessmentfactory::get_assessment($sourcetype, $sourceid);

        $assessmentdata = [];
        $assessmentdata['studentsnotrecognized'] = [];

        try {
            $students = $assessment->get_all_participants();
        } catch (\moodle_exception $e) {
            $students = [];
        }

        // Get assessment mappings.
        $mappings = $this->get_assessment_mappings($assessment);
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
            $studentsfromsits[$mabkey] = array_column($this->get_students_from_sits($mapping), null, 'code');

            // Add additional properties to the $mapping object.
            $mapping->markscount = 0;
            $mapping->nonsubmittedcount = 0;
            $mapping->source = $assessment;
            $mapping->students = [];

            // Students here is all the participants in that assessment.
            foreach ($students as $key => $student) {
                $studentrecord = new pushrecord($student, $assessment, $mapping);
                // Add participants who have push records of this mapping or
                // are in the studentsfromsits array and valid to the mapping type, e.g. main or re-assessment.
                $validrecord = $studentrecord->check_record_from_sits($mapping, $studentsfromsits[$mabkey]);
                if ($studentrecord->componentgrade == $mabkey || $validrecord) {
                    $mapping->students[] = $studentrecord;
                    if ($studentrecord->should_transfer_mark()) {
                        $mapping->markscount++;
                    }
                    if ($studentrecord->is_non_submitted()) {
                        $mapping->nonsubmittedcount++;
                    }
                    unset($students[$key]);
                }
            }

            $assessmentdata['mappings'][$mabkey] = $mapping;
        }

        // Remaining students are not valid for pushing.
        $invalidstudents = new \stdClass();
        $invalidstudents->formattedname = get_string('invalidstudents', 'local_sitsgradepush');
        $invalidstudents->students = [];
        foreach ($students as $student) {
            $invalidstudents->students[] = new pushrecord($student, $assessment);
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
     * @return array
     */
    private function sort_grade_push_history_table(array $data) {
        // Initialize an array to hold different types of records.
        $tempdataarray = [
            'transfererror' => [],
            'updatedaftertransfer' => [],
            'sublogerror' => [],
            'other' => [],
            'notyetpushed' => [],
        ];

        // Organize records into different arrays based on their types.
        foreach ($data as $record) {
            switch (true) {
                case $record->lastgradepushresult == 'failed':
                    // Mark transfer failed.
                    $tempdataarray['transfererror'][] = $record;
                    break;
                case $record->marksupdatedaftertransfer:
                    // Marks updated after transfer.
                    $tempdataarray['updatedaftertransfer'][] = $record;
                    break;
                case $record->lastsublogpushresult == 'failed':
                    // Submission log push failed.
                    $tempdataarray['sublogerror'][] = $record;
                    break;
                case !$record->isgradepushed && !$record->issublogpushed:
                    // Not yet pushed.
                    $tempdataarray['notyetpushed'][] = $record;
                    break;
                default:
                    // All other records, including successful transfers and submission log pushes.
                    $tempdataarray['other'][] = $record;
            }
        }

        // Sort errors by error types.
        foreach (['transfererror', 'sublogerror'] as $errortype) {
            if (!empty($tempdataarray[$errortype])) {
                usort($tempdataarray[$errortype], function ($a, $b) use ($errortype) {
                    if ($errortype === 'transfererror') {
                        return $b->lastgradepusherrortype <=> $a->lastgradepusherrortype;
                    } else {
                        return $b->lastsublogpusherrortype <=> $a->lastsublogpusherrortype;
                    }
                });
            }
        }

        // Merge all arrays and return the sorted result.
        return array_merge(
            ...array_values($tempdataarray)
        );
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
     * Validate assessment mapping request from select existing activity page.
     *
     * @param int $componentgradeid
     * @param string $sourcetype
     * @param int $sourceid
     * @param int $reassess
     * @return assessment
     * @throws \dml_exception
     * @throws \moodle_exception
     */
    public function validate_component_grade(int $componentgradeid, string $sourcetype, int $sourceid, int $reassess): assessment {
        // Check if the component grade exists.
        if (!$componentgrade = $this->get_local_component_grade_by_id($componentgradeid)) {
            throw new \moodle_exception('error:mab_not_found', 'local_sitsgradepush', '', $componentgradeid);
        }

        // Prevent new mapping for gradebook items and categories if the gradebook feature is disabled.
        $gradebookenabled = get_config('local_sitsgradepush', 'gradebook_enabled');
        if (!$gradebookenabled && ($sourcetype == assessmentfactory::SOURCETYPE_GRADE_ITEM ||
                $sourcetype == assessmentfactory::SOURCETYPE_GRADE_CATEGORY)) {
            throw new \moodle_exception('error:gradebook_disabled', 'local_sitsgradepush');
        }

        // Get the assessment.
        $assessment = assessmentfactory::get_assessment($sourcetype, $sourceid);

        $gradeitems = $assessment->get_grade_items();

        // Grade items not found.
        if (empty($gradeitems)) {
            throw new \moodle_exception('error:grade_items_not_found', 'local_sitsgradepush');
        }

        // Check the grade type of the course module is supported.
        foreach ($gradeitems as $gradeitem) {
            if ($gradeitem->gradetype != GRADE_TYPE_VALUE) {
                throw new \moodle_exception('error:gradetype_not_supported', 'local_sitsgradepush');
            }
        }

        // Do not allow mapping activity which is not from current academic year and not a re-assessment.
        if (!$this->is_current_academic_year_activity($assessment->get_course_id()) && $reassess == 0) {
            throw new \moodle_exception('error:pastactivity', 'local_sitsgradepush');
        }

        // A component grade can be mapped twice, one for reassessment and one for normal assessment.
        if (!empty($mapping = $this->is_component_grade_mapped($componentgradeid, $reassess))) {
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
            if ($mapping->sourcetype == $sourcetype && $mapping->sourceid == $sourceid) {
                throw new \moodle_exception('error:no_update_for_same_mapping', 'local_sitsgradepush');
            }
        }

        // Get existing mappings for this activity.
        if ($existingmappings = $this->get_assessment_mappings($assessment)) {
            // Make sure it does not map to another component grade with same map code.
            foreach ($existingmappings as $existingmapping) {
                if ($existingmapping->mapcode == $componentgrade->mapcode) {
                    throw new \moodle_exception('error:same_map_code_for_same_activity', 'local_sitsgradepush');
                }
            }
        }

        return $assessment;
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
        foreach (self::allowed_activities() as $modname) {
            if (!empty($results = get_coursemodules_in_course($modname, $courseid))) {
                foreach ($results as $result) {
                    $activities[] = assessmentfactory::get_assessment(assessmentfactory::SOURCETYPE_MOD, $result->id);
                }
            }
        }

        return $activities;
    }

    /**
     * Get data required for page update, e.g. progress bars, last transfer time.
     *
     * @param int $courseid
     * @param string $sourcetype
     * @param int $sourceid
     * @return array
     * @throws \coding_exception
     * @throws \dml_exception
     * @throws \moodle_exception
     */
    public function get_data_for_page_update(int $courseid, string $sourcetype = '', int $sourceid = 0): array {
        global $DB;
        $results = [];

        $conditions = [
            'courseid' => $courseid,
        ];

        if ($sourcetype && $sourceid) {
            $conditions['sourcetype'] = $sourcetype;
            $conditions['sourceid'] = $sourceid;
        }

        // Get assessment mappings.
        $mappings = $DB->get_records(self::TABLE_ASSESSMENT_MAPPING, $conditions);
        if (empty($mappings)) {
            return [];
        }

        foreach ($mappings as $mapping) {
            // Get assessment data.
            $assessmentdata = $this->get_assessment_data($mapping->sourcetype, $mapping->sourceid, $mapping->id);

            // Check if there is a pending / running task for this mapping.
            $task = taskmanager::get_pending_task_in_queue($mapping->id);
            $result = new \stdClass();
            $result->assessmentmappingid = $mapping->id;
            $result->courseid = $courseid;
            $result->sourcetype = $sourcetype;
            $result->sourceid = $sourceid;
            $result->markscount = $assessmentdata->markscount;
            $result->nonsubmittedcount = $assessmentdata->nonsubmittedcount;
            $result->task = !empty($task) ? $task : null;
            $result->lasttransfertime = taskmanager::get_last_push_task_time($mapping->id);
            $results[] = $result;
        }

        return $results;
    }

    /**
     * Can the source be changed for a component grade.
     *
     * @param int $componentgradeid
     * @param int $reassess
     * @return bool
     * @throws \coding_exception
     * @throws \dml_exception
     */
    public function can_change_source(int $componentgradeid, int $reassess): bool {
        if ($assessementmapping = $this->is_component_grade_mapped($componentgradeid, $reassess)) {
            return !taskmanager::get_pending_task_in_queue($assessementmapping->id) &&
                !$this->has_grades_pushed($assessementmapping->id);
        } else {
            return true;
        }
    }

    /**
     * Get formatted marks.
     *
     * @param int $courseid
     * @param float $marks
     * @return string
     */
    public function get_formatted_marks(int $courseid, float $marks): string {
        // Get course grade decimal places setting.
        $decimalplaces = grade_get_setting($courseid, 'decimalpoints', 2);
        return number_format($marks, $decimalplaces, '.', '');
    }

    /**
     * Get gradebook assessments, i.e. grade items and grade categories.
     *
     * @param int $courseid
     * @return array
     * @throws \dml_exception
     * @throws \moodle_exception
     */
    public function get_gradebook_assessments(int $courseid): array {
        global $DB;

        [$insql, $params] = $DB->get_in_or_equal(['category', 'manual'], SQL_PARAMS_NAMED);
        $params = array_merge(
            $params,
            [
                'courseid' => $courseid,
                'gradetype' => GRADE_TYPE_VALUE,
                'grademin' => 0,
                'grademax' => 100,
            ]
        );

        // Get all grade items for the course.
        $sql = "SELECT *
                FROM {grade_items} gi
                WHERE gi.courseid = :courseid AND gi.gradetype = :gradetype
                    AND gi.grademin = :grademin AND gi.grademax = :grademax
                    AND gi.itemtype $insql";

        $gradeitems = $DB->get_records_sql($sql, $params);

        $assessments = [];
        if (!empty($gradeitems)) {
            foreach ($gradeitems as $gradeitem) {
                switch ($gradeitem->itemtype) {
                    case 'category':
                        $assessments[] = assessmentfactory::get_assessment(
                            assessmentfactory::SOURCETYPE_GRADE_CATEGORY,
                            $gradeitem->iteminstance
                        );
                        break;
                    case 'manual':
                        $assessments[] =
                            assessmentfactory::get_assessment(
                                assessmentfactory::SOURCETYPE_GRADE_ITEM,
                                $gradeitem->id
                            );
                        break;
                    default:
                        break;
                }
            }
        }

        return $assessments;
    }

    /**
     * Get all summative grade items, i.e. grade items of mapped assessments.
     *
     * @param int $courseid
     *
     * @return array
     * @throws \dml_exception|\moodle_exception
     */
    public function get_all_summative_grade_items(int $courseid): array {
        global $DB;

        // Get all mapped assessments.
        $mappings = $DB->get_records(self::TABLE_ASSESSMENT_MAPPING, ['courseid' => $courseid]);

        // No mapping found for the course.
        if (empty($mappings)) {
            return [];
        }

        // Get all summative grade items.
        $results = [];
        $requiredfields = ['id', 'categoryid', 'itemname', 'itemtype', 'itemmodule', 'iteminstance', 'itemnumber', 'gradetype'];
        foreach ($mappings as $mapping) {
            $assessment = assessmentfactory::get_assessment($mapping->sourcetype, $mapping->sourceid);
            $gradeitems = $assessment->get_grade_items();

            // No grade items found for the assessment.
            if (empty($gradeitems)) {
                continue;
            }

            // Only return required fields.
            foreach ($gradeitems as $gradeitem) {
                $result = new \stdClass();
                foreach ($requiredfields as $field) {
                    $result->$field = $gradeitem->$field;
                }
                $results[] = $result;
            }
        }

        return $results;
    }

    /**
     * Get assessment mappings by course id.
     *
     * @param int $courseid
     * @param bool $extensionenabledonly
     * @return array
     * @throws \dml_exception
     */
    public function get_assessment_mappings_by_courseid(int $courseid, bool $extensionenabledonly = false): array {
        global $DB;

        if ($extensionenabledonly) {
            // Get mappings that are enabled for extension only.
            $extensionenabledonlysql = 'AND am.enableextension = 1';
        } else {
            $extensionenabledonlysql = '';
        }
        $sql = "SELECT am.*, cg.mapcode, cg.mabseq
                FROM {".self::TABLE_ASSESSMENT_MAPPING."} am
                JOIN {".self::TABLE_COMPONENT_GRADE."} cg ON am.componentgradeid = cg.id
                WHERE courseid = :courseid $extensionenabledonlysql";

        return $DB->get_records_sql($sql, ['courseid' => $courseid]);
    }

    /**
     * Delete assessment mapping.
     *
     * @param int $courseid
     * @param int $mappingid
     * @return void
     * @throws \dml_exception
     * @throws \moodle_exception
     */
    public function remove_mapping(int $courseid, int $mappingid): void {
        global $DB;

        // Check permission. Assume the user who has permission to map assessment is allowed to remove mapping too.
        if (!has_capability('local/sitsgradepush:mapassessment', context_course::instance($courseid))) {
            throw new \moodle_exception('error:remove_mapping', 'local_sitsgradepush');
        }

        // Check the mapping exists.
        if (!$DB->record_exists(self::TABLE_ASSESSMENT_MAPPING, ['id' => $mappingid])) {
            throw new \moodle_exception('error:assessmentmapping', 'local_sitsgradepush', '', $mappingid);
        }

        // Remove mapping is not allowed if grades have been pushed.
        if ($this->has_grades_pushed($mappingid)) {
            throw new \moodle_exception('error:mab_has_push_records', 'local_sitsgradepush', '', 'Mapping ID: ' . $mappingid);
        }

        // Everything is fine, remove the mapping.
        $DB->delete_records(self::TABLE_ASSESSMENT_MAPPING, ['id' => $mappingid]);
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
        string $type, int $assessmentmappingid, int $userid, mixed $request, array $response, ?int $taskid, ?int $errorlogid = null
    ): void {
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

    /**
     * Get a list of the allowed course modules for grade push.
     * @return string[] e.g. ['assign', 'quiz', 'turnitintooltwo']
     */
    public static function allowed_activities(): array {
        $mods = \core\plugininfo\mod::get_enabled_plugins();
        return array_values(array_filter($mods, function($mod): bool {
            return class_exists("\\local_sitsgradepush\\assessment\\$mod");
        }));
    }
}
