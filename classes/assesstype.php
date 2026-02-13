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
    /** @var int Action lock */
    const ACTION_LOCK = 1;

    /** @var int Action unlock */
    const ACTION_UNLOCK = 0;

    /** @var string Assessment type */
    const ASSESSMENT_TYPE = assess_type::ASSESS_TYPE_SUMMATIVE;

    /**
     * Update assessment type and lock status.
     *
     * @param int|\stdClass $mapping Mapping record ID or object.
     * @param int $action Action to perform.
     *
     * @throws \dml_exception
     */
    public static function update_assess_type(int|\stdClass $mapping, int $action): void {
        global $DB;

        try {
            if (!self::is_assess_type_installed() || !get_config('local_sitsgradepush', 'local_assess_type_update_enabled')) {
                return;
            }

            // Get mapping record if it is not already an object.
            if (is_int($mapping)) {
                $mapping = $DB->get_record(manager::TABLE_ASSESSMENT_MAPPING, ['id' => $mapping]);
            }

            if (empty($mapping)) {
                return;
            }

            $assessment = assessmentfactory::get_assessment($mapping->sourcetype, $mapping->sourceid);
            $gradeitems = $assessment->get_grade_items();
            $gradeitem = is_array($gradeitems) && !empty($gradeitems) ? reset($gradeitems) : null;

            if (!$gradeitem) {
                throw new \Exception('No grade item found for assessment.');
            }

            // Update the assessment type for the mapped assessment itself.
            self::apply_assessment_type($gradeitem, $action);

            // Process child grade items and activities if mapped assessment is a grade category.
            if ($mapping->sourcetype === assessmentfactory::SOURCETYPE_GRADE_CATEGORY) {
                self::update_assess_type_items_under_gradecategory($mapping->sourceid, $action);
            }

            // Process grade items that are part of the calculation formula if mapped assessment
            // is a manual grade item or grade category.
            self::update_assess_type_for_calculated_grade_items($gradeitem, $action);
        } catch (\Exception $e) {
            logger::log('Failed to update assessment type and lock status.', null, null, $e->getMessage());
        }
    }

    /**
     * Apply assessment type update.
     *
     * @param \stdClass|\grade_item $gradeitem Grade item object to update
     * @param int $action Lock or unlock action (ACTION_LOCK or ACTION_UNLOCK)
     * @param bool $bypass Whether to bypass the mapped/calculated check
     * @return void
     * @throws \moodle_exception
     */
    private static function apply_assessment_type(\stdClass|\grade_item $gradeitem, int $action, bool $bypass = false): void {
        // Skip unlock if the grade item is not summative and locked, it is not set by marks transfer.
        [$cmid, $gradeitemid] = self::get_ids_from_grade_item($gradeitem);

        // Grade item type not recognised or no course module found for mod item, skip processing.
        if (is_null($cmid) && is_null($gradeitemid)) {
            return;
        }

        if ($action === self::ACTION_UNLOCK) {
            // Skip this check as it is already checked by the function calling this.
            if (!$bypass) {
                // The grade item is either mapped or part of a calculation, so it should remain locked.
                if (self::is_mapped_or_calculated($gradeitem)) {
                    return;
                }
            }

            // Skip unlocking workshop if any of its grade items are mapped or calculated.
            if ($gradeitem->itemmodule === 'workshop' && !self::should_unlock_workshop($gradeitem)) {
                return;
            }

            // Skip if the grade item is already unlocked.
            if (!self::is_grade_item_locked($gradeitem->courseid, $cmid, $gradeitemid)) {
                return;
            }
        }

        assess_type::update_type($gradeitem->courseid, self::ASSESSMENT_TYPE, $cmid, $gradeitemid, $action);
    }

    /**
     * Update assessment type for grade items that are part of the calculation of a grade category or grade item.
     *
     * @param \stdClass|\grade_item $gradeitem Grade item object with calculation formula
     * @param int $action Lock or unlock action (ACTION_LOCK or ACTION_UNLOCK)
     * @return void
     * @throws \moodle_exception
     */
    private static function update_assess_type_for_calculated_grade_items(\stdClass|\grade_item $gradeitem, int $action): void {
        // Only process grade categories or manual grade items with calculations.
        if (!self::is_calculable_item($gradeitem)) {
            return;
        }

        // Get grade item IDs from the calculation formula.
        $gradeitemids = self::extract_grade_item_ids_from_calculation($gradeitem->calculation);

        if (empty($gradeitemids)) {
            return;
        }

        // Apply assessment type to each grade item in the calculation.
        foreach ($gradeitemids as $gradeitemid) {
            $calculateditem = \grade_item::fetch(['id' => $gradeitemid]);
            if (!empty($calculateditem)) {
                self::apply_assessment_type($calculateditem, $action);
            }
        }
    }

    /**
     * Check if a grade item is a calculable item (manual or category with calculation).
     *
     * @param \stdClass|\grade_item $gradeitem Grade item to check
     * @return bool True if the item is calculable, false otherwise
     */
    private static function is_calculable_item(\stdClass|\grade_item $gradeitem): bool {
        $validtypes = ['manual', 'category'];

        return in_array($gradeitem->itemtype, $validtypes) && !empty($gradeitem->calculation);
    }

    /**
     * Extract grade item IDs from a calculation formula.
     *
     * @param string $calculation The calculation formula
     * @return array Array of grade item IDs
     */
    private static function extract_grade_item_ids_from_calculation(string $calculation): array {
        preg_match_all('/##gi(\d+)##/', $calculation, $matches);

        return !empty($matches[1]) ? $matches[1] : [];
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
     */
    public static function grade_item_updated(\core\event\grade_item_updated $event): void {
        if (!self::is_assess_type_installed() || !get_config('local_sitsgradepush', 'local_assess_type_update_enabled')) {
            return;
        }
        $gradeitem = $event->get_record_snapshot('grade_items', $event->objectid);

        if ($gradeitem->itemtype === 'course') {
            return;
        }

        // Determine if the grade item is mapped or part of a mapped calculation.
        if (self::is_mapped_or_calculated($gradeitem)) {
            self::apply_assessment_type($gradeitem, self::ACTION_LOCK);
        } else {
            self::apply_assessment_type($gradeitem, self::ACTION_UNLOCK, true);
        }

        // Check for related grade items if it's a category or manual item.
        // Only category and manual items with value grade type can have calculations.
        if (in_array($gradeitem->itemtype, ['category', 'manual']) && $gradeitem->gradetype == GRADE_TYPE_VALUE) {
            self::process_related_grade_items($gradeitem);
        }
    }

    /**
     * Check if a grade item is under a mapped grade category or part of a calculation formula of another grade item.
     *
     * @param \stdClass|\grade_item $gradeitem The grade item to check
     * @return bool True if the grade item is mapped or part of a calculation, false otherwise
     * @throws \dml_exception
     */
    private static function is_mapped_or_calculated(\stdClass|\grade_item $gradeitem): bool {
        // Check if the grade item is part of a calculation formula.
        if (self::is_part_of_calculation($gradeitem)) {
            return true;
        }

        // Check if the grade item is under a mapped grade category.
        if (self::is_under_mapped_category($gradeitem)) {
            return true;
        }

        return false;
    }

    /**
     * Check if a grade item is part of a calculation formula of another grade item.
     *
     * @param \stdClass|\grade_item $gradeitem The grade item to check
     * @return bool True if the grade item is part of a calculation, false otherwise
     * @throws \dml_exception
     */
    private static function is_part_of_calculation(\stdClass|\grade_item $gradeitem): bool {
        global $DB;

        // Skip if grade item has no idnumber.
        if (empty($gradeitem->idnumber)) {
            return false;
        }

        $sql = "
        SELECT gi2.*
        FROM {grade_items} gi1
        JOIN {grade_items} gi2
            ON gi2.courseid = gi1.courseid
            AND gi2.calculation IS NOT NULL
            AND gi2.calculation LIKE " . $DB->sql_concat("'%##gi'", 'gi1.id', "'##%'") . "
        WHERE gi1.id = :gradeitemid AND gi1.idnumber IS NOT NULL AND gi1.idnumber <> ''";

        $calgradeitems = $DB->get_records_sql($sql, ['gradeitemid' => $gradeitem->id]);

        foreach ($calgradeitems as $calgradeitem) {
            // Skip course items.
            if ($calgradeitem->itemtype === 'course') {
                continue;
            }

            // The grade item is part of a calculation of a mapped manual grade item.
            if ($calgradeitem->itemtype === 'manual') {
                if (
                    $DB->record_exists(
                        manager::TABLE_ASSESSMENT_MAPPING,
                        ['sourcetype' => assessmentfactory::SOURCETYPE_GRADE_ITEM, 'sourceid' => $calgradeitem->id]
                    )
                ) {
                    return true;
                }
            }

            // For category items, check if any parent category is mapped.
            if ($calgradeitem->itemtype === 'category') {
                if (self::is_category_mapped($calgradeitem->iteminstance)) {
                    return true;
                }
            }

            // For manual items with a category, check if any parent category is mapped.
            if ($calgradeitem->itemtype === 'manual' && !empty($calgradeitem->categoryid)) {
                if (self::is_category_mapped($calgradeitem->categoryid)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Check if a grade item is under a mapped grade category.
     *
     * @param \stdClass|\grade_item $gradeitem The grade item to check
     * @return bool True if the grade item is under a mapped category, false otherwise
     * @throws \dml_exception
     */
    private static function is_under_mapped_category(\stdClass|\grade_item $gradeitem): bool {
        // For manual or module items, check if they're under a mapped category.
        if ($gradeitem->itemtype === 'manual' || $gradeitem->itemtype === 'mod') {
            // Skip if it's in the course root category.
            $coursecategorygradeitem = \grade_item::fetch(['itemtype' => 'course', 'courseid' => $gradeitem->courseid]);
            if ($gradeitem->categoryid == $coursecategorygradeitem->iteminstance) {
                return false;
            }

            return self::is_category_mapped($gradeitem->categoryid);
        }

        // For category items, check if the category itself or any parent is mapped.
        if ($gradeitem->itemtype === 'category') {
            return self::is_category_mapped($gradeitem->iteminstance);
        }

        return false;
    }

    /**
     * Check if a category or any of its parents is mapped.
     *
     * @param int $categoryid The category ID to check
     * @return bool True if the category or any parent is mapped, false otherwise
     * @throws \dml_exception
     */
    private static function is_category_mapped(int $categoryid): bool {
        global $DB;

        $category = \grade_category::fetch(['id' => $categoryid]);

        // Traverse up the category hierarchy.
        while ($category && $category->depth > 1) {
            // Check if this category is mapped.
            if (
                $DB->record_exists(
                    'local_sitsgradepush_mapping',
                    ['sourcetype' => 'gradecategory', 'sourceid' => $category->id]
                )
            ) {
                return true;
            }

            $category = $category->get_parent_category();
        }

        return false;
    }

    /**
     * Process related grade items that might be part of the calculation.
     * Locks or unlocks grade items based on whether they are mapped or part of a calculation.
     *
     * @param \stdClass|\grade_item $gradeitem The grade item whose related items should be processed
     * @return void
     * @throws \dml_exception
     */
    private static function process_related_grade_items($gradeitem): void {
        global $DB;

        [$insql, $inparams] = $DB->get_in_or_equal(['manual', 'mod', 'category'], SQL_PARAMS_NAMED, 'itemtype');

        // Only fetch grade items with valid idnumbers that could be affected.
        $sql = "SELECT gi.id, gi.courseid, gi.itemname, gi.itemtype, gi.itemmodule, gi.idnumber,
                       gi.iteminstance, gi.categoryid, gi.calculation
                FROM {grade_items} gi
                WHERE gi.courseid = :courseid
                AND gi.idnumber IS NOT NULL
                AND gi.idnumber <> ''
                AND gi.itemtype $insql";

        $records = $DB->get_records_sql($sql, array_merge(['courseid' => $gradeitem->courseid], $inparams));

        // Process each grade item.
        foreach ($records as $record) {
            // Apply the appropriate action based on whether the item is mapped or calculated.
            $action = self::is_mapped_or_calculated($record) ? self::ACTION_LOCK : self::ACTION_UNLOCK;
            self::apply_assessment_type($record, $action);
        }
    }

    /**
     * Get course module ID and grade item ID from a grade item.
     *
     * @param \stdClass|\grade_item $gradeitem Grade item object
     * @return array Array containing [cmid, gradeitemid]
     * @throws \moodle_exception
     */
    private static function get_ids_from_grade_item(\stdClass|\grade_item $gradeitem): array {
        $cmid = $gradeitemid = 0;
        switch ($gradeitem->itemtype) {
            case 'mod':
                $cm = get_coursemodule_from_instance(
                    $gradeitem->itemmodule,
                    $gradeitem->iteminstance,
                    $gradeitem->courseid
                );

                // If no course module found, return nulls to skip this grade item.
                if (!$cm) {
                    return [null, null];
                }

                $cmid = $cm->id;
                break;
            case 'category':
            case 'manual':
                $gradeitemid = $gradeitem->id;
                break;
            default:
                return [null, null];
        }

        return [$cmid, $gradeitemid];
    }

    /**
     * Check if a grade item is summative and currently locked.
     *
     * @param int $courseid Course ID
     * @param int $cmid Course module ID
     * @param int $gradeitemid Grade item ID
     * @return bool True if locked, false otherwise
     * @throws \dml_exception
     */
    private static function is_grade_item_locked(int $courseid, int $cmid, int $gradeitemid): bool {
        global $DB;

        return (bool)$DB->get_record(
            "local_assess_type",
            [
                'courseid' => $courseid,
                'cmid' => $cmid,
                'gradeitemid' => $gradeitemid,
                'type' => self::ASSESSMENT_TYPE,
                'locked' => self::ACTION_LOCK,
            ]
        );
    }

    /**
     * Update assessment type for all grade items and activities under a grade category.
     *
     * @param int $categoryid Grade category ID
     * @param int $action Lock or unlock action (ACTION_LOCK or ACTION_UNLOCK)
     * @return void
     * @throws \moodle_exception
     */
    private static function update_assess_type_items_under_gradecategory(int $categoryid, int $action): void {
        $category = \grade_category::fetch(['id' => $categoryid]);
        if (empty($category)) {
            return;
        }

        // Process all items in this category and its subcategories.
        self::process_category_and_children($category, $action);
    }

    /**
     * Process a category and all its children recursively.
     *
     * @param \grade_category $category The grade category to process
     * @param int $action Lock or unlock action
     * @return void
     */
    private static function process_category_and_children(\grade_category $category, int $action): void {
        // Get all children (grade items and subcategories).
        $children = $category->get_children(true);
        if (empty($children)) {
            return;
        }

        // Process each child.
        foreach ($children as $child) {
            foreach ($child as $item) {
                if ($item instanceof \grade_item) {
                    // Apply assessment type to grade items.
                    self::apply_assessment_type($item, $action);
                } else if ($item instanceof \grade_category) {
                    // Process subcategories recursively.
                    self::process_category_and_children($item, $action);
                }
            }
        }
    }

    /**
     * Check if the workshop should be unlocked. A workshop should remain locked if any of its
     * grade items are mapped or part of a calculation.
     *
     * @param \stdClass|\grade_item $gradeitem Grade item object for the workshop
     * @return bool True if the workshop should remain locked, false if it should be unlocked
     * @throws \dml_exception
     */
    private static function should_unlock_workshop($gradeitem): bool {
        global $DB;

        // Find all workshop grade items for this workshop instance.
        $workshopgradeitems = $DB->get_records('grade_items', [
            'courseid' => $gradeitem->courseid,
            'itemmodule' => 'workshop',
            'iteminstance' => $gradeitem->iteminstance,
        ]);

        // Check if any workshop grade item is mapped or calculated.
        foreach ($workshopgradeitems as $workshopgradeitem) {
            if (self::is_mapped_or_calculated($workshopgradeitem)) {
                return false; // Should not unlock if any item is mapped or calculated.
            }
        }

        return true;
    }
}
