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
    const SUPPORTED_MODULE_TYPES = ['assign', 'quiz'];

    /** @var \stdClass Message from AWS */
    protected \stdClass $message;

    /** @var int User ID */
    protected int $userid;

    /**
     * Constructor.
     *
     * @param string $message
     * @throws \Exception
     */
    public function __construct(string $message) {
        $this->message = $this->parse_event_json($message);
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
     * Get the MAB identifier.
     *
     * @return string
     */
    public function get_mab_identifier(): string {
        return $this->mabidentifier;
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
    protected function get_mappings_by_mab(string $mabidentifier): array {
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
                FROM {". manager::TABLE_ASSESSMENT_MAPPING ."} am
                JOIN {". manager::TABLE_COMPONENT_GRADE ."} mab ON am.componentgradeid = mab.id
                WHERE mab.mapcode = :mapcode AND mab.mabseq = :mabseq AND am.moduletype $insql
                AND am.enableextension = 1";

        return $DB->get_records_sql($sql, $params);
    }

    /**
     * Get all the assessment mappings by user ID.
     *
     * @param int $userid
     * @return array
     * @throws \dml_exception|\coding_exception
     */
    protected function get_mappings_by_userid(int $userid): array {
        global $DB;

        // Find all enrolled courses for the student.
        $courses = enrol_get_users_courses($userid, true);

        // Get courses that are in the current academic year.
        $courses = array_filter($courses, function($course) {
            return manager::get_manager()->is_current_academic_year_activity($course->id);
        });

        // Extract the course IDs.
        $courseids = array_map(function($course) {
            return $course->id;
        }, $courses);

        // Student is not enrolled in any courses that are in the current academic year.
        if (empty($courseids)) {
            return [];
        }

        [$courseinsql, $courseinparam] = $DB->get_in_or_equal($courseids, SQL_PARAMS_NAMED);

        // Currently only support assign and quiz.
        [$modinsql, $modinparams] = $DB->get_in_or_equal(self::SUPPORTED_MODULE_TYPES, SQL_PARAMS_NAMED);
        $params = array_merge($courseinparam, $modinparams);

        // Find all mapped moodle assessments for the student.
        $sql = "SELECT am.*
                FROM {". manager::TABLE_ASSESSMENT_MAPPING ."} am
                JOIN {". manager::TABLE_COMPONENT_GRADE ."} mab ON am.componentgradeid = mab.id
                WHERE am.courseid $courseinsql AND am.moduletype $modinsql AND am.enableextension = 1";

        return $DB->get_records_sql($sql, $params);
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
        if (empty($messageobject)) {
            throw new \Exception('Invalid message data');
        }
        return $messageobject;
    }
}
