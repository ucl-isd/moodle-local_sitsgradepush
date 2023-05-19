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

/**
 * Plugin strings are defined here.
 *
 * @package     sitsapiclient_stutalkdirect
 * @category    string
 * @copyright   2023 onwards University College London {@link https://www.ucl.ac.uk/}
 * @license     https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @author      Alex Yeung <k.yeung@ucl.ac.uk>
 */

defined('MOODLE_INTERNAL') || die();

$string['pluginname'] = 'Stutalk Direct';
$string['settings:generalsettingsheader'] = 'General Settings';
$string['settings:endpoint_component_grade'] = 'SITS endpoint for component grades';
$string['settings:endpoint_component_grade:desc'] = 'SITS endpoint for component grades';
$string['settings:endpoint_student'] = 'SITS endpoint student';
$string['settings:endpoint_student:desc'] = 'SITS endpoint for getting student\'s SPR_CODE';
$string['settings:endpoint_push_grade'] = 'SITS endpoint push grades';
$string['settings:endpoint_push_grade:desc'] = 'SITS endpoint for pushing grades';
$string['settings:endpoint_push_submission_log'] = 'SITS endpoint push submission log';
$string['settings:endpoint_push_submission_log:desc'] = 'SITS endpoint for pushing submission log';
$string['settings:endpoint_getmarkingschemes'] = 'SITS endpoint get marking schemes';
$string['settings:endpoint_getmarkingschemes:desc'] = 'SITS endpoint for getting marking schemes';
$string['settings:username'] = 'SITS Username';
$string['settings:username:desc'] = 'Username used to connect to SITS';
$string['settings:password'] = 'SITS Password';
$string['settings:password:desc'] = 'Password used to connect to SITS';
$string['error:stutalkdirect'] = 'Stutalk direct error: {$a->requestname}. HTTP code: {$a->httpstatuscode}';
$string['error:stutalkdirectcurl'] = 'Stutalk CURL error: {$a}.';
