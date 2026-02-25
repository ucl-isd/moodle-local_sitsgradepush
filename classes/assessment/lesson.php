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
use mod_lesson\event\group_override_created;
use mod_lesson\event\group_override_updated;
use mod_lesson\event\user_override_created;
use mod_lesson\event\user_override_updated;

/**
 * Class for lesson assessment.
 *
 * @package    local_sitsgradepush
 * @copyright  2024 onwards University College London {@link https://www.ucl.ac.uk/}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @author     David Watson <david-watson@ucl.ac.uk>
 */
class lesson extends activity {
    /**
     * Is the user a participant in the lesson.
     *
     * @param int $userid
     * @return bool
     */
    public function is_user_a_participant(int $userid): bool {
        return in_array($userid, array_keys($this->get_all_participants()));
    }

    /**
     * Get all participants.
     *
     * @return array
     */
    public function get_all_participants(): array {
        if ($this->sourceinstance->practice) {
            // If this is a "Practice Lesson" it does not appear in gradebook.
            return [];
        }
        return self::get_gradeable_enrolled_users_with_capability('mod/lesson:view');
    }

    /**
     * Get the start date of this assessment.
     *
     * @return int|null
     */
    public function get_start_date(): ?int {
        return !$this->sourceinstance->practice && $this->sourceinstance->available > 0
            ? $this->sourceinstance->available : null;
    }

    /**
     * Get the end date of this assessment.
     *
     * @return int|null
     */
    public function get_end_date(): ?int {
        return !$this->sourceinstance->practice && $this->sourceinstance->deadline > 0
            ? $this->sourceinstance->deadline : null;
    }

    /**
     * Get the time limit of this assessment.
     *
     * @return int|null
     */
    public function get_time_limit(): ?int {
        return !$this->sourceinstance->practice && $this->sourceinstance->timelimit > 0
            ? $this->sourceinstance->timelimit : null;
    }

    /**
     * Check assessment is valid for mapping.
     *
     * @return \stdClass
     */
    public function check_assessment_validity(): \stdClass {
        if ($this->sourceinstance->practice) {
            return $this->set_validity_result(false, 'error:lesson_practice');
        }
        return parent::check_assessment_validity();
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
        require_once($CFG->dirroot . '/mod/lesson/locallib.php');

        // Check if the override record saved in marks transfer overrides table exists.
        $override = $DB->get_record('lesson_overrides', ['id' => $mtsavedoverride->overrideid]);

        // Skip if the override does not exist, it might have been deleted by user.
        if (!$override) {
            return;
        }

        // Restore the original override settings if there was pre-existing override stored.
        if (!empty($mtsavedoverride->ori_override_data)) {
            $orioverridedata = json_decode($mtsavedoverride->ori_override_data);
            $orioverridedata->id = $override->id;
            $DB->update_record('lesson_overrides', $orioverridedata);
            $this->clear_override_cache($orioverridedata);
            $this->trigger_override_event($orioverridedata, false);
        } else {
            // Delete the override if there is no pre-existing override.
            $lesson = new \lesson($this->get_source_instance());
            $lesson->delete_override($override->id);
        }

        // Mark the override as restored.
        $this->mark_override_restored($mtsavedoverride->id);
    }

    /**
     * Get the lesson's override record by user ID and group ID.
     *
     * @param int $userid Moodle user ID
     * @param int|null $groupid Moodle group ID
     *
     * @return mixed
     */
    public function get_override_record(int $userid, ?int $groupid = null): mixed {
        return $this->find_override_record(
            'lesson_overrides',
            'lessonid',
            $this->get_source_instance()->id,
            $userid,
            $groupid
        );
    }

    /**
     * Apply EC extension to the lesson.
     *
     * @param ec $ec The EC extension.
     * @return void
     */
    protected function apply_ec_extension(ec $ec): void {
        global $CFG;
        require_once($CFG->dirroot . '/mod/lesson/locallib.php');

        // Calculate the new due date.
        $newduedate = $this->calculate_ec_new_duedate($ec);

        // Pre-existing override.
        $preexistingoverride = $this->get_override_record($ec->get_userid());

        // Override the lesson settings for user.
        $this->override_deadline($newduedate, null, $ec->get_userid());

        // Get the lesson override record.
        $lessonoverride = $this->get_override_record($ec->get_userid());

        // Get active EC override for the student if any.
        $mtoverride = $this->get_active_ec_override($ec->get_userid());

        // Save override record in marks transfer overrides table.
        $this->save_override(
            extensionmanager::EXTENSION_EC,
            $this->sitsmappingid,
            $ec->get_userid(),
            $mtoverride,
            $lessonoverride,
            $preexistingoverride,
            null,
            $ec->get_latest_identifier() ?: null
        );
    }

    /**
     * Check if the lesson has any teacher-created deadline group overrides.
     * Returns false if the deadline group prefix setting is empty.
     *
     * @return bool
     */
    protected function has_deadline_group_overrides(): bool {
        return $this->check_deadline_group_overrides('lesson_overrides', 'lessonid', $this->get_source_instance()->id);
    }

    /**
     * Get the start and end dates from the deadline group overrides for a user.
     * Returns null if the user is not in any deadline group with an override on this lesson.
     *
     * @param int $userid The Moodle user ID.
     * @return array|null Array with startdate and enddate keys, or null if none found.
     */
    protected function get_user_deadline_group_dates(int $userid): ?array {
        return $this->find_user_deadline_group_dates(
            'lesson_overrides',
            'lessonid',
            $this->get_source_instance()->id,
            'deadline',
            'available',
            $userid
        );
    }

    /**
     * Apply the lesson RAA group override.
     *
     * @param int $newduedate The new due date timestamp.
     * @param int $extensioninsecs The extension duration in seconds.
     * @param int $groupid The RAA group ID.
     * @param int $userid The Moodle user ID.
     * @param int|null $startdate The DLG start date to carry forward.
     * Not used for lesson, the final start date is either the original start date or the deadline group override start date.
     * @return void
     */
    protected function apply_raa_group_override(
        int $newduedate,
        int $extensioninsecs,
        int $groupid,
        int $userid,
        ?int $startdate = null
    ): void {
        $timelimit = $this->get_time_limit() ? $this->get_time_limit() + $extensioninsecs : null;
        $this->override_deadline($newduedate, $timelimit, 0, $groupid);
    }

    /**
     * Get all SORA group overrides for the lesson.
     *
     * @return array
     */
    protected function get_assessment_sora_overrides(): array {
        return $this->find_assessment_raa_overrides('lesson_overrides', 'lessonid', $this->sourceinstance->id);
    }

    /**
     * Get the assessment duration, which is the time between start and end date,
     * or the time limit if it is shorter than the time between start and end date.
     *
     * @param int|null $enddate Optional end date override.
     * @param int|null $startdate Optional start date override.
     * @return int The assessment duration in seconds.
     */
    public function get_assessment_duration(?int $enddate = null, ?int $startdate = null): int {
        $enddate = $enddate ?? $this->get_end_date();
        $startdate = $startdate ?? $this->get_start_date();
        $lessonduration = $enddate - $startdate;
        $timelimit = $this->get_time_limit();

        return $timelimit ? min($timelimit, $lessonduration) : $lessonduration;
    }

    /**
     * Override the deadline for the user or group.
     *
     * @param int $newdeadline The new deadline.
     * @param ?int $timelimit The time limit in seconds, if applicable.
     * @param int $userid The user id.
     * @param ?int $groupid The group id.
     * @return void
     */
    private function override_deadline(int $newdeadline, ?int $timelimit, int $userid, ?int $groupid = null): void {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/mod/lesson/locallib.php');
        require_once($CFG->dirroot . '/mod/lesson/lib.php');

        // Check if the override already exists.
        $override = $this->get_override_record($userid, $groupid);
        if ($override) {
            // No need to update if the deadline and time limit are the same.
            if ($override->deadline == $newdeadline && $override->timelimit == $timelimit) {
                return;
            }
            $override->deadline = $newdeadline;
            $override->timelimit = $timelimit;
            $DB->update_record('lesson_overrides', $override);
            $newrecord = false;
        } else {
            // Create a new override.
            $override = new \stdClass();
            $override->lessonid = $this->get_source_instance()->id;
            $override->deadline = $newdeadline;
            $override->timelimit = $timelimit;
            $override->userid = $groupid ? null : $userid;
            $override->groupid = $groupid ?: null;
            $override->id = $DB->insert_record('lesson_overrides', $override);
            $newrecord = true;
        }

        // Clear the cache.
        $this->clear_override_cache($override);

        // Trigger the event.
        $this->trigger_override_event($override, $newrecord);

        // Update the lesson events.
        $lesson = new \lesson($this->get_source_instance());
        lesson_update_events($lesson, $override);
    }

    /**
     * Trigger the override event.
     *
     * @param \stdClass $override The override object.
     * @param bool $newrecord Whether the override is a new record.
     * @return void
     */
    private function trigger_override_event(\stdClass $override, bool $newrecord): void {
        $params = [
            'context' => $this->context,
            'objectid' => $override->id,
            'other' => ['lessonid' => $override->lessonid],
        ];

        if (!$override->groupid) {
            $params['relateduserid'] = $override->userid;
            $eventclass = $newrecord ? user_override_created::class : user_override_updated::class;
        } else {
            $params['other']['groupid'] = $override->groupid;
            $eventclass = $newrecord ? group_override_created::class : group_override_updated::class;
        }

        $eventclass::create($params)->trigger();
    }

    /**
     * Clear the override cache.
     *
     * @param \stdClass $override The override object.
     * @return void
     */
    private function clear_override_cache(\stdClass $override): void {
        $cachekey = $override->groupid ?
            "{$override->lessonid}_g_{$override->groupid}" : "{$override->lessonid}_u_{$override->userid}";
        cache::make('mod_lesson', 'overrides')->delete($cachekey);
    }
}
