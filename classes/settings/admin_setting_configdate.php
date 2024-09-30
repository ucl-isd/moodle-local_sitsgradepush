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

/**
 * An admin setting for a date input.
 *
 * @package    local_sitsgradepush
 * @copyright  2024 onwards University College London {@link https://www.ucl.ac.uk/}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @author     Alex Yeung <k.yeung@ucl.ac.uk>
 */

namespace local_sitsgradepush\settings;

use admin_setting_configtext;

/**
 * An admin setting for a date input.
 */
class admin_setting_configdate extends admin_setting_configtext {

    /**
     * Validate the submitted data of the setting.
     *
     * @param string $data Value submitted for the field
     * @return true|string True if validation is ok, otherwise string containing error message.
     * @throws \coding_exception
     */
    public function validate($data) {
        // Date must be set.
        if ($data === '') {
            return get_string('required');
        }

        $date = date_parse($data);
        if (!checkdate($date['month'], $date['day'], $date['year'])) {
            return get_string('error:dateformat', 'local_sitsgradepush');
        }

        return true;
    }

    /**
     * Return the HTML output for the field.
     *
     * @param string $data
     * @param string $query
     * @return string
     */
    public function output_html($data, $query=''): string {
        global $OUTPUT;

        $default = $this->get_defaultsetting();
        $context = (object) [
            'type' => 'date',
            'size' => $this->size,
            'id' => $this->get_id(),
            'name' => $this->get_full_name(),
            'value' => $data,
            'forceltr' => $this->get_force_ltr(),
            'readonly' => $this->is_readonly(),
        ];
        $element = $OUTPUT->render_from_template('local_sitsgradepush/setting_configdate', $context);

        return format_admin_setting($this, $this->visiblename, $element, $this->description, true, '', $default, $query);
    }
}
