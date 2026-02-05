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

namespace local_sitsgradepush\extension;

use core\clock;
use core\di;
use local_sitsgradepush\manager;

/**
 * Parent class for extension. For example, EC and SORA.
 *
 * @package    local_sitsgradepush
 * @copyright  2024 onwards University College London {@link https://www.ucl.ac.uk/}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @author     Alex Yeung <k.yeung@ucl.ac.uk>
 */
abstract class extension implements iextension {
    /** @var array Supported module types */
    const SUPPORTED_MODULE_TYPES = ['assign', 'quiz', 'coursework', 'lesson'];

    /** @var string AWS datasource */
    const DATASOURCE_AWS = 'aws';

    /** @var string API datasource */
    const DATASOURCE_API = 'api';

    /** @var int User ID */
    protected int $userid;

    /** @var string Student code / ID number */
    protected string $studentcode = '';

    /** @var bool Used to check if the extension data is set. */
    protected bool $dataisset = false;

    /** @var string Datasource */
    protected string $datasource;

    /** @var clock Clock instance. */
    protected readonly clock $clock;

    /** @var array|null extension changes */
    protected ?array $extensionchanges = null;

    /** @var \stdClass|null event data */
    protected ?\stdClass $eventdata;

    /**
     * Set properties from JSON message like SORA / EC update message from AWS.
     *
     * @param string $messagebody
     * @return void
     */
    abstract public function set_properties_from_aws_message(string $messagebody): void;

    /**
     * Constructor.
     */
    public function __construct() {
        $this->clock = di::get(clock::class);
    }

    /**
     * Get the user ID.
     *
     * @return int
     */
    public function get_userid(): int {
        return $this->userid;
    }

    /**
     * Get the student code.
     *
     * @return string
     */
    public function get_student_code(): string {
        return $this->studentcode;
    }

    /**
     * Check if the module type is supported.
     *
     * @param string|null $module
     * @return bool
     */
    public static function is_module_supported(?string $module): bool {
        if (empty($module)) {
            return false;
        }
        return in_array($module, self::SUPPORTED_MODULE_TYPES);
    }

    /**
     * Get all the assessment mappings by MAB identifier.
     *
     * @param string $mabidentifier
     * @return array
     * @throws \coding_exception
     * @throws \dml_exception
     * @throws \moodle_exception
     */
    public function get_mappings_by_mab(string $mabidentifier): array {
        global $DB;

        // Extract the map code and MAB sequence number from the MAB identifier.
        $mapcode = explode('-', $mabidentifier)[0];
        $mabseq = explode('-', $mabidentifier)[1];

        $params = [
            'mapcode' => $mapcode,
            'mabseq' => $mabseq,
        ];

        // Currently only support assign and quiz.
        [$insql, $inparams] = $DB->get_in_or_equal(self::SUPPORTED_MODULE_TYPES, SQL_PARAMS_NAMED);
        $params = array_merge($params, $inparams);

        $sql = "SELECT am.*
                FROM {" . manager::TABLE_ASSESSMENT_MAPPING . "} am
                JOIN {" . manager::TABLE_COMPONENT_GRADE . "} mab ON am.componentgradeid = mab.id
                WHERE mab.mapcode = :mapcode AND mab.mabseq = :mabseq AND am.moduletype $insql
                AND am.enableextension = 1";

        return $DB->get_records_sql($sql, $params);
    }

    /**
     * Get all the assessment mappings by user ID.
     *
     * @param int $userid
     * @param string|null $astcode
     * @return array
     * @throws \dml_exception|\coding_exception
     */
    public function get_mappings_by_userid(int $userid, ?string $astcode = ''): array {
        global $DB;

        // Find all enrolled courses for the student.
        $courses = enrol_get_users_courses($userid);
        $courseids = array_map(fn($course) => $course->id, $courses);

        // Student is not enrolled in any courses.
        if (empty($courseids)) {
            return [];
        }

        // Get current academic year course IDs.
        $currentyearcourses = array_map(
            fn($course) => $course->id,
            array_filter($courses, fn($course) => manager::get_manager()->is_current_academic_year_activity($course->id))
        );

        [$courseinsql, $courseinparam] = $DB->get_in_or_equal($courseids, SQL_PARAMS_NAMED);

        // Currently only support assign and quiz.
        [$modinsql, $modinparams] = $DB->get_in_or_equal(self::SUPPORTED_MODULE_TYPES, SQL_PARAMS_NAMED);
        $params = array_merge($courseinparam, $modinparams);

        // Find all mapped moodle assessments for the student.
        $sql = "SELECT am.*, mab.mapcode, mab.mabseq, mab.astcode
                FROM {" . manager::TABLE_ASSESSMENT_MAPPING . "} am
                JOIN {" . manager::TABLE_COMPONENT_GRADE . "} mab ON am.componentgradeid = mab.id
                WHERE am.courseid $courseinsql AND am.moduletype $modinsql AND am.enableextension = 1";

        // Filter by assessment code if provided.
        if (!empty($astcode)) {
            $sql .= " AND mab.astcode = :astcode";
            $params['astcode'] = $astcode;
        }

        $mappings = $DB->get_records_sql($sql, $params);

        // Filter mappings to keep only those in the current academic year or reassessments.
        return array_filter(
            $mappings,
            fn($mapping) => in_array($mapping->courseid, $currentyearcourses) || $mapping->reassessment == 1
        );
    }

    /**
     * Set properties from get students API.
     *
     * @param array $student
     * @return void
     */
    public function set_properties_from_get_students_api(array $student): void {
        // Set the student code.
        $this->studentcode = $student['association']['supplementary']['student_code'] ?? '';

        // Set the user ID.
        if (!isset($this->userid)) {
            $this->set_userid($this->studentcode);
        }
    }

    /**
     * Get the extension changes.
     *
     * @return array|null
     */
    public function get_extension_changes(): ?array {
        return $this->extensionchanges;
    }

    /**
     * Get the data source.
     * To distinguish where the extension data come from, such as AWS or API.
     *
     * @return string
     */
    public function get_data_source(): string {
        return $this->datasource;
    }

    /**
     * Get the event data.
     *
     * @return \stdClass|null
     */
    public function get_event_data(): ?\stdClass {
        return $this->eventdata;
    }

    /**
     * Parse the message JSON.
     *
     * @param string $message
     * @return \stdClass
     * @throws \Exception
     */
    protected function parse_event_json(string $message): \stdClass {
        $messageobject = json_decode($message);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \Exception(get_string('error:invalid_json_data', 'local_sitsgradepush', json_last_error_msg()));
        }
        if (empty($messageobject)) {
            throw new \Exception(get_string('error:empty_json_data', 'local_sitsgradepush'));
        }
        return $messageobject;
    }

    /**
     * Set the user ID of the student.
     *
     * @param string $studentcode
     * @return void
     * @throws \dml_exception
     */
    protected function set_userid(string $studentcode): void {
        global $DB;

        if (empty($studentcode)) {
            $this->userid = 0;
            return;
        }

        // Find and set the user ID of the student.
        $user = $DB->get_record('user', ['idnumber' => $studentcode], 'id');
        $this->userid = $user ? $user->id : 0;
    }

    /**
     * Pre-process extension checks.
     *
     * @param array $mappings
     * @return bool true if the checks pass, false otherwise
     * @throws \coding_exception if extension data is not set
     */
    protected function pre_process_extension_checks(array $mappings): bool {
        // Exit if empty mappings.
        if (empty($mappings)) {
            return false;
        }

        if (!$this->userid) {
            return false;
        }

        if (!$this->dataisset) {
            throw new \coding_exception('error:extensiondataisnotset', 'local_sitsgradepush');
        }

        return true;
    }
}
