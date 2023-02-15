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

/**
 * Class for local_sitsgradepush observer.
 *
 * @package    local_sitsgradepush
 * @copyright  2023 onwards University College London {@link https://www.ucl.ac.uk/}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @author     Alex Yeung <k.yeung@ucl.ac.uk>
 */
class local_sitsgradepush_observer {
    /**
     * Handle the assignment submission graded event.
     *
     * @param \mod_assign\event\submission_graded $event
     * @return void
     */
    public static function submission_graded(\mod_assign\event\submission_graded $event) {
        // TODO: Handle the assignment submission graded event here.
    }

    /**
     * Handle the quiz attempt submitted event.
     *
     * @param \mod_quiz\event\attempt_submitted $event
     * @return void
     */
    public static function quiz_attempt_submitted(\mod_quiz\event\attempt_submitted $event) {
        // TODO: Handle the quiz attempt submitted event here.
    }

    /**
     * Handle the quiz attempt regraded event.
     *
     * @param \mod_quiz\event\attempt_regraded $event
     * @return void
     */
    public static function quiz_attempt_regraded(\mod_quiz\event\attempt_regraded $event) {
        // TODO: Handle the quiz attempt regraded event here.
    }

    /**
     * Handle the user graded event.
     *
     * @param \core\event\user_graded $event
     * @return void
     */
    public static function user_graded(\core\event\user_graded $event) {
        // Keep it for now just in case we need it.
    }
}
