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
use local_sitsgradepush\logger;

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
     * Returns the new deadline.
     *
     * @return string
     */
    public function get_new_deadline(): string {
        return $this->newdeadline;
    }

    /**
     * Process the extension.
     *
     * @param array $mappings
     * @throws \dml_exception
     */
    public function process_extension(array $mappings): void {
        // Exit if empty mappings.
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
                logger::log($e->getMessage(), null, "Mapping ID: $mapping->id");
            }
        }
    }

    /**
     * Set the EC properties from the AWS EC update message.
     * Note: The AWS EC update message is not yet developed, will implement this when the message is available.
     *
     * @param string $messagebody
     * @return void
     * @throws \dml_exception|\moodle_exception
     */
    public function set_properties_from_aws_message(string $messagebody): void {
        // Decode the JSON message.
        $messagedata = $this->parse_event_json($messagebody);

        // Set the user ID of the student.
        $this->set_userid($messagedata->student_code);

        // Set the MAB identifier.
        $this->mabidentifier = $messagedata->identifier;

        // Set new deadline.
        $this->newdeadline = $messagedata->new_deadline;
    }

    /**
     * Set the EC properties from the get students API.
     *
     * @param array $student
     * @return void
     */
    public function set_properties_from_get_students_api(array $student): void {
        // Will implement this when the get students API includes EC data.
    }
}
