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
 * CSV import form for extension tiers.
 *
 * @package     local_sitsgradepush
 * @copyright   2025 onwards University College London {@link https://www.ucl.ac.uk/}
 * @license     https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @author      Alex Yeung <k.yeung@ucl.ac.uk>
 */

namespace local_sitsgradepush\form;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/formslib.php');

/**
 * Form for importing extension tier configuration from CSV.
 */
class import_tiers_form extends \moodleform {
    /**
     * Form definition.
     */
    protected function definition(): void {
        $mform = $this->_form;

        // File picker for CSV upload.
        $mform->addElement(
            'filepicker',
            'csvfile',
            get_string('tier:csvfile', 'local_sitsgradepush'),
            null,
            ['accepted_types' => ['.csv']]
        );
        $mform->addRule('csvfile', null, 'required');
        $mform->addHelpButton('csvfile', 'tier:csvfile', 'local_sitsgradepush');

        // Import mode.
        $modes = [
            'replace' => get_string('tier:importmode_replace', 'local_sitsgradepush'),
            'update' => get_string('tier:importmode_update', 'local_sitsgradepush'),
        ];
        $mform->addElement('select', 'importmode', get_string('tier:importmode', 'local_sitsgradepush'), $modes);
        $mform->setDefault('importmode', 'replace');
        $mform->addHelpButton('importmode', 'tier:importmode', 'local_sitsgradepush');

        // Action buttons.
        $this->add_action_buttons(true, get_string('tier:preview', 'local_sitsgradepush'));
    }
}
