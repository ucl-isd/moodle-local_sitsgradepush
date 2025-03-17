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

        // Save the override.
        $this->get_override_manager()->save_override(['userid' => $ec->get_userid(), 'timeclose' => $newduedate]);
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

        // Determine time limit and new values.
        $hastimelimit = !empty($this->get_time_limit());
        $newtimeclose = $this->get_end_date() + $sora->get_time_extension();
        $newtimelimit = $hastimelimit ? $this->get_time_limit() + $sora->get_time_extension() : null;

        // Total extension in minutes.
        $totalminutes = round($sora->get_time_extension() / MINSECS);

        // Get the group ID.
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

        // Prepare override data.
        $overridedata = [
            'quiz' => $this->get_source_instance()->id,
            'groupid' => $groupid,
            'timelimit' => $newtimelimit,
        ];

        // Case 1: quiz has no time limit set, so set new time close.
        // Case 2: new time limit is greater than the quiz's duration, so set new time close.
        if (!$hastimelimit || $newtimelimit > ($this->get_end_date() - $this->get_start_date())) {
            $overridedata['timeclose'] = $newtimeclose;
        } else {
            // Case 3: new time limit is less than or equal to the quiz's duration, so should not set new time close.
            $overridedata['timeclose'] = null;
        }

        // Check for an existing override.
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
}
