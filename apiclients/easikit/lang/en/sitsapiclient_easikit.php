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
 * @package     sitsapiclient_easikit
 * @category    string
 * @copyright   2023 onwards University College London {@link https://www.ucl.ac.uk/}
 * @license     https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @author      Alex Yeung <k.yeung@ucl.ac.uk>
 */

defined('MOODLE_INTERNAL') || die();

$string['cachedef_oauth'] = 'Store OAuth access token';
$string['error:access_token'] = 'Cannot get access token. Request: {$a}';
$string['error:curl'] = 'Easikit curl error: {$a->requestname}. CURL error: {$a->error}';
$string['error:missing_or_invalid_field'] = 'Required field missing or invalid: {$a}';
$string['error:no_target_client_id'] = 'No target client id set for {$a}';
$string['error:setting_missing'] = '{$a} not found';
$string['error:webclient'] = 'Easikit web client error: {$a->requestname}. HTTP code: {$a->httpstatuscode}';
$string['msg:gradepushsuccess'] = 'Grade successfully pushed to SITS via Easikit';
$string['msg:submissionlogpushsuccess'] = 'Submission log successfully pushed to SITS via Easikit';
$string['pluginname'] = 'Easikit';
$string['privacy:metadata'] = 'This plugin does not store any personal data.';
$string['settings:assessmenttargetclientid'] = 'Assessment Target Client ID';
$string['settings:assessmenttargetclientid:desc'] = 'Assessment Target Client ID';
$string['settings:clientid'] = 'Client ID';
$string['settings:clientid:desc'] = 'Client ID registered on Azure AD';
$string['settings:clientsecret'] = 'Client Secret';
$string['settings:clientsecret:desc'] = 'Client Secret';
$string['settings:endpoint_component_grade'] = 'Get component grade endpoint';
$string['settings:endpoint_component_grade:desc'] = 'Get component grade endpoint';
$string['settings:endpoint_get_student'] = 'Get student endpoint';
$string['settings:endpoint_get_student:desc'] = 'Get student endpoint';
$string['settings:endpoint_get_student_v2'] = 'Get student endpoint v2';
$string['settings:endpoint_get_student_v2:desc'] = 'Get student endpoint v2';
$string['settings:endpoint_mark_schemes'] = 'Get mark schemes endpoint';
$string['settings:endpoint_mark_schemes:desc'] = 'Get mark schemes endpoint';
$string['settings:endpoint_push_grade'] = 'Push grades endpoint';
$string['settings:endpoint_push_grade:desc'] = 'Push grades endpoint';
$string['settings:endpoint_submission_log'] = 'Push submission log endpoint';
$string['settings:endpoint_submission_log:desc'] = 'Push submission log endpoint';
$string['settings:generalsettingsheader'] = 'General Settings';
$string['settings:mstokenendpoint'] = 'Microsoft token endpoint';
$string['settings:mstokenendpoint:desc'] = 'Microsoft token endpoint';
$string['settings:version2settings'] = 'Version 2 API Settings';
