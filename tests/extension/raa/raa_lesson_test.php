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

use DateTimeImmutable;
use local_sitsgradepush\extension\sora;
use local_sitsgradepush\manager;
use local_sitsgradepush\tests_data_provider;

defined('MOODLE_INTERNAL') || die();
global $CFG;
require_once($CFG->dirroot . '/local/sitsgradepush/tests/extension/raa/raa_base.php');

/**
 * Test class for Moodle lesson RAA extension.
 *
 * @package    local_sitsgradepush
 * @copyright  2026 onwards University College London {@link https://www.ucl.ac.uk/}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @author     Alex Yeung <k.yeung@ucl.ac.uk>
 */
final class raa_lesson_test extends raa_base {
    /** @var \stdClass|null RAA test lesson 1 (ED03 - Exam). */
    private ?\stdClass $raalesson1;

    /** @var \stdClass|null RAA test lesson 2 (CN01 - Coursework). */
    private ?\stdClass $raalesson2;

    /** @var \stdClass|null RAA test lesson 3 (HD05 - Take-home assessment). */
    private ?\stdClass $raalesson3;

    /**
     * Set up the test.
     *
     * @return void
     */
    public function setUp(): void {
        parent::setUp();

        // Current time 2025-02-10 09:00:00.
        // ED03 (Exam) - 3 hours duration.
        $this->raalesson1 = $this->setup_lesson_with_mapping(
            $this->course1->id,
            '2025-02-11 10:00:00',
            '2025-02-11 13:00:00',
            'LAWS0024A6UF',
            '001'
        );

        // CN01 (Coursework) - Only if feedback tracker is installed.
        if ($this->is_feedback_tracker_installed()) {
            $this->raalesson2 = $this->setup_lesson_with_mapping(
                $this->course1->id,
                '2025-02-11 10:00:00',
                '2025-02-20 14:00:00',
                'LAWS0024A6UF',
                '002'
            );
        }

        // HD05 (Take-home assessment).
        $this->raalesson3 = $this->setup_lesson_with_mapping(
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
     * @covers \local_sitsgradepush\assessment\lesson::apply_sora_extension
     * @return void
     */
    public function test_raa_process_extension_from_aws(): void {
        // Process the extension by passing the JSON event data.
        // Test with ED03, CN01 and HD05 assessment types.
        foreach ($this->testassessmenttypes as $astcode) {
            $sora = new sora();
            $sora->set_properties_from_aws_message(tests_data_provider::get_sora_event_data($astcode));
            $sora->process_extension($sora->get_mappings_by_userid($sora->get_userid(), $astcode));
        }

        // Verify all lesson overrides were created correctly.
        $this->assert_all_lesson_overrides($sora);
    }

    /**
     * Test no RAA override is created for past due date lessons.
     *
     * @covers \local_sitsgradepush\extensionmanager::update_sora_for_mapping
     * @covers \local_sitsgradepush\assessment\lesson::apply_sora_extension
     * @return void
     */
    public function test_no_raa_override_for_past_lesson(): void {
        global $DB;

        // Create a past due lesson (due date was yesterday).
        $pastlesson = $this->setup_lesson_with_mapping(
            $this->course1->id,
            '2025-02-01 10:00:00',
            '2025-02-08 13:00:00',
            'MSIN0047A7PF',
            '002'
        );

        // Process all mappings for RAA.
        $this->process_all_mappings_for_sora();

        // Verify no override was created for the past lesson.
        $override = $DB->get_record('lesson_overrides', ['lessonid' => $pastlesson->id]);
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
     */
    public function test_update_raa_for_mapping(): void {
        global $DB;

        // Process all mappings for RAA.
        $this->process_all_mappings_for_sora();

        // Verify overrides were created.
        $result = $DB->get_records('lesson_overrides');
        $this->assertNotEmpty($result);

        // Verify all lesson overrides were created correctly.
        $sora = new sora();
        $this->assert_all_lesson_overrides($sora);

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
     */
    public function test_update_raa_for_mapping_with_extension_off(): void {
        // Set extension disabled.
        set_config('extension_enabled', '0', 'local_sitsgradepush');

        // Process all mappings for RAA.
        $this->process_all_mappings_for_sora();

        // Verify no override was created for the lesson.
        $this->assert_no_overrides_exist();
    }

    /**
     * Test time limit extension for lessons.
     *
     * @covers \local_sitsgradepush\assessment\lesson::apply_sora_extension
     * @return void
     */
    public function test_timelimit_extension_for_lesson(): void {
        global $DB;

        // Update lessons with time limits.
        // ED03: 3 hours time limit.
        $DB->update_record('lesson', [
            'id' => $this->raalesson1->id,
            'timelimit' => 3 * HOURSECS,
        ]);

        // CN01: 7 days time limit (only if feedback tracker is installed).
        if ($this->is_feedback_tracker_installed()) {
            $DB->update_record('lesson', [
                'id' => $this->raalesson2->id,
                'timelimit' => 7 * DAYSECS,
            ]);
        }

        // HD05: 5 days time limit.
        $DB->update_record('lesson', [
            'id' => $this->raalesson3->id,
            'timelimit' => 5 * DAYSECS,
        ]);

        // Process all mappings for RAA.
        $this->process_all_mappings_for_sora();

        // Verify time limit extensions.
        $sora = new sora();

        // ED03 Tier 1: (15 minutes + 5 minutes break) x 3 hours = 1 hour extension.
        // Time limit: 3 hours + 1 hour = 4 hours.
        $this->assert_lesson_override_exists(
            $sora,
            $this->raalesson1,
            HOURSECS,
            (new DateTimeImmutable('2025-02-11 14:00:00'))->getTimestamp(),
            4 * HOURSECS
        );

        // CN01 Tier 1: 5 working days extension. Only test if feedback tracker is installed.
        // Time limit: 7 days + 5 days = 12 days.
        if ($this->is_feedback_tracker_installed()) {
            $this->assert_lesson_override_exists(
                $sora,
                $this->raalesson2,
                5 * DAYSECS,
                (new DateTimeImmutable('2025-02-27 14:00:00'))->getTimestamp(),
                12 * DAYSECS
            );
        }

        // HD05 Tier 1: 14 hours extension.
        // Time limit: 5 days + 14 hours.
        $this->assert_lesson_override_exists(
            $sora,
            $this->raalesson3,
            14 * HOURSECS,
            (new DateTimeImmutable('2025-02-19 04:00:00'))->getTimestamp(),
            5 * DAYSECS + 14 * HOURSECS
        );
    }

    /**
     * Test RAA extension for reassessment lesson.
     *
     * @covers \local_sitsgradepush\extension\sora::process_extension
     * @covers \local_sitsgradepush\extension\sora::set_properties_from_aws_message
     * @return void
     */
    public function test_sora_extension_for_reassessment(): void {
        // Create a past year course with lesson.
        $course = $this->create_past_year_course();
        $lesson = $this->setup_lesson_with_mapping(
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

        // Verify override was created for the lesson.
        $this->assert_lesson_override_exists(
            $sora,
            $lesson,
            HOURSECS,
            (new DateTimeImmutable('2025-02-11 14:00:00'))->getTimestamp()
        );
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
        $this->assertEmpty($DB->get_records('lesson_overrides'));

        // Process event with status approved.
        $eventdata = json_decode(tests_data_provider::get_sora_event_data_status(true), true);
        $sora = new sora();
        $sora->set_properties_from_aws_message(json_encode($eventdata));
        $sora->process_extension($sora->get_mappings_by_userid($sora->get_userid(), null));

        // Verify override was created.
        $this->assertNotEmpty($DB->get_records('lesson_overrides'));
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
        // Process all mappings for RAA to create override.
        $this->process_all_mappings_for_sora();

        // Verify override was created.
        $this->assertNotEmpty($DB->get_records('lesson_overrides'));

        // Process event with status not approved.
        $eventdata = json_decode(tests_data_provider::get_sora_event_data_status(false), true);

        $sora = new sora();
        $sora->set_properties_from_aws_message(json_encode($eventdata));
        $sora->process_extension($sora->get_mappings_by_userid($sora->get_userid(), null));

        // Verify override was removed.
        $this->assertEmpty($DB->get_records('lesson_overrides'));
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

        // Process all mappings for RAA to create override.
        $this->process_all_mappings_for_sora();

        // Verify override was created.
        $this->assertNotEmpty($DB->get_records('lesson_overrides'));

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
        $this->assertEmpty($DB->get_records('lesson_overrides'));
    }

    /**
     * Create mapping and setup for RAA testing.
     *
     * @param \stdClass $mab The MAB object.
     * @param int $courseid The course ID.
     * @param \stdClass $lesson The lesson object.
     * @param int $reassess The reassessment number.
     * @return bool|int The mapping ID.
     */
    protected function create_mapping(\stdClass $mab, int $courseid, \stdClass $lesson, int $reassess = 0): bool | int {
        // Insert mapping for the lesson and MAB.
        $mappingid = $this->insert_mapping($mab->id, $courseid, $lesson, 'lesson', $reassess);

        // Set API client with test student data.
        $this->setup_test_student_data($mab);

        return $mappingid;
    }

    /**
     * Assert that no overrides exist for lessons.
     *
     * @return void
     */
    protected function assert_no_overrides_exist(): void {
        global $DB;
        $this->assertEmpty($DB->get_records('lesson_overrides'));
    }

    /**
     * Assert all lesson overrides were created correctly.
     *
     * @param sora $sora The RAA extension object.
     * @return void
     */
    protected function assert_all_lesson_overrides(sora $sora): void {
        // ED03 Tier 1: (15 minutes + 5 minutes break) x 3 hours = 1 hour extension.
        $this->assert_lesson_override_exists(
            $sora,
            $this->raalesson1,
            HOURSECS,
            (new DateTimeImmutable('2025-02-11 14:00:00'))->getTimestamp()
        );

        // CN01 Tier 1: 5 working days extension. Only test if feedback tracker is installed.
        if ($this->is_feedback_tracker_installed()) {
            $this->assert_lesson_override_exists(
                $sora,
                $this->raalesson2,
                5 * DAYSECS,
                (new DateTimeImmutable('2025-02-27 14:00:00'))->getTimestamp()
            );
        }

        // HD05 Tier 1: 14 hours extension.
        $this->assert_lesson_override_exists(
            $sora,
            $this->raalesson3,
            14 * HOURSECS,
            (new DateTimeImmutable('2025-02-19 04:00:00'))->getTimestamp()
        );
    }

    /**
     * Assert that a lesson override exists.
     *
     * @param sora $sora The RAA extension object.
     * @param object $lesson The lesson object.
     * @param int $seconds The number of seconds for the extension.
     * @param int $enddate The expected end date timestamp.
     * @param int|null $expectedtimelimit The expected time limit in seconds.
     */
    protected function assert_lesson_override_exists(
        sora $sora,
        object $lesson,
        int $seconds,
        int $enddate,
        ?int $expectedtimelimit = null
    ): void {
        global $DB;

        // Test RAA override group exists.
        $groupid = $DB->get_field('groups', 'id', ['name' => $sora->get_extension_group_name($lesson->cmid, $seconds)]);
        $this->assertNotEmpty($groupid);

        // Test user is added to the RAA group.
        $groupmember = $DB->get_record('groups_members', ['groupid' => $groupid, 'userid' => $this->student1->id]);
        $this->assertNotEmpty($groupmember);

        // Test group override set in the lesson.
        $override = $DB->get_record('lesson_overrides', ['lessonid' => $lesson->id, 'userid' => null, 'groupid' => $groupid]);
        $this->assertEquals($groupid, $override->groupid);
        $this->assertEquals($enddate, $override->deadline);

        // Test time limit if expected.
        if ($expectedtimelimit !== null) {
            $this->assertEquals($expectedtimelimit, $override->timelimit);
        }
    }

    /**
     * Create lesson and mapping for testing.
     *
     * @param int $courseid The course ID.
     * @param string $startdatetime Start datetime string.
     * @param string $enddatetime End datetime string.
     * @param string $mapcode MAB mapcode.
     * @param string $mabseq MAB sequence.
     * @param int $reassess The reassessment number.
     * @return \stdClass The created lesson.
     */
    private function setup_lesson_with_mapping(
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
        $lesson = $this->create_lesson($courseid, $startdate, $enddate);

        $mab = $DB->get_record('local_sitsgradepush_mab', ['mapcode' => $mapcode, 'mabseq' => $mabseq]);
        $this->create_mapping($mab, $courseid, $lesson, $reassess);

        return $lesson;
    }
}
