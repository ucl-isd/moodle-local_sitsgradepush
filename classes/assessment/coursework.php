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

use local_sitsgradepush\extension\ec;
use local_sitsgradepush\extension\sora;
use local_sitsgradepush\extensionmanager;
use mod_coursework\models\deadline_extension;

/**
 * Class for coursework plugin (mod_coursework) assessment.
 *
 * @package    local_sitsgradepush
 * @copyright  2024 onwards University College London {@link https://www.ucl.ac.uk/}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @author     David Watson <david-watson@ucl.ac.uk>
 */
class coursework extends activity {

    /**
     * The table where coursework "overrides" are stored.
     * (The coursework plugin calls them extensions and does not have the concept of overrides like assign/quiz).
     */
    const TABLE_OVERRIDES = 'coursework_extensions';

    /**
     * Is the user a participant in the coursework.
     *
     * @param int $userid
     * @return bool
     */
    public function is_user_a_participant(int $userid): bool {
        return is_enrolled($this->get_module_context(), $userid, 'mod/coursework:submit');
    }

    /**
     * Get all participants.
     * @see \mod_coursework\models\coursework::get_students() which we don't use as it returns objects.
     * @return \stdClass[]
     */
    public function get_all_participants(): array {
        $modinfo = get_fast_modinfo($this->get_course_id());
        $cm = $modinfo->get_cm($this->coursemodule->id);
        $info = new \core_availability\info_module($cm);

        $users = get_enrolled_users($this->context, 'mod/coursework:submit');
        return $info->filter_user_list($users);
    }

    /**
     * Get the start date of this assessment.
     *
     * @return int|null
     */
    public function get_start_date(): ?int {
        return $this->sourceinstance->startdate > 0 ? $this->sourceinstance->startdate : null;
    }

    /**
     * Get the end date of this assessment.
     *
     * @return int|null
     */
    public function get_end_date(): ?int {
        return $this->sourceinstance->deadline > 0 ? $this->sourceinstance->deadline : null;
    }

    /**
     * Delete applied EC override and restore original override if any.
     *
     * @param \stdClass $mtsavedoverride - Override record saved in marks transfer overrides table (local_sitsgradepush_overrides)
     *
     * @return void
     */
    public function delete_ec_override(\stdClass $mtsavedoverride): void {
        global $DB;

        // Check if the override record saved in marks transfer overrides table exists.
        $override = $DB->get_record(self::TABLE_OVERRIDES, ['id' => $mtsavedoverride->overrideid]);

        // Skip if the override does not exist, it might have been deleted by user.
        if (!$override) {
            return;
        }

        // Restore the original override settings if there was pre-existing override stored.
        if (!empty($mtsavedoverride->ori_override_data)) {
            $orioverridedata = json_decode($mtsavedoverride->ori_override_data);
            $orioverridedata->id = $override->id;
            $DB->update_record(self::TABLE_OVERRIDES, $orioverridedata);
            $this->clear_override_cache($orioverridedata);
            $this->trigger_override_event($orioverridedata, false);
        } else {
            // Delete the override if there is no pre-existing override.
            $DB->delete_records(self::TABLE_OVERRIDES, ['id' => $mtsavedoverride->overrideid]);
        }

        // Mark the override as restored.
        $this->mark_override_restored($mtsavedoverride->id);
    }

    /**
     * Get the coursework's override record by user ID and group ID.
     *
     * @param int $userid Moodle user ID
     * @param int|null $groupid Moodle group ID
     *
     * @return mixed
     * @throws \dml_exception
     */
    public function get_override_record(int $userid, ?int $groupid = null): mixed {
        global $DB;
        $params = [
            'allocatableid' => $groupid ?: $userid,
            'allocatabletype' => $groupid ? 'group' : 'user',
            'courseworkid' => $this->get_source_instance()->id,
        ];
        $sql = 'SELECT * FROM {' . self::TABLE_OVERRIDES
            . '} WHERE courseworkid = :courseworkid AND allocatableid = :allocatableid AND allocatabletype = :allocatabletype';

        return $DB->get_record_sql($sql, $params);
    }

    /**
     * Apply EC extension to the assessment.
     *
     * @param ec $ec The EC extension.
     * @return void
     */
    protected function apply_ec_extension(ec $ec): void {
        $originalduedate = $this->get_end_date();

        // EC is using a new deadline without time. Extract the time part of the original due date.
        $time = date('H:i:s', $originalduedate);

        // Get the new date and time.
        $newduedate = strtotime($ec->get_new_deadline() . ' ' . $time);

        // Pre-existing override.
        $preexistingoverride = $this->get_override_record($ec->get_userid());

        // Override the coursework settings for user.
        $this->overrides_due_date($newduedate, $ec->get_userid());

        // Get the coursework override record.
        $courseworkoverride = $this->get_override_record($ec->get_userid());

        // Get active EC override for the student if any.
        $mtoverride = extensionmanager::get_active_user_mt_overrides_by_mapid(
            $this->sitsmappingid,
            $this->get_id(),
            extensionmanager::EXTENSION_EC,
            $ec->get_userid()
        );

        // Save override record in marks transfer overrides table.
        $this->save_override($this->sitsmappingid, $ec->get_userid(), $mtoverride, $courseworkoverride, $preexistingoverride);
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
     * Get all SORA group overrides for the coursework.
     *
     * @return array
     * @throws \dml_exception
     */
    protected function get_assessment_sora_overrides(): array {
        global $DB;
        // Find all the group overrides for the coursework.
        // We change some of the field names in the query to align with this plugin's expectations.
        $sqllike = $DB->sql_like('g.name', ':name');
        $sql = "SELECT ov.id, ov.allocatableid as groupid, ov.extended_deadline as duedate
                FROM {" . self::TABLE_OVERRIDES . "} ov
                JOIN {groups} g ON ov.allocatableid = g.id AND ov.allocatabletype = 'group'
                WHERE ov.courseworkid = :courseworkid AND $sqllike";

        $params = [
            'courseworkid' => $this->sourceinstance->id,
            'name' => sora::SORA_GROUP_PREFIX . $this->get_id() . '%',
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
        global $USER, $DB;

        // Check if the override already exists.
        $override = $this->get_override_record($userid, $groupid);
        if ($override) {
            // No need to update if the due date is the same.
            if ($override->extended_deadline == $newduedate) {
                return;
            }
            $override->extended_deadline = $newduedate;
            $override->timemodified = time();
            $override->lastmodifiedbyid = $USER->id ?? 0;
            $DB->update_record(self::TABLE_OVERRIDES, $override);
            $newrecord = false;
        } else {
            // Create a new override.
            $override = new \stdClass();
            $override->allocatableid = $groupid ?: $userid;
            $override->allocatabletype = $groupid ? 'group' : 'user';
            $override->courseworkid = $this->get_source_instance()->id;
            $override->extended_deadline = $newduedate;
            $override->createdbyid = $USER->id ?? 0;
            $override->timecreated = time();
            $override->id = $DB->insert_record(self::TABLE_OVERRIDES, $override);
            $newrecord = true;
        }

        // Trigger the event.
        $this->trigger_override_event($override, $newrecord);

        // Create / update the personal event in the user's calendar/timeline .
        // Includes a check that method exists since at the time of writing, required PR not yet merged.
        // See https://github.com/ucl-isd/moodle-mod_coursework/pull/79.
        if (method_exists('mod_coursework/models/coursework', 'update_user_calendar_event')) {
            $modinstance = $this->get_source_instance();

            // Check if user has extension first.  If so, event date needed is later of extension or new personal deadline.
            $allocatable = $modinstance->get_allocatable();
            $existingextension = \mod_coursework\models\deadline_extension::get_extension_for_student($allocatable, $modinstance);
            $modinstance->update_user_calendar_event(
                $override->allocatableid,
                $override->allocatabletype,
                max($override->extended_deadline, $existingextension->extended_deadline ?? 0)
            );
        }
    }

    /**
     * Trigger the override event.
     *
     * @param \stdClass $overridedata The override object.
     * @param bool $newrecord Whether the override is a new record.
     * @return void
     * @throws \coding_exception
     */
    private function trigger_override_event(\stdClass $overridedata, bool $newrecord): void {
        $eventparams = [
            'objectid' => $overridedata->id,
            'userid' => $USER->id ?? 0,
            'relateduserid' => $overridedata->allocatabletype == 'user' ? $overridedata->allocatableid : null,
            'context' => $this->context,
            'other' => [
                'allocatabletype' => $overridedata->allocatabletype,
                'courseworkid' => $overridedata->courseworkid,
                'groupid' => $overridedata->allocatabletype == 'group' ? $overridedata->allocatableid : null,
                'deadline' => $overridedata->extended_deadline,
            ],
        ];
        if ($newrecord) {
            // Classes may not exist if https://github.com/ucl-isd/moodle-mod_coursework/pull/83 is not yet merged.
            $event = class_exists('mod_coursework\event\extension_created')
                ? \mod_coursework\event\extension_created::create($eventparams)
                : null;
        } else {
            $event = class_exists('mod_coursework\event\extension_updated')
                ? \mod_coursework\event\extension_updated::create($eventparams)
                : null;
        }
        if ($event) {
            $event->trigger();
        }

    }

    /**
     * Clear the override cache.
     *
     * @param \stdClass $override The override object.
     * @return void
     * @throws \coding_exception
     */
    private function clear_override_cache(\stdClass $override): void {
        deadline_extension::remove_cache($override->courseworkid);
    }


    /**
     * Delete all SORA overrides for the assessment.
     *
     * @return void
     * @throws \coding_exception
     * @throws \dml_exception
     */
    public function delete_all_sora_overrides(): void {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/group/lib.php');

        // Find all the sora group overrides for the assessment.
        $overrides = $this->get_assessment_sora_overrides();
        // Delete all sora groups of that assessment from the course.
        if (!empty($overrides)) {
            foreach ($overrides as $override) {
                groups_delete_group($override->groupid);

                // Now group is deleted, associated entries in override table are orphaned so delete.
                $DB->delete_records(
                    self::TABLE_OVERRIDES,
                    ['allocatabletype' => 'group', 'allocatableid' => $override->groupid]
                );
            }
        }
    }
}
