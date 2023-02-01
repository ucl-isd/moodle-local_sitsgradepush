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
 * @package     local_sits_grade_push
 * @category    admin
 * @copyright   2023 onwards University College London {@link https://www.ucl.ac.uk/}
 * @license     https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @author      Alex Yeung <k.yeung@ucl.ac.uk>
 */

defined('MOODLE_INTERNAL') || die();

if ($hassiteconfig) {
    $settings = new admin_settingpage('local_sits_grade_push_settings', new lang_string('pluginname', 'local_sits_grade_push'));
    $ADMIN->add('localplugins', $settings);

    if ($ADMIN->fulltree) {
        // General settings.
        $settings->add(new admin_setting_heading('local_sits_grade_push_general_settings',
            get_string('settings:generalsettingsheader', 'local_sits_grade_push'),
            ''
        ));
        // Setting to enable/disable the plugin.
        $settings->add(new admin_setting_configcheckbox(
            'local_sits_grade_push/enabled',
            get_string('settings:enable', 'local_sits_grade_push'),
            get_string('settings:enable:desc', 'local_sits_grade_push'),
            '1'
        ));
    }
}
