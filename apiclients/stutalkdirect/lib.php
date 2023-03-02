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
use local_sitsgradepush\api\request;
use local_sitsgradepush\manager;
use sitsapiclient_stutalkdirect\requests\getcomponentgrade;
use sitsapiclient_stutalkdirect\requests\getstudent;
use sitsapiclient_stutalkdirect\requests\pushgrade;

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
     * @param \stdClass $data
     * @return getcomponentgrade|null
     * @throws \dml_exception
     * @throws \moodle_exception
     */
    public function build_request(string $action, \stdClass $data) {
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
        }

        return $request;
    }

    /**
     * Send request.
     *
     * @param request $request
     * @return mixed
     * @throws \dml_exception
     * @throws \moodle_exception
     */
    public function send_request(request $request) {
        // Get username and password.
        $username = get_config('sitsapiclient_stutalkdirect', 'username');
        $password = get_config('sitsapiclient_stutalkdirect', 'password');

        if (!$username || !$password) {
            throw new \moodle_exception('Stutalk username of password is not set on config!');
        }

        try {
            $curlclient = curl_init();
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
                    array('Content-Type: application/json')
                );
            }

            $curlresponse = curl_exec($curlclient);

            if ($curlresponse === false) {
                $info = curl_getinfo($curlclient);
                curl_close($curlclient);

                throw new \moodle_exception(
                    'An error occurred during curl exec  to get SITS data. Additional info: '
                    . var_export($info, true)
                );
            }
            curl_close($curlclient);

            // Convert JSON to array.
            $data = $request->process_response(json_decode($curlresponse, true));
        } catch (\Exception $e) {
            throw new \moodle_exception(
                'Unable to get data from request: ' . $request->get_request_name() . '. ' . $e->getMessage()
            );
        }

        return $data;
    }
}
