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

use cache;
use local_sitsgradepush\extension\ec;
use local_sitsgradepush\extension\sora;

/**
 * Class for assignment assessment.
 *
 * @package    local_sitsgradepush
 * @copyright  2023 onwards University College London {@link https://www.ucl.ac.uk/}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @author     Alex Yeung <k.yeung@ucl.ac.uk>
 */
class assign extends activity {

    /**
     * Is the user a participant in the assignment.
     *
     * @param int $userid
     * @return bool
     */
    public function is_user_a_participant(int $userid): bool {
        return is_enrolled($this->get_module_context(), $userid, 'mod/assign:submit');
    }

    /**
     * Get all participants.
     *
     * @return array
     */
    public function get_all_participants(): array {
        return get_enrolled_users($this->get_module_context(), 'mod/assign:submit');
    }

    /**
     * Get the start date of this assessment.
     *
     * @return int|null
     */
    public function get_start_date(): ?int {
        return $this->sourceinstance->allowsubmissionsfromdate;
    }

    /**
     * Get the end date of this assessment.
     *
     * @return int|null
     */
    public function get_end_date(): ?int {
        return $this->sourceinstance->duedate;
    }

    /**
     * Apply EC extension to the assessment.
     *
     * @param ec $ec The EC extension.
     * @return void
     */
    protected function apply_ec_extension(ec $ec): void {
        global $CFG;
        require_once($CFG->dirroot . '/mod/assign/locallib.php');
        $originalduedate = $this->get_end_date();

        // EC is using a new deadline without time. Extract the time part of the original due date.
        $time = date('H:i:s', $originalduedate);

        // Get the new date and time.
        $newduedate = strtotime($ec->get_new_deadline() . ' ' . $time);

        // Override the assignment settings for user.
        $this->overrides_due_date($newduedate, $ec->get_userid());
    }

    /**
     * Apply SORA extension to the assessment.
     *
     * @param sora $sora The SORA extension.
     * @return void
     * @throws \moodle_exception
     */
    protected function apply_sora_extension(sora $sora): void {
        global $CFG;
        require_once($CFG->dirroot . '/group/lib.php');

        // Get time extension in seconds.
        $timeextensionperhour = $sora->get_time_extension();

        // Calculate the new due date.
        // Find the difference between the start and end date in hours. Multiply by the time extension per hour.
        $actualextension = (($this->get_end_date() - $this->get_start_date()) / HOURSECS) * $timeextensionperhour;
        $newduedate = $this->get_end_date() + round($actualextension);

        // Get the group id, create if it doesn't exist and add the user to the group.
        $groupid = $sora->get_sora_group_id($this->get_course_id(), $sora->get_userid());

        if (!$groupid) {
            throw new \moodle_exception('error:cannotgetsoragroupid', 'local_sitsgradepush');
        }

        $this->overrides_due_date($newduedate, $sora->get_userid(), $groupid);
    }

    /**
     * Overrides the due date for the user or group.
     *
     * @param int $newduedate The new due date.
     * @param int $userid The user id.
     * @param int|null $groupid The group id.
     * @return void
     */
    private function overrides_due_date(int $newduedate, int $userid, ?int $groupid = null): void {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/mod/assign/locallib.php');
        require_once($CFG->dirroot . '/mod/assign/lib.php');

        // It is a group override.
        if ($groupid) {
            $sql = 'SELECT * FROM {assign_overrides} WHERE assignid = :assignid AND groupid = :groupid AND userid IS NULL';
            $params = [
                'assignid' => $this->get_source_instance()->id,
                'groupid' => $groupid,
            ];
        } else {
            // It is a user override.
            $sql = 'SELECT * FROM {assign_overrides} WHERE assignid = :assignid AND userid = :userid AND groupid IS NULL';
            $params = [
                'assignid' => $this->get_source_instance()->id,
                'userid' => $userid,
            ];
        }

        // Check if the override already exists.
        $override = $DB->get_record_sql($sql, $params);
        if ($override) {
            // No need to update if the due date is the same.
            if ($override->duedate == $newduedate) {
                return;
            }
            $override->duedate = $newduedate;
            $DB->update_record('assign_overrides', $override);
            $newrecord = false;
        } else {
            // Create a new override.
            $override = new \stdClass();
            $override->assignid = $this->get_source_instance()->id;
            $override->duedate = $newduedate;
            $override->userid = $groupid ? null : $userid;
            $override->groupid = $groupid ?: null;
            $override->sortorder = $groupid ? 0 : null;
            $override->id = $DB->insert_record('assign_overrides', $override);

            // Reorder the group overrides.
            if ($groupid) {
                reorder_group_overrides($override->assignid);
            }
            $newrecord = true;
        }

        // Clear the cache.
        $this->clear_override_cache($override);

        // Trigger the event.
        $this->trigger_override_event($override, $newrecord);

        // Update the assign events.
        assign_update_events(new \assign($this->context, $this->get_course_module(), null), $override);
    }

    /**
     * Trigger the override event.
     *
     * @param \stdClass $override The override object.
     * @param bool $newrecord Whether the override is a new record.
     * @return void
     * @throws \coding_exception
     */
    private function trigger_override_event(\stdClass $override, bool $newrecord): void {
        $params = [
            'context' => $this->context,
            'other' => [
                'assignid' => $override->assignid,
            ],
        ];

        $params['objectid'] = $override->id;
        if (!$override->groupid) {
            $params['relateduserid'] = $override->userid;
            if ($newrecord) {
                $event = \mod_assign\event\user_override_created::create($params);
            } else {
                $event = \mod_assign\event\user_override_updated::create($params);
            }
        } else {
            $params['other']['groupid'] = $override->groupid;
            if ($newrecord) {
                $event = \mod_assign\event\group_override_created::create($params);
            } else {
                $event = \mod_assign\event\group_override_updated::create($params);
            }
        }
        $event->trigger();
    }

    /**
     * Clear the override cache.
     *
     * @param \stdClass $override The override object.
     * @return void
     * @throws \coding_exception
     */
    private function clear_override_cache(\stdClass $override): void {
        $cachekey = $override->groupid ?
            "{$override->assignid}_g_{$override->groupid}" : "{$override->assignid}_u_{$override->userid}";
        cache::make('mod_assign', 'overrides')->delete($cachekey);
    }
}
