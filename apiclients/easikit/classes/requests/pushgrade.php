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
 * Class for pushgrade request.
 *
 * @package     sitsapiclient_easikit
 * @copyright   2023 onwards University College London {@link https://www.ucl.ac.uk/}
 * @license     https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @author      Alex Yeung <k.yeung@ucl.ac.uk>
 */
class pushgrade extends request {

    /** @var string[] Fields mapping - Local data fields to SITS' fields */
    const FIELDS_MAPPING = [
        'assessmentcomponent' => 'assessment-component',
        'student' => 'student',
    ];

    /** @var string request method. */
    const METHOD = 'PATCH';

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
        $endpointurl = get_config('sitsapiclient_easikit', 'endpoint_grade_push');

        // Check if endpoint is set.
        if (empty($endpointurl)) {
            throw new \moodle_exception('Endpoint URL for ' . $this->name . '  is not set');
        }

        // Transform data.
        self::transform_data($data);

        // Set the fields mapping, params fields and data.
        parent::__construct(self::FIELDS_MAPPING, $endpointurl, $data, self::METHOD);

        // Set the request body.
        $this->set_body($data);
    }

    /**
     * Transform data for this request.
     *
     * @param \stdClass $data
     */
    public static function transform_data(\stdClass &$data) {
        // Add required data.
        // The last part of the student identifier should be in 3 digits for re-assessment, e.g. 001, 002, 003.
        // But the resit number getting from the easikit api is in 1 digit, e.g. 1, 2, 3. (0 means not a re-assessment).
        // Therefore, we need to pad it to form a valid student identifier, e.g. 23456789_1-2023-T1-001.
        $rseq = $data->reassessment == 1 ? str_pad($data->srarseq, 3, '0', STR_PAD_LEFT) : '0';
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
     * @return array|void
     * @throws \coding_exception
     */
    public function process_response($response) {
        // Empty Easikit response for grade push means that the request was successful.
        // Http code >= 400 response was captured by the exception handler.
        if (empty($response)) {
            return ["code" => 0, "message" => get_string('msg:gradepushsuccess', 'sitsapiclient_easikit')];
        }
    }

    /**
     * Get endpoint url with params.
     *
     * @return string
     */
    public function get_endpoint_url_with_params(): string {
        return sprintf(
            '%s/%s/student/%s/marks',
            $this->endpointurl,
            $this->paramsdata['assessment-component'],
            $this->paramsdata['student']
        );
    }

    /**
     * Log errors by default.
     *
     * @return bool
     */
    public function log_error_by_default(): bool {
        return true;
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
