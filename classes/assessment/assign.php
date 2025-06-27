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
use local_sitsgradepush\extensionmanager;

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
     * Delete applied EC override and restore original override if any.
     *
     * @param \stdClass $mtsavedoverride - Override record saved in marks transfer overrides table.
     *
     * @return void
     */
    public function delete_ec_override(\stdClass $mtsavedoverride): void {
        global $DB, $CFG;
        require_once($CFG->dirroot . '/mod/assign/locallib.php');

        // Check if the override record saved in marks transfer overrides table exists.
        $override = $DB->get_record('assign_overrides', ['id' => $mtsavedoverride->overrideid]);

        // Skip if the override does not exist, it might have been deleted by user.
        if (!$override) {
            return;
        }

        // Restore the original override settings if there was pre-existing override stored.
        if (!empty($mtsavedoverride->ori_override_data)) {
            $orioverridedata = json_decode($mtsavedoverride->ori_override_data);
            $orioverridedata->id = $override->id;
            $DB->update_record('assign_overrides', $orioverridedata);
            $this->clear_override_cache($orioverridedata);
            $this->trigger_override_event($orioverridedata, false);
        } else {
            // Delete the override if there is no pre-existing override.
            $assign = new \assign(
                $this->get_module_context(),
                $this->get_course_module(),
                get_course($this->get_course_module()->course)
            );
            $assign->delete_override($override->id);
        }

        // Mark the override as restored.
        $this->mark_override_restored($mtsavedoverride->id);
    }

    /**
     * Get the assignment's override record by user ID and group ID.
     *
     * @param int $userid Moodle user ID
     * @param int|null $groupid Moodle group ID
     *
     * @return mixed
     * @throws \dml_exception
     */
    public function get_override_record(int $userid, ?int $groupid = null): mixed {
        global $DB;
        if ($groupid) {
            $sql = 'SELECT * FROM {assign_overrides} WHERE assignid = :assignid AND groupid = :groupid AND userid IS NULL';
            $params = [
                'assignid' => $this->get_source_instance()->id,
                'groupid' => $groupid,
            ];
        } else {
            $sql = 'SELECT * FROM {assign_overrides} WHERE assignid = :assignid AND userid = :userid';
            $params = [
                'assignid' => $this->get_source_instance()->id,
                'userid' => $userid,
            ];
        }

        return $DB->get_record_sql($sql, $params);
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

        // Pre-existing override.
        $preexistingoverride = $this->get_override_record($ec->get_userid());

        // Override the assignment settings for user.
        $this->overrides_due_date($newduedate, $ec->get_userid());

        // Get the assignment override record.
        $assignoverride = $this->get_override_record($ec->get_userid());

        // Get active EC override for the student if any.
        $mtoverride = extensionmanager::get_active_user_mt_overrides_by_mapid(
            $this->sitsmappingid,
            $this->get_id(),
            extensionmanager::EXTENSION_EC,
            $ec->get_userid()
        );

        // Save override record in marks transfer overrides table.
        $this->save_override($this->sitsmappingid, $ec->get_userid(), $mtoverride, $assignoverride, $preexistingoverride);
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

        // Calculate the new due date.
        $newduedate = $this->get_end_date() + $sora->get_time_extension();

        // Total extension in minutes.
        $totalminutes = round($sora->get_time_extension() / MINSECS);

        // Get the group id, create if it doesn't exist and add the user to the group.
        $groupid = $sora->get_sora_group_id(
            $this->get_course_id(),
            $this->get_coursemodule_id(),
            $sora->get_userid(),
            $totalminutes
        );

        if (!$groupid) {
            throw new \moodle_exception('error:cannotgetsoragroupid', 'local_sitsgradepush');
        }

        // Remove the user from the previous SORA groups.
        $this->remove_user_from_previous_sora_groups($sora->get_userid(), $groupid);
        $this->overrides_due_date($newduedate, $sora->get_userid(), $groupid);
    }

    /**
     * Get all SORA group overrides for the assignment.
     *
     * @return array
     * @throws \dml_exception
     */
    protected function get_assessment_sora_overrides(): array {
        global $DB;
        // Find all the group overrides for the assignment.
        $sql = 'SELECT ao.* FROM {assign_overrides} ao
                JOIN {groups} g ON ao.groupid = g.id
                WHERE ao.assignid = :assignid AND ao.userid IS NULL AND g.name LIKE :name';

        $params = [
            'assignid' => $this->sourceinstance->id,
            'name' => sora::SORA_GROUP_PREFIX . $this->get_id(). '%',
        ];

        // Get all sora group overrides.
        return $DB->get_records_sql($sql, $params);
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

        // Check if the override already exists.
        $override = $this->get_override_record($userid, $groupid);
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
        assign_update_events(
            new \assign(
                $this->get_module_context(),
                $this->get_course_module(),
                get_course($this->get_course_module()->course)
            ),
            $override
        );
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
