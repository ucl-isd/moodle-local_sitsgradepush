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

use Behat\Gherkin\Node\TableNode;
use local_sitsgradepush\assessment\assessmentfactory;
use local_sitsgradepush\manager;

require_once(__DIR__ . '/../../../../lib/behat/behat_base.php');
require_once(__DIR__ . '/../fixtures/tests_data_provider.php');

/**
 * Defines the behat steps for the sitsgradepush plugin.
 *
 * @package    local_sitsgradepush
 * @copyright  2024 onwards University College London {@link https://www.ucl.ac.uk/}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @author     Alex Yeung <k.yeung@ucl.ac.uk>
 */
class behat_sitsgradepush extends behat_base {

    /**
     * Set up before scenario.
     *
     * @BeforeScenario
     * @return void
     */
    public function setup_before_scenario(): void {
        set_config('apiclient', 'easikit', 'local_sitsgradepush');

        // Set block_lifecycle 'late_summer_assessment_end_date'.
        set_config('late_summer_assessment_end_' . date('Y'), date('Y-m-d', strtotime('+2 month')), 'block_lifecycle');
    }

    /**
     * Create custom field.
     *
     * @param  TableNode $table
     * @throws \dml_exception
     *
     * @Given /^the following custom field exists for grade push:$/
     */
    public function the_following_custom_field_exists_for_grade_push(TableNode $table): void {
        global $DB;

        $data = $table->getRowsHash();

        // Create a new custom field category if it doesn't exist.
        $category = $DB->get_record(
          'customfield_category',
          ['name' => $data['category'],
           'component' => 'core_course',
           'area' => 'course']);

        if (!$category) {
            $category     = (object)[
              'name'         => $data['category'],
              'component'    => 'core_course',
              'area'         => 'course',
              'sortorder'    => 1,
              'timecreated'  => time(),
              'timemodified' => time(),
            ];
            $category->id = $DB->insert_record(
              'customfield_category',
              $category
            );
        }

        // Check if the field already exists.
        $fieldexists = $DB->record_exists('customfield_field', ['shortname' => $data['shortname'], 'categoryid' => $category->id]);

        // Create the custom field if not exists.
        if (!$fieldexists) {
            $field = (object)[
              'shortname' => $data['shortname'],
              'name' => $data['name'],
              'type' => $data['type'],
              'categoryid' => $category->id,
              'sortorder' => 0,
              'configdata'   => json_encode([
                "required" => 0,
                "uniquevalues" => 0,
                "maxlength" => 4,
                "defaultvalue" => "",
                "ispassword" => 0,
                "displaysize" => 4,
                "locked" => 1,
                "visibility" => 0,
              ]),
              'timecreated' => time(),
              'timemodified' => time(),
            ];
            $DB->insert_record('customfield_field', $field);
        }
    }

    /**
     * Click on a button for a row.
     *
     * @param  string  $buttontext
     * @param  string  $rowtext
     * @throws \Exception
     *
     * @When I click on the :buttontext button for :rowtext
     */
    public function i_click_on_the_button_for(string $buttontext, string $rowtext): void {
        $page = $this->getSession()->getPage();

        // Buttons which are button type, i.e. not links.
        $buttons = ['Select', 'Transfer marks'];

        // Determine the XPath based on button type.
        if (in_array($buttontext, ['Select source', 'Change source', 'Transfer marks'])) {
            $xpath = "//tr[th[contains(text(), '$rowtext')]]";
        } else if ($buttontext == 'Select') {
            $xpath = "//tr[td[contains(text(), '$rowtext')]]";
        } else {
            throw new Exception("Button with text '$buttontext' not recognized.");
        }

        // Find the row with the specified text.
        $row = $page->find('xpath', $xpath);
        if (!$row) {
            throw new Exception("Row with text '$rowtext' not found.");
        }

        // Find the button or link within that row.
        $element = in_array($buttontext, $buttons) ? $row->findButton($buttontext) : $row->findLink($buttontext);
        if (!$element) {
            throw new Exception("Button or link with text '$buttontext' not found in the row with text '$rowtext'.");
        }

        // Click the element.
        $element->click();
    }

    /**
     * Check if a source is mapped to a SITS assessment.
     *
     * @param  string $linktext
     * @param  string $sitsassessment
     * @throws \Exception
     *
     * @Then I should see :linkText is mapped to :sitsassessment
     */
    public function i_should_see_activity_is_mapped_for(string $linktext, string $sitsassessment): void {
        $page = $this->getSession()->getPage();

        // Find the row with the specified activity.
        $row = $page->find('xpath', "//tr[th[contains(text(), '$sitsassessment')]]");

        if (!$row) {
            throw new Exception("Row with SITS assessment '$sitsassessment' not found.");
        }

        // Find the link within that row.
        $link = $row->findLink($linktext);

        if (!$link) {
            throw new Exception("Link with text '$linktext' not found in the row with SITS assessment '$sitsassessment'.");
        }

        // Check if the link is visible.
        if (!$link->isVisible()) {
            throw new Exception("Link with text '$linktext' is not visible in the row with SITS assessment '$sitsassessment'.");
        }
    }

    /**
     * Set up the course for marks transfer.
     *
     * @param  string $shortname
     * @throws \dml_exception
     * @throws \coding_exception
     *
     * @Given the course :shortname is set up for marks transfer
     */
    public function the_course_is_setup_for_marks_transfer(string $shortname): void {
        \local_sitsgradepush\tests_data_provider::import_sitsgradepush_grade_components();
        \local_sitsgradepush\tests_data_provider::set_marking_scheme_data();
    }

    /**
     * Map a re-assessment source to a SITS assessment component.
     *
     * @param string $sourcetype
     * @param string $sourcename
     * @param string $mabname
     *
     * @throws \dml_exception|\moodle_exception
     *
     * @Given the :sourcetype :sourcename is a re-assessment and mapped to :mabname
     */
    public function the_source_is_a_reassessment_and_mapped_to(string $sourcetype, string $sourcename, string $mabname): void {
        $this->map_source($sourcetype, $sourcename, $mabname, true);
    }

    /**
     * Map a normal source to a SITS assessment component.
     *
     * @param string $sourcetype
     * @param string $sourcename
     * @param string $mabname
     *
     * @throws \dml_exception|\moodle_exception
     *
     * @Given the :sourcetype :sourcename is mapped to :mabname
     */
    public function the_source_is_mapped_to(string $sourcetype, string $sourcename, string $mabname): void {
        $this->map_source($sourcetype, $sourcename, $mabname, false);
    }

    /**
     * Map a source to a SITS assessment component.
     *
     * @param string $sourcetype
     * @param string $sourcename
     * @param string $mabname
     * @param bool $reassessment
     *
     * @throws \dml_exception|\moodle_exception
     */
    public function map_source(string $sourcetype, string $sourcename, string $mabname, bool $reassessment): void {
        global $DB;
        $manager = manager::get_manager();

        // Get the source.
        $source = match ($sourcetype) {
            'mod' => $DB->get_record(
              'course_modules',
              ['idnumber' => $sourcename]
            ),
            'gradeitem' => $DB->get_record(
              'grade_items',
              ['idnumber' => $sourcename]
            ),
            'gradecategory' => $DB->get_record(
              'grade_categories',
              ['fullname' => $sourcename]
            ),
            default => throw new Exception(
              "Source type '$sourcetype' not recognized."
            ),
        };

        // Get the SITS component grade.
        $mab = $DB->get_record('local_sitsgradepush_mab', ['mabname' => $mabname]);

        // Get the assessment.
        $assessment = assessmentfactory::get_assessment($sourcetype, $source->id);

        // Insert new mapping.
        $record = new \stdClass();
        $record->courseid = $assessment->get_course_id();
        $record->sourcetype = $assessment->get_type();
        $record->sourceid = $assessment->get_id();
        if ($assessment->get_type() == assessmentfactory::SOURCETYPE_MOD) {
            $record->moduletype = $assessment->get_module_name();
        }
        $record->componentgradeid = $mab->id;
        $record->reassessment = $reassessment;
        $record->timecreated = time();
        $record->timemodified = time();

        $DB->insert_record($manager::TABLE_ASSESSMENT_MAPPING, $record);
    }

    /**
     * Re-grade the course.
     *
     * @param string $shortname
     * @throws \moodle_exception
     *
     * @Given the course :shortname is regraded
     */
    public function the_course_is_regraded(string $shortname): void {
        global $DB;
        $course = $DB->get_record('course', ['shortname' => $shortname]);

        if (getenv('PLUGIN_CI')) {
            grade_regrade_final_grades($course->id);
        } else {
            grade_regrade_final_grades($course->id, async: false);
        }
    }

    /**
     * Check the number of marks to transfer for a SITS component.
     *
     * @param string $markscount
     * @param string $sitscomponent
     * @throws \Exception
     *
     * @Then I should see :markscount marks to transfer for :sitscomponent
     */
    public function i_should_see_link_in_the_row(string $markscount, string $sitscomponent): void {
        $page = $this->getSession()->getPage();

        // Find the row containing the specified text.
        $row = $page->find('xpath', "//tr[th[contains(., '$sitscomponent')]]");
        if (!$row) {
            throw new Exception("Row with text '$sitscomponent' not found");
        }

        // Find the link with the specified text within the identified row.
        // Adjusted to find the link with nested spans and small elements.
        $link = $row->find('xpath', ".//a[span[contains(@class, 'marks-count') and contains(text(), $markscount)]]");
        if (!$link) {
            throw new Exception("Marks to transfer not match in the row with text '$sitscomponent'");
        }
    }

    /**
     * Click on the marks transfer types dropdown menu and select a transfer
     * type.
     *
     * @param  string  $transfertype
     *
     * @Given I click on the marks transfer types dropdown menu and select :transfertype
     * @throws \Exception
     */
    public function i_click_on_the_transfer_type_menu_and_select(string $transfertype): void {
        $this->execute('behat_forms::i_set_the_field_to', ['Dashboard Type', $transfertype]);
    }
}
