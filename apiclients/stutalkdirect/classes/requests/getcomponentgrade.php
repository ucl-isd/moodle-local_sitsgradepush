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

use local_sitsgradepush\api\request;

/**
 * Class for getcomponentgrade type request.
 *
 * @package     sitsapiclient_stutalkdirect
 * @copyright   2023 onwards University College London {@link https://www.ucl.ac.uk/}
 * @license     https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @author      Alex Yeung <k.yeung@ucl.ac.uk>
 */
class getcomponentgrade extends request {

    /** @var string[] Fields mapping - Local DB fields to SITS' fields */
    public $mapping = [
        'mod_code' => 'MOD_CODE',
        'mod_occ_year_code' => 'AYR_CODE',
        'mod_occ_psl_code' => 'PSL_CODE',
        'mod_occ_mav' => 'MAV_OCCUR'
    ];

    /**
     * Constructor.
     *
     * @param \stdClass $modocc
     * @throws \dml_exception
     * @throws \moodle_exception
     */
    public function __construct(\stdClass $modocc) {
        // Set request name.
        $this->name = 'Get component grade';

        // Define the parameters required for this request.
        $this->endpointparams = ['MOD_CODE', 'AYR_CODE', 'PSL_CODE', 'MAV_OCCUR'];

        // Get request endpoint.
        $endpointurl = get_config('sitsapiclient_stutalkdirect', 'endpoint_component_grade');
        if (!empty($endpointurl)) {
            $this->endpointurl = $endpointurl;
        } else {
            throw new \moodle_exception('Endpoint URL for ' . $this->name . '  is not set');
        }

        // Prepare the payload of the request.
        $payload = [];
        foreach ($this->mapping as $k => $v) {
            if (!empty($modocc->$k)) {
                $payload[$v] = $modocc->$k;
            } else {
                throw new \moodle_exception('Missing mandatory data ' . $k);
            }
        }
        $this->payload = $payload;
    }

    /**
     * Process returned response.
     *
     * @param array $response
     * @return array
     */
    public function processresponse(array $response) {
        $processedresponse = [];

        // Use the first element as keys of the remaining elements.
        if (!empty($response[0])) {
            $keys = array_shift($response);

            foreach ($response as $v) {
                $processedresponse[] = array_combine($keys, $v);
            }
        }

        return $processedresponse;
    }
}
