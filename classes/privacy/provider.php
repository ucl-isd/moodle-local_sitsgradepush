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

use context;
use context_course;
use core_privacy\local\metadata\collection;
use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\contextlist;
use core_privacy\local\request\transform;
use core_privacy\local\request\userlist;
use core_privacy\local\request\writer;

/**
 * Data provider class.
 *
 * @package    local_sitsgradepush
 * @copyright  2023 onwards University College London {@link https://www.ucl.ac.uk/}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @author     Alex Yeung <k.yeung@ucl.ac.uk>
 */
class provider implements
    \core_privacy\local\metadata\provider,
    \core_privacy\local\request\core_userlist_provider,
    \core_privacy\local\request\plugin\provider {
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

        $collection->add_database_table('local_sitsgradepush_mapping', [
            'userid' => 'privacy:metadata:local_sitsgradepush_mapping:userid',
        ], 'privacy:metadata:local_sitsgradepush_mapping');

        $collection->add_database_table('local_sitsgradepush_enrol', [
            'userid' => 'privacy:metadata:local_sitsgradepush_enrol:userid',
        ], 'privacy:metadata:local_sitsgradepush_enrol');

        $collection->add_database_table('local_sitsgradepush_overrides', [
            'userid' => 'privacy:metadata:local_sitsgradepush_overrides:userid',
        ], 'privacy:metadata:local_sitsgradepush_overrides');

        $collection->add_database_table('local_sitsgradepush_scn', [
            'userid' => 'privacy:metadata:local_sitsgradepush_scn:userid',
            'student_code' => 'privacy:metadata:local_sitsgradepush_scn:student_code',
            'candidate_number' => 'privacy:metadata:local_sitsgradepush_scn:candidate_number',
        ], 'privacy:metadata:local_sitsgradepush_scn');

        $collection->add_database_table('local_sitsgradepush_stuenrol', [
            'courseid' => 'privacy:metadata:local_sitsgradepush_stuenrol:courseid',
            'userid' => 'privacy:metadata:local_sitsgradepush_stuenrol:userid',
            'modcode' => 'privacy:metadata:local_sitsgradepush_stuenrol:modcode',
            'modocc' => 'privacy:metadata:local_sitsgradepush_stuenrol:modocc',
            'academicyear' => 'privacy:metadata:local_sitsgradepush_stuenrol:academicyear',
            'periodslotcode' => 'privacy:metadata:local_sitsgradepush_stuenrol:periodslotcode',
        ], 'privacy:metadata:local_sitsgradepush_stuenrol');

        return $collection;
    }

    /**
     * Get the list of contexts that contain user information for the specified user.
     *
     * @param int $userid The user to search.
     * @return contextlist The contextlist containing the list of contexts used in this plugin.
     */
    public static function get_contexts_for_userid(int $userid): contextlist {
        $contextlist = new contextlist();

        $sql = "SELECT ctx.id
                  FROM {local_sitsgradepush_stuenrol} se
                  JOIN {context} ctx ON ctx.instanceid = se.courseid AND ctx.contextlevel = :contextlevel
                 WHERE se.userid = :userid";
        $contextlist->add_from_sql($sql, [
            'contextlevel' => CONTEXT_COURSE,
            'userid' => $userid,
        ]);

        return $contextlist;
    }

    /**
     * Get the list of users who have data within a context.
     *
     * @param userlist $userlist The userlist containing the list of users who have data in this context.
     * @return void
     */
    public static function get_users_in_context(userlist $userlist): void {
        $context = $userlist->get_context();
        if (!$context instanceof context_course) {
            return;
        }

        $userlist->add_from_sql(
            'userid',
            "SELECT userid FROM {local_sitsgradepush_stuenrol} WHERE courseid = :courseid",
            ['courseid' => $context->instanceid]
        );
    }

    /**
     * Export all user data for the specified user, in the specified contexts.
     *
     * @param approved_contextlist $contextlist The approved contexts to export information for.
     * @return void
     */
    public static function export_user_data(approved_contextlist $contextlist): void {
        global $DB;

        $userid = $contextlist->get_user()->id;

        foreach ($contextlist->get_contexts() as $context) {
            if (!$context instanceof context_course) {
                continue;
            }

            $records = $DB->get_records('local_sitsgradepush_stuenrol', [
                'courseid' => $context->instanceid,
                'userid' => $userid,
            ]);
            if (empty($records)) {
                continue;
            }

            $deliveries = [];
            foreach ($records as $record) {
                $deliveries[] = (object) [
                    'modcode' => $record->modcode,
                    'modocc' => $record->modocc,
                    'academicyear' => $record->academicyear,
                    'periodslotcode' => $record->periodslotcode,
                    'timecreated' => transform::datetime($record->timecreated),
                ];
            }

            writer::with_context($context)->export_data(
                [get_string('privacy:stuenrolpath', 'local_sitsgradepush')],
                (object) ['deliveries' => $deliveries]
            );
        }
    }

    /**
     * Delete all data for all users in the specified context.
     *
     * @param context $context The specific context to delete data for.
     * @return void
     */
    public static function delete_data_for_all_users_in_context(context $context): void {
        global $DB;

        if (!$context instanceof context_course) {
            return;
        }

        $DB->delete_records('local_sitsgradepush_stuenrol', ['courseid' => $context->instanceid]);
    }

    /**
     * Delete all user data for the specified user, in the specified contexts.
     *
     * @param approved_contextlist $contextlist The approved contexts and user to delete information for.
     * @return void
     */
    public static function delete_data_for_user(approved_contextlist $contextlist): void {
        global $DB;

        $userid = $contextlist->get_user()->id;

        foreach ($contextlist->get_contexts() as $context) {
            if (!$context instanceof context_course) {
                continue;
            }

            $DB->delete_records('local_sitsgradepush_stuenrol', [
                'courseid' => $context->instanceid,
                'userid' => $userid,
            ]);
        }
    }

    /**
     * Delete multiple users' data within a single context.
     *
     * @param approved_userlist $userlist The approved context and user information to delete information for.
     * @return void
     */
    public static function delete_data_for_users(approved_userlist $userlist): void {
        global $DB;

        $context = $userlist->get_context();
        if (!$context instanceof context_course) {
            return;
        }

        [$insql, $inparams] = $DB->get_in_or_equal($userlist->get_userids(), SQL_PARAMS_NAMED);
        $params = ['courseid' => $context->instanceid] + $inparams;
        $DB->delete_records_select('local_sitsgradepush_stuenrol', "courseid = :courseid AND userid $insql", $params);
    }
}
