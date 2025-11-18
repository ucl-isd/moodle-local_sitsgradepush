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
use local_sitsgradepush\manager;
use local_sitsgradepush\tests_data_provider;

defined('MOODLE_INTERNAL') || die();
global $CFG;
require_once($CFG->dirroot . '/local/sitsgradepush/tests/extension/raa/raa_base.php');

/**
 * Test class for Moodle quiz RAA extension.
 *
 * @package    local_sitsgradepush
 * @copyright  2025 onwards University College London {@link https://www.ucl.ac.uk/}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @author     Alex Yeung <k.yeung@ucl.ac.uk>
 */
final class raa_quiz_test extends raa_base {
    /** @var \stdClass|null RAA test quiz 1 (ED03 - Exam). */
    private ?\stdClass $raaquiz1;

    /** @var \stdClass|null RAA test quiz 2 (CN01 - Coursework). */
    private ?\stdClass $raaquiz2;

    /** @var \stdClass|null RAA test quiz 3 (HD05 - Take-home assessment). */
    private ?\stdClass $raaquiz3;

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
        $this->raaquiz1 = $this->setup_quiz_with_mapping(
            $this->course1->id,
            '2025-02-11 10:00:00',
            '2025-02-11 13:00:00',
            'LAWS0024A6UF',
            '001'
        );

        // CN01 (Coursework) - Only if feedback tracker is installed.
        if ($this->is_feedback_tracker_installed()) {
            $this->raaquiz2 = $this->setup_quiz_with_mapping(
                $this->course1->id,
                '2025-02-11 10:00:00',
                '2025-02-20 14:00:00',
                'LAWS0024A6UF',
                '002'
            );
        }

        // HD05 (Take-home assessment).
        $this->raaquiz3 = $this->setup_quiz_with_mapping(
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
     * @covers \local_sitsgradepush\assessment\quiz::apply_sora_extension
     * @throws \coding_exception
     * @throws \dml_exception
     * @throws \moodle_exception
     */
    public function test_raa_process_extension_from_aws(): void {
        // Process the extension by passing the JSON event data.
        $sora = new sora();
        $sora->set_properties_from_aws_message(tests_data_provider::get_sora_event_data());
        $sora->process_extension($sora->get_mappings_by_userid($sora->get_userid()));

        // Verify all quiz overrides were created correctly.
        $this->assert_all_quiz_overrides($sora);
    }

    /**
     * Test no RAA override is created for past due date quizzes.
     *
     * @covers \local_sitsgradepush\extensionmanager::update_sora_for_mapping
     * @covers \local_sitsgradepush\assessment\quiz::apply_sora_extension
     * @return void
     * @throws \dml_exception
     */
    public function test_no_raa_override_for_past_quiz(): void {
        global $DB;

        // Create a past due quiz (due date was yesterday).
        $pastquiz = $this->setup_quiz_with_mapping(
            $this->course1->id,
            '2025-02-01 10:00:00',
            '2025-02-08 13:00:00',
            'MSIN0047A7PF',
            '002'
        );

        // Process all mappings for SORA.
        $this->process_all_mappings_for_sora();

        // Verify no override was created for the past quiz.
        $override = $DB->get_record('quiz_overrides', ['quiz' => $pastquiz->id]);
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
        $result = $DB->get_records('quiz_overrides');
        $this->assertNotEmpty($result);

        // Verify all quiz overrides were created correctly.
        $sora = new sora();
        $this->assert_all_quiz_overrides($sora);

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

        // Verify no override was created for the quiz.
        $this->assert_no_overrides_exist();
    }

    /**
     * Test time limit extension for quizzes.
     *
     * @covers \local_sitsgradepush\assessment\quiz::apply_sora_extension
     * @return void
     * @throws \dml_exception
     */
    public function test_timelimit_extension_for_quiz(): void {
        global $DB;

        // Update quizzes with time limits.
        // ED03: 3 hours time limit.
        $DB->update_record('quiz', [
            'id' => $this->raaquiz1->id,
            'timelimit' => 3 * HOURSECS,
        ]);

        // CN01: 7 days time limit (only if feedback tracker is installed).
        if ($this->is_feedback_tracker_installed()) {
            $DB->update_record('quiz', [
                'id' => $this->raaquiz2->id,
                'timelimit' => 7 * DAYSECS,
            ]);
        }

        // HD05: 5 days time limit.
        $DB->update_record('quiz', [
            'id' => $this->raaquiz3->id,
            'timelimit' => 5 * DAYSECS,
        ]);

        // Process all mappings for SORA.
        $this->process_all_mappings_for_sora();

        // Verify time limit extensions.
        $sora = new sora();

        // ED03 Tier 1: (15 minutes + 5 minutes break) x 3 hours = 1 hour extension.
        // Time limit: 3 hours + 1 hour = 4 hours.
        $this->assert_quiz_override_exists(
            $sora,
            $this->raaquiz1,
            60 * MINSECS,
            $this->clock->now()->modify('2025-02-11 14:00:00')->getTimestamp(),
            4 * HOURSECS
        );

        // CN01 Tier 1: 5 working days extension. Only test if feedback tracker is installed.
        // Time limit: 7 days + 5 days = 12 days.
        if ($this->is_feedback_tracker_installed()) {
            $this->assert_quiz_override_exists(
                $sora,
                $this->raaquiz2,
                5 * DAYSECS,
                $this->clock->now()->modify('2025-02-27 14:00:00')->getTimestamp(),
                12 * DAYSECS
            );
        }

        // HD05 Tier 1: 14 hours extension.
        // Time limit: 5 days + 14 hours.
        $this->assert_quiz_override_exists(
            $sora,
            $this->raaquiz3,
            14 * HOURSECS,
            $this->clock->now()->modify('2025-02-19 04:00:00')->getTimestamp(),
            5 * DAYSECS + 14 * HOURSECS
        );
    }

    /**
     * Test RAA extension for reassessment quiz.
     *
     * @covers \local_sitsgradepush\extension\sora::process_extension
     * @covers \local_sitsgradepush\extension\sora::set_properties_from_aws_message
     * @return void
     * @throws \dml_exception
     * @throws \moodle_exception
     */
    public function test_sora_extension_for_reassessment(): void {
        // Create a past year course with quiz.
        $course = $this->create_past_year_course();
        $quiz = $this->setup_quiz_with_mapping(
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

        // Verify override was created for the quiz.
        $this->assert_quiz_override_exists(
            $sora,
            $quiz,
            60 * MINSECS,
            $this->clock->now()->modify('2025-02-11 14:00:00')->getTimestamp()
        );
    }

    /**
     * Create mapping and setup for RAA testing.
     *
     * @param \stdClass $mab The MAB object.
     * @param int $courseid The course ID.
     * @param \stdClass $quiz The quiz object.
     * @param int $reassess The reassessment number.
     * @return bool|int The mapping ID.
     */
    protected function create_mapping(\stdClass $mab, int $courseid, \stdClass $quiz, int $reassess = 0): bool | int {
        // Insert mapping for the quiz and MAB.
        $mappingid = $this->insert_mapping($mab->id, $courseid, $quiz, 'quiz', $reassess);

        // Set API client with test student data.
        $this->setup_test_student_data($mab);

        return $mappingid;
    }

    /**
     * Assert that no overrides exist for quizzes.
     *
     * @throws \dml_exception
     */
    protected function assert_no_overrides_exist(): void {
        global $DB;
        $this->assertEmpty($DB->get_records('quiz_overrides'));
    }

    /**
     * Assert all quiz overrides were created correctly.
     *
     * @param sora $sora The SORA extension object.
     * @throws \dml_exception
     */
    protected function assert_all_quiz_overrides(sora $sora): void {
        // ED03 Tier 1: (15 minutes + 5 minutes break) x 3 hours = 1 hour extension.
        $this->assert_quiz_override_exists(
            $sora,
            $this->raaquiz1,
            60 * MINSECS,
            $this->clock->now()->modify('2025-02-11 14:00:00')->getTimestamp()
        );

        // CN01 Tier 1: 5 working days extension. Only test if feedback tracker is installed.
        if ($this->is_feedback_tracker_installed()) {
            $this->assert_quiz_override_exists(
                $sora,
                $this->raaquiz2,
                5 * DAYSECS,
                $this->clock->now()->modify('2025-02-27 14:00:00')->getTimestamp()
            );
        }

        // HD05 Tier 1: 14 hours extension.
        $this->assert_quiz_override_exists(
            $sora,
            $this->raaquiz3,
            14 * HOURSECS,
            $this->clock->now()->modify('2025-02-19 04:00:00')->getTimestamp()
        );
    }

    /**
     * Assert that a quiz override exists.
     *
     * @param sora $sora The SORA extension object.
     * @param object $quiz The quiz object.
     * @param int $seconds The number of seconds for the extension.
     * @param int $enddate The expected end date timestamp.
     * @param int|null $expectedtimelimit The expected time limit in seconds.
     * @throws \dml_exception
     */
    protected function assert_quiz_override_exists(
        sora $sora,
        object $quiz,
        int $seconds,
        int $enddate,
        ?int $expectedtimelimit = null
    ): void {
        global $DB;

        // Test SORA override group exists.
        $groupid = $DB->get_field('groups', 'id', ['name' => $sora->get_extension_group_name($quiz->cmid, $seconds)]);
        $this->assertNotEmpty($groupid);

        // Test user is added to the SORA group.
        $groupmember = $DB->get_record('groups_members', ['groupid' => $groupid, 'userid' => $this->student1->id]);
        $this->assertNotEmpty($groupmember);

        // Test group override set in the quiz.
        $override = $DB->get_record('quiz_overrides', ['quiz' => $quiz->id, 'userid' => null, 'groupid' => $groupid]);
        $this->assertEquals($groupid, $override->groupid);
        $this->assertEquals($enddate, $override->timeclose);

        // Test time limit if expected.
        if ($expectedtimelimit !== null) {
            $this->assertEquals($expectedtimelimit, $override->timelimit);
        }
    }

    /**
     * Create quiz and mapping for testing.
     *
     * @param int $courseid The course ID.
     * @param string $startdatetime Start datetime string.
     * @param string $enddatetime End datetime string.
     * @param string $mapcode MAB mapcode.
     * @param string $mabseq MAB sequence.
     * @param int $reassess The reassessment number.
     * @return \stdClass The created quiz.
     * @throws \dml_exception
     */
    private function setup_quiz_with_mapping(
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
        $quiz = $this->create_quiz($courseid, $startdate, $enddate);

        $mab = $DB->get_record('local_sitsgradepush_mab', ['mapcode' => $mapcode, 'mabseq' => $mabseq]);
        $this->create_mapping($mab, $courseid, $quiz, $reassess);

        return $quiz;
    }
}
