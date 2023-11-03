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
 * Class for getcomponentgrade request.
 *
 * @package     sitsapiclient_easikit
 * @copyright   2023 onwards University College London {@link https://www.ucl.ac.uk/}
 * @license     https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @author      Alex Yeung <k.yeung@ucl.ac.uk>
 */
class getcomponentgrade extends request {

    /** @var string[] Fields mapping - Local data fields to SITS' fields */
    const FIELDS_MAPPING = [
        'mod_code' => 'MOD_CODE',
        'mod_occ_year_code' => 'AYR_CODE',
        'mod_occ_psl_code' => 'PSL_CODE',
        'mod_occ_mav' => 'MAV_OCCUR',
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
        $this->name = 'Get component grade';

        // Get request endpoint.
        $endpointurl = get_config('sitsapiclient_easikit', 'endpoint_component_grade');

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
            foreach ($response['response']['assessment_component_collection']['assessment_component'] as $value) {
                // Extract assessment percentage from name.
                preg_match('/\((\d+)%\)$/', $value['name'], $matches);
                $result[] = [
                    'MOD_CODE' => $value['module']['identifier'],
                    'MAV_OCCUR' => $value['module']['delivery'][0]['occurrence_code'],
                    'AYR_CODE' => $value['module']['delivery'][0]['academic_year_code'],
                    'PSL_CODE' => $value['module']['delivery'][0]['period_slot_code'],
                    'MAP_CODE' => $value['assessment_pattern']['identifier'],
                    'MAB_SEQ' => $value['sequence_number'],
                    'AST_CODE' => $value['assessment_component_type']['code'],
                    'MAB_PERC' => $matches[1],
                    'MAB_NAME' => $value['name'],
                    'MKS_CODE' => $value['mark_scheme']['code'],
                    'APA_ROMC' => $value['schedule']['location']['room']['identifier'],
                ];
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
        return sprintf(
            '%s/%s-%s-%s-%s',
            $this->endpointurl,
            $this->paramsdata['MOD_CODE'],
            $this->paramsdata['MAV_OCCUR'],
            $this->paramsdata['PSL_CODE'],
            $this->paramsdata['AYR_CODE'],
        );
    }
}
