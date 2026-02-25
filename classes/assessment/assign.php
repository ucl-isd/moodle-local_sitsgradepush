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
     * Get the cut-off date of this assessment.
     *
     * @return int
     */
    public function get_cut_off_date(): int {
        return $this->sourceinstance->cutoffdate;
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
     */
    public function get_override_record(int $userid, ?int $groupid = null): mixed {
        return $this->find_override_record('assign_overrides', 'assignid', $this->get_source_instance()->id, $userid, $groupid);
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

        // Calculate the new due date.
        $newduedate = $this->calculate_ec_new_duedate($ec);

        // Pre-existing override.
        $preexistingoverride = $this->get_override_record($ec->get_userid());

        // Override the assignment settings for user.
        $this->overrides_due_date($newduedate, $ec->get_userid());

        // Get the assignment override record.
        $assignoverride = $this->get_override_record($ec->get_userid());

        // Get active EC override for the student if any.
        $mtoverride = $this->get_active_ec_override($ec->get_userid());

        // Save override record in marks transfer overrides table.
        $this->save_override(
            extensionmanager::EXTENSION_EC,
            $this->sitsmappingid,
            $ec->get_userid(),
            $mtoverride,
            $assignoverride,
            $preexistingoverride,
            null,
            $ec->get_latest_identifier() ?: null
        );
    }

    /**
     * Check if the assignment has any teacher-created deadline group overrides.
     * Returns false if the deadline group prefix setting is empty.
     *
     * @return bool
     */
    protected function has_deadline_group_overrides(): bool {
        return $this->check_deadline_group_overrides('assign_overrides', 'assignid', $this->get_source_instance()->id);
    }

    /**
     * Get the start and end dates from the highest-priority deadline group override for a user.
     * Assign uses sortorder to determine priority: the lowest sortorder value wins.
     * Falls back to the assessment's original dates if the override does not set them.
     * Returns null if the user is not in any deadline group with an override on this assignment.
     *
     * @param int $userid The Moodle user ID.
     * @return array|null Array with startdate and enddate keys, or null if not in any deadline group.
     */
    protected function get_user_deadline_group_dates(int $userid): ?array {
        global $DB;
        $prefix = extensionmanager::get_deadline_group_prefix();
        if ($prefix === '') {
            return null;
        }
        $sql = "SELECT ao.duedate, ao.allowsubmissionsfromdate
                FROM {assign_overrides} ao
                JOIN {groups} g ON ao.groupid = g.id
                JOIN {groups_members} gm ON g.id = gm.groupid
                WHERE ao.assignid = :assignid AND ao.userid IS NULL
                  AND gm.userid = :userid AND g.name LIKE :prefix
                ORDER BY ao.sortorder ASC";
        $record = $DB->get_record_sql($sql, [
            'assignid' => $this->get_source_instance()->id,
            'userid' => $userid,
            'prefix' => $DB->sql_like_escape($prefix) . '%',
        ], IGNORE_MULTIPLE);
        if (!$record) {
            return null;
        }
        return [
            'startdate' => $record->allowsubmissionsfromdate !== null
                ? (int)$record->allowsubmissionsfromdate : (int)$this->get_start_date(),
            'enddate' => $record->duedate !== null
                ? (int)$record->duedate : (int)$this->get_end_date(),
        ];
    }

    /**
     * Apply the assignment RAA group override.
     *
     * @param int $newduedate The new due date timestamp.
     * @param int $extensioninsecs The extension duration in seconds.
     * @param int $groupid The RAA group ID.
     * @param int $userid The Moodle user ID.
     * @param int|null $startdate The DLG start date to carry forward.
     * @return void
     */
    protected function apply_raa_group_override(
        int $newduedate,
        int $extensioninsecs,
        int $groupid,
        int $userid,
        ?int $startdate = null
    ): void {
        $this->overrides_due_date($newduedate, $userid, $groupid, $startdate);
    }

    /**
     * Get all SORA group overrides for the assignment.
     *
     * @return array
     * @throws \dml_exception
     */
    protected function get_assessment_sora_overrides(): array {
        return $this->find_assessment_raa_overrides('assign_overrides', 'assignid', $this->sourceinstance->id);
    }

    /**
     * Overrides the due date for the user or group.
     *
     * @param int $newduedate The new due date.
     * @param int $userid The user id.
     * @param int|null $groupid The group id.
     * @param int|null $startdate The DLG start date to carry forward.
     * @return void
     */
    private function overrides_due_date(
        int $newduedate,
        int $userid,
        ?int $groupid = null,
        ?int $startdate = null
    ): void {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/mod/assign/locallib.php');
        require_once($CFG->dirroot . '/mod/assign/lib.php');

        // Override cut-off date to align new due date if it is set and earlier than new due date.
        $cutoffdate = null;
        if ($this->get_cut_off_date() > 0 && $this->get_cut_off_date() < $newduedate) {
            $cutoffdate = $newduedate;
        }

        // Check if the override already exists.
        $override = $this->get_override_record($userid, $groupid);
        if ($override) {
            // No need to update if all override values are the same.
            if (
                $override->duedate == $newduedate
                && $override->cutoffdate == $cutoffdate
                && $override->allowsubmissionsfromdate == $startdate
            ) {
                return;
            }
            $override->duedate = $newduedate;
            $override->cutoffdate = $cutoffdate;
            $override->allowsubmissionsfromdate = $startdate;
            $DB->update_record('assign_overrides', $override);
            $newrecord = false;
        } else {
            // Create a new override.
            $override = new \stdClass();
            $override->assignid = $this->get_source_instance()->id;
            $override->duedate = $newduedate;
            $override->cutoffdate = $cutoffdate;
            $override->allowsubmissionsfromdate = $startdate;
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
