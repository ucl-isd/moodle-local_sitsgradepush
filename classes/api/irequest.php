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
 * Interface irequest
 *
 * @package    local_sitsgradepush
 * @copyright  2023 onwards University College London {@link https://www.ucl.ac.uk/}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @author     Alex Yeung <k.yeung@ucl.ac.uk>
 */
interface irequest {
    /**
     * Get request name.
     *
     * @package local_sitsgradepush
     * @return string
     */
    public function get_request_name(): string;

    /**
     * Get request body.
     *
     * @package local_sitsgradepush
     * @return string|null
     */
    public function get_request_body(): ?string;

    /**
     * Get endpoint url with params.
     *
     * @package local_sitsgradepush
     * @return string
     */
    public function get_endpoint_url_with_params(): string;

    /**
     * Get request method.
     *
     * @package local_sitsgradepush
     * @return string
     */
    public function get_method(): string;

    /**
     * Returns processed response.
     *
     * @package local_sitsgradepush
     * @param mixed $response
     * @return mixed
     */
    public function process_response($response);

    /**
     * Return target client id.
     *
     * @package local_sitsgradepush
     * @return string
     */
    public function get_target_client_id(): string;
}
