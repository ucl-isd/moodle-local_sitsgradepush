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
 * @package     sitsapiclient_stutalkdirect
 * @category    admin
 * @copyright   2023 onwards University College London {@link https://www.ucl.ac.uk/}
 * @license     https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @author      Alex Yeung <k.yeung@ucl.ac.uk>
 */

defined('MOODLE_INTERNAL') || die();

if ($ADMIN->fulltree) {
    // General settings header.
    $settings->add(new admin_setting_heading(
        'sitsapiclient_stutalkdirect_general_settings',
        get_string('settings:generalsettingsheader', 'sitsapiclient_stutalkdirect'),
        ''
    ));

    // Stutalk username.
    $settings->add(new admin_setting_configtext(
        'sitsapiclient_stutalkdirect/username',
        new lang_string('settings:username', 'sitsapiclient_stutalkdirect'),
        new lang_string('settings:username:desc', 'sitsapiclient_stutalkdirect'),
        'STUTALK_MDL'
    ));

    // Stutalk password.
    $settings->add(new admin_setting_configpasswordunmask(
        'sitsapiclient_stutalkdirect/password',
        new lang_string('settings:password', 'sitsapiclient_stutalkdirect'),
        new lang_string('settings:password:desc', 'sitsapiclient_stutalkdirect'),
        'ChangeMe!'
    ));

    // Endpoint for getting component grades.
    $settings->add(new admin_setting_configtext(
        'sitsapiclient_stutalkdirect/endpoint_component_grade',
        get_string('settings:endpoint_component_grade', 'sitsapiclient_stutalkdirect'),
        get_string('settings:endpoint_component_grade:desc', 'sitsapiclient_stutalkdirect'),
        'https://stutalk-dev-cloud.ucl.ac.uk/urd/sits.urd/run/SIW_RWS/MDL_MAB_1'
    ));

    // Endpoint for getting student SPR_CODE by student's id number, assessment map code and sequence number.
    $settings->add(new admin_setting_configtext(
        'sitsapiclient_stutalkdirect/endpoint_student',
        get_string('settings:endpoint_student', 'sitsapiclient_stutalkdirect'),
        get_string('settings:endpoint_student:desc', 'sitsapiclient_stutalkdirect'),
        'https://stutalk-dev-cloud.ucl.ac.uk/urd/sits.urd/run/SIW_RWS/MDL_GET_STD'
    ));

    // Endpoint for grade push.
    $settings->add(new admin_setting_configtext(
        'sitsapiclient_stutalkdirect/endpoint_grade_push',
        get_string('settings:endpoint_push_grade', 'sitsapiclient_stutalkdirect'),
        get_string('settings:endpoint_push_grade:desc', 'sitsapiclient_stutalkdirect'),
        'https://stutalk-dev-cloud.ucl.ac.uk/urd/sits.urd/run/SIW_RWS/MARKSIMPORT2'
    ));

    // Endpoint for push submission log.
    $settings->add(new admin_setting_configtext(
        'sitsapiclient_stutalkdirect/endpoint_push_submission_log',
        get_string('settings:endpoint_push_submission_log', 'sitsapiclient_stutalkdirect'),
        get_string('settings:endpoint_push_submission_log:desc', 'sitsapiclient_stutalkdirect'),
        'https://stutalk-dev-cloud.ucl.ac.uk/urd/sits.urd/run/SIW_RWS/MARKSLOGIMPORT'
    ));

    $settings->add(new admin_setting_configtext(
        'sitsapiclient_stutalkdirect/endpoint_getmarkingschemes',
        get_string('settings:endpoint_getmarkingschemes', 'sitsapiclient_stutalkdirect'),
        get_string('settings:endpoint_getmarkingschemes:desc', 'sitsapiclient_stutalkdirect'),
        'https://stutalk-dev-cloud.ucl.ac.uk/urd/sits.urd/run/SIW_RWS/MDL_CLC_MKS'
    ));
}
