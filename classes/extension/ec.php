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

namespace local_sitsgradepush\extension;

use local_sitsgradepush\assessment\assessmentfactory;

/**
 * Class for extenuating circumstance (EC).
 *
 * @package    local_sitsgradepush
 * @copyright  2024 onwards University College London {@link https://www.ucl.ac.uk/}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @author     Alex Yeung <k.yeung@ucl.ac.uk>
 */
class ec extends extension {

    /** @var string New deadline */
    private string $newdeadline;

    /** @var string MAB identifier, e.g. CCME0158A6UF-001 */
    protected string $mabidentifier;

    /**
     * Constructor.
     *
     * @param string $message
     * @throws \dml_exception
     */
    public function __construct(string $message) {
        parent::__construct($message);

        // Set the EC properties that we need.
        $this->set_ec_properties();
    }

    /**
     * Returns the new deadline.
     *
     * @return string
     */
    public function get_new_deadline(): string {
        return $this->newdeadline;
    }

    /**
     * Process the extension.
     */
    public function process_extension(): void {
        // Get all mappings for the SITS assessment.
        // We only allow one mapping per SITS assessment for now.
        $mappings = $this->get_mappings_by_mab($this->get_mab_identifier());

        if (empty($mappings)) {
            return;
        }

        foreach ($mappings as $mapping) {
            try {
                $assessment = assessmentfactory::get_assessment($mapping->sourcetype, $mapping->sourceid);
                if ($assessment->is_user_a_participant($this->userid)) {
                    $assessment->apply_extension($this);
                }
            } catch (\Exception $e) {
                // Consider logging the error here.
                continue;
            }
        }
    }

    /**
     * Set the EC properties.
     *
     * @return void
     * @throws \dml_exception
     */
    private function set_ec_properties(): void {
        global $DB;

        // Find and set the user ID from the student.
        $idnumber = $this->message->student_code;
        $user = $DB->get_record('user', ['idnumber' => $idnumber], 'id', MUST_EXIST);
        $this->userid = $user->id;

        // Set the MAB identifier.
        $this->mabidentifier = $this->message->identifier;

        // Set new deadline.
        $this->newdeadline = $this->message->new_deadline;
    }
}
