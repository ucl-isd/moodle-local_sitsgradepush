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

use core\context\module;
use grade_item;
use local_sitsgradepush\extension\ec;
use local_sitsgradepush\extension\sora;
use local_sitsgradepush\extensionmanager;
use local_sitsgradepush\manager;
use moodle_exception;
use moodle_url;

/**
 * Abstract class for activity assessment.
 *
 * @package    local_sitsgradepush
 * @copyright  2023 onwards University College London {@link https://www.ucl.ac.uk/}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @author     Alex Yeung <k.yeung@ucl.ac.uk>
 */
abstract class activity extends assessment {
    /** @var \stdClass Course module object */
    public \stdClass $coursemodule;

    /** @var module Context object */
    public module $context;

    /**
     * Constructor.
     *
     * @param \stdClass $coursemodule
     */
    public function __construct(\stdClass $coursemodule) {
        $this->coursemodule = $coursemodule;
        $this->context = \context_module::instance($this->coursemodule->id);
        parent::__construct(assessmentfactory::SOURCETYPE_MOD, $coursemodule->id);
    }

    /**
     * Returns the assessment name.
     *
     * @return string
     */
    public function get_assessment_name(): string {
        return $this->sourceinstance->name;
    }

    /**
     * Returns the URL to the assessment page.
     *
     * @param bool $escape
     * @return string
     * @throws \moodle_exception
     */
    public function get_assessment_url(bool $escape): string {
        $url = new \moodle_url(
            '/mod/' . $this->get_module_name() . '/view.php',
            ['id' => $this->get_coursemodule_id()]
        );

        return $url->out($escape);
    }

    /**
     * Get the course id.
     *
     * @return int
     */
    public function get_course_id(): int {
        return $this->coursemodule->course;
    }

    /**
     * Get the course module object related to this assessment.
     *
     * @return \stdClass
     */
    public function get_course_module(): \stdClass {
        return $this->coursemodule;
    }

    /**
     * Get the type name of the assessment to display.
     *
     * @return string
     */
    public function get_display_type_name(): string {
        return get_module_types_names()[$this->coursemodule->modname];
    }

    /**
     * Get the module name. E.g. assign, quiz, turnitintooltwo.
     *
     * @return string
     */
    public function get_module_name(): string {
        return $this->coursemodule->modname;
    }

    /**
     * Get the module context.
     *
     * @return module
     */
    public function get_module_context(): module {
        return $this->context;
    }

    /**
     * Get the course module id.
     *
     * @return int
     */
    public function get_coursemodule_id(): int {
        return $this->coursemodule->id;
    }

    /**
     * Get the grade of a user.
     *
     * @param int $userid
     * @param int|null $partid
     * @return array|null
     */
    public function get_user_grade(int $userid, ?int $partid = null): ?array {
        $result = null;
        if (
            $grade = grade_get_grades(
                $this->coursemodule->course,
                'mod',
                $this->coursemodule->modname,
                $this->coursemodule->instance,
                $userid
            )
        ) {
            foreach ($grade->items as $item) {
                foreach ($item->grades as $grade) {
                    if ($grade->grade) {
                        if (is_numeric($grade->grade)) {
                            $manager = manager::get_manager();
                            $formattedmarks = $manager->get_formatted_marks($this->coursemodule->course, (float)$grade->grade);
                            $equivalentgrade = $this->get_equivalent_grade_from_mark((float)$grade->grade);
                            return [$grade->grade, $equivalentgrade, $formattedmarks];
                        }
                    }
                }
            }
        }

        return $result;
    }

    /**
     * Get all grade items related to this assessment.
     *
     * @return array
     */
    public function get_grade_items(): array {
        global $CFG;
        require_once("$CFG->libdir/gradelib.php");

        $gradeitems = grade_item::fetch_all([
            'itemtype' => $this->get_type(),
            'itemmodule' => $this->get_module_name(),
            'iteminstance' => $this->get_source_instance()->id,
            'courseid' => $this->get_course_id(),
        ]);

        return !(empty($gradeitems)) ? $gradeitems : [];
    }

    /**
     * Delete SORA overrides for a mapping.
     *
     * @param \stdClass $mapping
     * @return void
     * @throws \coding_exception
     * @throws \dml_exception|moodle_exception
     */
    public function delete_sora_overrides_for_mapping(\stdClass $mapping): void {
        global $CFG;
        require_once($CFG->dirroot . '/group/lib.php');

        // Find all the sora group overrides for the assessment.
        $overrides = $this->get_assessment_sora_overrides();
        if (empty($overrides)) {
            return;
        }

        // Get MAB from the mapping.
        $mab = manager::get_manager()->get_local_component_grade_by_id($mapping->componentgradeid);

        if (empty($mab)) {
            throw new moodle_exception('error:mab_not_found', 'local_sitsgradepush', '', $mapping->componentgradeid);
        }

        foreach ($overrides as $override) {
            // Remove students coming from that mapping.
            $students = manager::get_manager()->get_students_from_sits($mab, false, 2);
            foreach ($students as $student) {
                groups_remove_member($override->groupid, $student['moodleuserid']);
            }

            // Check no group members.
            if (empty(groups_get_members($override->groupid))) {
                groups_delete_group($override->groupid);
            }
        }
    }

    /**
     * Get the URL to the overrides page.
     *
     * @param string $mode
     * @param bool $escape
     * @return string
     */
    public function get_overrides_page_url(string $mode, bool $escape = true): string {
        $modname = $this->get_module_name();
        $url = new moodle_url("/mod/$modname/overrides.php", ['cmid' => $this->get_coursemodule_id(), 'mode' => $mode]);
        return $url->out($escape);
    }

    /**
     * Check if the assessment has automated SORA override groups created.
     *
     * @return bool
     */
    public function has_sora_override_groups(): bool {
        if (!$this->is_extension_supported()) {
            return false;
        }
        return !empty($this->get_assessment_sora_overrides());
    }

    /**
     * Apply RAA extension to the assessment.
     * Handles common setup: calculates extension details, gets or creates the RAA group,
     * removes the user from previous groups, then delegates the module-specific override
     * application to apply_raa_group_override().
     *
     * @param sora $sora The RAA extension.
     * @return void
     */
    protected function apply_sora_extension(sora $sora): void {
        $extensiondetails = $this->calculate_raa_extension_details($sora);

        // Deadline groups exist but user is not in any, so skip RAA extension application.
        if ($extensiondetails === null) {
            return;
        }

        $extensioninsecs = $extensiondetails['extensioninsecs'];
        $newduedate = $extensiondetails['newduedate'];
        $startdate = $extensiondetails['startdate'] ?? null;

        // Get or create RAA group and add user to it.
        $groupid = $sora->get_or_create_sora_group(
            $this->get_course_id(),
            $this->get_coursemodule_id(),
            $extensioninsecs,
            !empty($extensiondetails['dlg']) ? $newduedate : null
        );

        // Remove user from previous RAA groups.
        $this->remove_user_from_previous_sora_groups($sora->get_userid(), $groupid);

        // Apply the module-specific group override.
        $this->apply_raa_group_override(
            $newduedate,
            $extensioninsecs,
            $groupid,
            $sora->get_userid(),
            $startdate
        );
    }

    /**
     * Apply the module-specific RAA group override.
     * Override this in activity subclasses to apply the appropriate group deadline override.
     *
     * @param int $newduedate The new due date timestamp.
     * @param int $extensioninsecs The extension duration in seconds.
     * @param int $groupid The RAA group ID.
     * @param int $userid The Moodle user ID.
     * @param int|null $startdate The DLG start date to carry forward.
     * @return void
     */
    protected function apply_raa_group_override(
        int $newduedate,
        int $extensioninsecs,
        int $groupid,
        int $userid,
        ?int $startdate = null
    ): void {
        throw new \moodle_exception('error:soraextensionnotsupported', 'local_sitsgradepush');
    }

    /**
     * Check if the assessment has any teacher-created deadline group overrides.
     * A deadline group is identified by the configured prefix (e.g. DLG-).
     * Returns false if the prefix setting is empty, disabling the feature.
     * Subclasses override this to query module-specific override tables.
     *
     * @return bool
     */
    protected function has_deadline_group_overrides(): bool {
        return false;
    }

    /**
     * Get the start and end dates from the highest-priority deadline group override for a user.
     * Returns null if the user is not a member of any deadline group with an override on this assessment.
     * Subclasses override this to query module-specific override tables.
     *
     * @param int $userid The Moodle user ID.
     * @return array|null Array with startdate and enddate keys, or null if none found.
     */
    protected function get_user_deadline_group_dates(int $userid): ?array {
        return null;
    }

    /**
     * Calculate RAA extension details, using deadline group dates if applicable.
     * Returns null if deadline groups exist for this assessment but the user is not in any.
     *
     * @param sora $sora The RAA extension.
     * @return array|null Extension details array or null if extension should be skipped.
     */
    protected function calculate_raa_extension_details(sora $sora): ?array {
        if ($this->has_deadline_group_overrides()) {
            $groupdates = $this->get_user_deadline_group_dates($sora->get_userid());

            // User is not in any deadline group, so skip RAA extension application.
            if ($groupdates === null) {
                return null;
            }
            // Calculate extension details based on the deadline group dates.
            $details = $sora->calculate_extension_details(
                $this,
                $groupdates['enddate'],
                $groupdates['startdate']
            );
            $details['dlg'] = true;
            $details['startdate'] = $groupdates['startdate'];
            return $details;
        }
        // No deadline groups, calculate extension details based on original assessment dates.
        return $sora->calculate_extension_details($this);
    }

    /**
     * Check if the assessment has teacher-created deadline group overrides in a given module override table.
     * Returns false if the deadline group prefix setting is empty.
     *
     * @param string $table The override table name (without prefix brackets).
     * @param string $idfield The module ID field name in the override table.
     * @param int $idvalue The module instance ID.
     * @return bool
     */
    protected function check_deadline_group_overrides(string $table, string $idfield, int $idvalue): bool {
        global $DB;
        $prefix = extensionmanager::get_deadline_group_prefix();
        // If prefix is empty, the feature is disabled, so return false to indicate no overrides.
        if ($prefix === '') {
            return false;
        }
        $sql = "SELECT 1 FROM {{$table}} ot
                JOIN {groups} g ON ot.groupid = g.id
                WHERE ot.{$idfield} = :idvalue AND ot.userid IS NULL AND g.name LIKE :prefix";
        return $DB->record_exists_sql($sql, [
            'idvalue' => $idvalue,
            'prefix' => $DB->sql_like_escape($prefix) . '%',
        ]);
    }

    /**
     * Get the deadline group dates for a user from a single matching override record.
     * Picks the highest-priority record ordered by endfield DESC (non-NULL values first).
     * Falls back to the assessment's original dates if the override does not set a field.
     * Returns null if the user is not a member of any matching deadline group.
     *
     * @param string $table The override table name (without prefix brackets).
     * @param string $idfield The module ID field name in the override table.
     * @param int $idvalue The module instance ID.
     * @param string $endfield The end date field name in the override table.
     * @param string $startfield The start date field name in the override table.
     * @param int $userid The Moodle user ID.
     * @return array|null Array with startdate and enddate keys, or null if not in any deadline group.
     */
    protected function find_user_deadline_group_dates(
        string $table,
        string $idfield,
        int $idvalue,
        string $endfield,
        string $startfield,
        int $userid
    ): ?array {
        global $DB;
        $prefix = extensionmanager::get_deadline_group_prefix();
        if ($prefix === '') {
            return null;
        }

        $sql = "SELECT ot.{$startfield} AS startdate, ot.{$endfield} AS enddate
                FROM {{$table}} ot
                JOIN {groups} g ON ot.groupid = g.id
                JOIN {groups_members} gm ON g.id = gm.groupid
                WHERE ot.{$idfield} = :idvalue AND ot.userid IS NULL
                  AND gm.userid = :userid AND g.name LIKE :prefix
                ORDER BY (CASE WHEN ot.{$endfield} IS NULL THEN 1 ELSE 0 END),
                         ot.{$endfield} DESC";

        $record = $DB->get_record_sql($sql, [
            'idvalue' => $idvalue,
            'userid' => $userid,
            'prefix' => $DB->sql_like_escape($prefix) . '%',
        ], IGNORE_MULTIPLE);

        if (!$record) {
            return null;
        }

        return [
            'startdate' => $record->startdate !== null
                ? (int)$record->startdate : (int)$this->get_start_date(),
            'enddate' => $record->enddate !== null
                ? (int)$record->enddate : (int)$this->get_end_date(),
        ];
    }

    /**
     * Get all RAA group overrides for the assessment from a given module override table.
     *
     * @param string $table The override table name (without prefix brackets).
     * @param string $idfield The module ID field name in the override table.
     * @param int $idvalue The module instance ID.
     * @return array
     */
    protected function find_assessment_raa_overrides(string $table, string $idfield, int $idvalue): array {
        global $DB;
        $sql = "SELECT ot.* FROM {{$table}} ot
                JOIN {groups} g ON ot.groupid = g.id
                WHERE ot.{$idfield} = :idvalue AND ot.userid IS NULL AND g.name LIKE :name";
        return $DB->get_records_sql($sql, [
            'idvalue' => $idvalue,
            'name' => sora::SORA_GROUP_PREFIX . $this->get_id() . '%',
        ]);
    }

    /**
     * Get an override record from a given module override table by user ID and optionally group ID.
     *
     * @param string $table The override table name (without prefix brackets).
     * @param string $idfield The module ID field name in the override table.
     * @param int $idvalue The module instance ID.
     * @param int $userid The Moodle user ID.
     * @param int|null $groupid The Moodle group ID.
     * @return mixed
     */
    protected function find_override_record(
        string $table,
        string $idfield,
        int $idvalue,
        int $userid,
        ?int $groupid = null
    ): mixed {
        global $DB;
        if ($groupid) {
            $sql = "SELECT * FROM {{$table}} WHERE {$idfield} = :idvalue AND groupid = :groupid AND userid IS NULL";
            $params = ['idvalue' => $idvalue, 'groupid' => $groupid];
        } else {
            $sql = "SELECT * FROM {{$table}} WHERE {$idfield} = :idvalue AND userid = :userid";
            $params = ['idvalue' => $idvalue, 'userid' => $userid];
        }
        return $DB->get_record_sql($sql, $params);
    }

    /**
     * Calculate the new due date for an EC extension by combining the EC deadline with the original assessment time.
     *
     * @param ec $ec The EC extension object.
     * @return int The new due date as a Unix timestamp.
     */
    protected function calculate_ec_new_duedate(ec $ec): int {
        $originalduedate = $this->get_end_date();
        $time = date('H:i:s', $originalduedate);
        return strtotime($ec->get_new_deadline() . ' ' . $time);
    }

    /**
     * Get the active EC override for a specific user from the marks transfer overrides table.
     *
     * @param int $userid The Moodle user ID.
     * @return mixed The active EC override record or false if not found.
     */
    protected function get_active_ec_override(int $userid): mixed {
        return extensionmanager::get_active_user_mt_overrides_by_mapid(
            $this->sitsmappingid,
            $this->get_id(),
            extensionmanager::EXTENSION_EC,
            $userid
        );
    }

    /**
     * Set the module instance.
     * @return void
     * @throws \dml_exception
     */
    protected function set_instance(): void {
        global $DB;
        $this->sourceinstance = $DB->get_record($this->coursemodule->modname, ['id' => $this->coursemodule->instance]);
    }

    /**
     * Get the user IDs of gradeable users in this context i.e. students not teachers.
     * @return int[] user IDs
     */
    protected function get_gradeable_user_ids(): array {
        global $DB, $CFG;

        // Code adapted from grade/report/lib.php to limit to users with a gradeable role, i.e. students.
        // The $CFG->gradebookroles setting is exposed on /admin/search.php?query=gradebookroles admin page.
        $gradebookroles = explode(',', $CFG->gradebookroles);
        if (empty($gradebookroles)) {
            return[];
        }
        [$gradebookrolessql, $gradebookrolesparams] =
            $DB->get_in_or_equal($gradebookroles, SQL_PARAMS_NAMED, 'gradebookroles');

        // We want to query both the current context and parent contexts.
        [$relatedctxsql, $relatedctxparams] = $DB->get_in_or_equal(
            $this->context->get_parent_context_ids(true),
            SQL_PARAMS_NAMED,
            'relatedctx'
        );
        $sql = "SELECT DISTINCT userid FROM {role_assignments} WHERE roleid $gradebookrolessql AND contextid $relatedctxsql";
        return  $DB->get_fieldset_sql($sql, array_merge($gradebookrolesparams, $relatedctxparams));
    }

    /**
     * Get the details of gradeable (i.e. students not teachers) enrolled users in this context with specified capability.
     * Can be used to get list of participants where activity has no 'student only' capability like 'mod/xxx:submit'.
     * @param string $capability the capability string e.g. 'mod/lti:view'.
     * @return array user details
     */
    protected function get_gradeable_enrolled_users_with_capability(string $capability): array {
        $enrolledusers = get_enrolled_users($this->context, $capability);
        // Filter out non-gradeable users e.g. teachers.
        $gradeableids = self::get_gradeable_user_ids();
        return array_filter($enrolledusers, function ($u) use ($gradeableids) {
            return in_array($u->id, $gradeableids);
        });
    }

    /**
     * Remove user from previous SORA groups of the assessment.
     * When a user is added to a new group, they should be removed from all other SORA groups of the assessment.
     *
     * @param int $userid User ID.
     * @param int|null $excludedgroupid Group ID to exclude. This is the group that the user is being added to.
     * @return void
     */
    protected function remove_user_from_previous_sora_groups(int $userid, ?int $excludedgroupid = null): void {
        global $CFG;
        require_once($CFG->dirroot . '/group/lib.php');

        // Get all the sora group overrides.
        $overrides = $this->get_assessment_sora_overrides();

        // No overrides to remove.
        if (empty($overrides)) {
            return;
        }

        foreach ($overrides as $override) {
            // Skip the excluded group.
            if ($excludedgroupid && $override->groupid == $excludedgroupid) {
                continue;
            }

            // Remove the user from the previous SORA group.
            groups_remove_member($override->groupid, $userid);

            // Delete the group if it is empty.
            if (empty(groups_get_members($override->groupid))) {
                groups_delete_group($override->groupid);
            }
        }
    }

    /**
     * Delete RAA overrides for a user.
     * Override this method in activity subclasses if not support override groups for that activity.
     *
     * @param int $userid User ID.
     * @return void
     */
    public function delete_raa_overrides(int $userid): void {
        $this->remove_user_from_previous_sora_groups($userid);
    }

    /**
     * Get all SORA override groups for the assessment.
     *
     * @return array
     */
    protected function get_assessment_sora_overrides(): array {
        return [];
    }

    /**
     * Get user IDs who have RAA overrides for this assessment.
     * Override this method in activity subclasses that do not use override groups.
     *
     * @return int[] Array of user IDs.
     */
    protected function get_users_with_raa_overrides(): array {
        global $CFG;
        require_once($CFG->dirroot . '/group/lib.php');

        $userids = [];
        $overrides = $this->get_assessment_sora_overrides();

        foreach ($overrides as $override) {
            $members = groups_get_members($override->groupid, 'u.id');
            foreach ($members as $member) {
                $userids[$member->id] = $member->id;
            }
        }

        return array_values($userids);
    }
}
