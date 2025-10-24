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

namespace local_sitsgradepush\privacy;

use core_privacy\local\metadata\collection;

/**
 * Data provider class.
 *
 * @package    local_sitsgradepush
 * @copyright  2023 onwards University College London {@link https://www.ucl.ac.uk/}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @author     Alex Yeung <k.yeung@ucl.ac.uk>
 */
class provider implements
    \core_privacy\local\metadata\null_provider,
    \core_privacy\local\metadata\provider {
    /**
     * Get the language string identifier with the component's language
     * file to explain why this plugin stores no data.
     *
     * @return  string
     */
    public static function get_reason(): string {
        return 'privacy:metadata';
    }

    /**
     * Returns metadata about this plugin.
     *
     * @param   collection     $collection The initialised collection to add items to.
     * @return  collection     A listing of user data stored through this system.
     */
    public static function get_metadata(collection $collection): collection {
        $collection->add_database_table('local_sitsgradepush_tfr_log', [
            'type' => 'privacy:metadata:local_sitsgradepush_tfr_log:type',
            'userid' => 'privacy:metadata:local_sitsgradepush_tfr_log:userid',
            'request' => 'privacy:metadata:local_sitsgradepush_tfr_log:request',
            'requestbody' => 'privacy:metadata:local_sitsgradepush_tfr_log:requestbody',
            'response' => 'privacy:metadata:local_sitsgradepush_tfr_log:response',
            'usermodified' => 'privacy:metadata:local_sitsgradepush_tfr_log:usermodified',
        ], 'privacy:metadata:local_sitsgradepush_tfr_log');

        $collection->add_database_table('local_sitsgradepush_err_log', [
            'message' => 'privacy:metadata:local_sitsgradepush_err_log:message',
            'errortype' => 'privacy:metadata:local_sitsgradepush_err_log:errortype',
            'requesturl' => 'privacy:metadata:local_sitsgradepush_err_log:requesturl',
            'data' => 'privacy:metadata:local_sitsgradepush_err_log:data',
            'response' => 'privacy:metadata:local_sitsgradepush_err_log:response',
            'userid' => 'privacy:metadata:local_sitsgradepush_err_log:userid',
        ], 'privacy:metadata:local_sitsgradepush_err_log');

        $collection->add_database_table('local_sitsgradepush_tasks', [
            'userid' => 'privacy:metadata:local_sitsgradepush_tasks:userid',
            'status' => 'privacy:metadata:local_sitsgradepush_tasks:status',
            'info' => 'privacy:metadata:local_sitsgradepush_tasks:info',
        ], 'privacy:metadata:local_sitsgradepush_tasks');

        return $collection;
    }
}
