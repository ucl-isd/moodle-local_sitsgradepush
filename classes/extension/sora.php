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

use local_sitsgradepush\assessment\assessment;
use local_sitsgradepush\assessment\assessmentfactory;
use local_sitsgradepush\extension\models\raa_event_message;
use local_sitsgradepush\extension\models\raa_required_provisions;
use local_sitsgradepush\extensionmanager;
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
    /** @var string Prefix used to create RAA groups */
    const SORA_GROUP_PREFIX = 'RAA-Activity-';

    /** @var string RAA record type - AAA record type that links to ARP records with extension data for each assessment type */
    const RAA_MESSAGE_TYPE_RAPAS = 'RAPAS';

    /** @var string RAA status: approved. */
    const RAA_STATUS_APPROVED = '5';

    /** @var int Time extension in seconds, including extra and rest duration */
    protected int $timeextension;

    /** @var raa_event_message|null RAA event message model */
    protected ?raa_event_message $raaeventmsg = null;

    /** @var raa_required_provisions|null RAA required provisions model */
    public ?raa_required_provisions $raarequiredprovisions = null;

    /**
     * Return the whole time extension in seconds, including extra and rest duration.
     *
     * @return int
     */
    public function get_time_extension(): int {
        return $this->timeextension;
    }

    /**
     * Get the SORA group ID. Create the group if it does not exist.
     * Add the user to the group. Remove the user from other SORA groups.
     *
     * @param int $courseid The course ID.
     * @param int $cmid The course module ID.
     * @param int $userid The user ID.
     * @param int $totalextension The total extension in seconds.
     * @param int|null $newduedate The new due date timestamp for DLG-based groups.
     *
     * @return int
     * @throws \coding_exception
     * @throws \dml_exception
     * @throws \moodle_exception
     */
    public function get_sora_group_id(
        int $courseid,
        int $cmid,
        int $userid,
        int $totalextension,
        ?int $newduedate = null
    ): int {
        global $CFG;
        require_once($CFG->dirroot . '/group/lib.php');

        // Check group exists.
        $groupname = self::get_extension_group_name($cmid, $totalextension, $newduedate);
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
            $newgroup->timecreated = $this->clock->time();
            $newgroup->timemodified = $this->clock->time();
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
     * @param int|null $newduedate The new due date timestamp for DLG-based groups.
     * @return string
     */
    public static function get_extension_group_name(
        int $sourceid,
        int $totalextension,
        ?int $newduedate = null
    ): string {
        $name = sprintf(
            self::SORA_GROUP_PREFIX . '%d-Extension-%s',
            $sourceid,
            self::formatextensionstime($totalextension)
        );
        if ($newduedate !== null) {
            $name .= '-Due-' . userdate($newduedate, '%Y%m%d%H%M%S');
        }
        return $name;
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

        // Set datasource and create event message model.
        $this->datasource = self::DATASOURCE_AWS;
        $this->raaeventmsg = new raa_event_message($messagedata);
        $this->studentcode = $this->raaeventmsg->get_student_code();
        $this->raarequiredprovisions = $this->raaeventmsg->get_required_provisions();

        // Set user ID and mark data as set.
        $this->set_userid($this->studentcode);
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

        // Set RAA required provisions data.
        $requiredprovisions = $student['student_assessment']['required_provisions'] ?? null;
        if (empty($requiredprovisions)) {
            throw new \moodle_exception('error:missing_required_provisions', 'local_sitsgradepush');
        }
        $this->raarequiredprovisions = new raa_required_provisions($requiredprovisions);
        $this->timeextension = $this->calculate_time_extension();
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

                // Set the SITS mapping ID for the assessment.
                $assessment->set_sits_mapping_id($mapping->id);

                // For status change events from AWS, update SORA extensions for the student.
                if ($this->datasource === self::DATASOURCE_AWS && $this->get_raa_event_message()->has_status_changed()) {
                    // RAA status from non-approved to approved, update SORA extensions.
                    $raastatus = $this->get_raa_event_message()->raastatus;
                    if ($raastatus === self::RAA_STATUS_APPROVED) {
                        extensionmanager::update_sora_for_mapping(
                            $mapping,
                            manager::get_manager()->get_students_from_sits($mapping, true, 2, $this->get_student_code())
                        );
                    }

                    // RAA status changed to non-approved, remove any existing SORA extension for the student.
                    if ($raastatus !== self::RAA_STATUS_APPROVED && in_array($this->get_userid(), $assessment->raauserids)) {
                        $assessment->delete_raa_overrides($this->get_userid());
                    }
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
     * Calculate extension details (new due date and extension time) based on tier configuration.
     *
     * @param assessment $assessment The assessment object.
     * @param int|null $enddate Optional end date override. When provided, this is used instead of
     *                          the assessment's original end date (e.g. a deadline group's due date).
     * @param int|null $startdate Optional start date override for duration calculation.
     * @return array Array with 'newduedate' and 'extensioninsecs' keys.
     * @throws \coding_exception If extension type is invalid or unsupported.
     */
    public function calculate_extension_details(
        assessment $assessment,
        ?int $enddate = null,
        ?int $startdate = null
    ): array {
        $extensiontype = $this->raarequiredprovisions->get_extension_type();
        $enddate = $enddate ?? $assessment->get_end_date();

        $extensioninsecs = match ($extensiontype) {
            raa_required_provisions::EXTENSION_TIME_PER_HOUR => (int) (
                ($assessment->get_assessment_duration($enddate, $startdate) / HOURSECS) *
                $this->raarequiredprovisions->get_time_per_hour_extension() *
                MINSECS
            ),
            raa_required_provisions::EXTENSION_HOURS => $this->raarequiredprovisions->get_hours_extension() * HOURSECS,
            raa_required_provisions::EXTENSION_DAYS => $this->raarequiredprovisions->get_days_extension() * DAYSECS,
            default => throw new \coding_exception(
                get_string('error:extensionunsupportedtype', 'local_sitsgradepush', $extensiontype)
            ),
        };

        // Days extension uses working days calculation, others simply add to end date.
        $newduedate = ($extensiontype === raa_required_provisions::EXTENSION_DAYS)
            ? $this->get_new_duedate($enddate)
            : $enddate + $extensioninsecs;

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
     * @param int|null $newduedate The new due date timestamp for DLG-based groups.
     * @return int The group ID.
     * @throws \moodle_exception If group creation or user addition fails.
     */
    public function get_or_create_sora_group(
        int $courseid,
        int $cmid,
        int $extensioninsecs,
        ?int $newduedate = null
    ): int {
        $groupid = $this->get_sora_group_id(
            $courseid,
            $cmid,
            $this->get_userid(),
            $extensioninsecs,
            $newduedate
        );

        if (!$groupid) {
            throw new \moodle_exception('error:cannotgetsoragroupid', 'local_sitsgradepush');
        }

        return $groupid;
    }

    /**
     * Get new due date for RAA extension.
     *
     * @param int $enddate The original assessment end date timestamp.
     * @return int The new due date timestamp.
     * @throws \coding_exception If the extension type is not days.
     * @throws \moodle_exception If the feedback tracker plugin is not installed.
     */
    public function get_new_duedate(int $enddate): int {
        // Only works for days extension type.
        if ($this->raarequiredprovisions->get_extension_type() !== raa_required_provisions::EXTENSION_DAYS) {
            throw new \coding_exception(get_string('error:extensionnewtduedatedaysonly', 'local_sitsgradepush'));
        }

        // Check if feedback tracker plugin is installed.
        if (!class_exists('\report_feedback_tracker\local\helper')) {
            throw new \moodle_exception('error:extensionfeedbacktrackernotinstalled', 'local_sitsgradepush');
        }

        $closuredays = \report_feedback_tracker\local\helper::get_closuredays();
        $daystoextend = $this->raarequiredprovisions->get_days_extension();
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
     * Get the RAA event message model.
     *
     * @return raa_event_message|null
     */
    public function get_raa_event_message(): ?raa_event_message {
        return $this->raaeventmsg;
    }

    /**
     * Pre-process extension checks.
     *
     * @param array $mappings
     * @return bool true if the checks pass, false otherwise
     */
    protected function pre_process_extension_checks(array $mappings): bool {
        // Basic extension checks from parent if it is AWS message with status change.
        if ($this->datasource === self::DATASOURCE_AWS && $this->get_raa_event_message()->has_status_changed()) {
            return parent::pre_process_extension_checks($mappings);
        }

        // Below checks for normal SORA extension processing, i.e. update on single assessment type.
        // Check required provisions exist.
        if ($this->raarequiredprovisions === null) {
            return false;
        }

        // Check if the assessment type code is eligible for RAA.
         $astcode = $this->raarequiredprovisions->get_assessment_type_code();
        if (empty($astcode) || !extensionmanager::is_ast_code_eligible_for_raa($astcode)) {
            return false;
        }

        return parent::pre_process_extension_checks($mappings);
    }

    /**
     * Calculate the time extension in seconds.
     *
     * @return int
     * @throws \moodle_exception If required provisions are missing.
     */
    private function calculate_time_extension(): int {
        // Check required provisions exist.
        if ($this->raarequiredprovisions === null) {
            throw new \moodle_exception('error:missing_required_provisions', 'local_sitsgradepush');
        }

        if (!$this->raarequiredprovisions->has_extension()) {
            return 0;
        }

        return match ($this->raarequiredprovisions->get_extension_type()) {
            raa_required_provisions::EXTENSION_DAYS => $this->raarequiredprovisions->get_days_extension() * DAYSECS,
            raa_required_provisions::EXTENSION_HOURS => $this->raarequiredprovisions->get_hours_extension() * HOURSECS,
            raa_required_provisions::EXTENSION_TIME_PER_HOUR => $this->raarequiredprovisions->get_time_per_hour_extension() *
                MINSECS,
            default => 0,
        };
    }
}
