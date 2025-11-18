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
        global $DB;
        if ($groupid) {
            $sql = 'SELECT * FROM {quiz_overrides} WHERE quiz = :quiz AND groupid = :groupid AND userid IS NULL';
            $params = [
                'quiz' => $this->get_source_instance()->id,
                'groupid' => $groupid,
            ];
        } else {
            $sql = 'SELECT * FROM {quiz_overrides} WHERE quiz = :quiz AND userid = :userid';
            $params = [
                'quiz' => $this->get_source_instance()->id,
                'userid' => $userid,
            ];
        }

        return $DB->get_record_sql($sql, $params);
    }

    /**
     * Apply EC extension to the quiz.
     *
     * @param ec $ec EC extension object.
     * @return void
     */
    protected function apply_ec_extension(ec $ec): void {
        // EC is using a new deadline without time. Extract the time part from the original deadline.
        $time = date('H:i:s', $this->get_end_date());

        // Get the new date and time.
        $newduedate = strtotime($ec->get_new_deadline() . ' ' . $time);

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

        // Get active EC override for the student if any.
        $mtoverride = extensionmanager::get_active_user_mt_overrides_by_mapid(
            $this->sitsmappingid,
            $this->get_id(),
            extensionmanager::EXTENSION_EC,
            $ec->get_userid()
        );

        // Save override record in marks transfer overrides table.
        $this->save_override($this->sitsmappingid, $ec->get_userid(), $mtoverride, $quizoverride, $preexistingoverride);
    }

    /**
     * Apply SORA extension to the quiz.
     *
     * @param sora $sora SORA extension object.
     * @return void
     * @throws \moodle_exception
     */
    protected function apply_sora_extension(sora $sora): void {
        global $DB;

        $tier = $this->get_assessment_extension_tier();
        if (!$tier) {
            throw new \moodle_exception('error:assessmentextensiontiernotset', 'local_sitsgradepush');
        }

        // Calculate extension details (duration for time_per_hour type).
        $duration = $tier->extensiontype === extensionmanager::RAA_EXTENSION_TYPE_TIME_PER_HOUR
            ? $this->calculate_actual_duration()
            : null;

        $extensiondetails = $sora->calculate_extension_details($tier, $this->get_end_date(), $duration);

        // Get or create SORA group and add user to it.
        $groupid = $sora->get_or_create_sora_group(
            $this->get_course_id(),
            $this->get_coursemodule_id(),
            $extensiondetails['extensioninsecs']
        );

        // Remove user from previous SORA groups.
        $this->remove_user_from_previous_sora_groups($sora->get_userid(), $groupid);

        // Prepare override data.
        $timelimit = $this->get_time_limit();
        $overridedata = [
            'quiz' => $this->get_source_instance()->id,
            'groupid' => $groupid,
            'timelimit' => $timelimit ? $timelimit + $extensiondetails['extensioninsecs'] : null,
            'timeclose' => $extensiondetails['newduedate'],
        ];

        // Check for existing override and update if exists.
        $override = $DB->get_record('quiz_overrides', [
            'quiz' => $this->get_source_instance()->id,
            'groupid' => $groupid,
            'userid' => null,
        ]);

        if ($override) {
            $overridedata['id'] = $override->id;
        }

        // Save the override.
        $this->get_override_manager()->save_override($overridedata);
    }

    /**
     * Get all SORA group overrides for the quiz.
     *
     * @return array
     * @throws \dml_exception
     */
    protected function get_assessment_sora_overrides() {
        global $DB;
        // Find all the group overrides for the quiz.
        $sql = 'SELECT qo.* FROM {quiz_overrides} qo
                JOIN {groups} g ON qo.groupid = g.id
                WHERE qo.quiz = :quizid AND qo.userid IS NULL AND g.name LIKE :name';

        $params = [
            'quizid' => $this->sourceinstance->id,
            'name' => sora::SORA_GROUP_PREFIX . $this->get_id() . '%',
        ];

        // Get all the group overrides except the excluded group.
        return $DB->get_records_sql($sql, $params);
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

    /**
     * Calculate the actual duration to use for time per hour extensions.
     * Returns the lesser of time limit and quiz duration, or quiz duration if no time limit.
     *
     * @return int Duration in seconds.
     */
    private function calculate_actual_duration(): int {
        $quizduration = $this->get_end_date() - $this->get_start_date();
        $timelimit = $this->get_time_limit();

        return $timelimit ? min($timelimit, $quizduration) : $quizduration;
    }
}
