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

use grade_category;
use local_sitsgradepush\assessment\assessmentfactory;
use mod_coursework\models\coursework;
use stdClass;

defined('MOODLE_INTERNAL') || die();
global $CFG;
require_once($CFG->dirroot . '/local/sitsgradepush/tests/fixtures/tests_data_provider.php');

/**
 * Tests for the assesstype class.
 *
 * @package    local_sitsgradepush
 * @copyright  2024 onwards University College London {@link https://www.ucl.ac.uk/}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @author     Alex Yeung <k.yeung@ucl.ac.uk>
 */
final class assesstype_test extends \advanced_testcase {
    /** @var stdClass Test course */
    private stdClass $course;

    /** @var stdClass Test grade category */
    private stdClass $gradecategory;

    /** @var stdClass Test grade item */
    private stdClass $gradeitem;

    /** @var stdClass Test assignment */
    private stdClass $assign;

    /** @var stdClass Test quiz */
    private stdClass $quiz;

    /** @var ?stdClass Test coursework */
    private ?stdClass $coursework;

    /** @var grade_category Course root grade category */
    private grade_category $coursegradecategory;

    /** @var array Component grade records */
    private array $mabs = [];

    /**
     * Set up the test environment.
     *
     * @return void
     */
    protected function setUp(): void {
        parent::setUp();

        // Skip the test if the plugin is not installed.
        if (!assesstype::is_assess_type_installed()) {
            $this->markTestSkipped('local_assess_type plugin is not installed.');
        }

        $this->setAdminUser();
        $this->resetAfterTest();
        $this->setup_test_data();
    }

    /**
     * Set up test data for all tests.
     *
     * @return void
     */
    private function setup_test_data(): void {
        global $CFG, $DB;
        require_once($CFG->libdir . '/gradelib.php');
        require_once(__DIR__ . '/../base_test_class.php');

        // Import test data.
        tests_data_provider::import_sitsgradepush_grade_components();

        // Store component grade records for later use.
        $this->mabs = $DB->get_records(manager::TABLE_COMPONENT_GRADE, [], 'id ASC', '*', 0, 5);

        // Set up configuration.
        set_config('late_summer_assessment_end_' . date('Y'), date('Y-m-d', strtotime('+2 month')), 'block_lifecycle');
        set_config('local_assess_type_update_enabled', 1, 'local_sitsgradepush');

        // Create custom fields.
        $this->getDataGenerator()->create_custom_field_category(['name' => 'CLC']);
        $this->getDataGenerator()->create_custom_field(['category' => 'CLC', 'shortname' => 'course_year']);

        // Create course.
        $this->course = $this->getDataGenerator()->create_course([
            'shortname' => 'C1',
            'customfields' => [
                ['shortname' => 'course_year', 'value' => date('Y')],
            ],
        ]);

        // Create grade category and items.
        $this->gradecategory = $this->getDataGenerator()->create_grade_category(['courseid' => $this->course->id]);
        $this->gradeitem = $this->create_grade_item();
        $this->assign = $this->getDataGenerator()->create_module('assign', ['course' => $this->course->id]);
        $this->quiz = $this->getDataGenerator()->create_module('quiz', ['course' => $this->course->id]);

        $courseworkpluginexists = \core_component::get_component_directory('mod_coursework');
        $coursework = $courseworkpluginexists
            ? $this->getDataGenerator()->create_module('coursework', ['course' => $this->course->id])
            : null;

        // Convert coursework to stdClass.
        if ($coursework instanceof coursework) {
            $this->coursework = base_test_class::convert_coursework_to_stdclass($coursework);
        } else {
            $this->coursework = null;
        }

        // Get course root category.
        $this->coursegradecategory = grade_category::fetch(['courseid' => $this->course->id, 'parent' => null, 'depth' => 1]);

        // Add items to grade category.
        $this->add_item_to_category($this->gradeitem->id, $this->gradecategory->id);
        $this->add_module_to_category('assign', $this->assign->id, $this->gradecategory->id);
        $this->add_module_to_category('quiz', $this->quiz->id, $this->gradecategory->id);
        if ($courseworkpluginexists) {
            $this->add_module_to_category('coursework', $this->coursework->id, $this->gradecategory->id);
        }

        // Create assessment mapping.
        $mab = reset($this->mabs);
        $mapping = new stdClass();
        $mapping->courseid = $this->course->id;
        $mapping->sourcetype = assessmentfactory::SOURCETYPE_GRADE_CATEGORY;
        $mapping->sourceid = $this->gradecategory->id;
        $mapping->componentgradeid = $mab->id;
        $mapping->reassessment = 0;

        manager::get_manager()->save_assessment_mapping($mapping);
    }

    /**
     * Create a grade item.
     *
     * @return stdClass
     */
    private function create_grade_item(): stdClass {
        return $this->getDataGenerator()->create_grade_item([
            'courseid' => $this->course->id,
            'itemname' => 'Grade item',
            'gradetype' => GRADE_TYPE_VALUE,
            'grademax' => 100,
            'grademin' => 0,
        ]);
    }

    /**
     * Add a grade item to a category.
     *
     * @param int $itemid Grade item ID.
     * @param int $categoryid Category ID.
     * @return void
     */
    private function add_item_to_category(int $itemid, int $categoryid): void {
        $gradeitem = \grade_item::fetch(['id' => $itemid]);
        $gradeitem->categoryid = $categoryid;
        $gradeitem->update();
    }

    /**
     * Add a module to a category.
     *
     * @param string $module Module name.
     * @param int $instanceid Module instance ID.
     * @param int $categoryid Category ID.
     * @return void
     */
    private function add_module_to_category(string $module, int $instanceid, int $categoryid): void {
        $gradeitem = \grade_item::fetch(['itemtype' => 'mod', 'iteminstance' => $instanceid, 'itemmodule' => $module]);
        $gradeitem->categoryid = $categoryid;
        $gradeitem->update();
    }

    /**
     * Create a calculated grade item.
     *
     * @param int $categoryid Category ID.
     * @param array $itemsforcalc Items to include in calculation.
     * @return stdClass
     */
    private function create_calculated_item(int $categoryid, array $itemsforcalc = []): stdClass {
        // Create a manual grade item with a calculation.
        $calculateditem = $this->getDataGenerator()->create_grade_item([
            'courseid' => $this->course->id,
            'itemname' => 'Calculated item',
            'itemtype' => 'manual',
            'gradetype' => GRADE_TYPE_VALUE,
            'grademax' => 100,
            'grademin' => 0,
            'categoryid' => $categoryid,
        ]);

        if (!empty($itemsforcalc)) {
            // Set up the calculation formula.
            $formula = "=";
            $separator = "";
            foreach ($itemsforcalc as $item) {
                $formula .= $separator . "##gi{$item->id}##";
                $separator = " + ";
            }

            $gradeitem = \grade_item::fetch(['id' => $calculateditem->id]);
            $gradeitem->calculation = $formula;
            $gradeitem->update();
        }

        return $calculateditem;
    }

    /**
     * Create a mapping for a grade item.
     *
     * @param int $sourceid Source ID.
     * @param string $sourcetype Source type.
     * @param int $mabindex Index of the mab to use (0-based).
     * @return stdClass
     */
    private function create_mapping(
        int $sourceid,
        string $sourcetype = assessmentfactory::SOURCETYPE_GRADE_ITEM,
        int $mabindex = 1
    ): stdClass {
        global $DB;

        // Get a different mab for each mapping.
        $mabs = array_values($this->mabs);
        $mab = $mabs[$mabindex];

        $mapping = new stdClass();
        $mapping->courseid = $this->course->id;
        $mapping->sourcetype = $sourcetype;
        $mapping->sourceid = $sourceid;
        $mapping->componentgradeid = $mab->id;
        $mapping->reassessment = 0;

        manager::get_manager()->save_assessment_mapping($mapping);

        return $DB->get_record(
            manager::TABLE_ASSESSMENT_MAPPING,
            [
                'courseid' => $this->course->id,
                'sourcetype' => $sourcetype,
                'sourceid' => $sourceid,
            ]
        );
    }

    /**
     * Check if a grade item is locked.
     *
     * @param int $gradeitemid Grade item ID.
     * @param int $cmid Course module ID (optional).
     * @param bool $expectedlocked Expected lock status.
     * @return void
     */
    private function assert_lock_status(int $gradeitemid, int $cmid = 0, bool $expectedlocked = true): void {
        global $DB;

        $locked = $expectedlocked ? 1 : 0;

        if ($cmid > 0) {
            $this->assertTrue(
                $DB->record_exists('local_assess_type', ['cmid' => $cmid, 'type' => 1, 'locked' => $locked])
            );
        } else {
            $this->assertTrue(
                $DB->record_exists('local_assess_type', ['gradeitemid' => $gradeitemid, 'type' => 1, 'locked' => $locked])
            );
        }
    }

    /**
     * Get a mapping record.
     *
     * @param string $sourcetype Source type.
     * @param int $sourceid Source ID.
     * @return stdClass
     */
    private function get_mapping(string $sourcetype, int $sourceid): stdClass {
        global $DB;

        return $DB->get_record(
            manager::TABLE_ASSESSMENT_MAPPING,
            [
                'courseid' => $this->course->id,
                'sourcetype' => $sourcetype,
                'sourceid' => $sourceid,
            ]
        );
    }

    /**
     * Test items under a mapped grade category are marked summative and locked.
     *
     * @covers \local_sitsgradepush\assesstype::update_assess_type
     * @covers \local_sitsgradepush\assesstype::update_assess_type_items_under_gradecategory
     */
    public function test_items_under_mapped_category(): void {
        // Get category's grade item.
        $category = grade_category::fetch(['id' => $this->gradecategory->id]);
        $categorygradeitem = $category->load_grade_item();

        // Verify all items are locked.
        $this->assert_lock_status($categorygradeitem->id);
        $this->assert_lock_status($this->gradeitem->id);
        $this->assert_lock_status(0, $this->assign->cmid);
        $this->assert_lock_status(0, $this->quiz->cmid);

        // Remove the mapping.
        $mapping = $this->get_mapping(assessmentfactory::SOURCETYPE_GRADE_CATEGORY, $this->gradecategory->id);
        manager::get_manager()->remove_mapping($this->course->id, $mapping->id);

        // Verify all items are unlocked.
        $this->assert_lock_status($categorygradeitem->id, 0, false);
        $this->assert_lock_status($this->gradeitem->id, 0, false);
        $this->assert_lock_status(0, $this->assign->cmid, false);
        $this->assert_lock_status(0, $this->quiz->cmid, false);
    }

    /**
     * Test grade item updated event handler.
     *
     * @covers \local_sitsgradepush\assesstype::grade_item_updated
     */
    public function test_grade_item_updated(): void {
        global $DB;

        // Create items for calculation.
        $itemforcalc1 = $this->getDataGenerator()->create_grade_item([
            'courseid' => $this->course->id,
            'itemname' => 'Item for calculation 1',
            'itemtype' => 'manual',
            'gradetype' => GRADE_TYPE_VALUE,
            'categoryid' => $this->coursegradecategory->id,
            'idnumber' => 'item-for-calc-1',
        ]);

        $itemforcalc2 = $this->getDataGenerator()->create_grade_item([
            'courseid' => $this->course->id,
            'itemname' => 'Item for calculation 2',
            'itemtype' => 'manual',
            'gradetype' => GRADE_TYPE_VALUE,
            'categoryid' => $this->coursegradecategory->id,
            'idnumber' => 'item-for-calc-2',
        ]);

        // Create calculated item with multiple items in formula.
        $calculateditem = $this->create_calculated_item($this->coursegradecategory->id, [$itemforcalc1, $itemforcalc2]);

        // Create mapping for calculated item.
        $this->create_mapping($calculateditem->id);

        // Verify all items are locked.
        $this->assert_lock_status($calculateditem->id);
        $this->assert_lock_status($itemforcalc1->id);
        $this->assert_lock_status($itemforcalc2->id);

        // Remove one item from the calculation by updating the calculation formula.
        $calculatedgradeitem = \grade_item::fetch(['id' => $calculateditem->id]);
        $calculatedgradeitem->calculation = "=##gi{$itemforcalc2->id}##"; // Only include itemforcalc2.
        $calculatedgradeitem->update();

        // Verify the removed item is now unlocked.
        $this->assert_lock_status($itemforcalc1->id, 0, false);

        // The other item should still be locked.
        $this->assert_lock_status($itemforcalc2->id);

        // Create a new item to add to the calculation.
        $newitemforcalc = $this->getDataGenerator()->create_grade_item([
            'courseid' => $this->course->id,
            'itemname' => 'New item for calculation',
            'itemtype' => 'manual',
            'gradetype' => GRADE_TYPE_VALUE,
            'categoryid' => $this->coursegradecategory->id,
            'idnumber' => 'new-item-for-calc',
        ]);

        // Initially, the new item should not be existed in local_assess_type plugin.
        $this->assertFalse($DB->record_exists('local_assess_type', ['gradeitemid' => $newitemforcalc->id]));

        // Update the calculation formula to include the new item.
        $calculatedgradeitem = \grade_item::fetch(['id' => $calculateditem->id]);
        $calculatedgradeitem->calculation = "=##gi{$itemforcalc2->id}## + ##gi{$newitemforcalc->id}##";
        $calculatedgradeitem->update();

        // Verify the new item is now locked because it's part of a mapped calculation.
        $this->assert_lock_status($newitemforcalc->id);

        // Move items out of the mapped category.
        $this->add_item_to_category($this->gradeitem->id, $this->coursegradecategory->id);
        $this->add_module_to_category('assign', $this->assign->id, $this->coursegradecategory->id);
        $this->add_module_to_category('quiz', $this->quiz->id, $this->coursegradecategory->id);
        if ($this->coursework) {
            $this->add_module_to_category('coursework', $this->coursework->id, $this->coursegradecategory->id);
        }

        // Verify all items are unlocked.
        $this->assert_lock_status($this->gradeitem->id, 0, false);
        $this->assert_lock_status(0, $this->assign->cmid, false);
        $this->assert_lock_status(0, $this->quiz->cmid, false);
    }

    /**
     * Test calculated grade items.
     *
     * @covers \local_sitsgradepush\assesstype::update_assess_type_for_calculated_grade_items
     * @covers \local_sitsgradepush\assesstype::is_calculable_item
     * @covers \local_sitsgradepush\assesstype::extract_grade_item_ids_from_calculation
     */
    public function test_calculated_grade_items(): void {
        // Create items for calculation.
        $itemforcalc = $this->getDataGenerator()->create_grade_item([
            'courseid' => $this->course->id,
            'itemname' => 'Item for calculation',
            'itemtype' => 'manual',
            'gradetype' => GRADE_TYPE_VALUE,
            'categoryid' => $this->coursegradecategory->id,
            'idnumber' => 'item-for-calc',
        ]);

        // Create calculated item.
        $calculateditem = $this->create_calculated_item($this->coursegradecategory->id, [$itemforcalc]);

        // Create mapping for calculated item.
        $mapping = $this->create_mapping($calculateditem->id);

        // Verify calculated item and item used in calculation are locked.
        $this->assert_lock_status($calculateditem->id);
        $this->assert_lock_status($itemforcalc->id);

        // Remove mapping.
        manager::get_manager()->remove_mapping($this->course->id, $mapping->id);

        // Verify both items are unlocked.
        $this->assert_lock_status($calculateditem->id, 0, false);
        $this->assert_lock_status($itemforcalc->id, 0, false);
    }

    /**
     * Test multiple items in calculation.
     *
     * @covers \local_sitsgradepush\assesstype::process_related_grade_items
     */
    public function test_multiple_calculation_items(): void {
        // Create items for calculation.
        $itemforcalc1 = $this->getDataGenerator()->create_grade_item([
            'courseid' => $this->course->id,
            'itemname' => 'Item for calculation 1',
            'itemtype' => 'manual',
            'gradetype' => GRADE_TYPE_VALUE,
            'categoryid' => $this->coursegradecategory->id,
            'idnumber' => 'item-for-calc-1',
        ]);

        $itemforcalc2 = $this->getDataGenerator()->create_grade_item([
            'courseid' => $this->course->id,
            'itemname' => 'Item for calculation 2',
            'itemtype' => 'manual',
            'gradetype' => GRADE_TYPE_VALUE,
            'categoryid' => $this->coursegradecategory->id,
            'idnumber' => 'item-for-calc-2',
        ]);

        // Create calculated item with multiple items in formula.
        $calculateditem = $this->create_calculated_item($this->coursegradecategory->id, [$itemforcalc1, $itemforcalc2]);

        // Create mapping for calculated item.
        $mapping = $this->create_mapping($calculateditem->id, assessmentfactory::SOURCETYPE_GRADE_ITEM, 2);

        // Verify all items are locked.
        $this->assert_lock_status($calculateditem->id);
        $this->assert_lock_status($itemforcalc1->id);
        $this->assert_lock_status($itemforcalc2->id);

        // Remove mapping.
        manager::get_manager()->remove_mapping($this->course->id, $mapping->id);

        // Verify all items are unlocked.
        $this->assert_lock_status($calculateditem->id, 0, false);
        $this->assert_lock_status($itemforcalc1->id, 0, false);
        $this->assert_lock_status($itemforcalc2->id, 0, false);
    }

    /**
     * Test category hierarchy.
     *
     * @covers \local_sitsgradepush\assesstype::is_under_mapped_category
     * @covers \local_sitsgradepush\assesstype::is_category_mapped
     */
    public function test_category_hierarchy(): void {
        // Create subcategory initially in the mapped category.
        $subcategory = $this->getDataGenerator()->create_grade_category([
            'courseid' => $this->course->id,
            'parent' => $this->gradecategory->id,
        ]);

        // Create item in subcategory.
        $subcategoryitem = $this->getDataGenerator()->create_grade_item([
            'courseid' => $this->course->id,
            'itemname' => 'Subcategory item',
            'itemtype' => 'manual',
            'gradetype' => GRADE_TYPE_VALUE,
            'categoryid' => $subcategory->id,
        ]);

        // Recalculate course grades to ensure all grade items are properly processed.
        grade_regrade_final_grades($this->course->id);

        // Verify item in subcategory is now locked (because parent category is mapped).
        $this->assert_lock_status($subcategoryitem->id);

        // Move subcategory item out of the subcategory.
        $this->add_item_to_category($subcategoryitem->id, $this->coursegradecategory->id);

        // Verify item in subcategory item is now unlocked.
        $this->assert_lock_status($subcategoryitem->id, 0, false);
    }

    /**
     * Test workshop grade items.
     *
     * @covers \local_sitsgradepush\assesstype::should_unlock_workshop
     */
    public function test_workshop_grade_items(): void {
        // Create workshop.
        $workshop = $this->getDataGenerator()->create_module('workshop', ['course' => $this->course->id]);

        // Get workshop grade items.
        $gradeitem1 = \grade_item::fetch(['itemmodule' => 'workshop', 'iteminstance' => $workshop->id, 'itemnumber' => 0]);
        $gradeitem2 = \grade_item::fetch(['itemmodule' => 'workshop', 'iteminstance' => $workshop->id, 'itemnumber' => 1]);

        // Move both workshop grade items to mapped category.
        $this->add_item_to_category($gradeitem1->id, $this->gradecategory->id);
        $this->add_item_to_category($gradeitem2->id, $this->gradecategory->id);

        // Verify workshop is locked.
        $this->assert_lock_status(0, $workshop->cmid);

        // Move first grade item out of mapped category.
        $this->add_item_to_category($gradeitem1->id, $this->coursegradecategory->id);

        // Workshop should still be locked because one grade item is still in mapped category.
        $this->assert_lock_status(0, $workshop->cmid);

        // Move second grade item out of mapped category.
        $this->add_item_to_category($gradeitem2->id, $this->coursegradecategory->id);

        // Now workshop should be unlocked.
        $this->assert_lock_status(0, $workshop->cmid, false);
    }
}
