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

namespace sitsapiclient_easikit\requests;

/**
 * Class for get students v2 request.
 *
 * @package     sitsapiclient_easikit
 * @copyright   2025 onwards University College London {@link https://www.ucl.ac.uk/}
 * @license     https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @author      Alex Yeung <k.yeung@ucl.ac.uk>
 */
class getstudentsv2 extends request {
    /** @var string[] Fields mapping - Local data fields to SITS' fields */
    const FIELDS_MAPPING = [
        'mapcode' => 'MAP_CODE',
        'mabseq' => 'MAB_SEQ',
    ];

    /** @var string request method */
    const METHOD = 'GET';

    /** @var int limit */
    const LIMIT = 2000;

    /** @var string AAA record type that links with RAA extension ARP records */
    const AAA_TYPE = 'RAPAS';

    /**
     * Constructor.
     *
     * @param \stdClass $data
     * @throws \dml_exception
     * @throws \moodle_exception
     */
    public function __construct(\stdClass $data) {
        // Set request name.
        $this->name = 'Get students v2';

        // Get request endpoint.
        $endpointurl = get_config('sitsapiclient_easikit', 'endpoint_get_student_v2');

        // Check if endpoint is set.
        if (empty($endpointurl)) {
            throw new \moodle_exception('Endpoint URL for ' . $this->name . '  is not set');
        }

        // Set the fields mapping, params fields and data.
        parent::__construct(self::FIELDS_MAPPING, $endpointurl, $data);
    }

    /**
     * Process returned response.
     *
     * @param mixed $response
     * @return array
     */
    public function process_response($response): array {
        global $DB;

        if (empty($response)) {
            // If no response, return empty array.
            return [];
        }

        // Convert response to suitable format.
        $response = json_decode($response, true);

        // No students found.
        if (empty($response['response']['student_collection']['student'])) {
            return [];
        }

        // Collect all student codes for batch query.
        $studentcodes = [];
        foreach ($response['response']['student_collection']['student'] as $student) {
            $studentcode = $student['association']['supplementary']['student_code'] ?? null;
            if ($studentcode !== null) {
                $studentcodes[] = $studentcode;
            }
        }

        // Query Moodle user IDs by student codes in a single query.
        $useridmap = [];
        if (!empty($studentcodes)) {
            [$insql, $params] = $DB->get_in_or_equal($studentcodes, SQL_PARAMS_NAMED);
            $sql = "SELECT idnumber, id FROM {user} WHERE idnumber $insql";
            $users = $DB->get_records_sql($sql, $params);

            foreach ($users as $user) {
                $useridmap[$user->idnumber] = $user->id;
            }
        }

        // Attach academic year and Moodle user ID to each student.
        $students = $response['response']['student_collection']['student'];
        foreach ($students as &$student) {
            if (isset($response['response']['assessment_component']['academic_year']['code'])) {
                $student['association']['supplementary']['academic_year'] =
                    $response['response']['assessment_component']['academic_year']['code'];
            }

            // Add Moodle user ID.
            $studentcode = $student['association']['supplementary']['student_code'] ?? null;
            $student['moodleuserid'] = $useridmap[$studentcode] ?? null;
        }

        return $students;
    }

    /**
     * Get endpoint url with params.
     *
     * @return string
     */
    public function get_endpoint_url_with_params(): string {
        return sprintf(
            '%s/%s-%s/student%s?type=%s&limit=%d',
            $this->endpointurl,
            $this->paramsdata['MAP_CODE'],
            $this->paramsdata['MAB_SEQ'],
            !empty($this->data->studentcode) ? '/' . $this->data->studentcode : '',
            self::AAA_TYPE,
            self::LIMIT
        );
    }

    /**
     * Set target client id.
     *
     * @return void
     * @throws \dml_exception
     */
    protected function set_target_client_id() {
        $this->targetclientid = get_config('sitsapiclient_easikit', 'assessmenttargetclientidv2');
    }
}
