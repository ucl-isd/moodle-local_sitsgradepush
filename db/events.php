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
 * Listen moodle events related to grading.
 *
 * @package    local_sits_grade_push
 * @copyright  2023 onwards University College London {@link https://www.ucl.ac.uk/}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @author     Alex Yeung <k.yeung@ucl.ac.uk>
 */
defined('MOODLE_INTERNAL') || die();

$observers = array(
    array(
        'eventname'   => '\mod_assign\event\submission_graded',
        'callback'    => 'local_sits_grade_push::submission_graded',
        'priority'    => 200,
    ),
    array(
        'eventname'   => '\mod_quiz\event\attempt_submitted',
        'callback'    => 'local_sits_grade_push::quiz_attempt_submitted',
        'priority'    => 200,
    ),
    array(
        'eventname'   => '\core\event\user_graded',
        'callback'    => 'local_sits_grade_push::user_graded',
        'priority'    => 200,
    ),
    array(
        'eventname'   => '\mod_quiz\event\attempt_regraded',
        'callback'    => 'local_sits_grade_push::quiz_attempt_regraded',
        'priority'    => 200,
    ),
);
