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

use local_sitsgradepush\extension\cdd;
use local_sitsgradepush\extension\cdd\cdd_base;
use local_sitsgradepush\extension\ec;

defined('MOODLE_INTERNAL') || die();
global $CFG;
require_once($CFG->dirroot . '/local/sitsgradepush/tests/extension/cdd/cdd_base.php');

/**
 * Tests for the combined due date (CDD) related methods of extensionmanager.
 *
 * @package    local_sitsgradepush
 * @copyright  2026 onwards University College London {@link https://www.ucl.ac.uk/}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @author     Alex Yeung <k.yeung@ucl.ac.uk>
 */
final class extensionmanager_test extends cdd_base {
    /**
     * Test the combined due date feature is enabled only when both settings are on.
     *
     * @covers \local_sitsgradepush\extensionmanager::is_cdd_enabled
     * @return void
     */
    public function test_is_cdd_enabled(): void {
        set_config('extension_enabled', '1', 'local_sitsgradepush');
        set_config('cdd_enabled', '0', 'local_sitsgradepush');
        $this->assertFalse(extensionmanager::is_cdd_enabled());

        set_config('extension_enabled', '0', 'local_sitsgradepush');
        set_config('cdd_enabled', '1', 'local_sitsgradepush');
        $this->assertFalse(extensionmanager::is_cdd_enabled());

        set_config('extension_enabled', '1', 'local_sitsgradepush');
        set_config('cdd_enabled', '1', 'local_sitsgradepush');
        $this->assertTrue(extensionmanager::is_cdd_enabled());
    }

    /**
     * Test update_cdd_for_mapping returns empty when the feature is disabled.
     *
     * @covers \local_sitsgradepush\extensionmanager::update_cdd_for_mapping
     * @return void
     */
    public function test_update_cdd_for_mapping_returns_empty_when_disabled(): void {
        $this->setup_common_test_data();
        set_config('cdd_enabled', '0', 'local_sitsgradepush');

        $this->assertEmpty(extensionmanager::update_cdd_for_mapping($this->get_mapping()));
    }

    /**
     * Test update_cdd_for_mapping returns empty when extension is not enabled on the mapping.
     *
     * @covers \local_sitsgradepush\extensionmanager::update_cdd_for_mapping
     * @return void
     */
    public function test_update_cdd_for_mapping_returns_empty_when_mapping_extension_disabled(): void {
        $this->setup_common_test_data();

        $mapping = $this->get_mapping();
        $mapping->enableextension = '0';

        $this->assertEmpty(extensionmanager::update_cdd_for_mapping($mapping));
    }

    /**
     * Test update_cdd_for_mapping returns empty when the API returns no records.
     *
     * @covers \local_sitsgradepush\extensionmanager::update_cdd_for_mapping
     * @return void
     */
    public function test_update_cdd_for_mapping_returns_empty_when_no_records(): void {
        $this->setup_common_test_data();
        $this->setup_mock_manager_with_cdd([]);

        $this->assertEmpty(extensionmanager::update_cdd_for_mapping($this->get_mapping()));
    }

    /**
     * Test update_cdd_for_mapping applies the combined due date and returns handled student codes.
     *
     * @covers \local_sitsgradepush\extensionmanager::update_cdd_for_mapping
     * @return void
     */
    public function test_update_cdd_for_mapping_applies_and_returns_handled(): void {
        global $DB;

        $activity = $this->setup_common_test_data();

        // One valid record plus one record with no student code, which must be skipped.
        $emptyrecord = tests_data_provider::get_cdd_api_record();
        $emptyrecord['student_programme_route_code'] = '';
        $this->setup_mock_manager_with_cdd([tests_data_provider::get_cdd_api_record(), $emptyrecord]);

        $handled = extensionmanager::update_cdd_for_mapping($this->get_mapping());

        $this->assertEquals(['12345678'], array_values($handled));

        // The combined due date override was applied.
        $override = $DB->get_record('assign_overrides', ['assignid' => $activity->id, 'userid' => $this->student1->id]);
        $this->assertEquals(strtotime('2025-02-27 12:00'), $override->duedate);
    }

    /**
     * Test filter_out_cdd_handled_students removes only handled students.
     *
     * @covers \local_sitsgradepush\extensionmanager::filter_out_cdd_handled_students
     * @return void
     */
    public function test_filter_out_cdd_handled_students(): void {
        $students = [
            ['association' => ['supplementary' => ['student_code' => '12345678']]],
            ['association' => ['supplementary' => ['student_code' => '87654321']]],
        ];

        // An empty handled list leaves the students unchanged.
        $this->assertCount(2, extensionmanager::filter_out_cdd_handled_students($students, []));

        // A handled student is removed.
        $remaining = array_values(extensionmanager::filter_out_cdd_handled_students($students, ['12345678']));
        $this->assertCount(1, $remaining);
        $this->assertEquals('87654321', $remaining[0]['association']['supplementary']['student_code']);
    }

    /**
     * Test delete_cdd_overrides removes only combined due date overrides.
     *
     * @covers \local_sitsgradepush\extensionmanager::delete_cdd_overrides
     * @covers \local_sitsgradepush\extensionmanager::delete_overrides_by_type
     * @return void
     */
    public function test_delete_cdd_overrides(): void {
        global $DB;

        $activity = $this->setup_common_test_data();
        $mapping = $this->get_mapping();

        // Apply an EC extension for student1 and a combined due date for student2.
        $ec = new ec();
        $ec->set_properties_from_get_students_api(tests_data_provider::get_ec_testing_student_data());
        $ec->process_extension([$mapping]);

        $cdd = new cdd();
        $cddrecord = tests_data_provider::get_cdd_api_record();
        $cddrecord['student_programme_route_code'] = '87654321/1';
        $cdd->set_properties_from_cdd_api($cddrecord);
        $cdd->process_extension([$mapping]);

        // Delete only the combined due date overrides.
        extensionmanager::delete_cdd_overrides($this->mappingid);

        // The combined due date override backup is restored and its assignment override removed.
        $cddbackup = $DB->get_record('local_sitsgradepush_overrides', [
            'mapid' => $this->mappingid,
            'userid' => $this->student2->id,
            'extensiontype' => extensionmanager::EXTENSION_CDD,
        ]);
        $this->assertNotEmpty($cddbackup->restored_by);
        $this->assertFalse($DB->get_record('assign_overrides', ['assignid' => $activity->id, 'userid' => $this->student2->id]));

        // The EC override backup is untouched.
        $ecbackup = $DB->get_record('local_sitsgradepush_overrides', [
            'mapid' => $this->mappingid,
            'userid' => $this->student1->id,
            'extensiontype' => extensionmanager::EXTENSION_EC,
        ]);
        $this->assertEmpty($ecbackup->restored_by);
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
