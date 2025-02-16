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
 * Plugin administration pages are defined here.
 *
 * @package     sitsapiclient_easikit
 * @category    admin
 * @copyright   2023 onwards University College London {@link https://www.ucl.ac.uk/}
 * @license     https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @author      Alex Yeung <k.yeung@ucl.ac.uk>
 */

defined('MOODLE_INTERNAL') || die();

if ($ADMIN->fulltree) {
    // General settings header.
    $settings->add(new admin_setting_heading('sitsapiclient_easikit_general_settings',
        get_string('settings:generalsettingsheader', 'sitsapiclient_easikit'),
        ''
    ));

    // Microsoft token endpoint.
    $settings->add(new admin_setting_configtext('sitsapiclient_easikit/mstokenendpoint',
        new lang_string('settings:mstokenendpoint', 'sitsapiclient_easikit'),
        new lang_string('settings:mstokenendpoint:desc', 'sitsapiclient_easikit'),
        'https://login.microsoftonline.com/xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx/oauth2/v2.0/token'
    ));

    // Client ID.
    $settings->add(new admin_setting_configtext('sitsapiclient_easikit/clientid',
        new lang_string('settings:clientid', 'sitsapiclient_easikit'),
        new lang_string('settings:clientid:desc', 'sitsapiclient_easikit'),
        'ChangeMe!'
    ));

    // Client secret.
    $settings->add(new admin_setting_configpasswordunmask('sitsapiclient_easikit/clientsecret',
        new lang_string('settings:clientsecret', 'sitsapiclient_easikit'),
        new lang_string('settings:clientsecret:desc', 'sitsapiclient_easikit'),
        'ChangeMe!'
    ));

    // Target client ID.
    $settings->add(new admin_setting_configtext('sitsapiclient_easikit/assessmenttargetclientid',
        get_string('settings:assessmenttargetclientid', 'sitsapiclient_easikit'),
        get_string('settings:assessmenttargetclientid:desc', 'sitsapiclient_easikit'),
        'ChangeMe!'
    ));

    // Grade push endpoint.
    $settings->add(new admin_setting_configtext('sitsapiclient_easikit/endpoint_grade_push',
        get_string('settings:endpoint_push_grade', 'sitsapiclient_easikit'),
        get_string('settings:endpoint_push_grade:desc', 'sitsapiclient_easikit'),
        'https://change-this-url.example.com'
    ));

    // Submission log endpoint.
    $settings->add(new admin_setting_configtext('sitsapiclient_easikit/endpoint_submission_log',
        get_string('settings:endpoint_submission_log', 'sitsapiclient_easikit'),
        get_string('settings:endpoint_submission_log:desc', 'sitsapiclient_easikit'),
        'https://change-this-url.example.com'
    ));

    // Get component grade endpoint.
    $settings->add(new admin_setting_configtext('sitsapiclient_easikit/endpoint_component_grade',
        get_string('settings:endpoint_component_grade', 'sitsapiclient_easikit'),
        get_string('settings:endpoint_component_grade:desc', 'sitsapiclient_easikit'),
        'https://change-this-url.example.com'
    ));

    // Get student endpoint.
    $settings->add(new admin_setting_configtext('sitsapiclient_easikit/endpoint_get_student',
        get_string('settings:endpoint_get_student', 'sitsapiclient_easikit'),
        get_string('settings:endpoint_get_student:desc', 'sitsapiclient_easikit'),
        'https://change-this-url.example.com'
    ));

    // Get mark schemes endpoint.
    $settings->add(new admin_setting_configtext('sitsapiclient_easikit/endpoint_get_mark_schemes',
        get_string('settings:endpoint_mark_schemes', 'sitsapiclient_easikit'),
        get_string('settings:endpoint_mark_schemes:desc', 'sitsapiclient_easikit'),
        'https://change-this-url.example.com'
    ));
}
