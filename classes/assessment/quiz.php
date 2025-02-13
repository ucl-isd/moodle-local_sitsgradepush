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
     * Check if the mapping of this assessment should be locked.
     *
     * @param \stdClass $mapping
     * @return bool
     */
    public function should_lock_mapping(\stdClass $mapping): bool {
        // Lock mapping if the current time has passed the cut-off time and the mapping is created before the assessment end date.
        return
            $mapping->enableextension == '1' &&
            $this->clock->time() > $this->get_start_date() - $this->changesourcecutofftime &&
            $mapping->timecreated < $this->get_end_date();
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

        // Calculate the time close for this sora override.
        $newtimeclose = $this->get_end_date() + $sora->get_time_extension();

        // Total extension in minutes.
        $totalminutes = round($sora->get_time_extension() / MINSECS);

        // Get the group id.
        $groupid = $sora->get_sora_group_id(
            $this->get_course_id(),
            $this->get_coursemodule_id(),
            $sora->get_userid(),
            $totalminutes
        );

        if (!$groupid) {
            throw new \moodle_exception('error:cannotgetsoragroupid', 'local_sitsgradepush');
        }

        $overridedata = [
            'quiz' => $this->get_source_instance()->id,
            'groupid' => $groupid,
            'timeclose' => $newtimeclose,
        ];

        // Get the override record if it exists.
        $override = $DB->get_record(
            'quiz_overrides',
            [
                'quiz' => $this->get_source_instance()->id,
                'groupid' => $groupid,
                'userid' => null,
            ]
        );

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
            'name' => sora::SORA_GROUP_PREFIX . '%',
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
