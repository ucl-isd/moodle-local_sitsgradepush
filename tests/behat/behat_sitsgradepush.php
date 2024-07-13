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

use Behat\Behat\Tester\Exception\PendingException;
use Behat\Gherkin\Node\TableNode;
use local_sitsgradepush\cachemanager;
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
     * Set up before feature.
     *
     * @BeforeFeature
     * @return void
     */
    public static function setup_before_feature(): void {
        global $DB;

        // Skip test if database family is postgres, the SQL query in the portico enrolment block is not supported.
        if ($DB->get_dbfamily() === 'postgres') {
            throw new PendingException('Skipping test because it is not supported for Postgres.');
        }
    }

    /**
     * Set up before scenario.
     *
     * @BeforeScenario
     * @return void
     * @throws \ddl_exception
     */
    public function setup_before_scenario(): void {
        set_config('apiclient', 'easikit', 'local_sitsgradepush');

        // Set the MIM database name.
        set_config('ucl_sf3_dbname', 'moodle');

        // Set the get token url.
        $tokenurl = new moodle_url('/local/sitsgradepush/tests/fixtures/mock_apis/easikit/get_token.php');
        set_config('mstokenendpoint', $tokenurl->out(false), 'sitsapiclient_easikit');

        // Set the push grade url.
        $pushgradeurl = new moodle_url('/local/sitsgradepush/tests/fixtures/mock_apis/easikit/push_grade.php');
        set_config('endpoint_grade_push', $pushgradeurl->out(false), 'sitsapiclient_easikit');

        // Set block_lifecycle 'late_summer_assessment_end_date'.
        set_config('late_summer_assessment_end_' . date('Y'), date('Y-m-d', strtotime('+2 month')), 'block_lifecycle');

        \local_sitsgradepush\tests\fixtures\tests_data_provider::create_mim_tables();
    }

    /**
     * Clean up after scenario.
     *
     * @AfterScenario
     * @return void
     * @throws \ddl_exception
     */
    public static function clean_up_after_scenario(): void {
        \local_sitsgradepush\tests\fixtures\tests_data_provider::tear_down_mim_tables();
    }

    /**
     * Create custom field.
     *
     * @param  TableNode $table
     * @throws \dml_exception
     *
     * @Given /^the following custom field exists:$/
     */
    public function the_following_custom_field_exists(TableNode $table): void {
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

        // Create the custom field.
        $field = (object)[
          'shortname' => $data['shortname'],
          'name' => $data['name'],
          'type' => $data['type'],
          'categoryid' => $category->id,
          'timecreated' => time(),
          'timemodified' => time(),
        ];
        $DB->insert_record('customfield_field', $field);
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
        \local_sitsgradepush\tests\fixtures\tests_data_provider::import_data_into_mim_tables();
        \local_sitsgradepush\tests\fixtures\tests_data_provider::import_sitsgradepush_grade_components();
        \local_sitsgradepush\tests\fixtures\tests_data_provider::set_marking_scheme_data();
        self::set_students();
    }

    /**
     * Map a source to a SITS assessment component.
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

        // Create the data object.
        $data = (object) [
          'componentgradeid' => $mab->id,
          'sourcetype' => $sourcetype,
          'sourceid' => $source->id,
        ];

        // Map the source to the MAB.
        $manager->save_assessment_mapping($data);
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
        grade_regrade_final_grades($course->id);
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
     * Set students data to cache.
     *
     * @return void
     */
    public static function set_students(): void {
        global $DB;

        $students = [
          [
            'code' => '23456781',
            'spr_code' => '23456781/1',
          ],
          [
            'code' => '23456782',
            'spr_code' => '23456782/1',
          ],
          [
            'code' => '23456783',
            'spr_code' => '23456783/1',
          ],
        ];

        // Get all SITS component grades for 'LAWS0024A6UF'.
        $sitscomponentgrades = $DB->get_records('local_sitsgradepush_mab', ['mapcode' => 'LAWS0024A6UF']);

        if (empty($sitscomponentgrades)) {
            throw new Exception('No SITS component grades found for LAWS0024A6UF');
        }

        foreach ($sitscomponentgrades as $sitscomponentgrade) {
            // Set student cache.
            $key = implode('_', [cachemanager::CACHE_AREA_STUDENTSPR, 'LAWS0024A6UF', $sitscomponentgrade->mabseq]);
            cachemanager::set_cache(cachemanager::CACHE_AREA_STUDENTSPR, $key, $students, 3600);
        }
    }
}
