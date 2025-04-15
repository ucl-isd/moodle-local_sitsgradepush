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

use assign;
use cache;
use context_course;
use context_module;
use local_sitsgradepush\api\client_factory;
use local_sitsgradepush\api\irequest;
use local_sitsgradepush\assessment\assessment;
use local_sitsgradepush\assessment\assessmentfactory;
use moodle_exception;

defined('MOODLE_INTERNAL') || die();
global $CFG;
require_once($CFG->dirroot . '/local/sitsgradepush/tests/fixtures/tests_data_provider.php');
require_once(__DIR__ . '/base_test_class.php');

/**
 * Tests for the manager class.
 *
 * @package    local_sitsgradepush
 * @copyright  2024 onwards University College London {@link https://www.ucl.ac.uk/}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @author     Alex Yeung <k.yeung@ucl.ac.uk>
 */
final class manager_test extends base_test_class {

    /** @var \local_sitsgradepush\manager|null Manager object */
    private ?manager $manager;

    /** @var \ReflectionClass Use to test private method */
    private \ReflectionClass $reflectionmanager;

    /** @var \stdClass $course1 Default test course 1 */
    private \stdClass $course1;

    /** @var \stdClass $course2 Default test course 2 */
    private \stdClass $course2;

    /** @var \testing_data_generator Test data generator */
    private \testing_data_generator $dg;

    /** @var \stdClass Default test student 1 */
    private \stdClass $student1;

    /** @var \stdClass Default test student 2 */
    private \stdClass $student2;

    /** @var \stdClass Default test gradebook category */
    private \stdClass $gradecategory1;

    /** @var \stdClass Default test gradebook item */
    private \stdClass $gradeitem1;

    /** @var int|bool Default test mapping 1 */
    private int|bool $mappingid1;

    /** @var \stdClass Default test component grade 1 */
    private \stdClass $mab1;

    /** @var \stdClass Default test assessment mapping */
    private \stdClass $assessmentmapping1;

    /** @var \stdClass Default test quiz 1*/
    private \stdClass $quiz1;

    /** @var \stdClass Default test assignment 1 */
    private \stdClass $assign1;

    /** @var \stdClass Default test assignment 2 */
    private \stdClass $assign2;

    /** @var \stdClass Default test teacher 1 */
    private \stdClass $teacher1;

    /** @var array Marks transfer types, i.e. Main transfer / Re-assessment */
    private array $markstransfertype = ['main' => 0, 'reassessment' => 1];

    /**
     * Set up the test.
     *
     * @return void
     * @throws \ReflectionException
     * @throws \coding_exception
     * @throws \dml_exception
     */
    protected function setUp(): void {
        global $CFG, $USER;

        parent::setUp();
        $this->resetAfterTest();
        $this->dg = $this->getDataGenerator();

        // Set default configurations.
        $this->set_default_configs();

        // Set current user's custom profile field value.
        require_once($CFG->dirroot . '/user/profile/lib.php');
        $userdata = profile_user_record($USER->id);
        $userdata->id = $USER->id;
        $userdata->profile_field_testfield = 'admin1234';
        profile_save_data($userdata);

        // Set up courses.
        $this->setup_test_courses();

        // Set up the manager.
        $this->manager = manager::get_manager();

        // Set up reflection for the manager.
        $this->reflectionmanager = new \ReflectionClass($this->manager);
    }

    /**
     * Test the constructor with a valid client.
     *
     * @covers \local_sitsgradepush\manager::__construct
     * @return void
     * @throws \ReflectionException
     * @throws \moodle_exception
     */
    public function test_manager_with_valid_api_client(): void {
        $apiclient = client_factory::get_api_client('easikit');

        // Use reflection to access the private constructor.
        $constructor = $this->reflectionmanager->getConstructor();

        // Create an instance of the class without calling the constructor.
        $instance = $this->reflectionmanager->newInstanceWithoutConstructor();
        $constructor->invoke($instance);

        // Assert that the apiclient property is set correctly.
        $apiclientproperty = $this->reflectionmanager->getProperty('apiclient');
        $this->assertEquals($apiclient, $apiclientproperty->getValue($instance));
    }

    /**
     * Test the constructor with an invalid client.
     *
     * @covers \local_sitsgradepush\manager::__construct
     * @return void
     * @throws \ReflectionException
     */
    public function test_manager_with_invalid_api_client(): void {
        // Set empty API client.
        set_config('apiclient', '', 'local_sitsgradepush');

        // Use reflection to access the private properties.
        $instanceproperty = $this->reflectionmanager->getProperty('instance');
        $instanceproperty->setValue(null, null);
        $apierrorsproperty = $this->reflectionmanager->getProperty('apierrors');

        // Get the manager instance.
        $manager = $this->reflectionmanager->getMethod('get_manager')->invoke(null);
        $apierrors = $apierrorsproperty->getValue($manager);

        // Test error message when API client is not set.
        $this->assertStringContainsStringIgnoringCase('API client not set.', $apierrors[0]);
    }

    /**
     * Test the fetch marking scheme method.
     *
     * @covers \local_sitsgradepush\manager::get_manager
     * @return void
     * @throws \ReflectionException
     */
    public function test_get_manager(): void {
        // Use reflection to access the private properties.
        $instanceproperty = $this->reflectionmanager->getProperty('instance');
        $instanceproperty->setValue(null, null);

        // First call to get_manager should create a new instance.
        $firstinstance = manager::get_manager();
        $this->assertInstanceOf(manager::class, $firstinstance);

        // Second call to get_manager should return the same instance.
        $secondinstance = manager::get_manager();
        $this->assertSame($firstinstance, $secondinstance);
    }

    /**
     * Test the fetch component grades from sits method.
     *
     * @covers \local_sitsgradepush\manager::fetch_component_grades_from_sits
     * @return void
     * @throws \ReflectionException
     * @throws \coding_exception
     * @throws \dml_exception
     */
    public function test_fetch_component_grades_from_sits(): void {
        global $DB;

        // Get component grades data.
        $componentgradesdata = tests_data_provider::get_sits_component_grades_data();

        // Set private property apiclient accessible.
        $apiclientproperty = $this->reflectionmanager->getProperty('apiclient');

        // Test error response and no component grades are saved.
        $apiclientproperty->setValue($this->manager, $this->get_apiclient_for_testing(true));
        $modocc = new \stdClass();
        $modocc->mod_code = 'LAWS0024';
        $modocc->mod_occ_mav = 'A6U';
        $modocc->mod_occ_psl_code = 'T1/2';
        $modocc->mod_occ_year_code = '2023';
        $this->manager->fetch_component_grades_from_sits([$modocc]);
        $mabs = $DB->get_records('local_sitsgradepush_mab', ['mapcode' => 'LAWS0024A6UF']);
        $this->assertEmpty($mabs);

        // Test normal response.
        $apiclientproperty->setValue($this->manager, $this->get_apiclient_for_testing(false, $componentgradesdata));
        $this->manager->fetch_component_grades_from_sits([$modocc]);
        $mabs = $DB->get_records('local_sitsgradepush_mab', ['mapcode' => 'LAWS0024A6UF']);
        $this->assertCount(2, $mabs);

        // Test api is not called again if the data is already cached.
        $DB->delete_records('local_sitsgradepush_mab');
        $apiclientproperty->setValue($this->manager, $this->get_apiclient_for_testing(true));
        $this->manager->fetch_component_grades_from_sits([$modocc]);
        $mabs = $DB->get_records('local_sitsgradepush_mab', ['mapcode' => 'LAWS0024A6UF']);
        $this->assertEmpty($mabs);
    }

    /**
     * Test the fetch marking scheme from sits method.
     *
     * @covers \local_sitsgradepush\manager::fetch_marking_scheme_from_sits
     * @return void
     * @throws \ReflectionException
     * @throws \coding_exception
     * @throws \dml_exception
     */
    public function test_fetch_marking_scheme_from_sits(): void {
        // Get marking scheme data.
        $markingschemedata = tests_data_provider::get_sits_marking_scheme_data();

        // Set private property apiclient accessible.
        $property = $this->reflectionmanager->getProperty('apiclient');

        // Test error response.
        $property->setValue($this->manager, $this->get_apiclient_for_testing(true));
        $this->assertNull($this->manager->fetch_marking_scheme_from_sits());

        // Test normal response.
        $property->setValue($this->manager, $this->get_apiclient_for_testing(false, $markingschemedata));

        $this->assertEquals(
          $markingschemedata,
          $this->manager->fetch_marking_scheme_from_sits()
        );

        // Test cache is used if data is already cached.
        $property->setValue($this->manager, $this->get_apiclient_for_testing(true));
        $this->assertEquals(
          $markingschemedata,
          $this->manager->fetch_marking_scheme_from_sits()
        );
    }

    /**
     * Test the is marking scheme supported method.
     *
     * @covers \local_sitsgradepush\manager::is_marking_scheme_supported
     * @return void
     * @throws \ReflectionException
     * @throws \coding_exception
     * @throws \dml_exception
     */
    public function test_is_marking_scheme_supported(): void {
        // Set marking scheme data.
        $markingschemedata = tests_data_provider::get_sits_marking_scheme_data();

        // Set private property apiclient accessible.
        $property = $this->reflectionmanager->getProperty('apiclient');

        // Set api client to return marking scheme data.
        $property->setValue($this->manager, $this->get_apiclient_for_testing(false, $markingschemedata));
        $mab = new \stdClass();

        // Test marking scheme is not supported.
        $mab->mkscode = 'UGM05';
        $this->assertFalse($this->manager->is_marking_scheme_supported($mab));

        // Test marking scheme is supported.
        $mab->mkscode = 'UNA01';
        $this->assertTrue($this->manager->is_marking_scheme_supported($mab));
    }

    /**
     * Test the get component grade options method.
     *
     * @covers \local_sitsgradepush\manager::get_component_grade_options
     * @return void
     * @throws \ReflectionException
     * @throws \coding_exception
     * @throws \dml_exception
     * @throws \moodle_exception
     */
    public function test_get_component_grade_options(): void {
        $this->setup_testing_environment();
        $options = $this->manager->get_component_grade_options($this->course1->id);
        $this->assertCount(4, $options);

        // Test course with no module occurrence mappings.
        $options = $this->manager->get_component_grade_options($this->course2->id);
        $this->assertEmpty($options);
    }

    /**
     * Test the get decoded mod occ mav method.
     *
     * @covers \local_sitsgradepush\manager::get_decoded_mod_occ_mav
     * @return void
     */
    public function test_get_decoded_mod_occ_mav(): void {
        $this->assertEquals(['level' => '6', 'graduatetype' => 'UNDERGRADUATE'], $this->manager->get_decoded_mod_occ_mav('A6U'));
        $this->assertEquals(['level' => '7', 'graduatetype' => 'POSTGRADUATE'], $this->manager->get_decoded_mod_occ_mav('A7P'));
    }

    /**
     * Test the get local component grades method.
     *
     * @covers \local_sitsgradepush\manager::get_local_component_grades
     * @return void
     * @throws \ReflectionException
     * @throws \coding_exception
     * @throws \dml_exception
     * @throws \moodle_exception
     */
    public function test_get_local_component_grades(): void {
        // Set up the test environment.
        $this->setup_testing_environment();

        // This module occurrence has two component grades.
        $modocc = new \stdClass();
        $modocc->mod_code = 'LAWS0024';
        $modocc->mod_occ_name = 'Family Law';
        $modocc->mod_occ_mav = 'A6U';
        $modocc->mod_occ_psl_code = 'T1/2';
        $modocc->mod_occ_year_code = '2023';

        $this->assertCount(2, $this->manager->get_local_component_grades([$modocc])[0]->componentgrades);

        // Test unsupported component grade.
        $modocc->mod_code = 'CCME0158';
        $modocc->mod_occ_name = 'Advanced Filmmaking - Making the Media V';
        $modocc->mod_occ_mav = 'A6U';
        $modocc->mod_occ_psl_code = 'T1';
        $modocc->mod_occ_year_code = '2023';
        $mab = reset($this->manager->get_local_component_grades([$modocc])[0]->componentgrades);
        $this->assertStringContainsString('Centrally managed exam NOT due to take place in Moodle', $mab->unavailablereasons);

        // Test no component grades for this module occurrence.
        $modocc->mod_code = 'LAWS0025';
        $modocc->mod_occ_name = 'Family Law';
        $modocc->mod_occ_mav = 'A6U';
        $modocc->mod_occ_psl_code = 'T1/2';
        $modocc->mod_occ_year_code = '2023';
        $this->assertEmpty($this->manager->get_local_component_grades([$modocc])[0]->componentgrades);
    }

    /**
     * Test the is component grade valid for mapping method.
     *
     * @covers \local_sitsgradepush\manager::is_component_grade_valid_for_mapping
     * @return void
     * @throws \ReflectionException
     * @throws \coding_exception
     * @throws \dml_exception
     * @throws \moodle_exception
     */
    public function test_is_component_grade_valid_for_mapping(): void {
        global $DB;
        // Set up the test environment.
        $this->setup_testing_environment();

        // Test component grade is not supported due to no exam room code.
        $mab = $DB->get_record('local_sitsgradepush_mab', ['mapcode' => 'CCME0158A6UF', 'mabseq' => '001']);
        $this->assertFalse($this->manager->is_component_grade_valid_for_mapping($mab)[0]);
        $this->assertStringContainsString(
          get_string('error:ast_code_exam_room_code_not_matched', 'local_sitsgradepush'),
          $this->manager->is_component_grade_valid_for_mapping($mab)[1][0]
        );

        // Test component grade is not supported due to invalid assessment type.
        $mab = $DB->get_record('local_sitsgradepush_mab', ['mapcode' => 'COMP0206F4UF', 'mabseq' => '001']);
        $this->assertFalse($this->manager->is_component_grade_valid_for_mapping($mab)[0]);
        $this->assertStringContainsString(
          get_string('error:ast_code_not_supported', 'local_sitsgradepush', $mab->astcode),
          $this->manager->is_component_grade_valid_for_mapping($mab)[1][0]
        );

        // Test component grade is not supported due to invalid marking scheme.
        $mab = $DB->get_record('local_sitsgradepush_mab', ['mapcode' => 'BASC0059A4UF', 'mabseq' => '001']);
        $this->assertFalse($this->manager->is_component_grade_valid_for_mapping($mab)[0]);
        $this->assertStringContainsString(
          get_string('error:mks_scheme_not_supported', 'local_sitsgradepush'),
          $this->manager->is_component_grade_valid_for_mapping($mab)[1][0]
        );
    }

    /**
     * Test the save component grades method.
     *
     * @covers \local_sitsgradepush\manager::save_component_grades
     * @return void
     * @throws \coding_exception
     * @throws \dml_exception
     */
    public function test_save_component_grades(): void {
        global $DB;
        // Two component grades to save.
        $sitscomponentgrades = tests_data_provider::get_sits_component_grades_data();
        $this->manager->save_component_grades($sitscomponentgrades);
        $mabs = $DB->get_records('local_sitsgradepush_mab');

        // Test that the component grades have been saved.
        $this->assertCount(2, $mabs);

        // Modify the component grades.
        $sitscomponentgrades[0]['MAB_NAME'] = 'Test update';

        // Test that the component grades can be updated.
        $this->manager->save_component_grades($sitscomponentgrades);
        $mab = $DB->get_record('local_sitsgradepush_mab', ['mapcode' => 'LAWS0024A6UF', 'mabseq' => '001']);
        $this->assertEquals('Test update', $mab->mabname);
    }

    /**
     * Test the save assessment mapping method.
     *
     * @covers \local_sitsgradepush\manager::save_assessment_mapping
     * @covers \local_sitsgradepush\manager::get_assessment_mappings
     * @covers \local_sitsgradepush\manager::is_component_grade_mapped
     * @covers \local_sitsgradepush\manager::get_local_component_grade_by_id
     * @return void
     * @throws \ReflectionException
     * @throws \coding_exception
     * @throws \dml_exception
     * @throws \moodle_exception
     */
    public function test_save_assessment_mapping(): void {
        // Set up the test environment.
        $this->setup_testing_environment();

        // Test for each marks transfer type.
        foreach ($this->markstransfertype as $type) {
            // Test component grade is found.
            $this->assertNotEmpty($this->manager->get_local_component_grade_by_id($this->mab1->id));

            // Test that the component grade is not mapped.
            $this->assertFalse($this->manager->is_component_grade_mapped($this->mab1->id, $type));

            // Create a test assignment.
            $assign = $this->dg->create_module('assign', ['course' => $this->course1->id]);

            $data  = new \stdClass();
            $data->componentgradeid = $this->mab1->id;
            $data->sourcetype = 'mod';
            $data->sourceid = $assign->cmid;
            $data->reassessment = $type;
            $this->manager->save_assessment_mapping($data);

            $assessment = assessmentfactory::get_assessment('mod', $assign->cmid);

            // Test that the mapping has been saved.
            $mapping = $this->manager->get_assessment_mappings($assessment, $this->mab1->id);
            $this->assertEquals($assign->cmid, $mapping->sourceid);

            // Create a test quiz.
            $quiz = $this->dg->create_module('quiz', ['course' => $this->course1->id]);

            // Test that the mapping can be updated.
            $data->sourceid = $quiz->cmid;
            $this->manager->save_assessment_mapping($data);
            $assessment = assessmentfactory::get_assessment('mod', $quiz->cmid);
            $mapping = $this->manager->get_assessment_mappings($assessment, $this->mab1->id);
            $this->assertEquals($quiz->cmid, $mapping->sourceid);

            // Test that the component grade is mapped.
            $this->assertNotEmpty($this->manager->is_component_grade_mapped($this->mab1->id, $type));
        }
    }

    /**
     * Test the get api client list method.
     *
     * @covers \local_sitsgradepush\manager::get_api_client_list
     * @return void
     * @throws \moodle_exception
     */
    public function test_get_api_client_list(): void {
        // Expected result.
        $expected = [
          'easikit' => 'Easikit',
          'stutalkdirect' => 'Stutalk Direct',
        ];

        // Test that the list of API clients is returned.
        $this->assertEquals($expected, $this->manager->get_api_client_list());
    }

    /**
     * Test the get student from sits method.
     *
     * @covers \local_sitsgradepush\manager::get_student_from_sits
     * @covers \local_sitsgradepush\manager::get_students_from_sits
     * @return void
     * @throws \ReflectionException
     * @throws \coding_exception
     * @throws \dml_exception
     * @throws \moodle_exception
     */
    public function test_get_student_from_sits(): void {
        // Set up the test environment.
        $this->setup_testing_environment();

        // Clear cache.
        cache::make('local_sitsgradepush', cachemanager::CACHE_AREA_STUDENTSPR)->purge();

        // Test student is found.
        $student = $this->manager->get_student_from_sits($this->mab1, $this->student1->id);
        $this->assertEquals('12345678/1', $student['spr_code'] );

        // Get student cache.
        $key = implode('_', [cachemanager::CACHE_AREA_STUDENTSPR, $this->mab1->mapcode, $this->mab1->mabseq, 1]);
        $students = cachemanager::get_cache(cachemanager::CACHE_AREA_STUDENTSPR, $key);

        // Test student is found in cache.
        $this->assertSame($students, $this->manager->get_students_from_sits($this->mab1));

        // Test null is returned if student is not found.
        $this->assertNull($this->manager->get_student_from_sits($this->mab1, $this->student2->id));

        // Test exception is thrown if stutalkdirect api client is used.
        set_config('apiclient', 'stutalkdirect', 'local_sitsgradepush');
        $apiclient = $this->get_apiclient_for_testing(false, [
          ['code' => 12345678, 'spr_code' => '12345678/1', 'forename' => 'Test', 'surname' => 'Student'],
        ]);
        tests_data_provider::set_protected_property($this->manager, 'apiclient', $apiclient);
        $this->expectException(\moodle_exception::class);
        $this->manager->get_student_from_sits($this->mab1, $this->student1->id);
    }

    /**
     * Test the push grade to sits method.
     *
     * @covers \local_sitsgradepush\manager::push_grade_to_sits
     * @covers \local_sitsgradepush\manager::get_transfer_logs
     * @covers \local_sitsgradepush\manager::has_grades_pushed
     * @covers \local_sitsgradepush\manager::validate_component_grade
     * @covers \local_sitsgradepush\manager::last_push_succeeded
     * @covers \local_sitsgradepush\manager::mark_push_as_failed
     * @return void
     * @throws \ReflectionException
     * @throws \coding_exception
     * @throws \dml_exception
     * @throws \moodle_exception
     */
    public function test_push_grade_to_sits(): void {
        global $DB;

        // Get assessment.
        $assessment = assessmentfactory::get_assessment('mod', $this->assign1->cmid);

        // Set up the testing environment.
        $this->setup_testing_environment($assessment);

        // Get assessment data of assignment 1.
        $assessmentdata = $this->manager->get_assessment_data('mod', $this->assign1->cmid);

        // Assignment 1 only has one mapping.
        $mapping = reset($assessmentdata['mappings']);

        // Set API client to throw exception.
        $apiclient = $this->get_apiclient_for_testing(true);
        tests_data_provider::set_protected_property($this->manager, 'apiclient', $apiclient);

        // Test grade push fails.
        $this->assertFalse($this->manager->push_grade_to_sits(reset($assessmentdata['mappings']), $this->student1->id));
        $transfer = $DB->get_record('local_sitsgradepush_tfr_log', ['type' => 'pushgrade', 'assessmentmappingid' => $mapping->id]);
        $this->assertStringContainsString('Easikit web client error', $transfer->response);

        // Test last push is failed.
        $lastpushsucceeded = $this->reflectionmanager->getMethod('last_push_succeeded');
        $this->assertFalse($lastpushsucceeded->invoke($this->manager, $mapping->id, $this->student1->id, 'pushgrade'));

        // Pause for a second to avoid same timestamp.
        sleep(1);

        // Set API client to return success response.
        $apiclient = $this->get_apiclient_for_testing(
          false,
          ['code' => '0', 'message' => 'Grade successfully pushed to SITS via Easikit']
        );
        tests_data_provider::set_protected_property($this->manager, 'apiclient', $apiclient);

        // Test grade push is successful.
        $this->assertTrue($this->manager->push_grade_to_sits($mapping, $this->student1->id));
        $transfers = $DB->get_records(
          'local_sitsgradepush_tfr_log', ['type' => 'pushgrade', 'assessmentmappingid' => $mapping->id], 'id DESC');
        $this->assertStringContainsString('Grade successfully pushed to SITS via Easikit', reset($transfers)->response);

        // Test last push is succeeded.
        $lastpushsucceeded = $this->reflectionmanager->getMethod('last_push_succeeded');
        $this->assertTrue($lastpushsucceeded->invoke($this->manager, $mapping->id, $this->student1->id, 'pushgrade'));

        // Test grade is pushed.
        $this->assertTrue($this->manager->has_grades_pushed($mapping->id));

        // Test exception is thrown when validate for mapping changes.
        try {
            $this->manager->validate_component_grade($this->mab1->id, 'mod', $this->assign1->cmid, $mapping->reassessment);
        } catch (\moodle_exception $e) {
            $this->assertStringContainsString(
              get_string('error:mab_has_push_records', 'local_sitsgradepush', $this->mab1->mapcode . '-' .$this->mab1->mabseq),
              $e->getMessage()
            );
        }

        // Test the latest transfer log is returned.
        $latesttransfer = $this->manager->get_transfer_logs($mapping->id, $this->student1->id, 'pushgrade');
        $this->assertCount(1, $latesttransfer);
        $this->assertEquals(reset($transfers)->id, reset($latesttransfer)->id);

        // Test same mark is not pushed again.
        $this->assertFalse($this->manager->push_grade_to_sits($mapping, $this->student1->id));

        // Test grade push with no grade.
        $this->assertFalse($this->manager->push_grade_to_sits($mapping, $this->student2->id));
    }

    /**
     * Test the push submission log to sits method.
     *
     * @covers \local_sitsgradepush\manager::push_submission_log_to_sits
     * @covers \local_sitsgradepush\manager::get_transfer_logs
     * @return void
     * @throws \ReflectionException
     * @throws \coding_exception
     * @throws \dml_exception
     * @throws \moodle_exception
     */
    public function test_push_submission_log_to_sits(): void {
        global $DB;

        // Get assessment.
        $assessment = assessmentfactory::get_assessment('mod', $this->assign1->cmid);

        // Set up the testing environment.
        $this->setup_testing_environment($assessment);

        // Get assessment data of assignment 1.
        $assessmentdata = $this->manager->get_assessment_data('mod', $this->assign1->cmid);

        // Assignment 1 only has one mapping.
        $mapping = reset($assessmentdata['mappings']);

        // Set API client to throw exception.
        $apiclient = $this->get_apiclient_for_testing(true);
        tests_data_provider::set_protected_property($this->manager, 'apiclient', $apiclient);

        // Test submission log push fails.
        $this->assertFalse($this->manager->push_submission_log_to_sits($mapping, $this->student1->id));
        $transfer = $DB->get_record(
          'local_sitsgradepush_tfr_log', ['type' => 'pushsubmissionlog', 'assessmentmappingid' => $mapping->id]);
        $this->assertStringContainsString('Easikit web client error', $transfer->response);

        // Pause for a second to avoid same timestamp.
        sleep(1);

        // Set API client to return success response.
        $apiclient = $this->get_apiclient_for_testing(
          false,
          ['code' => '0', 'message' => 'Submission log successfully pushed to SITS via Easikit']
        );
        tests_data_provider::set_protected_property($this->manager, 'apiclient', $apiclient);

        // Test submission log push is successful.
        $this->assertTrue($this->manager->push_submission_log_to_sits($mapping, $this->student1->id));
        $transfers = $DB->get_records(
          'local_sitsgradepush_tfr_log', ['type' => 'pushsubmissionlog', 'assessmentmappingid' => $mapping->id], 'id DESC');
        $this->assertStringContainsString('Submission log successfully pushed to SITS via Easikit', reset($transfers)->response);

        // Test the latest transfer log is returned.
        $latesttransfer = $this->manager->get_transfer_logs($mapping->id, $this->student1->id, 'pushsubmissionlog');
        $this->assertCount(1, $latesttransfer);
        $this->assertEquals(reset($transfers)->id, reset($latesttransfer)->id);

        // Test same submission log is not pushed again.
        $this->assertFalse($this->manager->push_submission_log_to_sits($mapping, $this->student1->id));

        // Test grade push with no submission.
        $this->assertFalse($this->manager->push_submission_log_to_sits($mapping, $this->student2->id));

        // Test submission log push disabled.
        set_config('sublogpush', 0, 'local_sitsgradepush');
        $this->assertFalse($this->manager->push_submission_log_to_sits($mapping, $this->student1->id));
    }

    /**
     * Test the push grade to sits method with grade item.
     *
     * @covers \local_sitsgradepush\manager::push_submission_log_to_sits
     * @return void
     * @throws \ReflectionException
     * @throws \coding_exception
     * @throws \dml_exception
     * @throws \moodle_exception
     */
    public function test_push_submission_log_to_sits_with_grade_item(): void {
        // Create a test assignment.
        $this->create_grade_category_and_grade_item();

        // Get assessment.
        $assessment = assessmentfactory::get_assessment('gradeitem', $this->gradeitem1->id);

        // Set up the testing environment.
        $this->setup_testing_environment($assessment);

        // Get assessment data of assignment 1.
        $assessmentdata = $this->manager->get_assessment_data('gradeitem', $this->gradeitem1->id);

        // Assignment 1 only has one mapping.
        $mapping = reset($assessmentdata['mappings']);

        // Test submission log push fails because grade item has no submission.
        $this->assertFalse($this->manager->push_submission_log_to_sits($mapping, $this->student1->id));
    }

    /**
     * Test the get required data for pushing method.
     *
     * @covers \local_sitsgradepush\manager::get_required_data_for_pushing
     * @return void
     * @throws \coding_exception
     * @throws \dml_exception
     * @throws \moodle_exception|\ReflectionException
     */
    public function test_get_required_data_for_pushing(): void {
        global $DB, $USER;

        // Map the assignment to a SITS component grade.
        $assessment = assessmentfactory::get_assessment('mod', $this->assign1->cmid);
        $this->setup_testing_environment($assessment);

        // Test exception is thrown when no student is found.
        try {
            $this->manager->get_required_data_for_pushing($this->assessmentmapping1, $this->student2->id);
        } catch (\moodle_exception $e) {
            $this->assertStringContainsString(
              get_string('error:nostudentfoundformapping', 'local_sitsgradepush'),
              $e->getMessage()
            );
        }

        // Test exception is thrown if student resit number is 0 for a re-assessment mapping.
        try {
            // Update the mapping to be a re-assessment, the student should have a resit number greater than 0, but it is 0 now.
            $DB->set_field('local_sitsgradepush_mapping', 'reassessment', 1, ['id' => $this->assessmentmapping1->id]);
            $this->assessmentmapping1 = $this->manager->get_assessment_mappings($assessment, $this->mab1->id);
            $this->manager->get_required_data_for_pushing($this->assessmentmapping1, $this->student1->id);
        } catch (\moodle_exception $e) {
            $this->assertStringContainsString(
              get_string('error:resit_number_zero_for_reassessment', 'local_sitsgradepush'),
              $e->getMessage()
            );
        }

        // Test required data is correctly returned for each marks transfer type.
        foreach ($this->markstransfertype as $type) {
            $assignmod = $this->dg->create_module('assign', ['course' => $this->course1->id]);

            // Get assessment.
            $assessment = assessmentfactory::get_assessment('mod', $assignmod->cmid);

            // Set up the testing environment.
            $this->setup_testing_environment($assessment, $type, true);

            $expected = (object)[
              'mapcode' => 'LAWS0024A6UF',
              'mabseq' => '001',
              'sprcode' => '12345678/1',
              'academicyear' => '2023',
              'pslcode' => 'T1/2',
              'reassessment' => $type,
              'source' => 'moodle-course'. $this->course1->id . '-' .$assessment->get_type() . $assessment->get_id()
                          . '-user' . $USER->id,
              'srarseq' => $type,
            ];

            $this->assertEquals(
              $expected,
              $this->manager->get_required_data_for_pushing($this->assessmentmapping1, $this->student1->id)
            );
        }
    }

    /**
     * Test the get assessment data method.
     *
     * @covers \local_sitsgradepush\manager::get_assessment_data
     * @return void
     * @throws \coding_exception
     * @throws \dml_exception
     * @throws \moodle_exception|\ReflectionException
     */
    public function test_get_assessment_data(): void {
        // Test no mapping for the assessment.
        $this->assertEmpty($this->manager->get_assessment_data('mod', $this->assign1->cmid));

        // Set up the testing environment.
        $this->setup_testing_environment(assessmentfactory::get_assessment('mod', $this->assign1->cmid));

        // Test only the assessment data for a specific mapping is returned.
        $this->assertInstanceOf(
          \stdClass::class,
          $this->manager->get_assessment_data('mod', $this->assign1->cmid, $this->mappingid1)
        );

        // Test array is returned if no mapping id is provided.
        $this->assertIsArray($this->manager->get_assessment_data('mod', $this->assign1->cmid));
    }

    /**
     * Test the sort grade push history table method.
     *
     * @covers \local_sitsgradepush\manager::sort_grade_push_history_table
     * @return void
     * @throws \ReflectionException
     */
    public function test_sort_grade_push_history_table(): void {
        $dataarray = tests_data_provider::get_sort_grade_push_history_table_data();

        $dataobjects = array_map(function($item) {
            return (object)$item;
        }, $dataarray);

        // Call the method and store the result.
        $result = $this->reflectionmanager->getMethod('sort_grade_push_history_table')->invoke($this->manager, $dataobjects);
        $extracted = array_column($result, 'testcase');

        // Assertions to ensure the sorting is correct.
        $this->assertEquals(
          ['transfererror1', 'transfererror2', 'updatedaftertransfer', 'sublogerror1', 'sublogerror2', 'other', 'notyetpushed'],
          $extracted
        );
    }

    /**
     * Test is current academic year activity method.
     *
     * @covers \local_sitsgradepush\manager::is_current_academic_year_activity
     * @return void
     * @throws \dml_exception
     */
    public function test_is_current_academic_year_activity(): void {
        // Test course 1 is in the current academic year.
        $this->assertTrue($this->manager->is_current_academic_year_activity($this->course1->id));

        // Set LSA end date to a past date.
        set_config('late_summer_assessment_end_' . date('Y'), date('Y-m-d', strtotime('-2 month')), 'block_lifecycle');
        // Test course 1 is not in the current academic year now.
        $this->assertFalse($this->manager->is_current_academic_year_activity($this->course1->id));

        // Test course 2 is not in the current academic year because it has no "course_year" custom field.
        $this->assertFalse($this->manager->is_current_academic_year_activity($this->course2->id));
    }

    /**
     * Test the get moodle AST codes method.
     *
     * @covers \local_sitsgradepush\manager::get_moodle_ast_codes
     * @return void
     * @throws \dml_exception
     */
    public function test_get_moodle_ast_codes(): void {
        // Test AST codes returned.
        $this->assertEquals(
          ['BC02', 'CN01', 'EC03', 'EC04', 'ED03', 'ED04', 'GD01', 'GN01', 'GN02', 'GN03', 'HC01', 'HD01', 'HD02',
           'HD03', 'HD04', 'HD05', 'LC01', 'MD01', 'ND01', 'RN01', 'RN02', 'RN03', 'SD01', 'TC01', 'ZD01', 'ZN01'],
          $this->manager->get_moodle_ast_codes()
        );

        // Set AST codes to empty.
        set_config('moodle_ast_codes', '', 'local_sitsgradepush');

        // Test empty AST codes returned.
        $this->assertEmpty($this->manager->get_moodle_ast_codes());
    }

    /**
     * Test the get moodle AST codes work with exam room code method.
     *
     * @covers \local_sitsgradepush\manager::get_moodle_ast_codes_work_with_exam_room_code
     * @return void
     * @throws \dml_exception
     */
    public function test_get_moodle_ast_codes_work_with_exam_room_code(): void {
        // Test AST codes returned.
        $this->assertEquals(
          ['BC02', 'EC03', 'EC04', 'ED03', 'ED04'],
          $this->manager->get_moodle_ast_codes_work_with_exam_room_code()
        );

        // Set AST codes to empty.
        set_config('moodle_ast_codes_exam_room', '', 'local_sitsgradepush');

        // Test empty AST codes returned.
        $this->assertEmpty($this->manager->get_moodle_ast_codes_work_with_exam_room_code());
    }

    /**
     * Test the get user profile fields method.
     *
     * @covers \local_sitsgradepush\manager::get_user_profile_fields
     * @return void
     * @throws \dml_exception
     */
    public function test_get_user_profile_fields(): void {
        $fields = $this->manager->get_user_profile_fields();

        // Extract the field shortnames.
        $fieldshortnames = array_map(function($field) {
            return $field->shortname;
        }, $fields);

        // Test user profile fields returned.
        $this->assertContains('testfield', $fieldshortnames);
    }

    /**
     * Test the get export staff method.
     *
     * @covers \local_sitsgradepush\manager::get_export_staff
     * @return void
     * @throws \dml_exception
     */
    public function test_get_export_staff(): void {
        // Test testfield value is returned for the current user.
        $this->assertEquals('admin1234', $this->manager->get_export_staff());

        // Set user profile field to empty.
        set_config('user_profile_field', '', 'local_sitsgradepush');

        // Test no staff information is returned.
        $this->assertEmpty($this->manager->get_export_staff());
    }

    /**
     * Test the validate component grade method.
     *
     * @covers \local_sitsgradepush\manager::validate_component_grade
     * @return void
     * @throws \coding_exception
     * @throws \moodle_exception|\ReflectionException
     */
    public function test_validate_component_grade(): void {
        global $DB;

        foreach ($this->markstransfertype as $type) {
            // Test exception is thrown if the component grade is not found.
            $assignmod = $this->dg->create_module('assign', ['course' => $this->course1->id]);

            try {
                $this->manager->validate_component_grade(0, 'mod', $assignmod->cmid, $type);
            } catch (\moodle_exception $e) {
                $this->assertStringContainsString(get_string('error:mab_not_found', 'local_sitsgradepush', 0), $e->getMessage());
            }

            // Set up the test environment.
            $assign1 = assessmentfactory::get_assessment('mod', $assignmod->cmid);
            $this->setup_testing_environment($assign1, $type);

            // Test exception is thrown if try to map the same activity again.
            try {
                $this->manager->validate_component_grade($this->mab1->id, 'mod', $assignmod->cmid, $type);
            } catch (\moodle_exception $e) {
                $this->assertStringContainsString(
                  get_string('error:no_update_for_same_mapping', 'local_sitsgradepush'),
                  $e->getMessage()
                );
            }

            // Test exception is thrown if try to map another component grade with same map code.
            try {
                $mab2 = $DB->get_record('local_sitsgradepush_mab', ['mapcode' => 'LAWS0024A6UF', 'mabseq' => '002']);
                $this->manager->validate_component_grade($mab2->id, 'mod', $assignmod->cmid, $type);
            } catch (\moodle_exception $e) {
                $this->assertStringContainsString(
                  get_string('error:same_map_code_for_same_activity', 'local_sitsgradepush'),
                  $e->getMessage()
                );
            }

            // Test exception is thrown when trying to map a gradebook item or category while gradebook feature is disabled.
            try {
                set_config('gradebook_enabled', 0, 'local_sitsgradepush');
                $this->manager->validate_component_grade($this->mab1->id, 'gradeitem', $this->gradeitem1->id, $type);
            } catch (\moodle_exception $e) {
                $this->assertStringContainsString(get_string('error:gradebook_disabled', 'local_sitsgradepush'), $e->getMessage());
            }

            // Test exception is thrown if no grade item is found.
            try {
                $quiz = assessmentfactory::get_assessment('mod', $this->quiz1->cmid);
                $gradeitems = $quiz->get_grade_items();
                foreach ($gradeitems as $gradeitem) {
                    $DB->delete_records('grade_items', ['id' => $gradeitem->id]);
                }
                $this->manager->validate_component_grade($this->mab1->id, 'mod', $this->quiz1->cmid, $type);
            } catch (\moodle_exception $e) {
                $this->assertStringContainsString(
                  get_string('error:grade_items_not_found', 'local_sitsgradepush'),
                  $e->getMessage()
                );
            }

            // Test exception is thrown when trying to map an assessment with grade type which is not 'value' type.
            try {
                $this->manager->validate_component_grade($this->mab1->id, 'mod', $this->assign2->cmid, $type);
            } catch (\moodle_exception $e) {
                $this->assertStringContainsString(
                  get_string('error:gradetype_not_supported', 'local_sitsgradepush'),
                  $e->getMessage()
                );
            }

            // Re-assessment is not restricted to the current academic year.
            if ($type == 1) {
                continue;
            }

            // Test exception is thrown if the activity is not in the current academic year.
            try {
                // Set LSA end date to a past date.
                set_config('late_summer_assessment_end_' . date('Y'), date('Y-m-d', strtotime('-2 month')), 'block_lifecycle');
                $this->manager->validate_component_grade($this->mab1->id, 'mod', $assignmod->cmid, $type);
            } catch (\moodle_exception $e) {
                $this->assertStringContainsString(get_string('error:pastactivity', 'local_sitsgradepush'), $e->getMessage());
            }

            // Reset LSA end date to a future date.
            set_config('late_summer_assessment_end_' . date('Y'), date('Y-m-d', strtotime('+2 month')), 'block_lifecycle');
        }
    }

    /**
     * Test the get all course activities method.
     *
     * @covers \local_sitsgradepush\manager::get_all_course_activities
     * @return void
     * @throws \moodle_exception
     */
    public function test_get_all_course_activities(): void {
        // Test all course activities are returned.
        $activities = $this->manager->get_all_course_activities($this->course1->id);
        $this->assertCount(3, $activities);

        // Test no activities are returned for a course with no activities.
        $this->assertEmpty($this->manager->get_all_course_activities($this->course2->id));
    }

    /**
     * Test the get data for page update method.
     *
     * @covers \local_sitsgradepush\manager::get_data_for_page_update
     * @return void
     * @throws \coding_exception
     * @throws \dml_exception
     * @throws \moodle_exception|\ReflectionException
     */
    public function test_get_data_for_page_update(): void {
        // Test empty array is returned when the course activity has no mapping.
        $this->assertEmpty($this->manager->get_data_for_page_update($this->course1->id, 'mod', $this->assign1->cmid));

        // Test empty array is returned when the course has no mapping.
        $this->assertEmpty($this->manager->get_data_for_page_update($this->course2->id));

        // Set up the test environment.
        $this->setup_testing_environment(assessmentfactory::get_assessment('mod', $this->assign1->cmid));

        // Expected result.
        $expected = [
            (object)[
              'assessmentmappingid' => $this->mappingid1,
              'courseid' => $this->course1->id,
              'sourcetype' => 'mod',
              'sourceid' => $this->assign1->cmid,
              'markscount' => 1,
              'nonsubmittedcount' => 0,
              'task' => null,
              'lasttransfertime' => null],
            ];

        // Test the data for page update is returned.
        $this->assertEquals($expected, $this->manager->get_data_for_page_update($this->course1->id, 'mod', $this->assign1->cmid));
    }

    /**
     * Test the get gradebook assessments method.
     *
     * @covers \local_sitsgradepush\manager::get_gradebook_assessments
     * @return void
     * @throws \dml_exception
     * @throws \moodle_exception
     */
    public function test_get_gradebook_assessments(): void {
        // Test all gradebook assessments are returned.
        $assessments = $this->manager->get_gradebook_assessments($this->course1->id);
        $this->assertCount(2, $assessments);

        // Test no gradebook assessments are returned when gradebook feature is disabled.
        set_config('gradebook_enabled', 0, 'local_sitsgradepush');
        $this->assertEmpty($this->manager->get_gradebook_assessments($this->course2->id));
    }

    /**
     * Test the get all summative grade items method.
     *
     * @covers \local_sitsgradepush\manager::get_all_summative_grade_items
     * @return void
     * @throws \dml_exception
     * @throws \moodle_exception|\ReflectionException
     */
    public function test_get_all_summative_grade_items(): void {
        global $DB;

        // Test no summative grade items are returned as no activity is mapped.
        $this->assertEmpty($this->manager->get_all_summative_grade_items($this->course1->id));

        // Set up the test environment.
        $this->setup_testing_environment(assessmentfactory::get_assessment('mod', $this->assign1->cmid));

        // Test all summative grade items are returned.
        $this->assertCount(1, $this->manager->get_all_summative_grade_items($this->course1->id));

        // Test assessment with no grade item is skipped.
        $assessment = assessmentfactory::get_assessment('mod', $this->assign1->cmid);
        $gradeitems = $assessment->get_grade_items();
        foreach ($gradeitems as $gradeitem) {
            $DB->delete_records('grade_items', ['id' => $gradeitem->id]);
        }
        $this->assertEmpty($this->manager->get_all_summative_grade_items($this->course1->id));
    }

    /**
     * Test the save transfer log method.
     *
     * @covers \local_sitsgradepush\manager::save_transfer_log
     * @return void
     * @throws \ReflectionException
     * @throws \dml_exception
     * @throws \moodle_exception
     */
    public function test_save_transfer_log(): void {
        global $DB;

        // Set up the test environment.
        $this->setup_testing_environment(assessmentfactory::get_assessment('mod', $this->assign1->cmid));

        // Create request object.
        $request = $this->createMock(irequest::class);
        $request->method('get_request_name')->willReturn('Push Grade');
        $request->method('get_endpoint_url_with_params')->willReturn(
          'https://student.integration-dev.ucl.ac.uk/assessment/v1/moodle/ARCL0018A6UF-001/student/23456789_1-2023-T2-0/marks'
        );
        $request->method('get_request_body')->willReturn(
          '{"actual_mark":"85.00000","actual_grade":"","source":"moodle-course40812-mapping1593-user2"}'
        );

        $response = [
          'code' => 0,
          'message' => 'Grade successfully pushed to SITS via Easikit',
        ];

        $savetransferlog = $this->reflectionmanager->getMethod('save_transfer_log');
        $savetransferlog->invoke($this->manager, 'pushgrade', $this->mappingid1, $this->student1->id, $request, $response, null);

        // Test can save transfer log.
        $this->assertCount(1, $DB->get_records('local_sitsgradepush_tfr_log', ['type' => 'pushgrade']));
    }

    /**
     * Test the can change source method.
     *
     * @covers \local_sitsgradepush\manager::can_change_source
     * @return void
     * @throws \coding_exception
     * @throws \dml_exception
     * @throws \moodle_exception|\ReflectionException
     */
    public function test_can_change_source(): void {
        global $DB;

        foreach ($this->markstransfertype as $type) {
            $assignmod = $this->dg->create_module('assign', ['course' => $this->course1->id]);

            // Set up the test environment.
            $this->setup_testing_environment(assessmentfactory::get_assessment('mod', $assignmod->cmid), $type, true);

            // Test the component grade can change source.
            $this->assertTrue($this->manager->can_change_source($this->mab1->id, $type));

            // Test the component grade can change source if it has no mapping.
            $mab2 = $DB->get_record('local_sitsgradepush_mab', ['mapcode' => 'LAWS0024A6UF', 'mabseq' => '002']);
            $this->assertTrue($this->manager->can_change_source($mab2->id, $type));

            // Set the current user to user who has grade push capability.
            $this->setUser($this->teacher1);

            // Set the component grade as pushed.
            taskmanager::schedule_push_task($this->mappingid1, ['recordnonsubmission' => false]);

            // Test the component grade can not change source once it has been pushed.
            $this->assertFalse($this->manager->can_change_source($this->mab1->id, $type));
        }
    }

    /**
     * Test the get formatted marks method.
     *
     * @covers \local_sitsgradepush\manager::get_formatted_marks
     * @return void
     */
    public function test_get_formatted_marks(): void {
        $testmarks = 86.56789;
        // Set course's grade decimal points to 4.
        grade_set_setting($this->course1->id, 'decimalpoints', 4);
        $this->assertEquals('86.5679', $this->manager->get_formatted_marks($this->course1->id, $testmarks));

        // Set course's grade decimal points to 2.
        grade_set_setting($this->course1->id, 'decimalpoints', 2);
        $this->assertEquals('86.57', $this->manager->get_formatted_marks($this->course1->id, $testmarks));
    }

    /**
     * Test the check response method.
     *
     * @covers \local_sitsgradepush\manager::check_response
     * @return void
     * @throws \ReflectionException
     */
    public function test_check_response(): void {
        // Create a request object.
        $request = $this->createMock(irequest::class);
        $request->method('get_request_name')->willReturn('Get student');
        // Test exception is thrown for empty response.
        $this->expectException(\moodle_exception::class);
        $this->reflectionmanager->getMethod('check_response')->invoke($this->manager, '', $request);
    }

    /**
     * Test the get mab by mapping id method.
     *
     * @covers \local_sitsgradepush\manager::get_mab_and_map_info_by_mapping_id
     * @return void
     * @throws \ReflectionException
     * @throws \coding_exception
     * @throws \dml_exception
     * @throws \moodle_exception
     */
    public function test_get_mab_and_map_info_by_mapping_id(): void {
        // Set up the test environment.
        $this->setup_testing_environment(assessmentfactory::get_assessment('mod', $this->assign1->cmid));

        // Test the mab is returned.
        $mab = $this->manager->get_mab_and_map_info_by_mapping_id($this->mappingid1);
        $this->assertEquals($this->mab1->id, $mab->mabid);
    }

    /**
     * Test the remove mapping method.
     *
     * @covers \local_sitsgradepush\manager::remove_mapping
     * @return void
     * @throws \ReflectionException
     * @throws \coding_exception
     * @throws \dml_exception
     * @throws \moodle_exception
     */
    public function test_remove_mapping(): void {
        global $DB;
        // Set up the test environment.
        $this->setup_testing_environment(assessmentfactory::get_assessment('mod', $this->assign1->cmid));
        $this->setUser($this->teacher1);

        try {
            // Test removing mapping without capability.
            $this->manager->remove_mapping($this->course1->id, $this->mappingid1);
        } catch (\moodle_exception $e) {
            $this->assertStringContainsString(get_string('error:remove_mapping', 'local_sitsgradepush'), $e->getMessage());
        }

        // Test mapping not exists.
        try {
            // Create role.
            $roleid = $this->dg->create_role(['shortname' => 'canmapassessment']);
            assign_capability(
                'local/sitsgradepush:mapassessment',
                CAP_ALLOW,
                $roleid,
                context_course::instance($this->course1->id)->id
            );
            $this->dg->enrol_user($this->teacher1->id, $this->course1->id, 'canmapassessment');
            $this->manager->remove_mapping($this->course1->id, 0);
        } catch (\moodle_exception $e) {
            $this->assertStringContainsString(get_string('error:assessmentmapping', 'local_sitsgradepush', 0), $e->getMessage());
        }

        // Test push records exist.
        try {
            // Add some push logs.
            $transferlog = new \stdClass();
            $transferlog->type = 'pushgrade';
            $transferlog->userid = $this->student1->id;
            $transferlog->assessmentmappingid = $this->mappingid1;
            $transferlog->usermodified = $this->teacher1->id;
            $transferlog->timecreated = time();
            $transferlogid = $DB->insert_record('local_sitsgradepush_tfr_log', $transferlog);

            // Test push logs exist.
            $this->manager->remove_mapping($this->course1->id, $this->mappingid1);
        } catch (\moodle_exception $e) {
            $this->assertStringContainsString(
                get_string('error:mab_has_push_records', 'local_sitsgradepush', 'Mapping ID: ' . $this->mappingid1),
                $e->getMessage()
            );
        }

        // Remove the push logs to allow mapping removal.
        $DB->delete_records('local_sitsgradepush_tfr_log', ['id' => $transferlogid]);

        // Test successful removal of the mapping.
        $this->manager->remove_mapping($this->course1->id, $this->mappingid1);
        $this->assertFalse($DB->record_exists('local_sitsgradepush_mapping', ['id' => $this->mappingid1]));
    }

    /**
     * Set default configurations for the tests.
     *
     * @return void
     * @throws \dml_exception
     */
    private function set_default_configs(): void {
        global $CFG, $DB;

        // Set Easikit API client.
        set_config('apiclient', 'easikit', 'local_sitsgradepush');

        // Set AST codes.
        set_config('moodle_ast_codes',
          'BC02, CN01, EC03, EC04, ED03, ED04, GD01, GN01, GN02, GN03, HC01, HD01, HD02,
            HD03, HD04, HD05, LC01, MD01, ND01, RN01, RN02, RN03, SD01, TC01, ZD01, ZN01',
          'local_sitsgradepush'
        );

        // Set AST codes must work with exam room code.
        set_config('moodle_ast_codes_exam_room',
          'BC02, EC03, EC04, ED03, ED04',
          'local_sitsgradepush'
        );

        // Set block_lifecycle 'late_summer_assessment_end_date'.
        set_config('late_summer_assessment_end_' . date('Y'), date('Y-m-d', strtotime('+2 month')), 'block_lifecycle');

        // Create test user profile field.
        $fielddata = [
          'shortname' => 'testfield',
          'name' => 'Test Field',
          'datatype' => 'text',
          'categoryid' => 1,
          'required' => 0,
          'locked' => 0,
          'visible' => 2,
          'forceunique' => 0,
        ];

        // Add the field to the database.
        $fieldid = $DB->insert_record('user_info_field', (object)$fielddata);

        // Set user profile field.
        set_config('user_profile_field', $fieldid, 'local_sitsgradepush');
    }

    /**
     * Setup courses for testing.
     *
     * @return void
     * @throws \coding_exception
     * @throws \dml_exception
     */
    private function setup_test_courses(): void {
        // Create a custom category and custom field.
        $this->dg->create_custom_field_category(['name' => 'CLC']);
        $this->dg->create_custom_field(['category' => 'CLC', 'shortname' => 'course_year']);

        // Create test courses.
        $this->course1 = $this->dg->create_course(
          ['shortname' => 'C1', 'customfields' => [
            ['shortname' => 'course_year', 'value' => date('Y')],
          ]]);
        $this->course2 = $this->dg->create_course(['shortname' => 'C2']);

        // Create role.
        $roleid = $this->dg->create_role(['shortname' => 'canpushgrades']);
        assign_capability('local/sitsgradepush:pushgrade', CAP_ALLOW, $roleid, context_course::instance($this->course1->id)->id);

        // Create a teacher.
        $this->teacher1 = $this->dg->create_user();
        $this->dg->enrol_user($this->teacher1->id, $this->course1->id, 'canpushgrades');

        // Create students.
        $this->student1 = $this->dg->create_user(['idnumber' => '12345678']);
        $this->student2 = $this->dg->create_user(['idnumber' => '12345679']);

        // Enrol students to course.
        $this->dg->enrol_user($this->student1->id, $this->course1->id, 'student');
        $this->dg->enrol_user($this->student2->id, $this->course1->id, 'student');

        // Create test assignment 1.
        $this->assign1 = $this->create_assignment_with_grade_and_submission();

        // Create test assignment 2 using scale grade type.
        $scale = $this->dg->create_scale([
          'name' => 'Test Scale',
          'scale' => 'Poor, Good, Excellent',
        ]);
        $this->assign2 = $this->dg->create_module('assign', ['course' => $this->course1->id, 'grade' => -$scale->id]);

        // Create test quiz 1.
        $this->quiz1 = $this->dg->create_module('quiz', ['course' => $this->course1->id]);

        // Create test grade category and grade item.
        [$this->gradecategory1, $this->gradeitem1] = $this->create_grade_category_and_grade_item();
    }

    /**
     * Setup testing environment for marks transfer of an assignment.
     *
     * @param  \local_sitsgradepush\assessment\assessment|null $assessment
     * @param  int $reassess
     * @param  bool $clearcache
     *
     * @return void
     * @throws \coding_exception
     * @throws \dml_exception
     * @throws \moodle_exception|\ReflectionException
     */
    private function setup_testing_environment(?assessment $assessment = null, int $reassess = 0, bool $clearcache = false): void {
        global $DB;

        // Clear cache.
        if ($clearcache) {
            cache::make('local_sitsgradepush', cachemanager::CACHE_AREA_MARKINGSCHEMES)->purge();
            cache::make('local_sitsgradepush', cachemanager::CACHE_AREA_STUDENTSPR)->purge();
        }

        // Import component grades.
        tests_data_provider::import_sitsgradepush_grade_components();

        // Set marking scheme data.
        tests_data_provider::set_marking_scheme_data();

        // Get component grade we are going to map.
        $this->mab1 = $DB->get_record('local_sitsgradepush_mab', ['mapcode' => 'LAWS0024A6UF', 'mabseq' => '001']);

        // Set the return student data from SITS.
        $students = [
          [
            'code' => 12345678,
            'spr_code' => '12345678/1',
            'forename' => 'Test',
            'surname' => 'Student',
            'assessment' => [
              "resit_number" => $reassess,
            ],
          ],
        ];
        $apiclient = $this->get_apiclient_for_testing(false, $students);
        tests_data_provider::set_protected_property($this->manager, 'apiclient', $apiclient);
        $this->manager->get_students_from_sits($this->mab1);

        if (!is_null($assessment)) {
            // Map the component grade to the assignment.
            $this->mappingid1 = $this->manager->save_assessment_mapping((object)[
              'componentgradeid' => $this->mab1->id,
              'sourcetype' => $assessment->get_type(),
              'sourceid' => $assessment->get_id(),
              'reassessment' => $reassess,
            ]);

            // Set assessment mapping.
            $this->assessmentmapping1 = $this->manager->get_assessment_mappings($assessment, $this->mab1->id);
        }
    }

    /**
     * Create a grade category and grade item.
     *
     * @return array
     * @throws \coding_exception
     * @throws \dml_exception
     * @throws \moodle_exception
     */
    private function create_grade_category_and_grade_item(): array {
        global $DB;

        // Create a grade category.
        $gradecategory = $this->getDataGenerator()->create_grade_category(['courseid' => $this->course1->id]);

        // Create a grade item.
        $gradeitem = $this->getDataGenerator()->create_grade_item([
          'courseid' => $this->course1->id,
          'itemname' => 'Test Grade Item',
          'idnumber' => 'testgradeitem',
          'gradetype' => GRADE_TYPE_VALUE,
          'grademax' => 100,
          'grademin' => 0,
          'categoryid' => $gradecategory->id,
        ]);

        $course = $DB->get_record('course', ['id' => $this->course1->id], '*', MUST_EXIST);
        if (getenv('PLUGIN_CI')) {
            grade_regrade_final_grades($course->id);
        } else {
            grade_regrade_final_grades($course->id, async: false);
        }

        return [$gradecategory, $gradeitem];
    }

    /**
     * Create a test assignment with a grade and submission.
     *
     * @return \stdClass
     * @throws \coding_exception
     * @throws \dml_exception
     */
    private function create_assignment_with_grade_and_submission(): \stdClass {
        global $DB;

        // Create a test assignment.
        $assignmodule1 = $this->dg->create_module('assign', ['course' => $this->course1->id]);
        $cm = get_coursemodule_from_instance('assign', $assignmodule1->id, $this->course1->id);
        $context = context_module::instance($assignmodule1->cmid);
        $assign = new assign($context, $cm, $this->course1);

        // Create a submission object.
        $submission = $assign->get_user_submission($this->student1->id, true);

        // Add text submission data.
        $data = new \stdClass();
        $data->onlinetext_editor = ['text' => 'This is my text submission', 'format' => FORMAT_MOODLE];
        $plugin = $assign->get_plugin_by_type('assignsubmission', 'onlinetext');
        $plugin->save($submission, $data);

        // Update the submission status in the database.
        $submissionid = $submission->id;
        $DB->set_field('assign_submission', 'status', ASSIGN_SUBMISSION_STATUS_SUBMITTED, ['id' => $submissionid]);

        // Add a grade to the submission.
        $grade = $assign->get_user_grade($this->student1->id, true);
        $grade->grade = 80;
        $assign->update_grade($grade);

        return $assignmodule1;
    }
}
