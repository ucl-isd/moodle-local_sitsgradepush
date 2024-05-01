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
namespace local_sitsgradepush\plugininfo;

use admin_settingpage;
use core\plugininfo\base;
use part_of_admin_tree;

/**
 * Class to define sitsapiclient sub-plugin.
 *
 * @package     local_sitsgradepush
 * @copyright   2023 onwards University College London {@link https://www.ucl.ac.uk/}
 * @license     https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @author      Alex Yeung <k.yeung@ucl.ac.uk>
 */
class sitsapiclient extends base {
    /**
     * Return false if local_sitsgradepush is disabled.
     *
     * @return bool
     * @throws \dml_exception
     */
    public function is_enabled() {
        return get_config('local_sitsgradepush', 'enabled') == '1';
    }

    /**
     * Set if the sub-plugin can be uninstalled.
     *
     * @return true
     */
    public function is_uninstall_allowed() {
        return true;
    }

    /**
     * Set the settings' section name.
     *
     * @return string
     */
    public function get_settings_section_name() {
        return 'sitsapiclient'.$this->name.'settings';
    }

    /**
     * Load sub-plugin's settings.
     *
     * @param part_of_admin_tree $adminroot
     * @param string $parentnodename
     * @param bool $hassiteconfig
     * @return void
     * @throws \dml_exception
     */
    public function load_settings(part_of_admin_tree $adminroot, $parentnodename, $hassiteconfig) {
        $ADMIN = $adminroot;
        $plugininfo = $this;

        if (!$this->is_installed_and_upgraded()) {
            return;
        }

        if (!$hassiteconfig || !file_exists($this->full_path('settings.php'))) {
            return;
        }

        $section = $this->get_settings_section_name();
        $settings = new admin_settingpage($section, $this->displayname, 'moodle/site:config', $this->is_enabled() === false);
        include_once($this->full_path('settings.php')); // This may also set $settings to null.

        if ($settings) {
            $ADMIN->add($parentnodename, $settings);
        }
    }
}
