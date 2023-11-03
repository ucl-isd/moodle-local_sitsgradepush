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

namespace sitsapiclient_stutalkdirect\requests;

/**
 * Class for getcomponentgrade request.
 *
 * @package     sitsapiclient_stutalkdirect
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

    /** @var string[] Endpoint params */
    const ENDPOINT_PARAMS = ['MOD_CODE', 'AYR_CODE', 'PSL_CODE', 'MAV_OCCUR'];

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
        $endpointurl = get_config('sitsapiclient_stutalkdirect', 'endpoint_component_grade');

        // Check if endpoint is set.
        if (empty($endpointurl)) {
            throw new \moodle_exception('Endpoint URL for ' . $this->name . '  is not set');
        }

        // Set the fields mapping, params fields and data.
        parent::__construct(self::FIELDS_MAPPING, $endpointurl, self::ENDPOINT_PARAMS, $data);
    }

    /**
     * Process returned response.
     *
     * @param mixed $response
     * @return array
     */
    public function process_response($response): array {
        return $this->make_array_first_row_as_keys(json_decode($response, true));
    }
}
