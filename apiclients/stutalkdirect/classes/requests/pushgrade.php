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
 * Class for pushgrade request.
 *
 * @package     sitsapiclient_stutalkdirect
 * @copyright   2023 onwards University College London {@link https://www.ucl.ac.uk/}
 * @license     https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @author      Alex Yeung <k.yeung@ucl.ac.uk>
 */
class pushgrade extends request {

    /** @var string[] Fields mapping - Local data fields to SITS' fields */
    const FIELDS_MAPPING = [
        'assessmentcomponent' => 'ASSESSMENT-COMPONENT',
        'student' => 'STUDENT',
    ];

    /** @var string[] Endpoint params */
    const ENDPOINT_PARAMS = ['ASSESSMENT-COMPONENT', 'STUDENT'];

    /** @var string request method. */
    const METHOD = 'PUT';

    /**
     * Constructor.
     *
     * @param \stdClass $data
     * @throws \dml_exception
     * @throws \moodle_exception
     */
    public function __construct(\stdClass $data) {
        // Set request name.
        $this->name = 'Push Grade';

        // Get request endpoint.
        $endpointurl = get_config('sitsapiclient_stutalkdirect', 'endpoint_grade_push');

        // Check if endpoint is set.
        if (empty($endpointurl)) {
            throw new \moodle_exception('Endpoint URL for ' . $this->name . '  is not set');
        }

        // Transform data.
        self::transform_data($data);

        // Set the fields mapping, params fields and data.
        parent::__construct(self::FIELDS_MAPPING, $endpointurl, self::ENDPOINT_PARAMS,  $data, self::METHOD);

        // Set the request body.
        $this->set_body($data);
    }

    /**
     * Replace invalid characters in parameter value.
     *
     * @param string $data
     * @return array|string|string[]
     */
    protected function replace_invalid_characters(string $data) {
        return str_replace('/', '_', $data);
    }

    /**
     * Transform data for this request.
     *
     * @param \stdClass $data
     */
    public static function transform_data(\stdClass &$data) {
        $rseq = empty($data->reassessment) ? '0' : $data->srarseq;
        $data->assessmentcomponent = sprintf('%s-%s', $data->mapcode, $data->mabseq);
        $data->student = sprintf(
            '%s-%s-%s-%s',
            $data->sprcode,
            $data->academicyear,
            $data->pslcode,
            $rseq
        );
    }

    /**
     * Process response.
     *
     * @param mixed $response
     * @return mixed
     */
    public function process_response($response) {
        if (!empty($response) && is_string($response)) {
            // Add curly brackets to response if missing.
            $response = $this->check_missing_curly_brackets($response);
        }

        return json_decode($response, true);
    }

    /**
     * Set request body in JSON format.
     *
     * @param \stdClass $data
     * @return void
     */
    private function set_body(\stdClass $data) {
        // Set request body.
        $this->body = json_encode(['actual_mark' => $data->marks, 'actual_grade' => $data->grade, 'source' => $this->get_source()]);
    }
}
