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

$string['pluginname'] = 'Easikit';
$string['settings:generalsettingsheader'] = 'General Settings';
$string['settings:endpoint_push_grade'] = 'Push grades endpoint';
$string['settings:endpoint_push_grade:desc'] = 'Push grades endpoint';
$string['settings:clientid'] = 'Client ID';
$string['settings:clientid:desc'] = 'Client ID registered on Azure AD';
$string['settings:clientsecret'] = 'Client Secret';
$string['settings:clientsecret:desc'] = 'Client Secret';
$string['settings:mstokenendpoint'] = 'Microsoft token endpoint';
$string['settings:mstokenendpoint:desc'] = 'Microsoft token endpoint';
$string['settings:assessmenttargetclientid'] = 'Assessment Target Client ID';
$string['settings:assessmenttargetclientid:desc'] = 'Assessment Target Client ID';
$string['error:setting_missing'] = '{$a} not found';
$string['error:access_token'] = 'Cannot get access token';
$string['error:no_target_client_id'] = 'No target client id set for {$a}';
$string['error:webclient'] = 'Web client error: {$a}.';
$string['cachedef_oauth'] = 'Store OAuth access token';
