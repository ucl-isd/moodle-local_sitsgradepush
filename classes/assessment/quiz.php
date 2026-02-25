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
use local_sitsgradepush\extensionmanager;
use mod_quiz\local\override_manager;

/**
 * Class for assessment quiz.
 *
 * @package    local_sitsgradepush
 * @copyright  2023 onwards University College London {@link https://www.ucl.ac.uk/}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @author     Alex Yeung <k.yeung@ucl.ac.uk>
 */
class quiz extends activity {
    /**
     * Is the user a participant in the quiz.
     *
     * @param int $userid
     * @return bool
     */
    public function is_user_a_participant(int $userid): bool {
        return is_enrolled($this->get_module_context(), $userid, 'mod/quiz:attempt');
    }

    /**
     * Get all participants.
     *
     * @return array
     */
    public function get_all_participants(): array {
        return get_enrolled_users($this->get_module_context(), 'mod/quiz:attempt');
    }

    /**
     * Get the start date of this assessment.
     *
     * @return int|null
     */
    public function get_start_date(): ?int {
        return $this->get_source_instance()->timeopen;
    }

    /**
     * Get the end date of this assessment.
     *
     * @return int|null
     */
    public function get_end_date(): ?int {
        return $this->get_source_instance()->timeclose;
    }

    /**
     * Get the time limit of this assessment.
     *
     * @return int|null
     */
    public function get_time_limit(): ?int {
        return $this->get_source_instance()->timelimit;
    }

    /**
     * Delete applied EC override and restore original override if any.
     *
     * @param \stdClass $mtsavedoverride - Override record saved in marks transfer overrides table.
     *
     * @return void
     */
    public function delete_ec_override(\stdClass $mtsavedoverride): void {
        global $DB;

        // Check if the override record saved in marks transfer overrides table exists.
        $override = $DB->get_record('quiz_overrides', ['id' => $mtsavedoverride->overrideid]);

        // Skip if the override does not exist, it might have been deleted by user.
        if (!$override) {
            return;
        }

        // Restore the original override settings if there was pre-existing override.
        if (!empty($mtsavedoverride->ori_override_data)) {
            $orioverridedata = json_decode($mtsavedoverride->ori_override_data, true);
            $orioverridedata['id'] = $override->id;
            $this->get_override_manager()->save_override($orioverridedata);
        } else {
            // Delete the override if there is no pre-existing override.
            $this->get_override_manager()->delete_overrides([$override]);
        }

        // Mark the override as restored.
        $this->mark_override_restored($mtsavedoverride->id);
    }

    /**
     * Get quiz override record by user ID or group ID.
     *
     * @param int $userid Moodle user ID
     * @param int|null $groupid Moodle group ID
     *
     * @return mixed
     * @throws \dml_exception
     */
    public function get_override_record(int $userid, ?int $groupid = null): mixed {
        return $this->find_override_record('quiz_overrides', 'quiz', $this->get_source_instance()->id, $userid, $groupid);
    }

    /**
     * Get the assessment duration in seconds.
     * Returns the lesser of time limit and quiz duration, or quiz duration if no time limit.
     *
     * @param int|null $enddate Optional end date override.
     * @param int|null $startdate Optional start date override.
     * @return int Duration in seconds.
     */
    public function get_assessment_duration(?int $enddate = null, ?int $startdate = null): int {
        $enddate = $enddate ?? $this->get_end_date();
        $startdate = $startdate ?? $this->get_start_date();
        $quizduration = $enddate - $startdate;
        $timelimit = $this->get_time_limit();

        return $timelimit ? min($timelimit, $quizduration) : $quizduration;
    }

    /**
     * Apply EC extension to the quiz.
     *
     * @param ec $ec EC extension object.
     * @return void
     */
    protected function apply_ec_extension(ec $ec): void {
        // Calculate the new due date.
        $newduedate = $this->calculate_ec_new_duedate($ec);

        // Pre-existing override.
        $preexistingoverride = $this->get_override_record($ec->get_userid());

        // If there is a pre-existing override, update it.
        if (!empty($preexistingoverride)) {
            $newoverride = clone $preexistingoverride;
            $newoverride = (array)$newoverride;
            $newoverride['timeclose'] = $newduedate;
        } else {
            $newoverride = [
                'userid' => $ec->get_userid(),
                'timeclose' => $newduedate,
            ];
        }

        // Save the quiz's override.
        $this->get_override_manager()->save_override($newoverride);

        // Get the updated quiz's override record.
        $quizoverride = $this->get_override_record($ec->get_userid());

        // Get active EC override for the student if any using the helper method.
        $mtoverride = $this->get_active_ec_override($ec->get_userid());

        // Save override record in marks transfer overrides table.
        $this->save_override(
            extensionmanager::EXTENSION_EC,
            $this->sitsmappingid,
            $ec->get_userid(),
            $mtoverride,
            $quizoverride,
            $preexistingoverride,
            null,
            $ec->get_latest_identifier() ?: null
        );
    }

    /**
     * Check if the quiz has any teacher-created deadline group overrides.
     * Returns false if the deadline group prefix setting is empty.
     *
     * @return bool
     */
    protected function has_deadline_group_overrides(): bool {
        return $this->check_deadline_group_overrides('quiz_overrides', 'quiz', $this->get_source_instance()->id);
    }

    /**
     * Get the start and end dates from the deadline group overrides for a user.
     * Returns null if the user is not in any deadline group with an override on this quiz.
     *
     * @param int $userid The Moodle user ID.
     * @return array|null Array with startdate and enddate keys, or null if none found.
     */
    protected function get_user_deadline_group_dates(int $userid): ?array {
        return $this->find_user_deadline_group_dates(
            'quiz_overrides',
            'quiz',
            $this->get_source_instance()->id,
            'timeclose',
            'timeopen',
            $userid
        );
    }

    /**
     * Apply the quiz RAA group override.
     *
     * @param int $newduedate The new due date timestamp.
     * @param int $extensioninsecs The extension duration in seconds.
     * @param int $groupid The RAA group ID.
     * @param int $userid The Moodle user ID.
     * @param int|null $startdate The DLG start date to carry forward.
     * Not used for quiz, the final start date is either the original start date or the deadline group override start date.
     * @return void
     */
    protected function apply_raa_group_override(
        int $newduedate,
        int $extensioninsecs,
        int $groupid,
        int $userid,
        ?int $startdate = null
    ): void {
        global $DB;
        $timelimit = $this->get_time_limit();
        $overridedata = [
            'quiz' => $this->get_source_instance()->id,
            'groupid' => $groupid,
            'timelimit' => $timelimit ? $timelimit + $extensioninsecs : null,
            'timeclose' => $newduedate,
        ];

        $override = $DB->get_record('quiz_overrides', [
            'quiz' => $this->get_source_instance()->id,
            'groupid' => $groupid,
            'userid' => null,
        ]);

        if ($override) {
            $overridedata['id'] = $override->id;
        }

        $this->get_override_manager()->save_override($overridedata);
    }

    /**
     * Get all SORA group overrides for the quiz.
     *
     * @return array
     * @throws \dml_exception
     */
    protected function get_assessment_sora_overrides(): array {
        return $this->find_assessment_raa_overrides('quiz_overrides', 'quiz', $this->sourceinstance->id);
    }

    /**
     * Get the quiz override manager.
     *
     * @return override_manager
     * @throws \coding_exception
     */
    private function get_override_manager(): override_manager {
        $quiz = $this->get_source_instance();
        $quiz->cmid = $this->get_coursemodule_id();

        return new override_manager(
            $quiz,
            $this->get_module_context()
        );
    }
}
