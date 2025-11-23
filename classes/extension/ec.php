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
use local_sitsgradepush\extensionmanager;
use local_sitsgradepush\logger;
use local_sitsgradepush\manager;

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
    protected string $newdeadline = '';

    /** @var string MAB identifier, e.g. CCME0158A6UF-001 */
    protected string $mabidentifier = '';

    /** @var string Identifier for the EC/DAP request which has latest new due date */
    protected string $latestidentifier = '';

    /** @var bool Indicate if it is a processable deleted DAP event */
    protected bool $deleteddapprocessable = false;

    /**
     * Returns the new deadline.
     *
     * @return string
     */
    public function get_new_deadline(): string {
        return $this->newdeadline;
    }

    /**
     * Get the MAB identifier.
     *
     * @return string
     */
    public function get_mab_identifier(): string {
        return $this->mabidentifier;
    }

    /**
     * Get the latest identifier. This is the identifier for the EC/DAP request which has the latest new due date.
     *
     * @return string
     */
    public function get_latest_identifier(): string {
        return $this->latestidentifier;
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

        foreach ($mappings as $mapping) {
            try {
                // Skip reassessments for EC.
                if ($mapping->reassessment == 1) {
                    continue;
                }

                $assessment = assessmentfactory::get_assessment($mapping->sourcetype, $mapping->sourceid);
                if (!$assessment->is_user_a_participant($this->userid)) {
                    continue;
                }
                $assessment->set_sits_mapping_id($mapping->id);
                if (empty($this->get_new_deadline())) {
                    // Check if student has an active EC override.
                    $mtoverride = extensionmanager::get_active_user_mt_overrides_by_mapid(
                        $mapping->id,
                        $mapping->sourceid,
                        extensionmanager::EXTENSION_EC,
                        $this->get_userid()
                    );
                    // Delete previous EC override if exists when the new deadline is empty,
                    // i.e. EC is removed for the student on SITS.
                    if (!empty($mtoverride)) {
                        $assessment->delete_ec_override($mtoverride);
                    }
                    // Continue to next mapping as the new deadline is empty.
                    continue;
                }
                $assessment->apply_extension($this);
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

        // Check if this is a deleted DAP event.
        if ($this->is_deleted_dap_event()) {
            // Handle deleted DAP event by looking up override record.
            if ($this->handle_deleted_dap_event()) {
                // Set data source and mark data as set.
                $this->datasource = self::DATASOURCE_AWS;
                $this->dataisset = true;
                $this->deleteddapprocessable = true;
                return;
            }
        }

        // Normal EC message processing.
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
            $latestduedate = $this->get_latest_deadline($student['extenuating_circumstance']);
            $this->newdeadline = $latestduedate['new_due_date'] ?? '';
            $this->latestidentifier = $latestduedate['identifier'] ?? '';
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
     * Check if this is a processable deleted DAP event.
     *
     * @return bool True if this is a processable deleted DAP event, false otherwise.
     */
    public function is_deleted_dap_processable(): bool {
        return $this->deleteddapprocessable;
    }

    /**
     * Get the latest deadline.
     *
     * @param array $extensions An array of extenuating circumstances.
     * @return array
     */
    protected function get_latest_deadline(array $extensions): array {
        $latestdate = null;
        $latestidentifier = null;

        // Use the latest deadline from the extenuating circumstances.
        foreach ($extensions as $extension) {
            if (empty($extension['new_due_date'])) {
                continue;
            }

            $current = new DateTime($extension['new_due_date']);

            if ($latestdate === null || $current > $latestdate) {
                $latestdate = $current;
                $latestidentifier = $extension['identifier'];
            }
        }

        if ($latestdate === null) {
            return [];
        }

        return [
            'identifier' => $latestidentifier,
            'new_due_date' => $latestdate->format('Y-m-d'),
        ];
    }

    /**
     * Check if this is a deleted DAP event.
     *
     * @return bool True if this is a deleted DAP event.
     */
    public function is_deleted_dap_event(): bool {
        // Check if process_status changed to 'D'.
        $statusdeleted = false;
        if (!empty($this->extensionchanges)) {
            foreach ($this->extensionchanges as $change) {
                if ($change->attribute === 'extenuating_circumstances.process_status' && $change->to === 'D') {
                    $statusdeleted = true;
                    break;
                }
            }
        }

        // Not a deleted event.
        if (!$statusdeleted) {
            return false;
        }

        // Check if request identifier starts with "DAP-".
        $identifier = $this->eventdata->extenuating_circumstances->request->identifier ?? '';
        return str_starts_with($identifier, 'DAP-');
    }

    /**
     * Handle deleted DAP event by looking up override record and setting EC properties.
     *
     * @return bool True if properties were set successfully, false otherwise.
     * @throws \dml_exception
     */
    protected function handle_deleted_dap_event(): bool {
        global $DB;

        // Get the DAP request identifier.
        $identifier = $this->eventdata->extenuating_circumstances->request->identifier ?? '';
        if (empty($identifier)) {
            return false;
        }

        // Query local_sitsgradepush_overrides for matching request identifier.
        $override = $DB->get_record('local_sitsgradepush_overrides', [
            'requestidentifier' => $identifier,
            'extensiontype' => extensionmanager::EXTENSION_EC,
            'timerestored' => null,
        ]);

        if (!$override) {
            return false;
        }

        // Get user's idnumber from user table.
        $user = $DB->get_record('user', ['id' => $override->userid], 'idnumber');
        if (!$user) {
            return false;
        }

        $this->userid = $override->userid;
        $this->studentcode = $user->idnumber;

        // Get MAB info from mapping id.
        $mapping = manager::get_manager()->get_mab_and_map_info_by_mapping_id($override->mapid);
        if (empty($mapping)) {
            return false;
        }

        // Construct mabidentifier as "mapcode-mabseq".
        $this->mabidentifier = $mapping->mapcode . '-' . $mapping->mabseq;

        return true;
    }
}
