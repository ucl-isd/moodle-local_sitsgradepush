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

namespace local_sitsgradepush;

use local_sitsgradepush\api\irequest;

/**
 * Logger.
 *
 * @package    local_sitsgradepush
 * @copyright  2023 onwards University College London {@link https://www.ucl.ac.uk/}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @author     Alex Yeung <k.yeung@ucl.ac.uk>
 */
class logger {
    /**
     * Log error.
     *
     * @param string $message
     * @param string|null $requesturl
     * @param string|null $data
     * @param string|null $response
     * @return bool|int
     * @throws \dml_exception
     */
    public static function log(string $message, string $requesturl = null, string $data = null, string $response = null) {
        global $DB, $USER;

        // Create the insert object.
        $error = new \stdClass();
        $error->message = $message;
        $error->userid = $USER->id;
        $error->requesturl = $requesturl;
        $error->data = $data;
        $error->response = $response;
        $error->timecreated = time();

        return $DB->insert_record('local_sitsgradepush_err_log', $error);
    }

    /**
     * Log request error.
     *
     * @param string $message
     * @param irequest $request
     * @param string|null $response
     * @return bool|int
     * @throws \dml_exception
     */
    public static function log_request_error(string $message, irequest $request, string $response = null) {
        global $DB, $USER;

        // Create the insert object.
        $error = new \stdClass();
        $error->message = $message;
        $error->userid = $USER->id;
        $error->requesturl = $request->get_endpoint_url_with_params();
        $error->data = $request->get_request_body();
        $error->response = $response;
        $error->timecreated = time();

        // Check if response is JSON.
        if ($decodedresponse = json_decode($response, true)) {
            // Try to identify the error type.
            if (!empty($decodedresponse['message'])) {
                $error->errortype = errormanager::identify_error($decodedresponse['message']);
            } else {
                $error->errortype = errormanager::ERROR_UNKNOWN;
            }
        }

        return $DB->insert_record('local_sitsgradepush_err_log', $error);
    }
}
