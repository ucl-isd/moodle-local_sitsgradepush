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

use local_sitsgradepush\api\irequest;

/**
 * Parent class for requests.
 *
 * @package     sitsapiclient_stutalkdirect
 * @copyright   2023 onwards University College London {@link https://www.ucl.ac.uk/}
 * @license     https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @author      Alex Yeung <k.yeung@ucl.ac.uk>
 */
abstract class request implements irequest {
    /** @var string Request endpoint URL */
    protected $endpointurl;

    /** @var string[] Required endpoint parameters */
    protected $endpointparams;

    /** @var \stdClass Original data passed in */
    protected $data;

    /** @var array Request params data */
    protected $paramsdata;

    /** @var string Request name */
    protected $name;

    /** @var array Local data fields to SITS fields mapping */
    protected $mapping;

    /** @var string request method, e.g. GET, PUT, etc. */
    protected $method;

    /** @var string request body */
    protected $body;

    /**
     * Constructor.
     *
     * @param array $mapping
     * @param string $endpointurl
     * @param array $endpointparams
     * @param \stdClass|null $data
     * @param string $method
     * @throws \moodle_exception
     */
    protected function __construct(
        array $mapping, string $endpointurl, array $endpointparams, ?\stdClass $data = null, string $method = 'GET'
    ) {
        $this->data = $data;
        $this->mapping = $mapping;
        $this->endpointurl = $endpointurl;
        $this->endpointparams = $endpointparams;
        if (!empty($data)) {
            $this->set_params_data($data);
        }
        $this->method = $method;
    }

    /**
     * Returns the endpoint's URL.
     *
     * @return string
     */
    public function get_endpoint_url(): string {
        return $this->endpointurl;
    }

    /**
     * Return the original data that passed in the request.
     *
     * @return \stdClass
     */
    public function get_data() {
        return $this->data;
    }

    /**
     * Return request's params data.
     *
     * @return array
     */
    public function get_params_data(): array {
        return $this->paramsdata;
    }

    /**
     * Return request's name.
     *
     * @return string
     */
    public function get_request_name(): string {
        return $this->name;
    }

    /**
     * Returns the endpoint's URL with required parameters.
     *
     * @return string
     * @throws \moodle_exception
     */
    public function get_endpoint_url_with_params(): string {
        $url = $this->endpointurl;
        // Construct the final URL if endpoint parameters are defined.
        if (is_array($this->endpointparams) && count($this->endpointparams) > 0) {
            foreach ($this->endpointparams as $param) {
                if (empty($this->paramsdata[$param])) {
                    throw new \moodle_exception('Mandatory field ' . $param . ' cannot be empty.');
                }
                $url .= '/' . $param . '/' . $this->replace_invalid_characters($this->paramsdata[$param]);
            }
        }

        return $url;
    }

    /**
     * Return the request method.
     *
     * @return string
     */
    public function get_method(): string {
        return $this->method;
    }

    /**
     * Return request body.
     *
     * @return string|null
     */
    public function get_request_body(): ?string {
        return $this->body;
    }

    /**
     * Returns processed response.
     *
     * @param mixed $response
     * @return mixed
     */
    public function process_response($response) {
        return $response;
    }

    /**
     * Return target client id.
     *
     * @return string
     */
    public function get_target_client_id(): string {
        // Not applicable for stutalk direct.
        return '';
    }

    /**
     * Return request source.
     *
     * @return string
     */
    public function get_source(): string {
        return (!empty($this->data->source)) ? $this->data->source : '';
    }

    /**
     * Log errors by default.
     *
     * @return bool
     */
    public function log_error_by_default(): bool {
        return false;
    }

    /**
     * Replace invalid characters in parameter value.
     *
     * @param string $data
     * @return array|string|string[]
     */
    protected function replace_invalid_characters(string $data) {
        return str_replace('/', '&&', $data);
    }

    /**
     * Set params data.
     *
     * @param \stdClass $data
     * @return void
     * @throws \moodle_exception
     */
    protected function set_params_data(\stdClass $data) {
        $paramsdata = [];
        foreach ($this->mapping as $k => $v) {
            if (!empty($data->$k)) {
                $paramsdata[$v] = $data->$k;
            } else {
                throw new \moodle_exception('Missing mandatory data ' . $k);
            }
        }
        $this->paramsdata = $paramsdata;
    }

    /**
     * Return array using the first rows value as keys.
     *
     * @param array $response
     * @return array
     */
    protected function make_array_first_row_as_keys(array $response): array {
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

    /**
     * Check missing curly brackets, add it back if missing.
     *
     * @param string $string
     * @return string
     */
    protected function check_missing_curly_brackets(string $string): string {
        // Check if the first character is '{' and last character is '}'.
        if (substr($string, 0, 1) !== '{' && substr($string, -1) !== '}') {
            // Add the missing '{' and '}'.
            $string = '{' . $string . '}';
        }

        return $string;
    }
}
