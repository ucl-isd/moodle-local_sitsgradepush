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

defined('MOODLE_INTERNAL') || die();
global $CFG;
require_once($CFG->dirroot . '/local/sitsgradepush/tests/extension/raa/raa_base.php');

/**
 * Test class for Moodle assignment RAA extension.
 *
 * @package    local_sitsgradepush
 * @copyright  2025 onwards University College London {@link https://www.ucl.ac.uk/}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @author     Alex Yeung <k.yeung@ucl.ac.uk>
 */
final class raa_assign_test extends raa_base {
    /** @var \stdClass|null RAA test assignment 1 (ED03 - Exam). */
    private ?\stdClass $raaassign1;

    /** @var \stdClass|null RAA test assignment 2 (CN01 - Coursework). */
    private ?\stdClass $raaassign2;

    /** @var \stdClass|null RAA test assignment 3 (HD05 - Take-home assessment). */
    private ?\stdClass $raaassign3;

    /**
     * Set up the test.
     *
     * @return void
     * @throws \dml_exception
     */
    public function setUp(): void {
        parent::setUp();

        // Current time 2025-02-10 09:00:00.
        // ED03 (Exam) - 3 hours duration.
        $this->raaassign1 = $this->setup_assignment_with_mapping(
            $this->course1->id,
            '2025-02-11 10:00:00',
            '2025-02-11 13:00:00',
            'LAWS0024A6UF',
            '001'
        );

        // CN01 (Coursework) - Only if feedback tracker is installed.
        if ($this->is_feedback_tracker_installed()) {
            $this->raaassign2 = $this->setup_assignment_with_mapping(
                $this->course1->id,
                '2025-02-11 10:00:00',
                '2025-02-20 14:00:00',
                'LAWS0024A6UF',
                '002'
            );
        }

        // HD05 (Take-home assessment).
        $this->raaassign3 = $this->setup_assignment_with_mapping(
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
     * @covers \local_sitsgradepush\extension\sora::get_sora_group_id
     * @covers \local_sitsgradepush\extension\sora::set_properties_from_aws_message
     * @covers \local_sitsgradepush\assessment\assign::apply_sora_extension
     * @return void
     * @throws \coding_exception
     * @throws \dml_exception
     * @throws \moodle_exception
     */
    public function test_raa_process_extension_from_aws(): void {
        // Process the extension by passing the JSON event data.
        $sora = new sora();
        $sora->set_properties_from_aws_message(tests_data_provider::get_sora_event_data());
        $sora->process_extension($sora->get_mappings_by_userid($sora->get_userid()));

        // Verify all assignment overrides were created correctly.
        $this->assert_all_assignment_overrides($sora);
    }

    /**
     * Test no RAA override is created for past due date assignments.
     *
     * @covers \local_sitsgradepush\extensionmanager::update_sora_for_mapping
     * @covers \local_sitsgradepush\assessment\assign::apply_sora_extension
     * @return void
     * @throws \dml_exception
     */
    public function test_no_raa_override_for_past_assignment(): void {
        global $DB;

        // Create a past due assignment (due date was yesterday).
        $pastassign = $this->setup_assignment_with_mapping(
            $this->course1->id,
            '2025-02-01 10:00:00',
            '2025-02-08 13:00:00',
            'MSIN0047A7PF',
            '002'
        );

        // Process all mappings for SORA.
        $this->process_all_mappings_for_sora();

        // Verify no override was created for the past assignment.
        $override = $DB->get_record('assign_overrides', ['assignid' => $pastassign->id]);
        $this->assertFalse($override);
    }

    /**
     * Test the update RAA override for students in a mapping.
     * It also tests the RAA override using the student data from assessment API
     * and the RAA override group is deleted when the mapping is removed.
     *
     * @covers \local_sitsgradepush\extensionmanager::update_sora_for_mapping
     * @covers \local_sitsgradepush\extensionmanager::delete_sora_overrides
     * @covers \local_sitsgradepush\manager::get_assessment_mappings_by_courseid
     * @return void
     * @throws \dml_exception
     */
    public function test_update_raa_for_mapping(): void {
        global $DB;

        // Process all mappings for SORA.
        $this->process_all_mappings_for_sora();

        // Verify overrides were created.
        $result = $DB->get_records('assign_overrides');
        $this->assertNotEmpty($result);

        // Verify all assignment overrides were created correctly.
        $sora = new sora();
        $this->assert_all_assignment_overrides($sora);

        // Delete all mappings and verify overrides were removed.
        $this->delete_all_mappings();

        // All overrides should be deleted.
        $this->assert_no_overrides_exist();
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

        // Process all mappings for SORA.
        $this->process_all_mappings_for_sora();

        // Verify no override was created for the assignment.
        $this->assert_no_overrides_exist();
    }

    /**
     * Test RAA extension for reassessment assignment.
     *
     * @covers \local_sitsgradepush\extension\sora::process_extension
     * @covers \local_sitsgradepush\extension\sora::set_properties_from_aws_message
     * @return void
     * @throws \dml_exception
     * @throws \moodle_exception
     */
    public function test_sora_extension_for_reassessment(): void {
        // Create a past year course with assignment.
        $course = $this->create_past_year_course();
        $assign = $this->setup_assignment_with_mapping(
            $course->id,
            '2025-02-11 10:00:00',
            '2025-02-11 13:00:00',
            'BIOC0006A5UG',
            '002',
            1 // Reassessment.
        );

        // Test update SORA from aws can handle reassessment.
        $sora = new sora();
        $sora->set_properties_from_aws_message(tests_data_provider::get_sora_event_data());
        $mappings = manager::get_manager()->get_assessment_mappings_by_courseid($course->id);
        $sora->process_extension($mappings);

        // Verify override was created for the assignment.
        $this->assert_assignment_override_exists(
            $sora,
            $assign,
            60 * MINSECS,
            $this->clock->now()->modify('2025-02-11 14:00:00')->getTimestamp()
        );
    }

    /**
     * Test RAA fallback extension when SORA data doesn't match any TIERREF.
     * Uses the time extension directly for time_per_hour assessment types.
     *
     * @covers \local_sitsgradepush\assessment\assessment::can_assessment_apply_sora
     * @covers \local_sitsgradepush\assessment\assign::apply_sora_extension
     * @return void
     * @throws \dml_exception
     */
    public function test_fallback_extension_for_non_matching_tierref(): void {
        global $DB;

        // Create SORA data that doesn't match any TIERREF (20 mins extra, 20 mins rest).
        $student = tests_data_provider::get_sora_testing_student_data();
        $student['moodleuserid'] = $this->student1->id;
        $student['student_assessment']['sora']['extra_duration'] = '00:20';
        $student['student_assessment']['sora']['rest_duration'] = '00:20';

        // Process SORA for all mappings with modified student data.
        $mappings = manager::get_manager()->get_assessment_mappings_by_courseid($this->course1->id);
        foreach ($mappings as $mapping) {
            extensionmanager::update_sora_for_mapping($mapping, [$student]);
        }

        // Verify override was created for ED03 assignment with fallback calculation.
        // Fallback: duration (3 hours) * timeextension (40 mins = 2400 secs) = 3 * 2400 = 7200 secs = 2 hours.
        $sora = new sora();
        $this->assert_assignment_override_exists(
            $sora,
            $this->raaassign1,
            7200,
            $this->clock->now()->modify('2025-02-11 15:00:00')->getTimestamp()
        );

        // Verify no override was created for HD05 (time type, not time_per_hour).
        $override = $DB->get_record('assign_overrides', ['assignid' => $this->raaassign3->id]);
        $this->assertFalse($override);
    }

    /**
     * Create mapping and setup for RAA testing
     *
     * @param \stdClass $mab The MAB object
     * @param int $courseid The course ID
     * @param \stdClass $assign The assignment object
     * @param int $reassess The reassessment number
     * @return bool|int The mapping ID
     */
    protected function create_mapping(\stdClass $mab, int $courseid, \stdClass $assign, int $reassess = 0): bool | int {
        // Insert mapping for the assignment and MAB.
        $mappingid = $this->insert_mapping($mab->id, $courseid, $assign, 'assign', $reassess);

        // Set API client with test student data.
        $this->setup_test_student_data($mab);

        return $mappingid;
    }

    /**
     * Assert that no overrides exist for assignments.
     *
     * @throws \dml_exception
     */
    protected function assert_no_overrides_exist(): void {
        global $DB;
        $this->assertEmpty($DB->get_records('assign_overrides'));
    }

    /**
     * Assert all assignment overrides were created correctly.
     *
     * @param sora $sora The SORA extension object.
     * @throws \dml_exception
     */
    protected function assert_all_assignment_overrides(sora $sora): void {
        // ED03 Tier 1: (15 minutes + 5 minutes break) x 3 hours = 1 hour.
        $this->assert_assignment_override_exists(
            $sora,
            $this->raaassign1,
            60 * MINSECS,
            $this->clock->now()->modify('2025-02-11 14:00:00')->getTimestamp()
        );

        // CN01 Tier 1: 5 working days extension. Only test if feedback tracker is installed.
        if ($this->is_feedback_tracker_installed()) {
            $this->assert_assignment_override_exists(
                $sora,
                $this->raaassign2,
                5 * DAYSECS,
                $this->clock->now()->modify('2025-02-27 14:00:00')->getTimestamp()
            );
        }

        // HD05 Tier 1: 14 hours extension.
        $this->assert_assignment_override_exists(
            $sora,
            $this->raaassign3,
            14 * HOURSECS,
            $this->clock->now()->modify('2025-02-19 04:00:00')->getTimestamp()
        );
    }

    /**
     * Assert that an assignment override exists.
     *
     * @param sora $sora The SORA extension object.
     * @param object $assign The assignment object.
     * @param int $seconds The number of seconds for the extension.
     * @param int $enddate The expected end date timestamp.
     * @throws \dml_exception
     */
    protected function assert_assignment_override_exists(sora $sora, object $assign, int $seconds, int $enddate): void {
        global $DB;

        // Test SORA override group exists.
        $groupid = $DB->get_field('groups', 'id', ['name' => $sora->get_extension_group_name($assign->cmid, $seconds)]);
        $this->assertNotEmpty($groupid);

        // Test user is added to the SORA group.
        $groupmember = $DB->get_record('groups_members', ['groupid' => $groupid, 'userid' => $this->student1->id]);
        $this->assertNotEmpty($groupmember);

        // Test group override set in the assignment.
        $override = $DB->get_record('assign_overrides', ['assignid' => $assign->id, 'userid' => null, 'groupid' => $groupid]);
        $this->assertEquals($groupid, $override->groupid);
        $this->assertEquals($enddate, $override->duedate);
    }

    /**
     * Create assignment and mapping for testing.
     *
     * @param int $courseid The course ID.
     * @param string $startdatetime Start datetime string.
     * @param string $enddatetime End datetime string.
     * @param string $mapcode MAB mapcode.
     * @param string $mabseq MAB sequence.
     * @param int $reassess 1 for reassessment.
     *
     * @return \stdClass The created assignment.
     * @throws \dml_exception
     */
    private function setup_assignment_with_mapping(
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
        $assign = $this->create_assignment($courseid, $startdate, $enddate);

        $mab = $DB->get_record('local_sitsgradepush_mab', ['mapcode' => $mapcode, 'mabseq' => $mabseq]);
        $this->create_mapping($mab, $courseid, $assign, $reassess);

        return $assign;
    }
}
