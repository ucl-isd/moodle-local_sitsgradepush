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
use local_sitsgradepush\extensionmanager;
use local_sitsgradepush\logger;

/**
 * Class for Summary of Reasonable Adjustments (SORA).
 *
 * @package    local_sitsgradepush
 * @copyright  2024 onwards University College London {@link https://www.ucl.ac.uk/}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @author     Alex Yeung <k.yeung@ucl.ac.uk>
 */
class sora extends extension {
    /** @var string Prefix used to create RAA groups */
    const SORA_GROUP_PREFIX = 'RAA-Activity-';

    /** @var string SORA message type - New code to replace the old EXAM type code */
    const SORA_MESSAGE_TYPE_RAPXR = 'RAPXR';

    /** @var string Empty time extension in HH:MM format */
    const EMPTY_EXTENSION = '00:00';

    /** @var int Extra duration in minutes per hour */
    protected int $extraduration;

    /** @var int Rest duration in minutes per hour */
    protected int $restduration;

    /** @var int Time extension in seconds, including extra and rest duration */
    protected int $timeextension;

    /** @var string|null SORA message type */
    protected ?string $soramessagetype;

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
     * @param int $totalextension The total extension in seconds.
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
     * @param int $totalextension The total extension in seconds.
     * @return string
     */
    public static function get_extension_group_name(int $sourceid, int $totalextension): string {
        return sprintf(self::SORA_GROUP_PREFIX . '%d-Extension-%s', $sourceid, self::formatextensionstime($totalextension));
    }

    /**
     * Format extension time from seconds to human-readable string.
     *
     * @param int $seconds The duration in seconds.
     * @return string Formatted time string (e.g., "2days3hrs30mins").
     */
    public static function formatextensionstime(int $seconds): string {
        $days = floor($seconds / DAYSECS);
        $remainingseconds = $seconds % DAYSECS;
        $hours = floor($remainingseconds / HOURSECS);
        $remainingseconds = $remainingseconds % HOURSECS;
        $minutes = floor($remainingseconds / MINSECS);

        $result = '';

        if ($days > 0) {
            $result .= "{$days}day" . ($days > 1 ? "s" : "");
        }

        if ($hours > 0) {
            $result .= "{$hours}hr" . ($hours > 1 ? "s" : "");
        }

        if ($minutes > 0) {
            $result .= "{$minutes}min" . ($minutes > 1 ? "s" : "");
        }

        return $result;
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
        $this->extensionchanges = $messagedata->changes ?? null;

        // Check the message is valid and set student code.
        $studentcode = $messagedata->entity->person_sora->person->student_code ?? null;
        if (!empty($studentcode)) {
            $this->studentcode = $studentcode;
            $this->set_userid($studentcode);
            $this->extraduration = $this->convert_time_to_minutes(
                $messagedata->entity->person_sora->extra_duration ?? self::EMPTY_EXTENSION
            );
            $this->restduration = $this->convert_time_to_minutes(
                $messagedata->entity->person_sora->rest_duration ?? self::EMPTY_EXTENSION
            );
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
        $this->extraduration = $this->convert_time_to_minutes(
            $student['student_assessment']['sora']['extra_duration'] ?? self::EMPTY_EXTENSION
        );
        $this->restduration = $this->convert_time_to_minutes(
            $student['student_assessment']['sora']['rest_duration'] ?? self::EMPTY_EXTENSION
        );

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

                // Check if the assessment can apply SORA extension.
                if (!$assessment->can_assessment_apply_sora($this, $mapping->id)) {
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
     * Get the SORA message type.
     *
     * @return string|null
     */
    public function get_sora_message_type(): ?string {
        return $this->soramessagetype;
    }

    /**
     * Get the extension tier.
     *
     * @return string|null
     */
    public function get_ref_extension_tier(): ?string {
        global $DB;

        // Get the extension tier from the database based on extra and rest duration.
        $record = $DB->get_record(extensionmanager::TABLE_EXTENSION_TIERS, [
            'assessmenttype' => 'TIERREF',
            'extensionvalue' => $this->get_extra_duration(),
            'breakvalue' => $this->get_rest_duration(),
        ]);

        return $record ? $record->tier : null;
    }

    /**
     * Calculate total extension time in seconds based on tier configuration.
     *
     * @param \stdClass $tier Extension tier configuration.
     * @param int|null $duration Duration in seconds (required for time_per_hour type).
     * @return int Total extension time in seconds.
     * @throws \coding_exception If invalid extension type or missing required duration.
     */
    public function calculate_total_extension_time(\stdClass $tier, ?int $duration = null): int {
        switch ($tier->extensiontype) {
            case extensionmanager::RAA_EXTENSION_TYPE_TIME_PER_HOUR:
                if ($duration === null) {
                    throw new \coding_exception(get_string('error:extensiondurationrequired', 'local_sitsgradepush'));
                }
                // Calculate total minutes per hour (extension + break), multiply by hours, convert to seconds.
                $totalhours = $duration / HOURSECS;
                $minutesperhour = $tier->extensionvalue + $tier->breakvalue;
                return (int) ($totalhours * $minutesperhour * MINSECS);

            case extensionmanager::RAA_EXTENSION_TYPE_TIME:
                return match ($tier->extensionunit) {
                    extensionmanager::RAA_EXTENSION_UNIT_MINUTES => (int) ($tier->extensionvalue * MINSECS),
                    extensionmanager::RAA_EXTENSION_UNIT_HOURS => (int) ($tier->extensionvalue * HOURSECS),
                    default => throw new \coding_exception(
                        get_string('error:extensioninvalidunit', 'local_sitsgradepush', $tier->extensionunit)
                    ),
                };

            case extensionmanager::RAA_EXTENSION_TYPE_DAYS:
                return (int) ($tier->extensionvalue * DAYSECS);

            default:
                throw new \coding_exception(
                    get_string('error:extensionunsupportedtype', 'local_sitsgradepush', $tier->extensiontype)
                );
        }
    }

    /**
     * Calculate extension details (new due date and extension time) based on tier configuration.
     *
     * @param \stdClass $tier The assessment extension tier configuration.
     * @param int $enddate The original assessment end date timestamp.
     * @param int|null $duration Optional duration in seconds (required for time_per_hour type).
     * @return array Array with 'newduedate' and 'extensioninsecs' keys.
     * @throws \coding_exception If tier is invalid or missing required duration.
     */
    public function calculate_extension_details(\stdClass $tier, int $enddate, ?int $duration = null): array {
        switch ($tier->extensiontype) {
            case extensionmanager::RAA_EXTENSION_TYPE_TIME_PER_HOUR:
                if ($duration === null) {
                    throw new \coding_exception(get_string('error:extensiondurationrequired', 'local_sitsgradepush'));
                }
                $extensioninsecs = $this->calculate_total_extension_time($tier, $duration);
                $newduedate = $enddate + $extensioninsecs;
                break;

            case extensionmanager::RAA_EXTENSION_TYPE_TIME:
                $extensioninsecs = $this->calculate_total_extension_time($tier);
                $newduedate = $enddate + $extensioninsecs;
                break;

            case extensionmanager::RAA_EXTENSION_TYPE_DAYS:
                $extensioninsecs = $this->calculate_total_extension_time($tier);
                $newduedate = $this->get_new_duedate($tier, $enddate);
                break;

            default:
                throw new \coding_exception(
                    get_string('error:extensionunsupportedtype', 'local_sitsgradepush', $tier->extensiontype)
                );
        }

        return [
            'newduedate' => $newduedate,
            'extensioninsecs' => $extensioninsecs,
        ];
    }

    /**
     * Get or create SORA group and add user to it.
     *
     * @param int $courseid The course ID.
     * @param int $cmid The course module ID.
     * @param int $extensioninsecs The extension time in seconds.
     * @return int The group ID.
     * @throws \moodle_exception If group creation or user addition fails.
     */
    public function get_or_create_sora_group(int $courseid, int $cmid, int $extensioninsecs): int {
        $groupid = $this->get_sora_group_id($courseid, $cmid, $this->get_userid(), $extensioninsecs);

        if (!$groupid) {
            throw new \moodle_exception('error:cannotgetsoragroupid', 'local_sitsgradepush');
        }

        return $groupid;
    }

    /**
     * Get new due date for RAA extension.
     *
     * @param \stdClass $tier The assessment extension tier, e.g. tier record from extension_tiers table for an assessment type.
     * @param int $enddate The original assessment end date timestamp.
     * @return int The new due date timestamp.
     * @throws \coding_exception If the extension type is not days.
     * @throws \moodle_exception If the feedback tracker plugin is not installed.
     */
    public function get_new_duedate(\stdClass $tier, int $enddate): int {
        // Only works for days extension type.
        if ($tier->extensiontype !== extensionmanager::RAA_EXTENSION_TYPE_DAYS) {
            throw new \coding_exception(get_string('error:extensionnewtduedatedaysonly', 'local_sitsgradepush'));
        }

        // Check if feedback tracker plugin is installed.
        if (!class_exists('\report_feedback_tracker\local\helper')) {
            throw new \moodle_exception('error:extensionfeedbacktrackernotinstalled', 'local_sitsgradepush');
        }

        $closuredays = \report_feedback_tracker\local\helper::get_closuredays();
        $daystoextend = (int) $tier->extensionvalue;
        $newduedate = $enddate;
        $daysadded = 0;

        // Loop until the required number of working days have been added.
        while ($daysadded < $daystoextend) {
            $newduedate += DAYSECS;
            $datestring = date('Y-m-d', $newduedate);
            $weekday = date('N', $newduedate);

            // Count the day if it's a weekday and not a closure day.
            if ($weekday < 6 && !in_array($datestring, $closuredays, true)) {
                $daysadded++;
            }
        }

        return $newduedate;
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
     * Convert time string in HH:MM format to total minutes.
     *
     * @param string $time Time string in HH:MM format (e.g., "00:15", "01:30").
     * @return int Total minutes.
     */
    private function convert_time_to_minutes(string $time): int {
        // Handle empty or invalid input.
        if (empty($time)) {
            return 0;
        }

        // Split the time string by colon.
        $parts = explode(':', $time);
        if (count($parts) !== 2) {
            return 0;
        }

        $hours = (int) $parts[0];
        $minutes = (int) $parts[1];

        return ($hours * HOURMINS) + $minutes;
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
