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
 * Class for get marking schemes request.
 *
 * @package     sitsapiclient_easikit
 * @copyright   2023 onwards University College London {@link https://www.ucl.ac.uk/}
 * @license     https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @author      Alex Yeung <k.yeung@ucl.ac.uk>
 */
class getmarkingschemes extends request {
    /** @var string request method */
    const METHOD = 'GET';

    /**
     * Constructor.
     *
     * @throws \dml_exception
     * @throws \moodle_exception
     */
    public function __construct() {
        // Set request name.
        $this->name = 'Get marking schemes';

        // Get request endpoint.
        $endpointurl = get_config('sitsapiclient_easikit', 'endpoint_get_mark_schemes');

        // Check if endpoint is set.
        if (empty($endpointurl)) {
            throw new \moodle_exception('Endpoint URL for ' . $this->name . '  is not set');
        }

        // Set the fields mapping, params fields and data.
        parent::__construct([], $endpointurl);
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
            $response = json_decode($response, true);
            // Make MKS_CODE the key.
            foreach ($response['response']['mark_scheme_collection']['mark_scheme'] as $markingscheme) {
                $result[$markingscheme['identifier']] = [
                    'MKS_CODE' => $markingscheme['identifier'],
                    'MKS_MARKS' => $markingscheme['usage_indicator']['code'],
                    'MKS_TYPE' => $markingscheme['type']['code'],
                    'MKS_IUSE' => $markingscheme['in_use_indicator'],
                ];
            }
        }

        return $result;
    }

    /**
     * Returns the endpoint's URL with required parameters.
     *
     * @return string
     */
    public function get_endpoint_url_with_params(): string {
        return $this->endpointurl;
    }
}
