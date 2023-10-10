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

namespace sitsapiclient_stutalkdirect;

use local_sitsgradepush\api\client;
use local_sitsgradepush\api\irequest;
use local_sitsgradepush\logger;
use local_sitsgradepush\manager;
use local_sitsgradepush\submission\submission;
use moodle_exception;
use sitsapiclient_stutalkdirect\requests\getcomponentgrade;
use sitsapiclient_stutalkdirect\requests\getmarkingschemes;
use sitsapiclient_stutalkdirect\requests\getstudent;
use sitsapiclient_stutalkdirect\requests\pushgrade;
use sitsapiclient_stutalkdirect\requests\pushsubmissionlog;

/**
 * Global library class for sitsapiclient_stutalkdirect.
 *
 * @package     sitsapiclient_stutalkdirect
 * @copyright   2023 onwards University College London {@link https://www.ucl.ac.uk/}
 * @license     https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @author      Alex Yeung <k.yeung@ucl.ac.uk>
 */
class stutalkdirect extends client {


    /**
     * Constructor.
     */
    public function __construct() {
        parent::__construct('Stutalk Direct');
    }

    /**
     * Build the request object for a given action.
     *
     * @param string $action
     * @param \stdClass|null $data
     * @param submission|null $submission
     * @return getcomponentgrade|getstudent|pushgrade|pushsubmissionlog|null
     * @throws \dml_exception
     * @throws \moodle_exception
     */
    public function build_request(string $action, \stdClass $data = null, submission $submission = null) {
        $request = null;
        switch ($action) {
            case manager::GET_COMPONENT_GRADE:
                $request = new getcomponentgrade($data);
                break;
            case manager::GET_STUDENT:
                $request = new getstudent($data);
                break;
            case manager::PUSH_GRADE:
                $request = new pushgrade($data);
                break;
            case manager::PUSH_SUBMISSION_LOG:
                $request = new pushsubmissionlog($data, $submission);
                break;
            case manager::GET_MARKING_SCHEMES:
                $request = new getmarkingschemes();
        }

        return $request;
    }

    /**
     * Send request.
     *
     * @param irequest $request
     * @return mixed
     * @throws \dml_exception
     * @throws \moodle_exception
     */
    public function send_request(irequest $request) {
        try {
            // Get username and password.
            $username = get_config('sitsapiclient_stutalkdirect', 'username');
            $password = get_config('sitsapiclient_stutalkdirect', 'password');

            if (!$username || !$password) {
                throw new \moodle_exception('error:stutalkdirect:accountinfo', 'sitsapiclient_stutalkdirect');
            }

            $curlclient = curl_init();
            curl_setopt($curlclient, CURLOPT_CONNECTTIMEOUT, 30);
            curl_setopt($curlclient, CURLOPT_SSL_VERIFYHOST, false);
            curl_setopt($curlclient, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($curlclient, CURLOPT_URL, $request->get_endpoint_url_with_params());
            curl_setopt($curlclient, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($curlclient, CURLOPT_USERPWD, $username . ":" . $password);
            curl_setopt($curlclient, CURLOPT_CUSTOMREQUEST, $request->get_method());
            if (in_array($request->get_method(), ['PUT', 'POST'])) {
                curl_setopt($curlclient, CURLOPT_POSTFIELDS, $request->get_request_body());
                curl_setopt(
                    $curlclient,
                    CURLOPT_HTTPHEADER,
                    ['Content-Type: application/json']
                );
            }

            // Execute request.
            $curlresponse = curl_exec($curlclient);

            // Get execute info.
            $info = curl_getinfo($curlclient);

            // CURL related errors.
            if ($curlresponse === false) {
                $error = curl_error($curlclient);
                curl_close($curlclient);
                throw new \moodle_exception(
                    'error:stutalkdirectcurl',
                    'sitsapiclient_stutalkdirect',
                    '',
                    $error
                );
            }

            // Check server response codes 400 and above.
            if ($info['http_code'] >= 400) {
                curl_close($curlclient);
                // Throw exception.
                throw new moodle_exception(
                    'error:stutalkdirect',
                    'sitsapiclient_stutalkdirect',
                    '',
                    ['requestname' => $request->get_request_name(), 'httpstatuscode' => $info['http_code']]
                );
            }

            // Close curl session.
            curl_close($curlclient);

            return $request->process_response($curlresponse);
        } catch (\moodle_exception $e) {
            // Log error.
            $errorlogid = logger::log_request_error($e->getMessage(), $request, $curlresponse ?? null);
            // Add error log id to exception.
            $e->debuginfo = $errorlogid;
            // Throw exception.
            throw $e;
        }
    }
}
