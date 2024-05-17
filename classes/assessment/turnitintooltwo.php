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

namespace local_sitsgradepush\assessment;

/**
 * Class for Turnitin assignment.
 *
 * @package    local_sitsgradepush
 * @copyright  2023 onwards University College London {@link https://www.ucl.ac.uk/}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @author     Alex Yeung <k.yeung@ucl.ac.uk>
 */
class turnitintooltwo extends activity {

    /** @var array Parts of this turnitin assignment. */
    private array $turnitinparts;

    /** @var \stdClass First part of this turnitin assignment. */
    private mixed $firstpart;

    /**
     * Constructor.
     *
     * @param \stdClass $coursemodule
     * @throws \dml_exception
     */
    public function __construct(\stdClass $coursemodule) {
        global $DB;
        parent::__construct($coursemodule);

        // Get all parts for this assignment.
        $this->turnitinparts = $DB->get_records('turnitintooltwo_parts', ['turnitintooltwoid' => $this->get_source_instance()->id]);
        $this->firstpart = reset($this->turnitinparts);
    }

    /**
     * Get all participants.
     *
     * @return array
     */
    public function get_all_participants(): array {
        $context = \context_module::instance($this->coursemodule->id);
        return get_enrolled_users($context, 'mod/turnitintooltwo:submit');
    }

    /**
     * Get the start date of this assessment.
     *
     * @return int|null
     */
    public function get_start_date(): ?int {
        // Return the start date of the first part.
        return $this->firstpart->dtstart;
    }

    /**
     * Get the end date of this assessment.
     *
     * @return int|null
     */
    public function get_end_date(): ?int {
        // Return the end date of the first part.
        return $this->firstpart->dtdue;
    }
}
