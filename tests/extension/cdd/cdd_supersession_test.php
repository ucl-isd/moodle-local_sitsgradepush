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
 * Tests for combined due date (CDD) supersession of EC and RAA processing.
 *
 * @package    local_sitsgradepush
 * @copyright  2026 onwards University College London {@link https://www.ucl.ac.uk/}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @author     Alex Yeung <k.yeung@ucl.ac.uk>
 */
final class cdd_supersession_test extends cdd_base {
    /**
     * Test no combined due date override means EC/RAA processing is not superseded.
     *
     * @covers \local_sitsgradepush\extensionmanager::handle_cdd_supersession
     * @return void
     */
    public function test_handle_cdd_supersession_no_override_returns_false(): void {
        $activity = $this->setup_common_test_data();
        $assessment = assessmentfactory::get_assessment('mod', $activity->cmid);

        $this->assertFalse(
            extensionmanager::handle_cdd_supersession($assessment, $this->get_mapping(), $this->student1->id)
        );
    }

    /**
     * Test an active combined due date override supersedes EC/RAA processing when the feature is enabled.
     *
     * @covers \local_sitsgradepush\extensionmanager::handle_cdd_supersession
     * @return void
     */
    public function test_handle_cdd_supersession_returns_true_when_enabled(): void {
        global $DB;

        $activity = $this->setup_common_test_data();
        $mapping = $this->get_mapping();

        // Apply a combined due date so an active override exists.
        $this->apply_cdd($mapping);

        $assessment = assessmentfactory::get_assessment('mod', $activity->cmid);
        $this->assertTrue(extensionmanager::handle_cdd_supersession($assessment, $mapping, $this->student1->id));

        // The active override is left untouched.
        $backup = $DB->get_record('local_sitsgradepush_overrides', [
            'mapid' => $this->mappingid,
            'userid' => $this->student1->id,
            'extensiontype' => extensionmanager::EXTENSION_CDD,
            'restored_by' => null,
        ]);
        $this->assertNotEmpty($backup);
    }

    /**
     * Test a stale combined due date override is removed when the feature is disabled.
     *
     * @covers \local_sitsgradepush\extensionmanager::handle_cdd_supersession
     * @return void
     */
    public function test_handle_cdd_supersession_removes_stale_override_when_disabled(): void {
        global $DB;

        $activity = $this->setup_common_test_data();
        $mapping = $this->get_mapping();

        // Apply a combined due date, then disable the feature.
        $this->apply_cdd($mapping);
        set_config('cdd_enabled', '0', 'local_sitsgradepush');

        $assessment = assessmentfactory::get_assessment('mod', $activity->cmid);
        $assessment->set_sits_mapping_id($this->mappingid);
        $this->assertFalse(extensionmanager::handle_cdd_supersession($assessment, $mapping, $this->student1->id));

        // The stale override is removed and its backup marked restored.
        $backup = $DB->get_record('local_sitsgradepush_overrides', [
            'mapid' => $this->mappingid,
            'userid' => $this->student1->id,
            'extensiontype' => extensionmanager::EXTENSION_CDD,
        ]);
        $this->assertNotEmpty($backup->restored_by);
        $this->assertFalse($DB->get_record('assign_overrides', ['assignid' => $activity->id, 'userid' => $this->student1->id]));
    }

    /**
     * Test EC processing is skipped for a student who has an active combined due date override.
     *
     * @covers \local_sitsgradepush\extension\ec::process_extension
     * @covers \local_sitsgradepush\extension\ec::is_superseded_by_cdd
     * @return void
     */
    public function test_ec_processing_skipped_when_superseded_by_cdd(): void {
        global $DB;

        $activity = $this->setup_common_test_data();
        $mapping = $this->get_mapping();

        // Apply a combined due date with a later date (2025-03-20).
        $cdd = new cdd();
        $cdd->set_properties_from_cdd_api(tests_data_provider::get_cdd_api_record_reassessment());
        $cdd->process_extension([$mapping]);

        // Run EC processing, which would otherwise set the due date to 2025-02-27.
        $ec = new ec();
        $ec->set_properties_from_get_students_api(tests_data_provider::get_ec_testing_student_data());
        $ec->process_extension([$mapping]);

        // The combined due date is preserved as EC was superseded.
        $override = $DB->get_record('assign_overrides', ['assignid' => $activity->id, 'userid' => $this->student1->id]);
        $this->assertEquals(strtotime('2025-03-20 12:00'), $override->duedate);

        // No EC override backup was created.
        $ecbackup = $DB->get_record('local_sitsgradepush_overrides', [
            'mapid' => $this->mappingid,
            'userid' => $this->student1->id,
            'extensiontype' => extensionmanager::EXTENSION_EC,
        ]);
        $this->assertFalse($ecbackup);
    }

    /**
     * Test EC processing proceeds and removes a stale combined due date override when disabled.
     *
     * @covers \local_sitsgradepush\extension\ec::process_extension
     * @covers \local_sitsgradepush\extensionmanager::handle_cdd_supersession
     * @return void
     */
    public function test_ec_processing_proceeds_when_cdd_disabled(): void {
        global $DB;

        $activity = $this->setup_common_test_data();
        $mapping = $this->get_mapping();

        // Apply a combined due date, then disable the feature.
        $cdd = new cdd();
        $cdd->set_properties_from_cdd_api(tests_data_provider::get_cdd_api_record_reassessment());
        $cdd->process_extension([$mapping]);
        set_config('cdd_enabled', '0', 'local_sitsgradepush');

        // Run EC processing, which sets the due date to 2025-02-27.
        $ec = new ec();
        $ec->set_properties_from_get_students_api(tests_data_provider::get_ec_testing_student_data());
        $ec->process_extension([$mapping]);

        // EC applied its due date.
        $override = $DB->get_record('assign_overrides', ['assignid' => $activity->id, 'userid' => $this->student1->id]);
        $this->assertEquals(strtotime('2025-02-27 12:00'), $override->duedate);

        // The stale combined due date override backup is marked restored.
        $cddbackup = $DB->get_record('local_sitsgradepush_overrides', [
            'mapid' => $this->mappingid,
            'userid' => $this->student1->id,
            'extensiontype' => extensionmanager::EXTENSION_CDD,
        ]);
        $this->assertNotEmpty($cddbackup->restored_by);

        // An active EC override backup now exists.
        $ecbackup = $DB->get_record('local_sitsgradepush_overrides', [
            'mapid' => $this->mappingid,
            'userid' => $this->student1->id,
            'extensiontype' => extensionmanager::EXTENSION_EC,
            'restored_by' => null,
        ]);
        $this->assertNotEmpty($ecbackup);
    }

    /**
     * Apply a combined due date for the default student on the given mapping.
     *
     * @param \stdClass $mapping The mapping record.
     * @return void
     */
    private function apply_cdd(\stdClass $mapping): void {
        $cdd = new cdd();
        $cdd->set_properties_from_cdd_api(tests_data_provider::get_cdd_api_record());
        $cdd->process_extension([$mapping]);
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
