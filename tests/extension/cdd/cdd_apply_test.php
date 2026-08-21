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

namespace local_sitsgradepush\extension\cdd;

use local_sitsgradepush\assessment\assessmentfactory;
use local_sitsgradepush\extension\cdd;
use local_sitsgradepush\extension\ec;
use local_sitsgradepush\extensionmanager;
use local_sitsgradepush\tests_data_provider;

defined('MOODLE_INTERNAL') || die();
global $CFG;
require_once($CFG->dirroot . '/local/sitsgradepush/tests/extension/cdd/cdd_base.php');

/**
 * Tests for applying and removing combined due date (CDD) overrides on assessments.
 *
 * @package    local_sitsgradepush
 * @copyright  2026 onwards University College London {@link https://www.ucl.ac.uk/}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @author     Alex Yeung <k.yeung@ucl.ac.uk>
 */
final class cdd_apply_test extends cdd_base {
    /**
     * Data provider for the supported activity types.
     *
     * @return array
     */
    public static function activity_type_provider(): array {
        return [
            'assign' => ['assign', 'userid'],
            'quiz' => ['quiz', 'userid'],
            'lesson' => ['lesson', 'userid'],
            'coursework' => ['coursework', 'allocatableid'],
        ];
    }

    /**
     * Test applying a combined due date creates the correct override and backup record.
     *
     * @dataProvider activity_type_provider
     * @covers \local_sitsgradepush\assessment\assign::apply_cdd_extension
     * @covers \local_sitsgradepush\assessment\quiz::apply_cdd_extension
     * @covers \local_sitsgradepush\assessment\lesson::apply_cdd_extension
     * @covers \local_sitsgradepush\assessment\coursework::apply_cdd_extension
     * @param string $type Activity type.
     * @param string $userfield User ID field in the override table.
     * @return void
     */
    public function test_apply_cdd_creates_override(string $type, string $userfield): void {
        global $DB;

        $activity = $this->setup_common_test_data($type);
        if ($activity === null) {
            $this->markTestSkipped("Activity type $type is not installed.");
        }

        $cdd = new cdd();
        $cdd->set_properties_from_cdd_api(tests_data_provider::get_cdd_api_record());
        $cdd->process_extension([$this->get_mapping()]);

        // The combined due date (2025-02-27) is applied at the original time of day (12:00).
        $this->verify_override($activity, $type, strtotime('2025-02-27 12:00'), $userfield);

        // A combined due date override backup is recorded.
        $backup = $DB->get_record('local_sitsgradepush_overrides', [
            'mapid' => $this->mappingid,
            'userid' => $this->student1->id,
            'extensiontype' => extensionmanager::EXTENSION_CDD,
        ]);
        $this->assertNotEmpty($backup);
    }

    /**
     * Test the combined due date supersedes an existing EC override before it is applied.
     *
     * @covers \local_sitsgradepush\extension\cdd::before_apply_extension
     * @return void
     */
    public function test_before_apply_extension_supersedes_ec_override(): void {
        global $DB;

        $assign = $this->setup_common_test_data();
        $mapping = $this->get_mapping();

        // Apply an EC extension first, which sets the due date to 2025-02-27.
        $ec = new ec();
        $ec->set_properties_from_get_students_api(tests_data_provider::get_ec_testing_student_data());
        $ec->process_extension([$mapping]);

        $ecbackup = $DB->get_record('local_sitsgradepush_overrides', [
            'mapid' => $this->mappingid,
            'userid' => $this->student1->id,
            'extensiontype' => extensionmanager::EXTENSION_EC,
        ]);
        $this->assertNotEmpty($ecbackup);
        $this->assertEmpty($ecbackup->restored_by);

        // Apply a combined due date with a later date via the re-assessment record (2025-03-20).
        $cdd = new cdd();
        $cdd->set_properties_from_cdd_api(tests_data_provider::get_cdd_api_record_reassessment());
        $cdd->process_extension([$mapping]);

        // The assignment override now reflects the combined due date.
        $override = $DB->get_record('assign_overrides', ['assignid' => $assign->id, 'userid' => $this->student1->id]);
        $this->assertEquals(strtotime('2025-03-20 12:00'), $override->duedate);

        // The EC override backup is marked restored.
        $ecbackup = $DB->get_record('local_sitsgradepush_overrides', ['id' => $ecbackup->id]);
        $this->assertNotEmpty($ecbackup->restored_by);

        // An active combined due date override backup now exists.
        $cddbackup = $DB->get_record('local_sitsgradepush_overrides', [
            'mapid' => $this->mappingid,
            'userid' => $this->student1->id,
            'extensiontype' => extensionmanager::EXTENSION_CDD,
            'restored_by' => null,
        ]);
        $this->assertNotEmpty($cddbackup);
    }

    /**
     * Test a gated out record removes a previously applied combined due date override.
     *
     * @covers \local_sitsgradepush\extension\ec::process_extension
     * @covers \local_sitsgradepush\assessment\assign::delete_user_override
     * @return void
     */
    public function test_gated_out_record_removes_existing_override(): void {
        global $DB;

        $assign = $this->setup_common_test_data();
        $mapping = $this->get_mapping();

        // Apply a combined due date first.
        $cdd = new cdd();
        $cdd->set_properties_from_cdd_api(tests_data_provider::get_cdd_api_record());
        $cdd->process_extension([$mapping]);
        $this->assertNotEmpty($DB->get_record('assign_overrides', ['assignid' => $assign->id, 'userid' => $this->student1->id]));

        // A subsequent gated out record removes the override.
        // All four extensions are 0 or empty, so CDD should be removed.
        $gatedout = new cdd();
        $gatedout->set_properties_from_cdd_api(tests_data_provider::get_cdd_api_record_gated_out());
        $gatedout->process_extension([$mapping]);

        // The assignment override is removed.
        $this->assertFalse($DB->get_record('assign_overrides', ['assignid' => $assign->id, 'userid' => $this->student1->id]));

        // The combined due date override backup is marked restored.
        $backup = $DB->get_record('local_sitsgradepush_overrides', [
            'mapid' => $this->mappingid,
            'userid' => $this->student1->id,
            'extensiontype' => extensionmanager::EXTENSION_CDD,
        ]);
        $this->assertNotEmpty($backup->restored_by);
    }

    /**
     * Test calculating a new due date throws when the assessment has no original due date.
     *
     * @covers \local_sitsgradepush\assessment\activity::calculate_ec_new_duedate
     * @return void
     */
    public function test_calculate_new_duedate_throws_on_empty_original_duedate(): void {
        $this->setup_common_test_data();

        // Create an assignment with no due date.
        $assign = $this->getDataGenerator()->create_module('assign', ['course' => $this->course1->id, 'duedate' => 0]);
        $assessment = assessmentfactory::get_assessment('mod', $assign->cmid);

        $cdd = new cdd();
        $cdd->set_properties_from_cdd_api(tests_data_provider::get_cdd_api_record());

        $method = $this->get_accessible_method($assessment, 'calculate_ec_new_duedate');
        $this->expectException(\moodle_exception::class);
        $method->invoke($assessment, $cdd);
    }

    /**
     * Test calculating a new due date throws when the deadline is unparseable.
     *
     * @covers \local_sitsgradepush\assessment\activity::calculate_ec_new_duedate
     * @return void
     */
    public function test_calculate_new_duedate_throws_on_unparseable_deadline(): void {
        $activity = $this->setup_common_test_data();
        $assessment = assessmentfactory::get_assessment('mod', $activity->cmid);

        // Build a combined due date with an unparseable deadline.
        $cdd = new cdd();
        $cdd->set_properties_from_cdd_api(tests_data_provider::get_cdd_api_record());
        tests_data_provider::set_protected_property($cdd, 'newdeadline', 'not-a-date');

        $method = $this->get_accessible_method($assessment, 'calculate_ec_new_duedate');
        $this->expectException(\moodle_exception::class);
        $method->invoke($assessment, $cdd);
    }

    /**
     * Get the full mapping record for the inserted mapping.
     *
     * @return \stdClass
     */
    private function get_mapping(): \stdClass {
        global $DB;
        return $DB->get_record('local_sitsgradepush_mapping', ['id' => $this->mappingid]);
    }
}
