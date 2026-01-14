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

namespace local_sitsgradepush\extension\raa;

use local_sitsgradepush\extension\sora;
use local_sitsgradepush\extensionmanager;
use local_sitsgradepush\manager;
use local_sitsgradepush\tests_data_provider;
use mod_coursework\event\extension_created;
use mod_coursework\event\extension_updated;

defined('MOODLE_INTERNAL') || die();
global $CFG;
require_once($CFG->dirroot . '/local/sitsgradepush/tests/extension/raa/raa_base.php');

/**
 * Test class for Moodle coursework RAA extension.
 *
 * @package    local_sitsgradepush
 * @copyright  2025 onwards University College London {@link https://www.ucl.ac.uk/}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @author     Alex Yeung <k.yeung@ucl.ac.uk>
 */
final class raa_coursework_test extends raa_base {
    /** @var \stdClass|null RAA test coursework 1 (ED03 - Exam). */
    private ?\stdClass $raacoursework1;

    /** @var \stdClass|null RAA test coursework 2 (CN01 - Coursework). */
    private ?\stdClass $raacoursework2;

    /** @var \stdClass|null RAA test coursework 3 (HD05 - Take-home assessment). */
    private ?\stdClass $raacoursework3;

    /**
     * Set up the test.
     *
     * @return void
     * @throws \dml_exception
     */
    public function setUp(): void {
        // Skip all tests if coursework plugin is not installed.
        if (!\core_component::get_component_directory('mod_coursework')) {
            $this->markTestSkipped('Coursework plugin not installed.');
        }

        parent::setUp();

        // Current time 2025-02-10 09:00:00.
        // ED03 (Exam) - 3 hours duration.
        $this->raacoursework1 = $this->setup_coursework_with_mapping(
            $this->course1->id,
            '2025-02-11 10:00:00',
            '2025-02-11 13:00:00',
            'LAWS0024A6UF',
            '001'
        );

        // CN01 (Coursework) - Only if feedback tracker is installed.
        if ($this->is_feedback_tracker_installed()) {
            $this->raacoursework2 = $this->setup_coursework_with_mapping(
                $this->course1->id,
                '2025-02-11 10:00:00',
                '2025-02-20 14:00:00',
                'LAWS0024A6UF',
                '002'
            );
        }

        // HD05 (Take-home assessment).
        $this->raacoursework3 = $this->setup_coursework_with_mapping(
            $this->course1->id,
            '2025-02-11 10:00:00',
            '2025-02-18 14:00:00',
            'PUBL0065A7PG',
            '001'
        );
    }

    /**
     * Test RAA override using data from AWS message.
     *
     * @covers \local_sitsgradepush\extension\extension::get_mappings_by_userid
     * @covers \local_sitsgradepush\extension\sora::process_extension
     * @covers \local_sitsgradepush\extension\sora::set_properties_from_aws_message
     * @covers \local_sitsgradepush\assessment\coursework::apply_sora_extension
     * @return void
     * @throws \coding_exception
     * @throws \dml_exception
     * @throws \moodle_exception
     */
    public function test_raa_process_extension_from_aws(): void {
        // Process the extension by passing the JSON event data.
        // Test with ED03, CN01 and HD05 assessment types.
        foreach ($this->testassessmenttypes as $astcode) {
            $sora = new sora();
            $sora->set_properties_from_aws_message(tests_data_provider::get_sora_event_data($astcode));
            $sora->process_extension($sora->get_mappings_by_userid($sora->get_userid(), $astcode));
        }

        // Verify all coursework overrides were created correctly.
        $this->assert_all_coursework_overrides();
    }

    /**
     * Test that extension_created event is triggered when creating a new override.
     *
     * @covers \local_sitsgradepush\assessment\coursework::apply_sora_extension
     * @covers \local_sitsgradepush\assessment\coursework::trigger_override_event
     * @return void
     * @throws \coding_exception
     * @throws \dml_exception
     * @throws \moodle_exception
     */
    public function test_extension_created_event_triggered(): void {
        $sink = $this->redirectEvents();

        // Process the extension by passing the JSON event data.
        $astcode = 'HD05';
        $sora = new sora();
        $sora->set_properties_from_aws_message(tests_data_provider::get_sora_event_data($astcode));
        $sora->process_extension($sora->get_mappings_by_userid($sora->get_userid(), $astcode));

        $events = $sink->get_events();
        $sink->close();

        // Filter for extension_created events.
        $createdevents = array_filter($events, function ($event) {
            return $event instanceof extension_created;
        });

        // Should have at least one extension_created event.
        $this->assertNotEmpty($createdevents);

        // Verify event data for one of the created events.
        $event = reset($createdevents);
        $this->assertEquals($this->student1->id, $event->relateduserid);
        $this->assertEquals(0, $event->anonymous);
        $this->assertArrayHasKey('allocatabletype', $event->other);
        $this->assertEquals('user', $event->other['allocatabletype']);
        $this->assertArrayHasKey('courseworkid', $event->other);
        $this->assertArrayHasKey('deadline', $event->other);
    }

    /**
     * Test that extension_updated event is triggered when updating an existing override.
     *
     * @covers \local_sitsgradepush\assessment\coursework::apply_sora_extension
     * @covers \local_sitsgradepush\assessment\coursework::trigger_override_event
     * @return void
     * @throws \coding_exception
     * @throws \dml_exception
     * @throws \moodle_exception
     */
    public function test_extension_updated_event_triggered(): void {
        $astcode = 'HD05';

        // First, create initial overrides.
        $student = tests_data_provider::get_sora_testing_student_data($astcode);
        $mappings = manager::get_manager()->get_assessment_mappings_by_courseid($this->course1->id);
        foreach ($mappings as $mapping) {
            if ($mapping->astcode === $astcode) {
                extensionmanager::update_sora_for_mapping($mapping, [$student]);
            }
        }

        // Now update the override with a different extension.
        $sink = $this->redirectEvents();

        $sora = new sora();
        $eventdata = json_decode(tests_data_provider::get_sora_event_data($astcode), true);
        $eventdata['entity']['person_sora']['required_provisions'][0]['provision_tier'] = 'TIER2';
        $eventdata['entity']['person_sora']['required_provisions'][0]['no_hrs_ext'] = '16';

        $sora->set_properties_from_aws_message(json_encode($eventdata));
        $sora->process_extension($sora->get_mappings_by_userid($sora->get_userid(), $astcode));

        $events = $sink->get_events();
        $sink->close();

        // Filter for extension_updated events.
        $updatedevents = array_filter($events, function ($event) {
            return $event instanceof extension_updated;
        });

        // Should have at least one extension_updated event.
        $this->assertNotEmpty($updatedevents);

        // Verify event data for one of the updated events.
        $event = reset($updatedevents);
        $this->assertEquals($this->student1->id, $event->relateduserid);
        $this->assertEquals(0, $event->anonymous);
        $this->assertArrayHasKey('allocatabletype', $event->other);
        $this->assertEquals('user', $event->other['allocatabletype']);
        $this->assertArrayHasKey('courseworkid', $event->other);
        $this->assertArrayHasKey('deadline', $event->other);
    }

    /**
     * Test no RAA override is created for past due date coursework.
     *
     * @covers \local_sitsgradepush\extensionmanager::update_sora_for_mapping
     * @covers \local_sitsgradepush\assessment\coursework::apply_sora_extension
     * @return void
     * @throws \dml_exception
     */
    public function test_no_raa_override_for_past_coursework(): void {
        global $DB;

        // Create a past due coursework.
        $pastcoursework = $this->setup_coursework_with_mapping(
            $this->course1->id,
            '2025-02-01 10:00:00',
            '2025-02-08 13:00:00',
            'MSIN0047A7PF',
            '002'
        );

        // Process all mappings for RAA.
        $this->process_all_mappings_for_sora();

        // Verify no override was created for the past coursework.
        $override = $DB->get_record('coursework_extensions', ['courseworkid' => $pastcoursework->id]);
        $this->assertFalse($override);
    }

    /**
     * Test the update RAA override for students in a mapping.
     * It also tests the RAA override using the student data from assessment API
     * and the RAA override is deleted when the mapping is removed.
     *
     * @covers \local_sitsgradepush\extensionmanager::update_sora_for_mapping
     * @covers \local_sitsgradepush\extensionmanager::delete_sora_overrides
     * @covers \local_sitsgradepush\manager::get_assessment_mappings_by_courseid
     * @return void
     * @throws \dml_exception
     */
    public function test_update_raa_for_mapping(): void {
        global $DB;

        // Process all mappings for RAA.
        $this->process_all_mappings_for_sora();

        // Verify overrides were created.
        $result = $DB->get_records('coursework_extensions');
        $this->assertNotEmpty($result);

        // Verify all coursework overrides were created correctly.
        $this->assert_all_coursework_overrides();

        // Delete all mappings and verify overrides were removed.
        $this->delete_all_mappings();

        // All overrides should be deleted.
        $this->assert_no_overrides_exist();
    }

    /**
     * Test RAA override is added when RAA status changed to approved event received.
     *
     * @covers \local_sitsgradepush\extension\sora::process_extension
     * @covers \local_sitsgradepush\assessment\assessment::apply_extension
     * @covers \local_sitsgradepush\extension\models\raa_required_provisions::has_extension
     * @return void
     */
    public function test_raa_override_added_when_status_changed_to_approved(): void {
        global $DB;
        // Verify no override exists initially.
        $this->assertEmpty($DB->get_records('coursework_extensions'));

        // Process event with status approved.
        $eventdata = json_decode(tests_data_provider::get_sora_event_data_status(true), true);
        $sora = new sora();
        $sora->set_properties_from_aws_message(json_encode($eventdata));
        $sora->process_extension($sora->get_mappings_by_userid($sora->get_userid(), null));

        // Verify override was created.
        $this->assertNotEmpty($DB->get_records('coursework_extensions'));
    }

    /**
     * Test RAA override is removed when RAA status changed to not approved event received.
     *
     * @covers \local_sitsgradepush\extension\sora::process_extension
     * @covers \local_sitsgradepush\assessment\assessment::apply_extension
     * @covers \local_sitsgradepush\extension\models\raa_required_provisions::has_extension
     * @return void
     */
    public function test_raa_override_removed_when_status_not_approved(): void {
        global $DB;
        // Process all mappings for SORA to create override.
        $this->process_all_mappings_for_sora();

        // Verify override was created.
        $this->assertNotEmpty($DB->get_records('coursework_extensions'));

        // Process event with status not approved.
        $eventdata = json_decode(tests_data_provider::get_sora_event_data_status(false), true);

        $sora = new sora();
        $sora->set_properties_from_aws_message(json_encode($eventdata));
        $sora->process_extension($sora->get_mappings_by_userid($sora->get_userid(), null));

        // Verify override was removed.
        $this->assertEmpty($DB->get_records('coursework_extensions'));
    }

    /**
     * Test RAA override is removed when all extension fields are empty.
     *
     * @covers \local_sitsgradepush\extension\sora::process_extension
     * @covers \local_sitsgradepush\assessment\assessment::apply_extension
     * @covers \local_sitsgradepush\extension\models\raa_required_provisions::has_extension
     * @return void
     */
    public function test_raa_override_removed_when_all_extension_fields_empty(): void {
        global $DB;

        // Process all mappings for SORA to create override.
        $this->process_all_mappings_for_sora();

        // Verify override was created.
        $this->assertNotEmpty($DB->get_records('coursework_extensions'));

        // Process event with all extension fields set to None.
        foreach ($this->testassessmenttypes as $astcode) {
            $eventdata = json_decode(tests_data_provider::get_sora_event_data($astcode), true);
            $eventdata['entity']['person_sora']['required_provisions'][0]['no_dys_ext'] = null;
            $eventdata['entity']['person_sora']['required_provisions'][0]['no_hrs_ext'] = null;
            $eventdata['entity']['person_sora']['required_provisions'][0]['add_exam_time'] = null;
            $eventdata['entity']['person_sora']['required_provisions'][0]['rest_brk_add_time'] = null;

            $sora = new sora();
            $sora->set_properties_from_aws_message(json_encode($eventdata));
            $sora->process_extension($sora->get_mappings_by_userid($sora->get_userid(), $astcode));
        }

        // Verify override was removed.
        $this->assertEmpty($DB->get_records('coursework_extensions'));
    }

    /**
     * Test the update RAA extension for students in a mapping with extension off.
     *
     * @covers \local_sitsgradepush\extensionmanager::update_sora_for_mapping
     * @return void
     * @throws \dml_exception
     */
    public function test_update_raa_for_mapping_with_extension_off(): void {
        // Set extension disabled.
        set_config('extension_enabled', '0', 'local_sitsgradepush');

        // Process all mappings for RAA.
        $this->process_all_mappings_for_sora();

        // Verify no override was created for the coursework.
        $this->assert_no_overrides_exist();
    }

    /**
     * Test RAA extension for reassessment coursework.
     *
     * @covers \local_sitsgradepush\extension\sora::process_extension
     * @covers \local_sitsgradepush\extension\sora::set_properties_from_aws_message
     * @return void
     * @throws \dml_exception
     * @throws \moodle_exception
     */
    public function test_sora_extension_for_reassessment(): void {
        // Create a past year course with coursework.
        $course = $this->create_past_year_course();
        $coursework = $this->setup_coursework_with_mapping(
            $course->id,
            '2025-02-11 10:00:00',
            '2025-02-11 13:00:00',
            'BIOC0006A5UG',
            '002',
            1 // Reassessment.
        );

        // Test update RAA from aws can handle reassessment.
        $sora = new sora();
        $sora->set_properties_from_aws_message(tests_data_provider::get_sora_event_data('ED03'));
        $mappings = manager::get_manager()->get_assessment_mappings_by_courseid($course->id);
        $sora->process_extension($mappings);

        // Verify override was created for the coursework.
        $this->assert_coursework_override_exists(
            $coursework,
            $this->clock->now()->modify('2025-02-11 14:00:00')->getTimestamp()
        );
    }

    /**
     * Create mapping and setup for RAA testing.
     *
     * @param \stdClass $mab The MAB object.
     * @param int $courseid The course ID.
     * @param \stdClass $coursework The coursework object.
     * @param int $reassess The reassessment number.
     * @return bool|int The mapping ID.
     */
    protected function create_mapping(\stdClass $mab, int $courseid, \stdClass $coursework, int $reassess = 0): bool | int {
        // Insert mapping for the coursework and MAB.
        $mappingid = $this->insert_mapping($mab->id, $courseid, $coursework, 'coursework', $reassess);

        // Set API client with test student data.
        $this->setup_test_student_data($mab);

        return $mappingid;
    }

    /**
     * Assert that no overrides exist for courseworks.
     *
     * @throws \dml_exception
     */
    protected function assert_no_overrides_exist(): void {
        global $DB;
        $this->assertEmpty($DB->get_records('coursework_extensions'));
    }

    /**
     * Assert all coursework overrides were created correctly.
     *
     * @throws \dml_exception
     */
    protected function assert_all_coursework_overrides(): void {
        // ED03 Tier 1: (15 minutes + 5 minutes break) x 3 hours = 1 hour extension.
        $this->assert_coursework_override_exists(
            $this->raacoursework1,
            $this->clock->now()->modify('2025-02-11 14:00:00')->getTimestamp()
        );

        // CN01 Tier 1: 5 working days extension. Only test if feedback tracker is installed.
        if ($this->is_feedback_tracker_installed()) {
            $this->assert_coursework_override_exists(
                $this->raacoursework2,
                $this->clock->now()->modify('2025-02-27 14:00:00')->getTimestamp()
            );
        }

        // HD05 Tier 1: 14 hours extension.
        $this->assert_coursework_override_exists(
            $this->raacoursework3,
            $this->clock->now()->modify('2025-02-19 04:00:00')->getTimestamp()
        );
    }

    /**
     * Assert that a coursework override exists.
     *
     * @param object $coursework The coursework object.
     * @param int $enddate The expected end date timestamp.
     * @throws \dml_exception
     */
    protected function assert_coursework_override_exists(object $coursework, int $enddate): void {
        global $DB;

        // Test user extension exists in coursework_extensions table.
        $override = $DB->get_record('coursework_extensions', [
            'courseworkid' => $coursework->id,
            'allocatableid' => $this->student1->id,
            'allocatabletype' => 'user',
        ]);
        $this->assertNotEmpty($override);
        $this->assertEquals($this->student1->id, $override->allocatableid);
        $this->assertEquals($enddate, $override->extended_deadline);
    }

    /**
     * Create a test coursework.
     *
     * @param int $courseid The course ID.
     * @param int $startdate The start date timestamp.
     * @param int $enddate The end date timestamp.
     * @return object The coursework object.
     */
    protected function create_coursework(int $courseid, int $startdate, int $enddate): object {
        $coursework = $this->getDataGenerator()->create_module('coursework', [
            'course' => $courseid,
            'name' => 'Test Coursework',
            'startdate' => $startdate,
            'deadline' => $enddate,
        ]);

        // Enrol the student to the course.
        $this->getDataGenerator()->enrol_user($this->student1->id, $courseid, 'student');

        return self::convert_coursework_to_stdclass($coursework);
    }

    /**
     * Create coursework and mapping for testing.
     *
     * @param int $courseid The course ID.
     * @param string $startdatetime Start datetime string.
     * @param string $enddatetime End datetime string.
     * @param string $mapcode MAB mapcode.
     * @param string $mabseq MAB sequence.
     * @param int $reassess The reassessment number.
     * @return \stdClass The created coursework.
     * @throws \dml_exception
     */
    private function setup_coursework_with_mapping(
        int $courseid,
        string $startdatetime,
        string $enddatetime,
        string $mapcode,
        string $mabseq,
        int $reassess = 0
    ): \stdClass {
        global $DB;

        $startdate = $this->clock->now()->modify($startdatetime)->getTimestamp();
        $enddate = $this->clock->now()->modify($enddatetime)->getTimestamp();
        $coursework = $this->create_coursework($courseid, $startdate, $enddate);

        $mab = $DB->get_record('local_sitsgradepush_mab', ['mapcode' => $mapcode, 'mabseq' => $mabseq]);
        $this->create_mapping($mab, $courseid, $coursework, $reassess);

        return $coursework;
    }
}
