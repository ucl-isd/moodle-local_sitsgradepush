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

use core\clock;
use mod_coursework\models\course_module;
use mod_coursework\models\coursework;
use ReflectionClass;
use ReflectionMethod;

defined('MOODLE_INTERNAL') || die();
global $CFG;
require_once($CFG->dirroot . '/local/sitsgradepush/tests/fixtures/tests_data_provider.php');
require_once($CFG->dirroot . '/local/sitsgradepush/tests/base_test_class.php');

/**
 * Base class for extension tests.
 *
 * @package    local_sitsgradepush
 * @copyright  2024 onwards University College London {@link https://www.ucl.ac.uk/}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @author     Alex Yeung <k.yeung@ucl.ac.uk>
 */
class extension_common extends base_test_class {
    /** @var string Deadline group name prefix. */
    const DLG_PREFIX = 'DLG-';

    /** @var \stdClass $course1 Default test course 1 */
    protected \stdClass $course1;

    /** @var \stdClass Default test student 1 */
    protected \stdClass $student1;

    /** @var \stdClass Default test student 2 */
    protected \stdClass $student2;

    /** @var \stdClass Default test assignment 1 */
    protected \stdClass $assign1;

    /** @var \stdClass Default test quiz 1*/
    protected \stdClass $quiz1;

    /** @var \stdClass Default test lesson 1*/
    protected \stdClass $lesson1;

    /** @var \stdClass|null Default test coursework 1*/
    protected ?\stdClass $coursework1;

    /** @var clock $clock */
    protected readonly clock $clock;

    /** @var int Mapping ID. */
    protected $mappingid;

    /**
     * Tear down the test.
     *
     * @return void
     */
    public function tearDown(): void {
        parent::tearDown();
        $this->reset_manager_instance();
    }

    /**
     * Set up the test.
     *
     * @return void
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();

        // Set admin user.
        $this->setAdminUser();

        // Get data generator.
        $dg = $this->getDataGenerator();

        // Mock the clock.
        $this->clock = $this->mock_clock_with_frozen(strtotime('2025-02-10 09:00:00')); // Current time 2025-02-10 09:00:00.

        // Set Easikit API client.
        set_config('apiclient', 'easikit', 'local_sitsgradepush');

        // Setup testing environment.
        set_config('late_summer_assessment_end_2025', '2025-11-30', 'block_lifecycle');

        // Enable the extension.
        set_config('extension_enabled', '1', 'local_sitsgradepush');

        // Create a custom category and custom field.
        $dg->create_custom_field_category(['name' => 'CLC']);
        $dg->create_custom_field(['category' => 'CLC', 'shortname' => 'course_year']);

        // Create test courses.
        $this->course1 = $dg->create_course(
            ['shortname' => 'C1', 'customfields' => [
                ['shortname' => 'course_year', 'value' => $this->clock->now()->format('Y')],
            ]]
        );

        // Create test students and enrol them in course 1.
        $this->student1 = $dg->create_user(['idnumber' => '12345678']);
        $dg->enrol_user($this->student1->id, $this->course1->id, 'student');

        $this->student2 = $dg->create_user(['idnumber' => '87654321']);
        $dg->enrol_user($this->student2->id, $this->course1->id, 'student');

        $assessmentstartdate = strtotime('2025-02-17 09:00:00'); // Start date: 2025-02-17 09:00:00.
        $assessmentenddate = strtotime('2025-02-17 12:00:00'); // End date: 2025-02-17 12:00:00.

        // Clear coursework cm cache to avoid using cached cm which may have old data and cause issues with mapping.
        self::clear_coursework_cm_cache();

        // Create test assignment 1.
        $this->assign1 = $dg->create_module(
            'assign',
            [
                'name' => 'Test Assignment 1',
                'course' => $this->course1->id,
                'allowsubmissionsfromdate' => $assessmentstartdate,
                'duedate' => $assessmentenddate,
            ]
        );

        // Create test quiz 1.
        $this->quiz1 = $dg->create_module(
            'quiz',
            [
                'course' => $this->course1->id,
                'name' => 'Test Quiz 1',
                'timeopen' => $assessmentstartdate,
                'timeclose' => $assessmentenddate,
            ]
        );

        // Create test lesson 1.
        $this->lesson1 = $dg->create_module(
            'lesson',
            [
                'course' => $this->course1->id,
                'name' => 'Test Lesson 1',
                'available' => $assessmentstartdate,
                'deadline' => $assessmentenddate,
                'practice' => 0,
            ]
        );

        $courseworkpluginexists = \core_component::get_component_directory('mod_coursework');
        // Create test coursework 1 if coursework is installed.
        $coursework1 = $courseworkpluginexists
            ? $dg->create_module(
                'coursework',
                [
                    'name' => 'Test Coursework 1',
                    'course' => $this->course1->id,
                    'startdate' => $assessmentstartdate,
                    'deadline' => $assessmentenddate,
                    'extensionsenabled' => 1,
                ]
            )
            : null;

        // Convert coursework1 to stdClass if it exists.
        if ($coursework1 instanceof coursework) {
            // Convert coursework to stdClass.
            $this->coursework1 = self::convert_coursework_to_stdclass($coursework1);
        } else {
            $this->coursework1 = null;
        }

        // Set up the SITS grade push.
        $this->setup_sitsgradepush();
    }

    /**
     * Set up the SITS grade push.
     *
     * @return void
     * @throws \dml_exception|\coding_exception
     */
    protected function setup_sitsgradepush(): void {
        // Insert MABs.
        tests_data_provider::import_sitsgradepush_grade_components();
    }

    /**
     * Insert a test mapping.
     *
     * @param int $mabid
     * @param int $courseid
     * @param \stdClass $assessment
     * @param string $modtype
     * @param int $reassess
     * @return bool|int
     * @throws \dml_exception
     */
    protected function insert_mapping(
        int $mabid,
        int $courseid,
        \stdClass $assessment,
        string $modtype,
        int $reassess = 0
    ): bool|int {
        global $DB;

        return $DB->insert_record('local_sitsgradepush_mapping', [
            'courseid' => $courseid,
            'sourceid' => $assessment->cmid,
            'sourcetype' => 'mod',
            'moduletype' => $modtype,
            'componentgradeid' => $mabid,
            'reassessment' => $reassess,
            'enableextension' => extensionmanager::is_extension_enabled() ? 1 : 0,
            'timecreated' => $this->clock->now()->modify('-3 days')->getTimestamp(),
            'timemodified' => $this->clock->now()->modify('-3 days')->getTimestamp(),
        ]);
    }

    /**
     * Delete all mappings for the course
     */
    protected function delete_all_mappings(): void {
        $mappings = manager::get_manager()->get_assessment_mappings_by_courseid($this->course1->id);
        foreach ($mappings as $mapping) {
            manager::get_manager()->remove_mapping($this->course1->id, $mapping->id);
        }
    }

    /**
     * Create a past year course
     *
     * @return object The course object
     */
    protected function create_past_year_course(): object {
        return $this->getDataGenerator()->create_course(
            ['shortname' => 'C2', 'customfields' => [
                ['shortname' => 'course_year', 'value' => $this->clock->now()->modify('-1 year')->format('Y')],
            ]]
        );
    }

    /**
     * Create a deadline group in the test course.
     *
     * @param string $name The group name.
     * @return int The group ID.
     */
    protected function create_deadline_group(string $name): int {
        $group = new \stdClass();
        $group->courseid = $this->course1->id;
        $group->name = $name;
        return groups_create_group($group);
    }

    /**
     * Insert a group override into the assign_overrides table.
     *
     * @param int $assignid The assignment ID.
     * @param int $groupid The group ID.
     * @param int|null $duedate The due date timestamp.
     * @param int|null $startdate The start date timestamp.
     * @param int $sortorder The sort order value.
     * @return int The inserted override ID.
     */
    protected function create_assign_group_override(
        int $assignid,
        int $groupid,
        ?int $duedate = null,
        ?int $startdate = null,
        int $sortorder = 0
    ): int {
        global $DB;
        $override = new \stdClass();
        $override->assignid = $assignid;
        $override->groupid = $groupid;
        $override->duedate = $duedate;
        $override->allowsubmissionsfromdate = $startdate;
        $override->sortorder = $sortorder;
        return $DB->insert_record('assign_overrides', $override);
    }

    /**
     * Insert a group override into the quiz_overrides table.
     *
     * @param int $quizid The quiz ID.
     * @param int $groupid The group ID.
     * @param int|null $timeopen The time open timestamp.
     * @param int|null $timeclose The time close timestamp.
     * @return int The inserted override ID.
     */
    protected function create_quiz_group_override(
        int $quizid,
        int $groupid,
        ?int $timeopen = null,
        ?int $timeclose = null
    ): int {
        global $DB;
        $override = new \stdClass();
        $override->quiz = $quizid;
        $override->groupid = $groupid;
        $override->timeopen = $timeopen;
        $override->timeclose = $timeclose;
        return $DB->insert_record('quiz_overrides', $override);
    }

    /**
     * Insert a group override into the lesson_overrides table.
     *
     * @param int $lessonid The lesson ID.
     * @param int $groupid The group ID.
     * @param int|null $available The available timestamp.
     * @param int|null $deadline The deadline timestamp.
     * @return int The inserted override ID.
     */
    protected function create_lesson_group_override(
        int $lessonid,
        int $groupid,
        ?int $available = null,
        ?int $deadline = null
    ): int {
        global $DB;
        $override = new \stdClass();
        $override->lessonid = $lessonid;
        $override->groupid = $groupid;
        $override->available = $available;
        $override->deadline = $deadline;
        return $DB->insert_record('lesson_overrides', $override);
    }

    /**
     * Setup common test data and insert a mapping for the given activity type.
     *
     * @param string $type Activity type (assign/quiz/lesson/coursework).
     * @return object The activity object.
     */
    protected function setup_common_test_data(string $type = 'assign'): object {
        global $DB;

        $mab1 = $DB->get_record('local_sitsgradepush_mab', ['mapcode' => 'LAWS0024A6UF', 'mabseq' => '001']);
        $activity = $this->resolve_activity($type);

        $this->mappingid = $this->insert_mapping($mab1->id, $this->course1->id, $activity, $type);

        return $activity;
    }

    /**
     * Get the default test activity for a given activity type.
     *
     * @param string $type Activity type (assign/quiz/lesson/coursework).
     * @return object The activity object.
     */
    protected function resolve_activity(string $type): object {
        if ($type === 'assign') {
            return $this->assign1;
        } else if ($type === 'quiz') {
            return $this->quiz1;
        } else if ($type === 'lesson') {
            return $this->lesson1;
        } else {
            return $this->coursework1;
        }
    }

    /**
     * Get override table details for a given activity type.
     *
     * @param string $type Activity type (assign/quiz/lesson/coursework).
     * @return array Table details containing table name, date field, and activity field.
     */
    protected static function get_override_table_details(string $type): array {
        if ($type === 'assign') {
            return ['table' => 'assign_overrides', 'datefield' => 'duedate', 'activityfield' => 'assignid'];
        } else if ($type === 'quiz') {
            return ['table' => 'quiz_overrides', 'datefield' => 'timeclose', 'activityfield' => 'quiz'];
        } else if ($type === 'lesson') {
            return ['table' => 'lesson_overrides', 'datefield' => 'deadline', 'activityfield' => 'lessonid'];
        } else {
            return ['table' => 'coursework_extensions', 'datefield' => 'extended_deadline', 'activityfield' => 'courseworkid'];
        }
    }

    /**
     * Verify an override exists with the expected date, or that no override exists.
     *
     * @param object $activity The activity object.
     * @param string $type Activity type.
     * @param int|null $expecteddate Expected date timestamp, null to expect no override.
     * @param string $userfield Name of the user ID field.
     * @param int|null $userid The user ID to check, defaults to student 1.
     * @return void
     */
    protected function verify_override(
        object $activity,
        string $type,
        ?int $expecteddate,
        string $userfield,
        ?int $userid = null
    ): void {
        global $DB;
        $details = $this->get_override_table_details($type);

        $conditions = [
            $details['activityfield'] => $activity->id,
            $userfield => $userid ?? $this->student1->id,
        ];

        // Coursework requires allocatabletype field.
        if ($type === 'coursework') {
            $conditions['allocatabletype'] = 'user';
        }

        $override = $DB->get_record($details['table'], $conditions);

        if ($expecteddate === null) {
            $this->assertFalse($override);
        } else {
            $this->assertEquals($expecteddate, $override->{$details['datefield']});
        }
    }

    /**
     * Get an active override backup record of a given extension type for a user.
     *
     * @param int $userid The Moodle user ID.
     * @param string $extensiontype The extension type, e.g. EC, CDD.
     * @return mixed The record or false if not found.
     */
    protected function get_override_backup(int $userid, string $extensiontype): mixed {
        global $DB;
        return $DB->get_record('local_sitsgradepush_overrides', [
            'userid' => $userid,
            'extensiontype' => $extensiontype,
            'restored_by' => null,
        ]);
    }

    /**
     * Get an accessible method from an object.
     *
     * @param object $object The object to get the method from.
     * @param string $methodname The method name.
     * @return ReflectionMethod The accessible method.
     */
    protected function get_accessible_method(object $object, string $methodname): ReflectionMethod {
        $reflection = new ReflectionClass($object);
        $method = $reflection->getMethod($methodname);
        $method->setAccessible(true);
        return $method;
    }

    /**
     * Set the manager singleton instance via reflection.
     *
     * @param manager|null $manager The manager instance to set.
     * @return void
     */
    protected function set_manager_instance(?manager $manager): void {
        $managerreflection = new ReflectionClass(manager::class);
        $instance = $managerreflection->getProperty('instance');
        $instance->setAccessible(true);
        $instance->setValue(null, $manager);
    }

    /**
     * Reset the manager singleton instance.
     *
     * @return void
     */
    protected function reset_manager_instance(): void {
        $this->set_manager_instance(null);
    }
}
