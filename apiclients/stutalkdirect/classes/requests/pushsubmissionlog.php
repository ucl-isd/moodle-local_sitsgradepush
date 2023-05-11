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

use local_sitsgradepush\submission\submission;

/**
 * Class for pushsubmissionlog request.
 *
 * @package     sitsapiclient_stutalkdirect
 * @copyright   2023 onwards University College London {@link https://www.ucl.ac.uk/}
 * @license     https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @author      Alex Yeung <k.yeung@ucl.ac.uk>
 */
class pushsubmissionlog extends request {

    /** @var string[] Fields mapping - Local data fields to SITS' fields */
    const FIELDS_MAPPING = [
        'assessmentcomponent' => 'ASSESSMENT-COMPONENT',
        'student' => 'STUDENT',
    ];

    /** @var string[] Endpoint params */
    const ENDPOINT_PARAMS = ['ASSESSMENT-COMPONENT', 'STUDENT'];

    /** @var string request method. */
    const METHOD = 'PUT';

    /** @var submission assessment submission */
    protected $submission;

    /**
     * Constructor.
     *
     * @param \stdClass $data
     * @param submission $submission
     * @throws \dml_exception
     * @throws \moodle_exception
     */
    public function __construct(\stdClass $data, submission $submission) {
        // Set request name.
        $this->name = 'Push submission log';

        // Get request endpoint.
        $endpointurl = get_config('sitsapiclient_stutalkdirect', 'endpoint_push_submission_log');

        // Check if endpoint is set.
        if (empty($endpointurl)) {
            throw new \moodle_exception('Endpoint URL for ' . $this->name . '  is not set');
        }

        // Set submission.
        $this->submission = $submission;

        // Set request body.
        $this->set_body();

        // Transform data.
        $data = pushgrade::transform_data($data);

        // Set the fields mapping, params fields and data.
        parent::__construct(self::FIELDS_MAPPING, $endpointurl, self::ENDPOINT_PARAMS, $data, self::METHOD);
    }

    /**
     * Process the response.
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
     * Replace invalid characters in parameter value.
     *
     * @param string $data
     * @return array|string|string[]
     */
    protected function replace_invalid_characters(string $data) {
        return str_replace('/', '_', $data);
    }

    /**
     * Set the request body.
     *
     * @return void
     */
    protected function set_body() {
        $body = [];
        $body['original_due_datetime'] = $this->submission->get_original_due_datetime();
        $body['current_due_datetime'] = $this->submission->get_current_due_datetime();
        $body['handin_datetime'] = $this->submission->get_handin_datetime();
        $body['handin_status'] = $this->submission->get_handin_status();
        $body['handed_in'] = $this->submission->get_handed_in();
        $body['handed_in_blank'] = $this->submission->get_handed_in_blank();
        $body['permitted_submission_period'] = $this->submission->get_permitted_submission_period();
        $body['export_staff'] = $this->submission->get_export_staff();
        $body['export_timestamp'] = $this->submission->get_export_timestamp();
        $body['export_flow_id'] = $this->submission->get_export_flow_id();
        $body['no_of_items'] = $this->submission->get_no_of_items();
        $this->body = json_encode($body);
    }
}
