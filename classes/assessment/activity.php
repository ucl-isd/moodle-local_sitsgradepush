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
use local_sitsgradepush\manager;
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
        if ($grade = grade_get_grades(
            $this->coursemodule->course, 'mod', $this->coursemodule->modname, $this->coursemodule->instance, $userid)) {
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
     * Delete all SORA overrides for the assessment.
     *
     * @return void
     * @throws \coding_exception
     * @throws \dml_exception
     */
    public function delete_all_sora_overrides(): void {
        global $CFG;
        require_once($CFG->dirroot . '/group/lib.php');

        // Find all the sora group overrides for the assessment.
        $overrides = $this->get_assessment_sora_overrides();

        // Delete all sora groups of that assessment from the course.
        if (!empty($overrides)) {
            foreach ($overrides as $override) {
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
        list($gradebookrolessql, $gradebookrolesparams) =
            $DB->get_in_or_equal($gradebookroles, SQL_PARAMS_NAMED, 'gradebookroles');

        // We want to query both the current context and parent contexts.
        list($relatedctxsql, $relatedctxparams) = $DB->get_in_or_equal(
            $this->context->get_parent_context_ids(true), SQL_PARAMS_NAMED, 'relatedctx'
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
        return array_filter($enrolledusers, function($u) use ($gradeableids) {
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
}
