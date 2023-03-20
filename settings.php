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
 * @package     local_sitsgradepush
 * @category    admin
 * @copyright   2023 onwards University College London {@link https://www.ucl.ac.uk/}
 * @license     https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @author      Alex Yeung <k.yeung@ucl.ac.uk>
 */

use local_sitsgradepush\manager;
use local_sitsgradepush\plugininfo\sitsapiclient;

defined('MOODLE_INTERNAL') || die();

if ($hassiteconfig) {
    $ADMIN->add('localplugins', new admin_category('localsitssettings', 'SITS'));
    $ADMIN->add('localsitssettings', new admin_category('apiclients', 'API Clients'));
    $settings = new admin_settingpage('local_sitsgradepush_settings', new lang_string('pluginname', 'local_sitsgradepush'));
    $ADMIN->add('localsitssettings', $settings);

    if ($ADMIN->fulltree) {
        // General settings.
        $settings->add(new admin_setting_heading('local_sitsgradepush_general_settings',
            get_string('settings:generalsettingsheader', 'local_sitsgradepush'),
            ''
        ));
        // Setting to enable/disable the plugin.
        $settings->add(new admin_setting_configcheckbox(
            'local_sitsgradepush/enabled',
            get_string('settings:enable', 'local_sitsgradepush'),
            get_string('settings:enable:desc', 'local_sitsgradepush'),
            '1'
        ));

        // Setting to select API client.
        $manager = manager::get_manager();
        $options = ['' => get_string('settings:apiclientselect', 'local_sitsgradepush')] + $manager->get_api_client_list();

        $settings->add(new admin_setting_configselect(
            'local_sitsgradepush/apiclient',
            get_string('settings:apiclient', 'local_sitsgradepush'),
            get_string('settings:apiclient:desc', 'local_sitsgradepush'),
            null,
            $options
        ));

        // Set SITS moodle AST codes, separated by comma.
        $settings->add(new admin_setting_configtext('local_sitsgradepush/moodle_ast_codes',
            get_string('settings:moodleastcode', 'local_sitsgradepush'),
            get_string('settings:moodleastcode:desc', 'local_sitsgradepush'),
            ''
        ));

        // Set SITS moodle exam room code.
        $settings->add(new admin_setting_configtext('local_sitsgradepush/moodle_exam_room_code',
            get_string('settings:moodleexamroomcode', 'local_sitsgradepush'),
            get_string('settings:moodleexamroomcode:desc', 'local_sitsgradepush'),
            'EXAMMDLE'
        ));
    }

    $subplugins = core_plugin_manager::instance()->get_plugins_of_type('sitsapiclient');
    foreach ($subplugins as $plugin) {
        /** @var sitsapiclient $plugin */
        $plugin->load_settings($ADMIN, 'apiclients', $hassiteconfig);
    }
}
