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

namespace local_sitsgradepush;

use core_plugin_manager;
use local_assess_type\assess_type;
use local_sitsgradepush\assessment\assessmentfactory;

/**
 * Assessment type class for assessment categorization.
 *
 * @package     local_sitsgradepush
 * @copyright   2024 onwards University College London {@link https://www.ucl.ac.uk/}
 * @license     https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @author      Alex Yeung <k.yeung@ucl.ac.uk>
 */
class assesstype {

    /** @var string Action lock */
    const ACTION_LOCK = 'lock';

    /** @var string Action unlock */
    const ACTION_UNLOCK = 'unlock';

    /**
     * Update assessment type and lock status.
     *
     * @param int|\stdClass $mapping Mapping record ID or object.
     * @param string $action Action to perform.
     *
     * @throws \dml_exception
     */
    public static function update_assess_type(int|\stdClass $mapping, string $action): void {
        global $DB;

        try {
            if (!self::is_assess_type_installed()) {
                return;
            }

            // Get mapping record if it is not already an object.
            if (is_int($mapping)) {
                $mapping = $DB->get_record(manager::TABLE_ASSESSMENT_MAPPING, ['id' => $mapping]);
            }

            // Mapping is not found. It should not happen.
            if (empty($mapping)) {
                return;
            }

            // Set course module ID if the mapped assessment is a course module, otherwise set to 0.
            $cmid = $mapping->sourcetype === assessmentfactory::SOURCETYPE_MOD ? $mapping->sourceid : 0;

            // Everything that is not a course module is a grade item or category.
            $gradeitemid = $cmid ? 0 : assessmentfactory::get_assessment($mapping->sourcetype, $mapping->sourceid)
                ->get_grade_items()[0]->id;

            $lockstatus = $action === self::ACTION_LOCK ? 1 : 0;
            assess_type::update_type($mapping->courseid, assess_type::ASSESS_TYPE_SUMMATIVE, $cmid, $gradeitemid, $lockstatus);

            // Update assess type for items (grade items or activities) under the grade category.
            if ($mapping->sourcetype === assessmentfactory::SOURCETYPE_GRADE_CATEGORY) {
                self::update_assess_type_items_under_gradecategory($action, $mapping->sourceid);
            }
        } catch (\Exception $e) {
            logger::log('Failed to update assessment type and lock status.', null, null, $e->getMessage());
        }
    }

    /**
     * Check if the assessment type plugin is installed.
     *
     * @return bool
     */
    public static function is_assess_type_installed(): bool {
        // Check if the assessment type plugin is installed.
        return (bool)core_plugin_manager::instance()->get_plugin_info(
          'local_assess_type'
        );
    }

    /**
     * Update assessment type and lock status for grade item when it is updated outside marks transfer.
     *
     * @param \core\event\grade_item_updated $event
     * @return void
     * @throws \coding_exception
     * @throws \dml_exception
     */
    public static function grade_item_updated(\core\event\grade_item_updated $event): void {
        global $DB;

        // Skip if assessment type plugin is not installed.
        if (!self::is_assess_type_installed()) {
            return;
        }

        $gradeitem = $event->get_record_snapshot('grade_items', $event->objectid);

        // Determine source type and source ID based on item type.
        switch ($gradeitem->itemtype) {
            case 'manual':
                $sourcetype = assessmentfactory::SOURCETYPE_GRADE_ITEM;
                $sourceid = $gradeitemid = $gradeitem->id;
                $cmid = 0;
                break;
            case 'mod':
                $sourcetype = assessmentfactory::SOURCETYPE_MOD;
                $sourceid = $cmid = get_coursemodule_from_instance(
                    $gradeitem->itemmodule,
                    $gradeitem->iteminstance,
                    $gradeitem->courseid
                )->id;
                $gradeitemid = 0;
                break;
            default:
                return;
        }

        // Skip if grade item or activity is mapped. It will be handled by the mapping / unmapping actions.
        if ($DB->record_exists(manager::TABLE_ASSESSMENT_MAPPING, ['sourcetype' => $sourcetype, 'sourceid' => $sourceid])) {
            return;
        }

        // Depending on the grade item's category. If the category is mapped, mark it summative and lock it.
        // Otherwise, unlock it.
        $action = $DB->record_exists(
            manager::TABLE_ASSESSMENT_MAPPING,
            ['sourcetype' => assessmentfactory::SOURCETYPE_GRADE_CATEGORY, 'sourceid' => $gradeitem->categoryid]
        ) ? self::ACTION_LOCK : self::ACTION_UNLOCK;

        // We only want to unlock grade items or activities that are summative and have been locked.
        if ($action === self::ACTION_UNLOCK) {
            $assesstyperecords = assess_type::get_assess_type_records_by_courseid(
                $gradeitem->courseid,
                assess_type::ASSESS_TYPE_SUMMATIVE
            );

            if (empty(array_filter(
                $assesstyperecords,
                fn($record) => $record->cmid == $cmid && $record->gradeitemid == $gradeitemid && $record->locked))
            ) {
                return;
            }
        }

        self::update_assess_type_items_under_gradecategory($action, $gradeitem->categoryid, $gradeitem);
    }

    /**
     * Update assess type for grade items and activities under a grade category.
     *
     * @param string $action
     * @param int $categoryid
     * @param \stdClass|null $gradeitem
     * @return void
     * @throws \coding_exception
     * @throws \dml_exception
     */
    private static function update_assess_type_items_under_gradecategory(
        string $action,
        int $categoryid,
        ?\stdClass $gradeitem = null
    ): void {
        $lockstatus = $action === self::ACTION_LOCK ? 1 : 0;
        $gradeitems = $gradeitem ? [$gradeitem] : \grade_item::fetch_all(['categoryid' => $categoryid]);

        foreach ($gradeitems as $item) {
            if (!in_array($item->itemtype, ['mod', 'manual'])) {
                continue;
            }

            // Workshop can have multiple grade items under the same category. Only unlock the workshop if it is the
            // only grade item under the category.
            if ($action === self::ACTION_UNLOCK && $item->itemmodule === 'workshop') {
                if (!self::should_unlock_workshop($item->iteminstance)) {
                    continue;
                }
            }

            $cmid = $item->itemtype === 'mod'
                ? get_coursemodule_from_instance($item->itemmodule, $item->iteminstance, $item->courseid)->id
                : 0;
            $gradeitemid = $item->itemtype === 'manual' ? $item->id : 0;

            assess_type::update_type(
                $item->courseid,
                assess_type::ASSESS_TYPE_SUMMATIVE,
                $cmid,
                $gradeitemid,
                $lockstatus
            );
        }
    }

    /**
     * Check if the workshop should be unlocked. A workshop should be unlocked if there is no other grade item under
     * a category that has been mapped.
     *
     * @param int $workshopinstanceid Workshop instance ID.
     * @return bool
     * @throws \dml_exception
     */
    private static function should_unlock_workshop(int $workshopinstanceid): bool {
        global $DB;
        // Check if there is any grade item under the workshop that has category mapped.
        $sql = "SELECT gi.id
                FROM {grade_items} gi
                JOIN {". manager::TABLE_ASSESSMENT_MAPPING . "} m ON gi.categoryid = m.sourceid AND m.sourcetype = :sourcetype
                WHERE gi.itemmodule = :itemmodule AND gi.iteminstance = :workshopinstanceid";

        $params = [
            'sourcetype' => assessmentfactory::SOURCETYPE_GRADE_CATEGORY,
            'itemmodule' => 'workshop',
            'workshopinstanceid' => $workshopinstanceid,
        ];

        return empty($DB->get_records_sql($sql, $params));
    }
}
