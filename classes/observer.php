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

use core\clock;
use core\di;
use local_sitsgradepush\assessment\assessmentfactory;
use local_sitsgradepush\assesstype;
use local_sitsgradepush\cachemanager;
use local_sitsgradepush\extensionmanager;
use local_sitsgradepush\manager;
use local_sitsgradepush\scnmanager;
use local_sitsgradepush\taskmanager;

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

    /**
     * Handle the assessment mapped event.
     *
     * @param \local_sitsgradepush\event\assessment_mapped  $event
     *
     * @return void
     * @throws \dml_exception|\coding_exception
     */
    public static function assessment_mapped(\local_sitsgradepush\event\assessment_mapped $event): void {
        // Get the data from the event.
        $data = $event->get_data();
        if (empty($data['other']['mabid'])) {
            return;
        }
        $manager = manager::get_manager();
        $mab = $manager->get_local_component_grade_by_id($data['other']['mabid']);

        if (empty($mab)) {
            return;
        }

        // Purge students cache for the mapped assessment component.
        // This is to get the latest student data for the same SITS assessment component.
        // For example, the re-assessment with the same SITS assessment component will have the latest student data
        // instead of using the cached data, such as the resit_number.
        $key = implode('_', [cachemanager::CACHE_AREA_STUDENTSPR, $mab->mapcode, $mab->mabseq]);
        cachemanager::purge_cache(cachemanager::CACHE_AREA_STUDENTSPR, $key);

        // Add the process extensions adhoc task if process extensions is enabled.
        if (extensionmanager::is_extension_enabled()) {
            taskmanager::add_process_extensions_for_new_mapping_adhoc_task($data['other']['mappingid']);
        }

        // Add adhoc task to fetch candidate numbers for the course.
        taskmanager::add_fetch_candidate_numbers_task($data['other']['courseid']);
    }

    /**
     * Handle the grade item updated event.
     *
     * @param \core\event\grade_item_updated $event
     * @return void
     */
    public static function grade_item_updated(\core\event\grade_item_updated $event): void {
        assesstype::grade_item_updated($event);
    }

    /**
     * Handle group override created/updated events for assign, quiz, and lesson.
     * When a teacher creates or updates a deadline group override (identified by the configured prefix),
     * RAA extensions are re-processed for all SITS mappings on that course module.
     *
     * @param \core\event\base $event The group override event.
     * @return void
     */
    public static function group_override_changed(\core\event\base $event): void {
        global $DB;
        // Only process if the event is for a module context (i.e. an override for an activity).
        if ($event->contextlevel !== CONTEXT_MODULE) {
            return;
        }

        $groupid = $event->other['groupid'] ?? null;
        if (empty($groupid) || !self::is_deadline_group($groupid)) {
            return;
        }

        // Find SITS mappings for the course module.
        $cmid = $event->contextinstanceid;
        $mappings = $DB->get_records(
            manager::TABLE_ASSESSMENT_MAPPING,
            ['sourceid' => $cmid, 'enableextension' => 1]
        );

        foreach ($mappings as $mapping) {
            // Delete existing SORA overrides for this mapping to ensure they are re-created with the updated override data.
            try {
                $assessment = assessmentfactory::get_assessment(
                    $mapping->sourcetype,
                    $mapping->sourceid
                );
                $assessment->delete_sora_overrides_for_mapping($mapping);
            } catch (\Exception $e) {
                // Very unlikely to have an error here,
                // but if any error occurs (e.g. assessment not found), skip to the next mapping.
                continue;
            }
            taskmanager::add_process_extensions_for_new_mapping_adhoc_task((int)$mapping->id);
        }
    }

    /**
     * Handle group member added/removed events.
     * When a student is added to or removed from a deadline group, RAA extensions
     * are re-processed for all SITS mappings on assessments that use that group override.
     *
     * @param \core\event\base $event The group member event.
     * @return void
     */
    public static function deadline_group_member_changed(\core\event\base $event): void {
        $groupid = $event->objectid;
        if (empty($groupid) || !self::is_deadline_group($groupid)) {
            return;
        }

        // Find SITS mappings for assessments that have a group override for this group.
        global $DB;
        $sql = "SELECT DISTINCT am.id
                FROM {" . manager::TABLE_ASSESSMENT_MAPPING . "} am
                JOIN {course_modules} cm ON cm.id = am.sourceid
                WHERE am.enableextension = 1
                  AND am.courseid = :courseid
                  AND (
                      EXISTS (
                          SELECT 1 FROM {assign_overrides} ao
                          WHERE ao.groupid = :groupid1 AND ao.assignid = cm.instance
                            AND am.moduletype = 'assign'
                      )
                      OR EXISTS (
                          SELECT 1 FROM {quiz_overrides} qo
                          WHERE qo.groupid = :groupid2 AND qo.quiz = cm.instance
                            AND am.moduletype = 'quiz'
                      )
                      OR EXISTS (
                          SELECT 1 FROM {lesson_overrides} lo
                          WHERE lo.groupid = :groupid3 AND lo.lessonid = cm.instance
                            AND am.moduletype = 'lesson'
                      )
                  )";

        $courseid = $event->courseid;
        $mappings = $DB->get_records_sql($sql, [
            'courseid' => $courseid,
            'groupid1' => $groupid,
            'groupid2' => $groupid,
            'groupid3' => $groupid,
        ]);

        foreach ($mappings as $mapping) {
            taskmanager::add_process_extensions_for_new_mapping_adhoc_task((int)$mapping->id);
        }
    }

    /**
     * Handle deadline group deleted event.
     * When a deadline group is deleted from a course, clear RAA overrides
     * and re-process extensions for all SITS mappings in that course.
     *
     * @param \core\event\base $event The group deleted event.
     * @return void
     */
    public static function deadline_group_deleted(\core\event\base $event): void {
        global $DB;

        if (!extensionmanager::is_extension_enabled()) {
            return;
        }

        // Check if the deleted group was a deadline group by its name.
        $prefix = extensionmanager::get_deadline_group_prefix();
        if ($prefix === '') {
            return;
        }

        $group = $event->get_record_snapshot('groups', $event->objectid);
        if (!$group || !str_starts_with($group->name, $prefix)) {
            return;
        }

        // Find all extension-enabled SITS mappings for this course.
        $mappings = $DB->get_records(
            manager::TABLE_ASSESSMENT_MAPPING,
            ['courseid' => $event->courseid, 'enableextension' => 1]
        );

        foreach ($mappings as $mapping) {
            try {
                $assessment = assessmentfactory::get_assessment(
                    $mapping->sourcetype,
                    $mapping->sourceid
                );

                // Skip assessments in the past.
                if ($assessment->get_end_date() < di::get(clock::class)->time()) {
                    continue;
                }

                $assessment->delete_sora_overrides_for_mapping($mapping);
            } catch (\Exception $e) {
                // If any error occurs (e.g. assessment not found), skip to the next mapping.
                continue;
            }
            taskmanager::add_process_extensions_for_new_mapping_adhoc_task((int)$mapping->id);
        }
    }

    /**
     * Check if a group is a teacher-created deadline group.
     * Returns false if extensions are disabled or the prefix setting is empty.
     *
     * @param int $groupid The Moodle group ID.
     * @return bool
     */
    private static function is_deadline_group(int $groupid): bool {
        global $DB;
        if (!extensionmanager::is_extension_enabled()) {
            return false;
        }

        $prefix = extensionmanager::get_deadline_group_prefix();
        if ($prefix === '') {
            return false;
        }
        $groupname = $DB->get_field('groups', 'name', ['id' => $groupid]);

        return $groupname && str_starts_with($groupname, $prefix);
    }
}
