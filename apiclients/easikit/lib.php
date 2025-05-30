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

namespace sitsapiclient_easikit;

use local_sitsgradepush\api\client;
use local_sitsgradepush\api\irequest;
use local_sitsgradepush\manager;
use local_sitsgradepush\submission\submission;
use sitsapiclient_easikit\requests\getcomponentgrade;
use sitsapiclient_easikit\requests\getmarkingschemes;
use sitsapiclient_easikit\requests\getstudent;
use sitsapiclient_easikit\requests\getstudents;
use sitsapiclient_easikit\requests\getstudentsv2;
use sitsapiclient_easikit\requests\pushgrade;
use sitsapiclient_easikit\requests\pushsubmissionlog;
use sitsapiclient_easikit\requests\request;

/**
 * Global library class for sitsapiclient_easikit.
 *
 * @package     sitsapiclient_easikit
 * @copyright   2023 onwards University College London {@link https://www.ucl.ac.uk/}
 * @license     https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @author      Alex Yeung <k.yeung@ucl.ac.uk>
 */
class easikit extends client {

    /**
     * Constructor.
     */
    public function __construct() {
        parent::__construct(get_string('pluginname', 'sitsapiclient_easikit'));
    }

    /**
     * Build request.
     *
     * @param string $action
     * @param \stdClass|null $data
     * @param submission|null $submission
     * @return request|null
     * @throws \dml_exception
     * @throws \moodle_exception
     */
    public function build_request(string $action, ?\stdClass $data = null, ?submission $submission = null) {
        return match ($action) {
            manager::PUSH_GRADE => new pushgrade($data),
            manager::GET_COMPONENT_GRADE => new getcomponentgrade($data),
            manager::GET_STUDENT => new getstudent($data),
            manager::GET_STUDENTS => new getstudents($data),
            manager::GET_STUDENTS_V2 => new getstudentsv2($data),
            manager::PUSH_SUBMISSION_LOG => new pushsubmissionlog($data, $submission),
            manager::GET_MARKING_SCHEMES => new getmarkingschemes(),
            default => null,
        };
    }

    /**
     * Send request.
     *
     * @param irequest $request
     * @return mixed|void
     * @throws \moodle_exception
     */
    public function send_request(irequest $request) {
        // Get web client.
        $client = webclient::get_web_client();
        // Make API call.
        return $client->make_api_call($request);
    }
}
