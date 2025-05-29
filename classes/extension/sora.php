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
use local_sitsgradepush\manager;

/**
 * Class for Summary of Reasonable Adjustments (SORA).
 *
 * @package    local_sitsgradepush
 * @copyright  2024 onwards University College London {@link https://www.ucl.ac.uk/}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @author     Alex Yeung <k.yeung@ucl.ac.uk>
 */
class sora extends extension {

    /** @var string Prefix used to create SORA groups */
    const SORA_GROUP_PREFIX = 'SORA-Activity-';

    /** @var string SORA message type - EXAM */
    const SORA_MESSAGE_TYPE_EXAM = 'EXAM';

    /** @var int Extra duration in minutes per hour */
    protected int $extraduration;

    /** @var int Rest duration in minutes per hour */
    protected int $restduration;

    /** @var int Time extension in seconds, including extra and rest duration */
    protected int $timeextension;

    /** @var string|null SORA message type */
    protected ?string $soramessagetype;

    /** @var array|null SORA changes */
    protected ?array $sorachanges;

    /**
     * Return the whole time extension in seconds, including extra and rest duration.
     *
     * @return int
     */
    public function get_time_extension(): int {
        return $this->timeextension;
    }

    /**
     * Return the extra duration in minutes.
     *
     * @return int
     */
    public function get_extra_duration(): int {
        return $this->extraduration;
    }

    /**
     * Return the rest duration in minutes.
     *
     * @return int
     */
    public function get_rest_duration(): int {
        return $this->restduration;
    }

    /**
     * Get the SORA group ID. Create the group if it does not exist.
     * Add the user to the group. Remove the user from other SORA groups.
     *
     * @param int $courseid The course ID.
     * @param int $cmid The course module ID.
     * @param int $userid The user ID.
     * @param int $totalextension The total extension in minutes, including extra and rest duration.
     *
     * @return int
     * @throws \coding_exception
     * @throws \dml_exception
     * @throws \moodle_exception
     */
    public function get_sora_group_id(int $courseid, int $cmid, int $userid, int $totalextension): int {
        global $CFG;
        require_once($CFG->dirroot . '/group/lib.php');

        // Check group exists.
        $groupname = self::get_extension_group_name($cmid, $totalextension);
        $groupid = groups_get_group_by_name($courseid, $groupname);

        if (!$groupid) {
            // Create group.
            $newgroup = new \stdClass();
            $newgroup->courseid = $courseid;
            $newgroup->name = $groupname;
            $newgroup->description = '';
            $newgroup->enrolmentkey = '';
            $newgroup->picture = 0;
            $newgroup->visibility = GROUPS_VISIBILITY_OWN;
            $newgroup->hidepicture = 0;
            $newgroup->timecreated = time();
            $newgroup->timemodified = time();
            $groupid = groups_create_group($newgroup);
        }

        // Add user to group.
        if (!groups_add_member($groupid, $userid)) {
            throw new \moodle_exception('error:cannotaddusertogroup', 'local_sitsgradepush');
        }

        return $groupid;
    }

    /**
     * Get the SORA group name.
     *
     * @param int $sourceid The moodle source ID.
     * @param int $totalextension The total extension in minutes, including extra and rest duration.
     * @return string
     */
    public static function get_extension_group_name(int $sourceid, int $totalextension): string {
        return sprintf(self::SORA_GROUP_PREFIX . '%d-Extension-%s', $sourceid, self::formatextensionstime($totalextension));
    }

    /**
     * Format the extension time.
     *
     * @param int $minutes
     * @return string
     */
    public static function formatextensionstime(int $minutes): string {
        if ($minutes < HOURMINS) {
            return "{$minutes}mins";
        }

        $hours = floor($minutes / HOURMINS);
        $remainingminutes = $minutes % HOURMINS;

        if ($remainingminutes == 0) {
            return "{$hours}hr" . ($hours > 1 ? "s" : "");
        }

        return "{$hours}hr" . ($hours > 1 ? "s" : "") . "{$remainingminutes}mins";
    }

    /**
     * Set properties from AWS SORA update message.
     * This set the student code and user id for this SORA, the SORA extension information will be obtained from the API.
     *
     * @param string $messagebody
     * @return void
     * @throws \dml_exception
     * @throws \moodle_exception
     */
    public function set_properties_from_aws_message(string $messagebody): void {
        // Decode the JSON message body.
        $messagedata = $this->parse_event_json($messagebody);

        // Set datasource.
        $this->datasource = self::DATASOURCE_AWS;

        // Set SORA message type.
        $this->soramessagetype = $messagedata->entity->person_sora->type->code ?? null;

        // Set SORA changes.
        $this->sorachanges = $messagedata->changes ?? null;

        // Check the message is valid and set student code.
        $studentcode = $messagedata->entity->person_sora->person->student_code ?? null;
        if (!empty($studentcode)) {
            $this->studentcode = $studentcode;
            $this->set_userid($studentcode);
            $this->extraduration = (int) $messagedata->entity->person_sora->extra_duration ?? 0;
            $this->restduration = (int) $messagedata->entity->person_sora->rest_duration ?? 0;
            $this->timeextension = $this->calculate_time_extension($this->get_extra_duration(), $this->get_rest_duration());
        } else {
            throw new \moodle_exception('error:invalid_message', 'local_sitsgradepush', '', null, $messagebody);
        }

        $this->dataisset = true;
    }

    /**
     * Set properties from get students API.
     *
     * @param array $student
     * @return void
     */
    public function set_properties_from_get_students_api(array $student): void {
        // Set the user ID of the student.
        parent::set_properties_from_get_students_api($student);

        // Set datasource.
        $this->datasource = self::DATASOURCE_API;

        // Set properties.
        $this->extraduration = (int) $student['student_assessment']['sora']['extra_duration'] ?? 0;
        $this->restduration = (int) $student['student_assessment']['sora']['rest_duration'] ?? 0;

        // Calculate and set the time extension in seconds.
        $this->timeextension = $this->calculate_time_extension($this->get_extra_duration(), $this->get_rest_duration());
        $this->dataisset = true;
    }

    /**
     * Process the extension.
     *
     * @param array $mappings
     *
     * @return void
     * @throws \coding_exception
     * @throws \dml_exception|\moodle_exception
     */
    public function process_extension(array $mappings): void {
        // Pre-process extension checks.
        if (!$this->pre_process_extension_checks($mappings)) {
            return;
        }

        // Apply the extension to the assessments.
        foreach ($mappings as $mapping) {
            try {
                $assessment = assessmentfactory::get_assessment($mapping->sourcetype, $mapping->sourceid);
                if (!$assessment->is_user_a_participant($this->get_userid())) {
                    continue;
                }

                // Apply the extension to the assessment.
                $assessment->apply_extension($this);
            } catch (\Exception $e) {
                logger::log($e->getMessage());
            }
        }
    }

    /**
     * Get the data source.
     * To distinguish where the SORA data come from AWS or API.
     *
     * @return string
     */
    public function get_data_source(): string {
        return $this->datasource;
    }

    /**
     * Get the SORA message type.
     *
     * @return string|null
     */
    public function get_sora_message_type(): ?string {
        return $this->soramessagetype;
    }

    /**
     * Get the SORA changes.
     *
     * @return array|null
     */
    public function get_sora_changes(): ?array {
        return $this->sorachanges;
    }

    /**
     * Pre-process extension checks.
     *
     * @param array $mappings
     * @return bool
     */
    protected function pre_process_extension_checks(array $mappings): bool {
        // API is only use to set the initial SoRA extension via new mapping or new student enrollment.
        // If the SoRA extension is not set, do not process the extension.
        if ($this->get_data_source() === self::DATASOURCE_API && $this->get_time_extension() === 0) {
            return false;
        }

        // Common pre-process extension checks.
        return parent::pre_process_extension_checks($mappings);
    }

    /**
     * Calculate the time extension in seconds.
     *
     * @param int $extraduration Extra duration in minutes.
     * @param int $restduration Rest duration in minutes.
     * @return int
     */
    private function calculate_time_extension(int $extraduration, int $restduration): int {
        return ($extraduration + $restduration) * MINSECS;
    }
}
