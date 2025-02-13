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

use local_sitsgradepush\cachemanager;
use local_sitsgradepush\logger;

/**
 * Class for getstudents request.
 *
 * @package     sitsapiclient_easikit
 * @copyright   2023 onwards University College London {@link https://www.ucl.ac.uk/}
 * @license     https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @author      Alex Yeung <k.yeung@ucl.ac.uk>
 */
class getstudents extends request {

    /** @var string[] Fields mapping - Local data fields to SITS' fields */
    const FIELDS_MAPPING = [
        'mapcode' => 'MAP_CODE',
        'mabseq' => 'MAB_SEQ',
    ];

    /** @var string request method */
    const METHOD = 'GET';

    /**
     * Constructor.
     *
     * @param \stdClass $data
     * @throws \dml_exception
     * @throws \moodle_exception
     */
    public function __construct(\stdClass $data) {
        // Set request name.
        $this->name = 'Get students';

        // Get request endpoint.
        $endpointurl = get_config('sitsapiclient_easikit', 'endpoint_get_student');

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

        $result = [];
        if (!empty($response)) {
            // Convert response to suitable format.
            $response = json_decode($response, true);
            $result = $response['response']['student_collection']['student'] ?? [];

            // Early return if no students.
            if (empty($result)) {
                return $result;
            }

            // Find the moodle user id for each student.
            try {
                [$insql, $params] = $DB->get_in_or_equal(array_column($result, 'code'));
                $sql = "SELECT idnumber, id FROM {user} WHERE idnumber $insql";
                $users = $DB->get_records_sql($sql, $params);
                foreach ($result as &$student) {
                    $student['moodleuserid'] = $users[$student['code']]->id ?? null;
                }
            } catch (\Exception $e) {
                // Log the error.
                logger::log('Error getting students user IDs', null, $e->getMessage());
            }
        }

        return $result;
    }

    /**
     * Get endpoint url with params.
     *
     * @return string
     */
    public function get_endpoint_url_with_params(): string {
        // Return endpoint url with params.
        return sprintf(
            '%s/%s-%s/student',
            $this->endpointurl,
            $this->paramsdata['MAP_CODE'],
            $this->paramsdata['MAB_SEQ']
        );
    }
}
