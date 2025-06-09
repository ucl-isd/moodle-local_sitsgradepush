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

use DateTime;
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
    private string $newdeadline = '';

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
     * @throws \dml_exception|\coding_exception
     */
    public function process_extension(array $mappings): void {
        // Pre-process extension checks.
        if (!$this->pre_process_extension_checks($mappings)) {
            return;
        }

        // Skip if the new deadline is empty.
        if (empty($this->get_new_deadline())) {
            return;
        }

        foreach ($mappings as $mapping) {
            try {
                // Skip reassessments for EC.
                if ($mapping->reassessment == 1) {
                    continue;
                }

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
        $messagedata = $this->parse_event_json($messagebody);

        $this->extensionchanges = $messagedata->changes ?? null;
        $studentec = $messagedata->entity->student_extenuating_circumstances ?? null;

        if (empty($studentec)) {
            throw new \moodle_exception('error:invalid_message', 'local_sitsgradepush', '', null, $messagebody);
        }

        // Set event data.
        $this->eventdata = $studentec;
        $this->mabidentifier = $studentec->assessment_component->identifier;
        $this->studentcode = $studentec->student->student_code;
        $this->set_userid($this->studentcode);

        // Set data source.
        $this->datasource = self::DATASOURCE_AWS;
        $this->dataisset = true;
    }

    /**
     * Set the EC properties from the get students API.
     *
     * @param array $student
     * @return void
     */
    public function set_properties_from_get_students_api(array $student): void {
        // Set the user ID of the student.
        parent::set_properties_from_get_students_api($student);

        // Set new deadline.
        // $student['extenuating_circumstance'] is an array.
        if (!empty($student['extenuating_circumstance'])) {
            $this->newdeadline = $this->get_latest_deadline($student['extenuating_circumstance']);
        }

        // Set data source.
        $this->datasource = self::DATASOURCE_API;
        $this->dataisset = true;
    }

    /**
     * Set the MAB identifier.
     *
     * @param string $mabidentifier Map code and MAB sequence number, e.g. CCME0158A6UF-001.
     * @return void
     */
    public function set_mabidentifier(string $mabidentifier): void {
        $this->mabidentifier = $mabidentifier;
    }

    /**
     * Get the latest deadline.
     *
     * @param array $extensions An array of extenuating circumstances.
     * @return string
     */
    protected function get_latest_deadline(array $extensions): string {
        $latest = null;

        // Use the latest deadline from the extenuating circumstances.
        foreach ($extensions as $extension) {
            if (empty($extension['new_due_date'])) {
                continue;
            }

            $current = new DateTime($extension['new_due_date']);

            if (!$latest || $current > $latest) {
                $latest = $current;
            }
        }

        return $latest ? $latest->format('Y-m-d') : '';
    }
}
