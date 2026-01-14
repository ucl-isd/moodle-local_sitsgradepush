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
use local_sitsgradepush\manager;
use mod_coursework\event\extension_created;
use mod_coursework\event\extension_updated;
use mod_coursework\models\deadline_extension;
use stdClass;

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
     * @return stdClass[]
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
     * @param stdClass $mtsavedoverride - Override record saved in marks transfer overrides table (local_sitsgradepush_overrides)
     *
     * @return void
     */
    public function delete_ec_override(stdClass $mtsavedoverride): void {
        global $DB;
        $this->process_overrides_deletion([$mtsavedoverride]);

        // If a student has EC/DAP and RAA at the same time, since EC/DAP takes precedence,
        // RAA is ignored when applying EC/DAP.
        // Therefore, when deleting EC/DAP, we need to re-apply RAA if it exists.
        $manager = manager::get_manager();

        // Check if mapping still exists.
        $mapping = $manager->get_mab_and_map_info_by_mapping_id($mtsavedoverride->mapid);
        if (empty($mapping)) {
            return;
        }

        // Find idnumber of the user.
        $studentidnumber = $DB->get_field('user', 'idnumber', ['id' => $mtsavedoverride->userid]);

        // Should not happen but just in case.
        if (empty($studentidnumber)) {
            return;
        }

        extensionmanager::update_sora_for_mapping($mapping, $manager->get_students_from_sits($mapping, true, 2, $studentidnumber));
    }

    /**
     * Get the coursework's override record by user ID.
     *
     * @param int $userid Moodle user ID
     * @param int|null $groupid Not used for coursework
     *
     * @return mixed
     */
    public function get_override_record(int $userid, ?int $groupid = null): mixed {
        global $DB;
        $params = [
            'allocatableid' => $userid,
            'allocatabletype' => 'user',
            'courseworkid' => $this->get_source_instance()->id,
        ];

        return $DB->get_record(self::TABLE_OVERRIDES, $params);
    }

    /**
     * Delete all SORA overrides for a specific mapping.
     *
     * @param stdClass $mapping The assessment mapping.
     * @return void
     */
    public function delete_sora_overrides_for_mapping(stdClass $mapping): void {
        // Get all active SORA overrides for this assessment.
        $conditions = [
            'mapid' => $mapping->id,
            'cmid' => $this->get_id(),
            'extensiontype' => extensionmanager::EXTENSION_SORA,
            'restored_by' => null,
        ];
        $soraoverrides = extensionmanager::get_mt_overrides($conditions);

        // Nothing to do if there are no active SORA overrides.
        if (empty($soraoverrides)) {
            return;
        }

        // Process all RAA overrides deletion.
        $this->process_overrides_deletion($soraoverrides);
    }

    /**
     * Check if the coursework is a group assessment.
     *
     * @return bool
     */
    public function is_group_assessment(): bool {
        $coursework = new \mod_coursework\models\coursework($this->get_source_instance());
        return $coursework->is_configured_to_have_group_submissions();
    }

    /**
     * Get the URL to the overrides page.
     *
     * @param string $mode
     * @param bool $escape
     * @return string
     */
    public function get_overrides_page_url(string $mode, bool $escape = true): string {
        return $this->get_assessment_url($escape);
    }

    /**
     * Delete all RAA overrides or for a specific user if user ID is provided.
     *
     * @param int|null $userid
     * @return void
     */
    public function delete_raa_overrides(?int $userid = null): void {
        // Get active RAA override for this user.
        if ($userid !== null) {
            // Get active RAA override for specific user.
            $activesoraoverride = extensionmanager::get_active_user_mt_overrides_by_mapid(
                $this->sitsmappingid,
                $this->get_id(),
                extensionmanager::EXTENSION_SORA,
                $userid
            );
            $soraoverrides = $activesoraoverride ? [$activesoraoverride] : [];
        } else {
            // Get all active RAA overrides for this assessment.
            $conditions = [
                'mapid' => $this->sitsmappingid,
                'cmid' => $this->get_id(),
                'extensiontype' => extensionmanager::EXTENSION_SORA,
                'restored_by' => null,
            ];
            $soraoverrides = extensionmanager::get_mt_overrides($conditions);
        }

        // Nothing to do if there are no active RAA overrides.
        if (empty($soraoverrides)) {
            return;
        }

        // Process all RAA overrides.
        $this->process_overrides_deletion($soraoverrides);
    }

    /**
     * Get user IDs who have RAA overrides for this assessment.
     * Coursework does not use override groups, so query the marks transfer overrides table.
     *
     * @return int[] Array of user IDs.
     */
    protected function get_users_with_raa_overrides(): array {
        $conditions = [
            'cmid' => $this->get_id(),
            'extensiontype' => extensionmanager::EXTENSION_SORA,
            'restored_by' => null,
        ];
        $overrides = extensionmanager::get_mt_overrides($conditions);

        $userids = [];
        foreach ($overrides as $override) {
            $userids[$override->userid] = $override->userid;
        }

        return array_values($userids);
    }

    /**
     * Apply EC extension to the assessment.
     *
     * @param ec $ec The EC extension.
     * @return void
     */
    protected function apply_ec_extension(ec $ec): void {
        // Do not apply EC if it is a group assessment as ECs are only for individual students.
        // Currently, coursework does not support individual extensions for group assessments.
        if ($this->is_group_assessment()) {
            return;
        }

        // Calculate the new due date.
        $newduedate = $this->calculate_ec_new_duedate($ec);

        // Get existing override for the user if any.
        $preexistingoverride = $this->get_override_record($ec->get_userid());

        // Override the coursework settings for user.
        $this->overrides_due_date($newduedate, $ec->get_userid(), $preexistingoverride);

        // Get the updated coursework override record.
        $courseworkoverride = $this->get_override_record($ec->get_userid());

        // Get active RAA override for the student if any.
        $activesoraoverride = extensionmanager::get_active_user_mt_overrides_by_mapid(
            $this->sitsmappingid,
            $this->get_id(),
            extensionmanager::EXTENSION_SORA,
            $ec->get_userid()
        );

        // If there is an active SORA override, update it to restored state as EC takes precedence.
        if ($activesoraoverride) {
            $preexistingoverride = json_decode($activesoraoverride->ori_override_data) ?: false;
            $this->mark_override_restored($activesoraoverride->id);

            // This will create a new MT override record for the EC, so set $mtoverride to false.
            $mtoverride = false;
        } else {
            // Get active EC override for the student if any.
            $mtoverride = $this->get_active_ec_override($ec->get_userid());
        }

        // Save override record in marks transfer overrides table.
        $this->save_override(
            extensionmanager::EXTENSION_EC,
            $this->sitsmappingid,
            $ec->get_userid(),
            $mtoverride,
            $courseworkoverride,
            $preexistingoverride,
            null,
            $ec->get_latest_identifier() ?: null
        );
    }

    /**
     * Apply SORA extension to the assessment.
     *
     * @param sora $sora The SORA extension.
     * @return void
     */
    protected function apply_sora_extension(sora $sora): void {
        // Do not apply RAA if it is a group assessment as RAA are only for individual students.
        // Currently, coursework does not support individual extensions for group assessments.
        if ($this->is_group_assessment()) {
            return;
        }

        // Check if EC override exists - if yes, ignore RAA.
        $activeecoverride = extensionmanager::get_active_user_mt_overrides_by_mapid(
            $this->sitsmappingid,
            $this->get_id(),
            extensionmanager::EXTENSION_EC,
            $sora->get_userid()
        );

        if ($activeecoverride) {
            return; // EC takes precedence - exit without applying RAA.
        }

        // Get active RAA override from marks transfer table if any.
        $mtoverride = extensionmanager::get_active_user_mt_overrides_by_mapid(
            $this->sitsmappingid,
            $this->get_id(),
            extensionmanager::EXTENSION_SORA,
            $sora->get_userid()
        );

        // Calculate extension details.
        $extensiondetails = $sora->calculate_extension_details($this);
        $newduedate = $extensiondetails['newduedate'];

        // Get existing user extension if any.
        $preexistingoverride = $this->get_override_record($sora->get_userid());

        // Create/update the user extension.
        $this->overrides_due_date($newduedate, $sora->get_userid(), $preexistingoverride);

        // Get the updated coursework override record.
        $courseworkoverride = $this->get_override_record($sora->get_userid());

        // Save override record in marks transfer overrides table.
        $this->save_override(
            extensionmanager::EXTENSION_SORA,
            $this->sitsmappingid,
            $sora->get_userid(),
            $mtoverride,
            $courseworkoverride,
            $preexistingoverride
        );
    }

    /**
     * Overrides the due date for the user.
     *
     * @param int $newduedate The new due date.
     * @param int $userid The user id.
     * @param mixed $preexistingoverride The existing user extension record.
     * @return void
     */
    private function overrides_due_date(int $newduedate, int $userid, mixed $preexistingoverride): void {
        global $USER, $DB;

        // Check if the override already exists.
        if ($preexistingoverride) {
            $override = clone $preexistingoverride;
            // No need to update if the due date is the same.
            if ($override->extended_deadline == $newduedate) {
                return;
            }
            $override->extended_deadline = $newduedate;
            $override->timemodified = $this->clock->time();
            $override->lastmodifiedbyid = $USER->id ?? 0;
            $DB->update_record(self::TABLE_OVERRIDES, $override);
            $newrecord = false;
        } else {
            // Create a new override.
            $override = new stdClass();
            $override->allocatableid = $override->allocatableuser = $userid;
            $override->allocatabletype = 'user';
            $override->courseworkid = $this->get_source_instance()->id;
            $override->extended_deadline = $newduedate;
            $override->createdbyid = $USER->id ?? 0;
            $override->timecreated = $this->clock->time();
            $override->id = $DB->insert_record(self::TABLE_OVERRIDES, $override);
            $newrecord = true;
        }

        // Trigger the event.
        $this->trigger_override_event($override, $newrecord);
        // Clear the override cache.
        $this->clear_override_cache($override);

        // Create / update the personal event in the user's calendar/timeline.
        if (method_exists('mod_coursework/models/coursework', 'update_user_calendar_event')) {
            $modinstance = $this->get_source_instance();

            // As pre-existing extension will be overwritten by EC/DAP/SORA extension,
            // so use the extension deadline from the override.
            $modinstance->update_user_calendar_event(
                $override->allocatableid,
                $override->allocatabletype,
                $override->extended_deadline
            );
        }
    }

    /**
     * Trigger the override event.
     *
     * @param stdClass $overridedata The override object.
     * @param bool $newrecord Whether the override is a new record.
     * @return void
     */
    private function trigger_override_event(stdClass $overridedata, bool $newrecord): void {
        global $USER;

        $coursework = new \mod_coursework\models\coursework($this->get_source_instance());
        $eventparams = [
            'objectid' => $overridedata->id,
            'userid' => $USER->id ?? 0,
            'relateduserid' => $overridedata->allocatableid,
            'context' => $this->context,
            'anonymous' => $coursework->blindmarking_enabled() ? 1 : 0,
            'other' => [
                'allocatabletype' => $overridedata->allocatabletype,
                'courseworkid' => $overridedata->courseworkid,
                'groupid' => null,
                'deadline' => $overridedata->extended_deadline,
            ],
        ];
        if ($newrecord) {
            // Classes may not exist if https://github.com/ucl-isd/moodle-mod_coursework/pull/83 is not yet merged.
            $event = class_exists('mod_coursework\event\extension_created')
                ? extension_created::create($eventparams)
                : null;
        } else {
            $event = class_exists('mod_coursework\event\extension_updated')
                ? extension_updated::create($eventparams)
                : null;
        }
        if ($event) {
            $event->trigger();
        }
    }

    /**
     * Clear the override cache.
     *
     * @param stdClass $override The override object.
     * @return void
     */
    private function clear_override_cache(stdClass $override): void {
        deadline_extension::remove_cache($override->courseworkid);
    }

    /**
     * Restore or delete an override based on whether pre-existing data exists.
     *
     * @param stdClass $mtsavedoverride MT override record.
     * @param stdClass $override The current override record.
     * @return void
     */
    private function restore_or_delete_override(stdClass $mtsavedoverride, stdClass $override): void {
        global $DB;

        // Restore the original override settings if there was pre-existing override stored.
        if (!empty($mtsavedoverride->ori_override_data)) {
            $orioverridedata = json_decode($mtsavedoverride->ori_override_data);
            $orioverridedata->id = $override->id;
            $DB->update_record(self::TABLE_OVERRIDES, $orioverridedata);
            $this->trigger_override_event($orioverridedata, false);
        } else {
            // Delete the override if there is no pre-existing override.
            $DB->delete_records(self::TABLE_OVERRIDES, ['id' => $mtsavedoverride->overrideid]);
        }
        // Clear the override cache.
        $this->clear_override_cache((object) ['courseworkid' => $this->get_source_instance()->id]);
    }

    /**
     * Process RAA overrides by restoring or deleting them and marking as restored in MT table.
     *
     * @param array $soraoverrides Array of active override records from MT table.
     * @return void
     */
    private function process_overrides_deletion(array $soraoverrides): void {
        global $DB;

        foreach ($soraoverrides as $soraoverride) {
            // Check if the extension record still exists in coursework_extensions table.
            $override = $DB->get_record(self::TABLE_OVERRIDES, ['id' => $soraoverride->overrideid]);

            // Extension record does not exist, could have been deleted by user - just mark as restored in MT table.
            if (!$override) {
                $this->mark_override_restored($soraoverride->id);
                continue;
            }

            // Restore or delete the override.
            $this->restore_or_delete_override($soraoverride, $override);

            // Mark the SORA override as restored in MT table.
            $this->mark_override_restored($soraoverride->id);
        }
    }
}
