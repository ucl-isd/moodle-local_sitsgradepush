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

use local_sitsgradepush\api\iclient;
use local_sitsgradepush\api\client;
use local_sitsgradepush\api\irequest;
use mod_coursework\models\course_module;
use mod_coursework\models\coursework;
use moodle_exception;
use stdClass;

/**
 * Base test class to provide common methods for testing.
 *
 * @package    local_sitsgradepush
 * @copyright  2024 onwards University College London {@link https://www.ucl.ac.uk/}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @author     Alex Yeung <k.yeung@ucl.ac.uk>
 */
abstract class base_test_class extends \advanced_testcase {
    /**
     * Get api client for testing.
     *
     * @param  bool $shouldthrowexception
     * @param  mixed|null $response
     *
     * @return iclient
     * @throws \coding_exception
     * @throws \dml_exception
     */
    protected function get_apiclient_for_testing(bool $shouldthrowexception, mixed $response = null): iclient {
        $apiclient = $this->createMock(client::class);
        $apiclient->expects($this->any())
            ->method('get_client_name')
            ->willReturn(get_string('pluginname', 'sitsapiclient_' . get_config('local_sitsgradepush', 'apiclient')));
        $apiclient->expects($this->any())
            ->method('build_request')
            ->willReturn($this->createMock(irequest::class));
        if ($shouldthrowexception) {
            $apiclient->expects($this->any())
                ->method('send_request')
                ->will($this->throwException(new moodle_exception('error:webclient', 'sitsapiclient_easikit')));
        } else {
            $apiclient->expects($this->any())
                ->method('send_request')
                ->willReturn($response);
        }
        return $apiclient;
    }

    /**
     * Convert coursework model to stdClass.
     *
     * @param coursework $coursework
     * @return stdClass
     */
    public static function convert_coursework_to_stdclass(coursework $coursework): stdClass {
        global $DB;

        $record = $DB->get_record('coursework', ['id' => $coursework->id], '*', MUST_EXIST);
        $record->cmid = $coursework->get_coursemodule_id();
        return $record;
    }

    /**
     * Clear coursework course module cache.
     *
     * This is necessary to prevent pollution from previously created modules in coursework tests.
     */
    public static function clear_coursework_cm_cache(): void {
        // Clear coursework static cache to prevent pollution from previously created modules.
        if (class_exists('mod_coursework\\models\\course_module')) {
            course_module::$pool = [];
        }
    }
}
