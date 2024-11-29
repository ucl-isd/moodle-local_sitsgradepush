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

use local_sitsgradepush\assessment\assessmentfactory;

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

    /** @var \stdClass Test course */
    private \stdClass $course;

    /** @var \stdClass Test grade item */
    private \stdClass $gradeitem;

    /** @var \stdClass Test grade category */
    private \stdClass $gradecategory;

    /** @var \stdClass Test assignment */
    private \stdClass $assign;

    /** @var \stdClass Test quiz */
    private \stdClass $quiz;

    /**
     * Set up the test.
     *
     * @return void
     */
    protected function setUp(): void {
        parent::setUp();
        // Skip the test if the plugin is not installed.
        if (!assesstype::is_assess_type_installed()) {
            $this->markTestSkipped('local_assess_type plugin is not installed.');
        }

        // Set admin user.
        $this->setAdminUser();
        $this->setup_environment();
        $this->resetAfterTest();
    }

    /**
     * Test items under a mapped grade category are marked summative and locked.
     *
     * @covers \local_sitsgradepush\assesstype::update_assess_type
     * @covers \local_sitsgradepush\assesstype::is_assess_type_installed
     * @covers \local_sitsgradepush\assesstype::update_assess_type_items_under_gradecategory
     * @return void
     * @throws \dml_exception
     */
    public function test_items_under_mapped_grade_category_marked_summative(): void {
        global $DB;

        $gradeitems = \grade_item::fetch_all(['categoryid' => $this->gradecategory->id]);
        $this->assertCount(3, $gradeitems);

        // Check category's grade item is marked as summative and locked.
        $category = \grade_category::fetch(['id' => $this->gradecategory->id]);
        $this->assertTrue(
            $DB->record_exists('local_assess_type', ['gradeitemid' => $category->load_grade_item()->id, 'type' => 1, 'locked' => 1])
        );

        // Check manual grade item is marked as summative and locked.
        $this->assertTrue(
            $DB->record_exists('local_assess_type', ['gradeitemid' => $this->gradeitem->id, 'type' => 1, 'locked' => 1])
        );
        // Check assignment is marked as summative and locked.
        $this->assertTrue(
            $DB->record_exists('local_assess_type', ['cmid' => $this->assign->cmid, 'type' => 1, 'locked' => 1])
        );
        // Check quiz is marked as summative and locked.
        $this->assertTrue(
            $DB->record_exists('local_assess_type', ['cmid' => $this->quiz->cmid, 'type' => 1, 'locked' => 1])
        );
    }

    /**
     * Test a grade category mapping is removed.
     *
     * @covers \local_sitsgradepush\manager::remove_mapping
     * @covers \local_sitsgradepush\assesstype::update_assess_type
     * @covers \local_sitsgradepush\assesstype::update_assess_type_items_under_gradecategory
     * @return void
     * @throws \dml_exception
     * @throws \moodle_exception
     */
    public function test_remove_grade_category_mapping(): void {
        global $DB;

        // Get the grade category mapping.
        $mapping = $DB->get_record(
            manager::TABLE_ASSESSMENT_MAPPING,
            [
                'courseid' => $this->course->id,
                'sourcetype' => assessmentfactory::SOURCETYPE_GRADE_CATEGORY,
                'sourceid' => $this->gradecategory->id,
            ]
        );

        // Remove the grade category mapping.
        manager::get_manager()->remove_mapping($this->course->id, $mapping->id);

        // Check category's grade item is unlocked.
        $category = \grade_category::fetch(['id' => $this->gradecategory->id]);
        $this->assertTrue(
            $DB->record_exists('local_assess_type', ['gradeitemid' => $category->load_grade_item()->id, 'type' => 1, 'locked' => 0])
        );

        // Check manual grade item is unlocked.
        $this->assertTrue(
            $DB->record_exists('local_assess_type', ['gradeitemid' => $this->gradeitem->id, 'type' => 1, 'locked' => 0])
        );

        // Check assignment is unlocked.
        $this->assertTrue(
            $DB->record_exists('local_assess_type', ['cmid' => $this->assign->cmid, 'type' => 1, 'locked' => 0])
        );

        // Check quiz is unlocked.
        $this->assertTrue(
            $DB->record_exists('local_assess_type', ['cmid' => $this->quiz->cmid, 'type' => 1, 'locked' => 0])
        );
    }

    /**
     * Test grade item updated.
     *
     * @covers \local_sitsgradepush\assesstype::grade_item_updated
     * @covers \local_sitsgradepush\assesstype::update_assess_type_items_under_gradecategory
     * @return void
     * @throws \dml_exception
     */
    public function test_grade_item_updated(): void {
        global $DB;

        // Get the course grade category.
        $coursegradecategory = \grade_category::fetch(['courseid' => $this->course->id, 'parent' => null, 'depth' => 1]);

        // Move the manual grade item out of the mapped category.
        $gradeitem = \grade_item::fetch(['id' => $this->gradeitem->id]);
        $gradeitem->categoryid = $coursegradecategory->id;
        $gradeitem->update();

        // Check manual grade item is unlocked.
        $this->assertTrue(
            $DB->record_exists('local_assess_type', ['gradeitemid' => $this->gradeitem->id, 'type' => 1, 'locked' => 0])
        );

        // Move the assignment out of the mapped category.
        $gradeitem = \grade_item::fetch(['itemtype' => 'mod', 'iteminstance' => $this->assign->id, 'itemmodule' => 'assign']);
        $gradeitem->categoryid = $coursegradecategory->id;
        $gradeitem->update();

        // Check assignment is unlocked.
        $this->assertTrue(
            $DB->record_exists('local_assess_type', ['cmid' => $this->assign->cmid, 'type' => 1, 'locked' => 0])
        );

        // Move the quiz out of the mapped category.
        $gradeitem = \grade_item::fetch(['itemtype' => 'mod', 'iteminstance' => $this->quiz->id, 'itemmodule' => 'quiz']);
        $gradeitem->categoryid = $coursegradecategory->id;
        $gradeitem->update();

        // Check quiz is unlocked.
        $this->assertTrue(
            $DB->record_exists('local_assess_type', ['cmid' => $this->quiz->cmid, 'type' => 1, 'locked' => 0])
        );
    }

    /**
     * Test workshop grade items.
     *
     * @covers \local_sitsgradepush\assesstype::grade_item_updated
     * @covers \local_sitsgradepush\assesstype::update_assess_type_items_under_gradecategory
     * @covers \local_sitsgradepush\assesstype::should_unlock_workshop
     * @return void
     * @throws \dml_exception
     */
    public function test_workshop_grade_items(): void {
        global $DB;

        // Create a workshop.
        $workshop = $this->getDataGenerator()->create_module('workshop', ['course' => $this->course->id]);

        // Get the first grade item of the workshop.
        $gradeitem1 = \grade_item::fetch(['itemmodule' => 'workshop', 'iteminstance' => $workshop->id, 'itemnumber' => 0]);

        // Move the workshop grade item to the mapped category.
        $gradeitem1->categoryid = $this->gradecategory->id;
        $gradeitem1->update();

        // Check the workshop is marked as summative and locked.
        $this->assertTrue(
            $DB->record_exists('local_assess_type', ['cmid' => $workshop->cmid, 'type' => 1, 'locked' => 1])
        );

        // Get the second grade item of the workshop.
        $gradeitem2 = \grade_item::fetch(['itemmodule' => 'workshop', 'iteminstance' => $workshop->id, 'itemnumber' => 1]);

        // Move the workshop grade item to the mapped category.
        $gradeitem2->categoryid = $this->gradecategory->id;
        $gradeitem2->update();

        // Now remove the first grade item from the mapped category.
        $coursecategory = \grade_category::fetch(['courseid' => $this->course->id, 'parent' => null, 'depth' => 1]);
        $gradeitem1->categoryid = $coursecategory->id;
        $gradeitem1->update();

        // Check the workshop is still marked as summative and locked.
        $this->assertTrue(
            $DB->record_exists('local_assess_type', ['cmid' => $workshop->cmid, 'type' => 1, 'locked' => 1])
        );

        // Now remove the second grade item from the mapped category.
        $gradeitem2->categoryid = $coursecategory->id;
        $gradeitem2->update();

        // Check the workshop is now unlocked.
        $this->assertTrue(
            $DB->record_exists('local_assess_type', ['cmid' => $workshop->cmid, 'type' => 1, 'locked' => 0])
        );
    }

    /**
     * Set up the testing environment.
     *
     * @return void
     * @throws \coding_exception
     * @throws \dml_exception
     * @throws \moodle_exception
     */
    protected function setup_environment() {
        global $CFG, $DB;
        require_once($CFG->libdir . '/gradelib.php');

        // Set up the testing environment.
        tests_data_provider::import_sitsgradepush_grade_components();

        // Set block_lifecycle 'late_summer_assessment_end_date'.
        set_config('late_summer_assessment_end_' . date('Y'), date('Y-m-d', strtotime('+2 month')), 'block_lifecycle');

        // Create a custom category and custom field.
        $this->getDataGenerator()->create_custom_field_category(['name' => 'CLC']);
        $this->getDataGenerator()->create_custom_field(['category' => 'CLC', 'shortname' => 'course_year']);

        // Create test course.
        $this->course = $this->getDataGenerator()->create_course(
            ['shortname' => 'C1', 'customfields' => [
                ['shortname' => 'course_year', 'value' => date('Y')],
            ]]);
        $this->gradecategory =
            $this->getDataGenerator()->create_grade_category(['courseid' => $this->course->id]); // Create grade category.
        $this->gradeitem = $this->create_grade_item(); // Create grade item.
        $this->assign = $this->getDataGenerator()->create_module('assign', ['course' => $this->course->id]); // Create assignment.
        $this->quiz = $this->getDataGenerator()->create_module('quiz', ['course' => $this->course->id]); // Create quiz.

        // Add grade item and activities to grade category.
        $gradeitem = \grade_item::fetch(['id' => $this->gradeitem->id]);
        $gradeitem->categoryid = $this->gradecategory->id;
        $gradeitem->update();

        // Add assignment to grade category.
        $gradeitem = \grade_item::fetch(['itemtype' => 'mod', 'iteminstance' => $this->assign->id, 'itemmodule' => 'assign']);
        $gradeitem->categoryid = $this->gradecategory->id;
        $gradeitem->update();

        // Add quiz to grade category.
        $gradeitem = \grade_item::fetch(['itemtype' => 'mod', 'iteminstance' => $this->quiz->id, 'itemmodule' => 'quiz']);
        $gradeitem->categoryid = $this->gradecategory->id;
        $gradeitem->update();

        // Add assessment mapping.
        $mab = $DB->get_record(manager::TABLE_COMPONENT_GRADE, ['mapcode' => 'LAWS0024A6UF', 'mabseq' => '001']);
        $mapping = new \stdClass();
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
     * @return \stdClass
     */
    private function create_grade_item(): \stdClass {
        // Create grade item.
        return $this->getDataGenerator()->create_grade_item([
            'courseid' => $this->course->id,
            'itemname' => 'Grade item',
            'gradetype' => GRADE_TYPE_VALUE,
            'grademax' => 100,
            'grademin' => 0,
            'scaleid' => 0,
            'multfactor' => 1.0,
            'plusfactor' => 0.0,
            'aggregationcoef' => 0.0,
            'aggregationcoef2' => 0.0,
            'sortorder' => 1,
            'hidden' => 0,
            'locked' => 0,
            'locktime' => 0,
            'needsupdate' => 0,
            'weightoverride' => 0,
            'timecreated' => time(),
            'timemodified' => time(),
        ]);
    }
}
