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

namespace local_sitsgradepush\submission;

use mod_quiz\quiz_attempt;

/**
 * Class for quiz submission.
 *
 * @package    local_sitsgradepush
 * @copyright  2023 onwards University College London {@link https://www.ucl.ac.uk/}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @author     Alex Yeung <k.yeung@ucl.ac.uk>
 */
class quiz extends submission {
    /**
     * Get hand in datetime.
     *
     * @return string
     */
    public function get_handin_datetime(): string {
        if ($this->submissiondata) {
            return $this->get_iso8601_datetime($this->submissiondata->timefinish);
        } else {
            return "";
        }
    }

    /**
     * Set the module instance.
     *
     * @return void
     * @throws \dml_exception
     * @throws \moodle_exception
     */
    protected function set_module_instance() {
        global $DB;
        if (!$quiz = $DB->get_record('quiz', ['id' => $this->coursemodule->instance])) {
            throw new \moodle_exception('Quiz not found, id: ' . $this->coursemodule->instance);
        }

        $this->modinstance = $quiz;
    }

    /**
     * Set submission.
     *
     * @return void
     * @throws \dml_exception
     * @throws \moodle_exception
     */
    protected function set_submission_data() {
        $this->submissiondata = $this->get_best_attempt();
    }

    /**
     * Return the best quiz attempt for a user.
     *
     * @return object
     * @throws \dml_exception
     * @throws \moodle_exception
     */
    private function get_best_attempt() {
        global $CFG, $DB;
        require_once($CFG->dirroot.'/mod/quiz/locallib.php');

        // Get student's quiz attempts.
        $attempts = quiz_get_user_attempts($this->modinstance->id, $this->userid);

        // Throw error if no attempt found.
        if (!empty($attempts)) {
            switch ($this->modinstance->grademethod) {
                // Return the first attempt.
                case QUIZ_ATTEMPTFIRST:
                    return reset($attempts);

                // Return the last attempt.
                case QUIZ_ATTEMPTLAST:
                case QUIZ_GRADEAVERAGE:
                    return end($attempts);

                // Return the highest grade attempt.
                case QUIZ_GRADEHIGHEST:
                default:
                    $maxattempt = null;
                    $maxsumgrades = -1;

                    foreach ($attempts as $attempt) {
                        if ($attempt->sumgrades > $maxsumgrades) {
                            $maxsumgrades = $attempt->sumgrades;
                            $maxattempt = $attempt;
                        }
                    }

                    return $maxattempt;
            }
        }

        return null;
    }
}
