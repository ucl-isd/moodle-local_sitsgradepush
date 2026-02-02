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

namespace local_sitsgradepush\form;

defined('MOODLE_INTERNAL') || die();

require_once("$CFG->libdir/formslib.php");

/**
 * Form for processing extensions.
 *
 * @package    local_sitsgradepush
 * @copyright  2026 onwards University College London {@link https://www.ucl.ac.uk/}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @author     Alex Yeung <k.yeung@ucl.ac.uk>
 */
class process_extensions_form extends \moodleform {
    /**
     * Define the form.
     */
    public function definition() {
        $mform = $this->_form;

        $mform->addElement(
            'text',
            'courseid',
            get_string('manualprocessextensions:courseid', 'local_sitsgradepush')
        );
        $mform->setType('courseid', PARAM_INT);
        $mform->addHelpButton('courseid', 'manualprocessextensions:courseid', 'local_sitsgradepush');

        $options = [
            'both' => get_string('manualprocessextensions:extensiontype:both', 'local_sitsgradepush'),
            'raa' => get_string('manualprocessextensions:extensiontype:raa', 'local_sitsgradepush'),
            'ec' => get_string('manualprocessextensions:extensiontype:ec', 'local_sitsgradepush'),
        ];

        $mform->addElement(
            'select',
            'extensiontype',
            get_string('manualprocessextensions:extensiontype', 'local_sitsgradepush'),
            $options
        );
        $mform->setDefault('extensiontype', 'both');

        $this->add_action_buttons(false, get_string('manualprocessextensions:submit', 'local_sitsgradepush'));
    }
}
