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
 * List of scheduled tasks in this plugin.
 *
 * @package    local_sitsgradepush
 * @copyright  2023 onwards University College London {@link https://www.ucl.ac.uk/}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @author     Alex Yeung <k.yeung@ucl.ac.uk>
 */

defined('MOODLE_INTERNAL') || die;

$tasks = [
    [
        'classname' => 'local_sitsgradepush\task\pushtask',
        'blocking' => 0,
        'minute' => '*',
        'hour' => '*',
        'day' => '*',
        'month' => '*',
        'dayofweek' => '*',
    ],
    [
      'classname' => 'local_sitsgradepush\task\assesstypetask',
      'blocking' => 0,
      'minute' => 0,
      'hour' => '*',
      'day' => '*',
      'month' => '*',
      'dayofweek' => '*',
    ],
    [
      'classname' => 'local_sitsgradepush\task\process_aws_sora_updates',
      'blocking' => 0,
      'minute' => '*/5', // Runs every 5 minutes.
      'hour' => '*',
      'day' => '*',
      'month' => '*',
      'dayofweek' => '*',
    ],
    [
        'classname' => 'local_sitsgradepush\task\process_aws_ec_updates',
        'blocking' => 0,
        'minute' => '*/5', // Runs every 5 minutes.
        'hour' => '*',
        'day' => '*',
        'month' => '*',
        'dayofweek' => '*',
    ],
];
