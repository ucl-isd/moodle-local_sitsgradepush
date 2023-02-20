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

namespace local_sitsgradepush\api;

/**
 * Parent class for requests.
 *
 * @package     local_sitsgradepush
 * @copyright   2023 onwards University College London {@link https://www.ucl.ac.uk/}
 * @license     https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @author      Alex Yeung <k.yeung@ucl.ac.uk>
 */
abstract class request {
    /** @var string Request endpoint URL */
    protected $endpointurl;

    /** @var string[] Required endpoint parameters */
    protected $endpointparams;

    /** @var array Request payload  */
    protected $payload;

    /** @var string Request name */
    protected $name;

    /**
     * Returns processed response.
     *
     * @param array $response
     * @return mixed
     */
    abstract public function processresponse(array $response);

    /**
     * Returns the endpoint's URL.
     *
     * @return string
     */
    public function getendpointurl(): string {
        return $this->endpointurl;
    }

    /**
     * Return request's payload.
     *
     * @return array
     */
    public function getpayload(): array {
        return $this->payload;
    }

    /**
     * Return request's name.
     *
     * @return string
     */
    public function getrequestname(): string {
        return $this->name;
    }

    /**
     * Returns the endpoint's URL with required parameters.
     *
     * @return string
     * @throws \moodle_exception
     */
    public function getendpointurlwithparams(): string {
        $url = $this->endpointurl;
        // Construct the final URL if endpoint parameters are defined.
        if (is_array($this->endpointparams) && count($this->endpointparams) > 0) {
            foreach ($this->endpointparams as $param) {
                if (empty($this->payload[$param])) {
                    throw new \moodle_exception('Mandatory field ' . $param . ' cannot be empty.');
                }
                $url .= '/' . $param . '/' . $this->replaceinvalidcharacters($this->payload[$param]);
            }
        }

        return $url;
    }

    /**
     * Replace invalid characters in parameter value.
     *
     * @param string $data
     * @return array|string|string[]
     */
    protected function replaceinvalidcharacters(string $data) {
        return str_replace('/', '&&', $data);
    }
}
