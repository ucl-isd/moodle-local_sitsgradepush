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

    /** @var string DB table for storing component grades from SITS */
    const TABLE_COMPONENT_GRADE = 'local_sitsgradepush_mab';

    /** @var string DB table for storing assessment mappings */
    const TABLE_ASSESSMENT_MAPPING = 'local_sitsgradepush_mapping';

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
            $apiclient = client_factory::getapiclient($clientname);
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
    public static function getmanager() {
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
    public function fetchcomponentgradesfromsits(array $modocc): bool {
        try {
            if (!empty($modocc)) {
                // Get component grades from SITS.
                foreach ($modocc as $occ) {
                    $request = $this->apiclient->buildrequest(self::GET_COMPONENT_GRADE, $occ);
                    $response = $this->apiclient->sendrequest($request);
                    // Save component grades to DB.
                    $this->savecomponentgrades($response);
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
    public function getcomponentgradeoptions(int $courseid): array {
        $options = [];
        // Get module occurrences from portico enrolments block.
        $modocc = \block_portico_enrolments\manager::get_modocc_mappings($courseid);

        // Fetch component grades from SITS.
        if ($this->fetchcomponentgradesfromsits($modocc)) {
            // Get the updated records from local component grades table.
            $records = $this->getlocalcomponentgrades($modocc);
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
    public function getlocalcomponentgrades(array $modocc) {
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
    public function savecomponentgrades(array $componentgrades) {
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
    public function saveassessmentmapping(\stdClass $data) {
        global $DB;

        if (!$this->isactivitymapped($data->coursemodule, $data->module)) {
            $record = new \stdClass();
            $record->courseid = $data->course;
            $record->instanceid = $data->coursemodule;
            $record->moduletypeid = $data->module;
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
     * @param int $moduletypeid module type id
     * @return false|mixed|\stdClass
     * @throws \dml_exception
     */
    public function getassessmentmapping(int $cmid, int $moduletypeid) {
        global $DB;
        return $DB->get_record(self::TABLE_ASSESSMENT_MAPPING, ['instanceid' => $cmid, 'moduletypeid' => $moduletypeid]);
    }

    /**
     * Check if the activity is mapped to a component grade.
     *
     * @param int $cmid
     * @param int $moduletypeid
     * @return bool
     * @throws \dml_exception
     */
    public function isactivitymapped(int $cmid, int $moduletypeid) {
        global $DB;
        return $DB->record_exists(self::TABLE_ASSESSMENT_MAPPING, ['instanceid' => $cmid, 'moduletypeid' => $moduletypeid]);
    }

    /**
     * Check if the component grade is mapped to an activity.
     *
     * @param int $id
     * @return bool
     * @throws \dml_exception
     */
    public function iscomponentgrademapped(int $id) {
        global $DB;
        return $DB->record_exists(self::TABLE_ASSESSMENT_MAPPING, ['componentgradeid' => $id]);
    }

    /**
     * Return api errors.
     *
     * @return array
     */
    public function getapierrors() {
        return $this->apierrors;
    }

    /**
     * Return list of api clients available.
     *
     * @return array
     * @throws \moodle_exception
     */
    public function getapiclientlist() {
        $dir = new DirectoryIterator(__DIR__ . '/../apiclients');
        $list = [];
        foreach ($dir as $fileinfo) {
            if ($fileinfo->isDir() && !$fileinfo->isDot()) {
                $client = client_factory::getapiclient($fileinfo->getFilename());
                $list[$fileinfo->getFilename()] = $client->getclientname();
            }
        }

        return $list;
    }

    /**
     * Return allowed activity types for grade push.
     *
     * @return string[]
     */
    public function getallowedactivities() {
        return self::ALLOWED_ACTIVITIES;
    }
}
