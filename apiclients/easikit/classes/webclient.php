<?php
// This file is part of Moodle - https://moodle.org/
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
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

namespace sitsapiclient_easikit;

use cache;
use curl;
use local_sitsgradepush\api\irequest;
use moodle_exception;

/**
 * Web client class for sitsapiclient_easikit.
 *
 * @package     sitsapiclient_easikit
 * @copyright   2023 onwards University College London {@link https://www.ucl.ac.uk/}
 * @license     https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @author      Alex Yeung <k.yeung@ucl.ac.uk>
 */
class webclient {
    /** @var string[] Required settings for getting access token */
    const REQUIRED_SETTINGS = ['clientid', 'clientsecret', 'mstokenendpoint'];

    /** @var webclient webclient instance */
    private static $instance;

    /** @var string Client ID */
    private $clientid;

    /** @var string Client secret */
    private $clientsecret;

    /** @var string Microsoft token endpoint */
    private $mstokenendpoint;

    /**
     * Constructor.
     */
    private function __construct() {
        // Get plugin configs.
        $config = get_config('sitsapiclient_easikit');
        // Initialize variable and check settings exists.
        foreach (self::REQUIRED_SETTINGS as $setting) {
            if (!empty($config->$setting)) {
                $this->$setting = $config->$setting;
            } else {
                throw new \moodle_exception(
                    'error:setting_missing',
                    'sitsapiclient_easikit',
                    '',
                    get_string('settings:' . $setting, 'sitsapiclient_easikit')
                );
            }
        }
    }

    /**
     * Return the webclient instance.
     *
     * @return webclient|null
     */
    public static function get_web_client(): ?webclient {
        if (self::$instance == null) {
            self::$instance = new webclient();
        }

        return self::$instance;
    }

    /**
     * Make API call.
     *
     * @param irequest $request
     * @return mixed
     * @throws \coding_exception
     * @throws \dml_exception
     * @throws moodle_exception
     */
    public function make_api_call(irequest $request) {
        // Get the access token for sending request.
        $token = $this->get_access_token($request);
        $curl = new curl();
        $curl->setHeader('Authorization: Bearer ' . $token);
        $curl->setHeader('Accept: application/json');
        // Set timeout in 30 seconds.
        $curl->setopt(['CURLOPT_CONNECTTIMEOUT'  => 30]);
        if ($request->get_method() !== 'GET') {
            // Set content type in header.
            $curl->setHeader('Content-Type: application/json');
        }
        $requestmethod = strtolower($request->get_method());

        // Make call.
        $response = $curl->$requestmethod($request->get_endpoint_url_with_params(), $request->get_request_body());
        if ($curl->get_errno()) {
            throw new moodle_exception('error:webclient', 'sitsapiclient_easikit', '', $curl->error);
        }

        // Get HTTP status code.
        $httpcode = $curl->get_info()['http_code'];

        // Hardcoded response as no response message returned if request completed successfully.
        if ($httpcode >= 200 && $httpcode <= 299) {
            $response = '{"code":0,"message":"Request completed successfully."}';
        }

        // Return processed response.
        return $request->process_response($response);
    }

    /**
     * Get access token.
     *
     * @param irequest $request
     * @return string
     * @throws \coding_exception
     * @throws moodle_exception
     */
    private function get_access_token(irequest $request): string {
        // Check target client id exists.
        $targetclientid = $request->get_target_client_id();
        if (empty($targetclientid)) {
            throw new moodle_exception(
                'error:webclient',
                'sitsapiclient_easikit',
                '',
                get_string('error:no_target_client_id', 'sitsapiclient_easikit', $request->get_request_name()));
        }

        // For constructing cache key.
        $targetclientidkey = str_replace('-', '_', $targetclientid);

        $cache = cache::make('sitsapiclient_easikit', 'oauth');
        $token = $cache->get('accesstoken_' . $targetclientidkey);
        $expires = $cache->get('expires_' . $targetclientidkey);
        if (empty($token) || empty($expires) || time() >= $expires) {
            // Request timestamp.
            $requesttimestamp = time();
            // Request body.
            $authform = 'grant_type=client_credentials' . '&' .
                'client_id=' . $this->clientid . '&' .
                'client_secret=' . $this->clientsecret . '&' .
                'scope=' . $targetclientid . '/.default';
            $curl = new curl();
            // Define body content type.
            $curl->setHeader('Content-Type: application/x-www-form-urlencoded');
            // Ask for access token.
            $response = $curl->post($this->mstokenendpoint, $authform);
            // Error occured.
            if ($curl->get_errno()) {
                throw new moodle_exception('error:webclient', 'sitsapiclient_easikit', '', $curl->error);
            }
            // Decompose the response.
            $response = json_decode($response);
            // Cache access token and expires it in 1 hour.
            if (isset($response->access_token)) {
                $token = $response->access_token;
                $cache->set('accesstoken_' . $targetclientidkey, $token);

                if (isset($response->expires_in)) {
                    $expires = $response->expires_in + $requesttimestamp;
                } else {
                    $expires = 3599 + $requesttimestamp;
                }

                $cache->set('expires_' . $targetclientidkey, $expires);

                return $token;
            } else {
                throw new moodle_exception(
                    'error:webclient', 'sitsapiclient_easikit', '', get_string('error:access_token', 'sitsapiclient_easikit')
                );
            }
        } else {
            return $token;
        }
    }
}
