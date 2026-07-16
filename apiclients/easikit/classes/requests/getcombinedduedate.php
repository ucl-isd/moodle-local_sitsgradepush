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
 * Class for get combined due date request.
 *
 * @package     sitsapiclient_easikit
 * @copyright   2026 onwards University College London {@link https://www.ucl.ac.uk/}
 * @license     https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @author      Alex Yeung <k.yeung@ucl.ac.uk>
 */
class getcombinedduedate extends request {
    /** @var string[] Fields mapping - Local data fields to SITS' fields */
    const FIELDS_MAPPING = [
        'academicyear' => 'year',
        'modcode' => 'module_code',
        'modocc' => 'module_occurrence',
        'mabseq' => 'module_sequence',
        'periodslotcode' => 'period_slot_code',
    ];

    /** @var string request method */
    const METHOD = 'GET';

    /** @var int limit */
    const LIMIT = 2000;

    /**
     * Constructor.
     *
     * @param \stdClass $data
     * @throws \dml_exception
     * @throws \moodle_exception
     */
    public function __construct(\stdClass $data) {
        // Set request name.
        $this->name = 'Get combined due date';

        // Get request endpoint.
        $endpointurl = get_config('sitsapiclient_easikit', 'endpoint_combined_due_date');

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
        if (empty($response)) {
            // If no response, return empty array.
            return [];
        }

        // Convert response to suitable format.
        $response = json_decode($response, true);

        // No combined due date records found.
        if (empty($response['response']['combined_new_due_date_collection']['combined_new_due_date'])) {
            return [];
        }

        // Key records by student programme route code.
        $records = [];
        foreach ($response['response']['combined_new_due_date_collection']['combined_new_due_date'] as $record) {
            if (!empty($record['student_programme_route_code'])) {
                $records[$record['student_programme_route_code']] = $record;
            }
        }

        return $records;
    }

    /**
     * Get endpoint url with params.
     *
     * @return string
     */
    public function get_endpoint_url_with_params(): string {
        $filter = sprintf(
            "year eq '%s' and module_code eq '%s' and module_occurrence eq '%s' and " .
            "module_sequence eq '%s' and period_slot_code eq '%s'",
            $this->paramsdata['year'],
            $this->paramsdata['module_code'],
            $this->paramsdata['module_occurrence'],
            $this->paramsdata['module_sequence'],
            $this->paramsdata['period_slot_code']
        );

        // Filter by student programme route code if provided.
        if (!empty($this->data->sprcode)) {
            $filter .= sprintf(
                " and student_programme_route_code eq '%s'",
                $this->replace_invalid_characters($this->data->sprcode)
            );
        }

        return sprintf(
            '%s?limit=%d&$filter=%s',
            $this->endpointurl,
            self::LIMIT,
            rawurlencode($filter)
        );
    }

    /**
     * Replace invalid characters in parameter value.
     * The endpoint requires a slash to be replaced with @@, e.g. T1/2 becomes T1@@2.
     *
     * @param string $data
     * @return array|string|string[]
     */
    protected function replace_invalid_characters(string $data) {
        return str_replace('/', '@@', $data);
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
