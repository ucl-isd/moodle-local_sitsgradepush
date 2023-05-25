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

use cache;

/**
 * Class for getstudent request.
 *
 * @package     sitsapiclient_easikit
 * @copyright   2023 onwards University College London {@link https://www.ucl.ac.uk/}
 * @license     https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @author      Alex Yeung <k.yeung@ucl.ac.uk>
 */
class getstudent extends request {

    /** @var string[] Fields mapping - Local data fields to SITS' fields */
    const FIELDS_MAPPING = [
        'idnumber' => 'STU_CODE',
        'mapcode' => 'MAP_CODE',
        'mabseq' => 'MAB_SEQ'
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
        $this->name = 'Get student';

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
        $result = [];
        if (!empty($response)) {
            // Convert response to suitable format.
            $response = json_decode($response, true);

            // Loop through all returned students and create cache for each student.
            foreach ($response['response']['student_collection']['student'] as $student) {
                // Build cache key.
                $cache = cache::make('local_sitsgradepush', 'studentspr');
                $sprcodecachekey = 'studentspr_' . $this->paramsdata['MAP_CODE'] . '_' . $student['code'];
                $expirescachekey = 'expires_' . $this->paramsdata['MAP_CODE'] . '_' . $student['code'];

                // Set cache.
                $cache->set($sprcodecachekey, $student['spr_code']);

                // Set cache expires in 30 days.
                $cache->set($expirescachekey, time() + 2592000);

                // Set result.
                if ($student['code'] == $this->paramsdata['STU_CODE']) {
                    $result = ['SPR_CODE' => $student['spr_code']];
                }
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
