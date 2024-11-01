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
 * Class for Summary of Reasonable Adjustments (SORA).
 *
 * @package    local_sitsgradepush
 * @copyright  2024 onwards University College London {@link https://www.ucl.ac.uk/}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @author     Alex Yeung <k.yeung@ucl.ac.uk>
 */
class sora extends extension {

    /** @var string Prefix used to create SORA groups */
    const SORA_GROUP_PREFIX = 'DEFAULT-SORA-';

    /** @var int Extra duration in minutes per hour */
    protected int $extraduration;

    /** @var int Rest duration in minutes per hour */
    protected int $restduration;

    /** @var int Time extension in seconds, including extra and rest duration */
    protected int $timeextension;

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
     * @param int $courseid
     * @param int $userid
     *
     * @return int
     * @throws \coding_exception
     * @throws \dml_exception
     * @throws \moodle_exception
     */
    public function get_sora_group_id(int $courseid, int $userid): int {
        global $CFG;
        require_once($CFG->dirroot . '/group/lib.php');

        // Check group exists.
        $groupid = groups_get_group_by_name($courseid, $this->get_extension_group_name());

        if (!$groupid) {
            // Create group.
            $newgroup = new \stdClass();
            $newgroup->courseid = $courseid;
            $newgroup->name = $this->get_extension_group_name();
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

        // Remove user from previous SORA groups.
        $this->remove_user_from_previous_sora_groups($groupid, $courseid, $userid);

        return $groupid;
    }

    /**
     * Get the SORA group name.
     *
     * @return string
     */
    public function get_extension_group_name(): string {
        return sprintf(self::SORA_GROUP_PREFIX . '%d', $this->get_extra_duration() + $this->get_rest_duration());
    }

    /**
     * Set properties from AWS SORA update message.
     *
     * @param string $messagebody
     * @return void
     * @throws \dml_exception
     * @throws \moodle_exception
     */
    public function set_properties_from_aws_message(string $messagebody): void {

        // Decode the JSON message body.
        $messagedata = $this->parse_event_json($messagebody);

        // Check the message is valid.
        if (empty($messagedata->entity->person_sora->sora[0])) {
            throw new \moodle_exception('error:invalid_message', 'local_sitsgradepush', '', null, $messagebody);
        }

        $soradata = $messagedata->entity->person_sora->sora[0];

        // Set properties.
        $this->extraduration = (int) $soradata->extra_duration ?? 0;
        $this->restduration = (int) $soradata->rest_duration ?? 0;

        // A SORA update message must have at least one of the durations.
        if ($this->extraduration == 0 && $this->restduration == 0) {
            throw new \moodle_exception('error:invalid_duration', 'local_sitsgradepush');
        }

        // Calculate and set the time extension in seconds.
        $this->timeextension = $this->calculate_time_extension($this->extraduration, $this->restduration);

        // Set the user ID of the student.
        $this->set_userid($soradata->person->student_code);

        $this->dataisset = true;
    }

    /**
     * Set properties from get students API.
     *
     * @param array $student
     * @return void
     */
    public function set_properties_from_get_students_api(array $student): void {
        // Set the user ID.
        $this->set_userid($student['code']);

        // Set properties.
        $this->extraduration = (int) $student['assessment']['sora_assessment_duration'];
        $this->restduration = (int) $student['assessment']['sora_rest_duration'];

        // Calculate and set the time extension in seconds.
        $this->timeextension = $this->calculate_time_extension($this->get_extra_duration(), $this->get_rest_duration());
        $this->dataisset = true;
    }

    /**
     * Process the extension.
     *
     * @return void
     * @throws \coding_exception
     * @throws \dml_exception
     */
    public function process_extension(): void {
        if (!$this->dataisset) {
            throw new \coding_exception('error:extensiondataisnotset', 'local_sitsgradepush');
        }

        // Get all mappings for the student.
        $mappings = $this->get_mappings_by_userid($this->get_userid());

        // No mappings found.
        if (empty($mappings)) {
            return;
        }

        // Apply the extension to the assessments.
        foreach ($mappings as $mapping) {
            try {
                $assessment = assessmentfactory::get_assessment($mapping->sourcetype, $mapping->sourceid);
                if ($assessment->is_user_a_participant($this->userid)) {
                    $assessment->apply_extension($this);
                }
            } catch (\Exception $e) {
                logger::log($e->getMessage());
            }
        }
    }

    /**
     * Remove the user from previous SORA groups.
     *
     * @param int $newgroupid The new group ID or the group to keep the user in.
     * @param int $courseid The course ID.
     * @param int $userid The user ID.
     * @return void
     * @throws \dml_exception
     */
    protected function remove_user_from_previous_sora_groups(int $newgroupid, int $courseid, int $userid): void {
        global $DB;

        // Find all default SORA groups created by marks transfer.
        $sql = 'SELECT g.id
                FROM {groups} g
                WHERE g.courseid = :courseid
                AND g.name LIKE :name';
        $params = [
            'courseid' => $courseid,
            'name' => self::SORA_GROUP_PREFIX . '%',
        ];
        $soragroups = $DB->get_records_sql($sql, $params);

        foreach ($soragroups as $soragroup) {
            if ($soragroup->id != $newgroupid) {
                groups_remove_member($soragroup->id, $userid);
            }
        }
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
