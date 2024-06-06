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
 * Web services for local_sitsgradepush.
 *
 * @package    local_sitsgradepush
 * @copyright  2023 onwards University College London {@link https://www.ucl.ac.uk/}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @author     Alex Yeung <k.yeung@ucl.ac.uk>
 */

defined('MOODLE_INTERNAL') || die();

// We defined the web service functions to install.
$functions = [
    'local_sitsgradepush_schedule_push_task' => [
        'classname' => 'local_sitsgradepush\external\schedule_push_task',
        'description' => 'Schedule a push task',
        'ajax' => true,
        'type' => 'write',
        'loginrequired' => true,
    ],
    'local_sitsgradepush_map_assessment' => [
        'classname' => 'local_sitsgradepush\external\map_assessment',
        'description' => 'Map assessment',
        'ajax' => true,
        'type' => 'write',
        'loginrequired' => true,
    ],
    'local_sitsgradepush_get_assessments_update' => [
        'classname' => 'local_sitsgradepush\external\get_assessments_update',
        'description' => 'Get assessment updates for a given course / course module',
        'ajax' => true,
        'type' => 'read',
        'readonlysession' => true,
        'loginrequired' => true,
    ],
];
