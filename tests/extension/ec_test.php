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

namespace local_sitsgradepush\extension;

use local_sitsgradepush\extension_common;
use local_sitsgradepush\extensionmanager;
use local_sitsgradepush\manager;
use local_sitsgradepush\task\process_extensions_new_enrolment;
use local_sitsgradepush\task\process_extensions_new_mapping;

defined('MOODLE_INTERNAL') || die();
global $CFG;
require_once($CFG->dirroot . '/local/sitsgradepush/tests/fixtures/tests_data_provider.php');
require_once($CFG->dirroot . '/local/sitsgradepush/tests/extension/extension_common.php');

/**
 * Tests for the EC extension.
 *
 * @package    local_sitsgradepush
 * @copyright  2025 onwards University College London {@link https://www.ucl.ac.uk/}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @author     Alex Yeung <k.yeung@ucl.ac.uk>
 */
final class ec_test extends extension_common {
    /**
     * Mapping ID.
     * @var int
     */
    private $mappingid;

    /**
     * Tear down the test.
     * @return void
     */
    public function tearDown(): void {
        parent::tearDown();
        $this->reset_manager_instance();
    }

    /**
     * Test process_message method
     *
     * @covers \local_sitsgradepush\extension\ec_queue_processor::process_message
     * @covers \local_sitsgradepush\extension\ec_queue_processor::should_ignore_message
     * @covers \local_sitsgradepush\extension\ec::process_extension
     * @return void
     */
    public function test_ec_queue_processor_process_message(): void {
        global $DB;

        $assign = $this->setup_common_test_data();
        $eventdata = file_get_contents(__DIR__ . '/../fixtures/ec_event_data.json');
        $processor = new ec_queue_processor();
        $method = $this->get_accessible_method($processor, 'process_message');

        // Test case 1: Process a valid message.
        $result = $method->invoke($processor, ['Message' => $eventdata]);
        $this->assertEquals(aws_queue_processor::STATUS_PROCESSED, $result['status']);

        $override = $DB->get_record('assign_overrides', ['assignid' => $assign->id, 'userid' => $this->student1->id]);
        $this->assertNotEmpty($override);
        $this->assertEquals(strtotime('2025-02-27 12:00'), $override->duedate);

        // Test case 2: Test message that should be ignored.
        $ignoredmessage = json_decode($eventdata, true);
        $ignoredmessage['entity']['student_extenuating_circumstances']['extenuating_circumstances']['request']['status'] =
            'PENDING';
        $ignoredmessage['entity']['student_extenuating_circumstances']['extenuating_circumstances']['request']['decision_type'] =
            'PANEL';

        $result = $method->invoke($processor, ['Message' => json_encode($ignoredmessage)]);
        $this->assertEquals(aws_queue_processor::STATUS_IGNORED, $result['status']);

        // Test case 3: Test exception when student not found.
        $manager = $this->createMock(manager::class);
        $manager->method('get_students_from_sits')->willReturn([]);
        $this->set_manager_instance($manager);
        $this->expectException(\moodle_exception::class);
        $method->invoke($processor, ['Message' => $eventdata]);
    }

    /**
     * Data provider for testing new student enrollment
     *
     * @return array[]
     */
    public static function new_student_enrollment_provider(): array {
        return [
            'assignment' => ['assign'],
            'quiz' => ['quiz'],
        ];
    }

    /**
     * Test process extensions for new student enrollments
     *
     * @covers \local_sitsgradepush\task\process_extensions_new_enrolment::execute
     * @dataProvider new_student_enrollment_provider
     * @param string $type Activity type (assign/quiz)
     */
    public function test_process_extensions_new_enrolment(string $type): void {
        $activity = $this->setup_common_test_data($type);

        // Verify no initial override.
        $this->verify_override($activity, $type, null);

        // Execute new enrollment task.
        $task = new process_extensions_new_enrolment();
        $task->set_custom_data((object)['courseid' => $this->course1->id]);
        $task->execute();

        // Verify new due date is set.
        $this->verify_override($activity, $type, strtotime('2025-02-27 12:00'));

        // Remove mapping and verify override is removed.
        manager::get_manager()->remove_mapping($this->course1->id, $this->mappingid);
        $this->verify_override($activity, $type, null);
    }

    /**
     * Data provider for testing user override restoration
     *
     * @return array[]
     */
    public static function override_restoration_provider(): array {
        return [
            'assignment' => ['assign', 'assign_overrides', 'duedate', 'assignid'],
            'quiz' => ['quiz', 'quiz_overrides', 'timeclose', 'quiz'],
        ];
    }

    /**
     * Test process extensions for new SITS mapping
     *
     * @covers \local_sitsgradepush\task\process_extensions_new_mapping::execute
     * @covers \local_sitsgradepush\extensionmanager::update_ec_for_mapping
     * @covers \local_sitsgradepush\extensionmanager::delete_ec_overrides
     * @dataProvider override_restoration_provider
     * @param string $type Activity type (assign/quiz)
     * @param string $table Override table name
     * @param string $datefield Name of the date field
     * @param string $activityfield Name of the activity ID field
     */
    public function test_process_extensions_new_sits_mapping(
        string $type,
        string $table,
        string $datefield,
        string $activityfield
    ): void {
        global $DB;
        $this->setAdminUser();

        $activity = $this->setup_common_test_data($type);

        // Add user override with original due date.
        $override = [
            $activityfield => $activity->id,
            'userid' => $this->student1->id,
            $datefield => strtotime('2025-02-20 12:00'),
        ];
        $DB->insert_record($table, $override);

        // Execute extension new mapping task.
        $task = new process_extensions_new_mapping();
        $task->set_custom_data((object)['mapid' => $this->mappingid]);
        $task->execute();

        // Verify new due date.
        $this->verify_override($activity, $type, strtotime('2025-02-27 12:00'));

        // Remove mapping and verify original date is restored.
        manager::get_manager()->remove_mapping($this->course1->id, $this->mappingid);
        $this->verify_override($activity, $type, strtotime('2025-02-20 12:00'));
    }

    /**
     * Test deleted DAP event processing.
     *
     * @covers \\local_sitsgradepush\\extension\\ec::is_deleted_dap_event
     * @covers \\local_sitsgradepush\\extension\\ec::handle_deleted_dap_event
     * @covers \\local_sitsgradepush\\extension\\ec::set_properties_from_aws_message
     * @covers \\local_sitsgradepush\\extension\\ec::process_extension
     * @covers \\local_sitsgradepush\\extension\\ec_queue_processor::process_message
     * @return void
     * @throws \dml_exception
     * @throws \moodle_exception
     */
    public function test_deleted_dap_event(): void {
        global $DB;
        $assign = $this->setup_common_test_data();

        // Process initial DAP extension message.
        $eventdata = file_get_contents(__DIR__ . '/../fixtures/ec_event_data.json');
        $processor = new ec_queue_processor();
        $method = $this->get_accessible_method($processor, 'process_message');
        $result = $method->invoke($processor, ['Message' => $eventdata]);

        // Verify override was created with DAP identifier.
        $this->assertEquals(aws_queue_processor::STATUS_PROCESSED, $result['status']);
        $this->assertNotEmpty($DB->get_record('assign_overrides', ['assignid' => $assign->id, 'userid' => $this->student1->id]));

        $overridebackup = $DB->get_record('local_sitsgradepush_overrides', [
            'mapid' => $this->mappingid,
            'userid' => $this->student1->id,
            'extensiontype' => extensionmanager::EXTENSION_EC,
        ]);
        $this->assertNotEmpty($overridebackup);
        $this->assertEquals('DAP-ABCDE07-001', $overridebackup->requestidentifier);

        // Process deleted DAP event with empty EC data.
        $deletedmessage = file_get_contents(__DIR__ . '/../fixtures/deleted_dap_event_data.json');
        $this->setup_mock_manager_with_empty_ec();
        $result = $method->invoke($processor, ['Message' => $deletedmessage]);

        // Verify override was deleted and backup marked as restored.
        $this->assertEquals(aws_queue_processor::STATUS_PROCESSED, $result['status']);
        $this->assertFalse($DB->get_record('assign_overrides', ['assignid' => $assign->id, 'userid' => $this->student1->id]));

        $overridebackup = $DB->get_record('local_sitsgradepush_overrides', [
            'mapid' => $this->mappingid,
            'userid' => $this->student1->id,
            'extensiontype' => extensionmanager::EXTENSION_EC,
        ]);

        // Verify restored fields are set.
        $this->assertNotEmpty($overridebackup->restored_by);
        $this->assertNotEmpty($overridebackup->timerestored);
    }

    /**
     * Setup mock manager with optional student data.
     *
     * @param array|null $studentdata Student data to return, null loads from fixture
     * @return void
     */
    private function setup_mock_manager(?array $studentdata = null): void {
        if ($studentdata === null) {
            $studentdata = json_decode(file_get_contents(__DIR__ . '/../fixtures/ec_test_students.json'), true);
        }

        $manager = $this->getMockBuilder(manager::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['get_students_from_sits'])
            ->getMock();
        $manager->method('get_students_from_sits')
            ->willReturn([$studentdata]);

        $this->set_manager_instance($manager);
    }

    /**
     * Setup mock manager with empty EC data (for deleted DAP events).
     *
     * @return void
     */
    private function setup_mock_manager_with_empty_ec(): void {
        $this->setup_mock_manager(['moodleuserid' => $this->student1->id, 'extenuating_circumstance' => []]);
    }

    /**
     * Setup common test data including mock manager and mapping
     *
     * @param string $type Activity type (assign/quiz)
     * @return object The activity object
     */
    private function setup_common_test_data(string $type = 'assign'): object {
        global $DB;
        $this->setup_mock_manager();

        $mab1 = $DB->get_record('local_sitsgradepush_mab', ['mapcode' => 'LAWS0024A6UF', 'mabseq' => '001']);
        $activity = $type === 'assign' ? $this->assign1 : $this->quiz1;
        $this->mappingid = $this->insert_mapping($mab1->id, $this->course1->id, $activity, $type);

        return $activity;
    }

    /**
     * Get override table details for a given activity type
     *
     * @param string $type Activity type (assign/quiz)
     * @return array Table details containing table name, date field, and activity field
     */
    private static function get_override_table_details(string $type): array {
        return $type === 'assign' ?
            ['table' => 'assign_overrides', 'datefield' => 'duedate', 'activityfield' => 'assignid'] :
            ['table' => 'quiz_overrides', 'datefield' => 'timeclose', 'activityfield' => 'quiz'];
    }

    /**
     * Verify override exists with expected date
     *
     * @param object $activity The activity object
     * @param string $type Activity type
     * @param int|null $expecteddate Expected date timestamp (null to expect no override)
     * @return void
     */
    private function verify_override(object $activity, string $type, ?int $expecteddate): void {
        global $DB;
        $details = $this->get_override_table_details($type);
        $conditions = [$details['activityfield'] => $activity->id, 'userid' => $this->student1->id];
        $override = $DB->get_record($details['table'], $conditions);

        if ($expecteddate === null) {
            $this->assertFalse($override);
        } else {
            $this->assertEquals($expecteddate, $override->{$details['datefield']});
        }
    }

    /**
     * Get accessible method from an object.
     *
     * @param object $object The object to get method from
     * @param string $methodname The method name
     * @return \ReflectionMethod The accessible method
     */
    private function get_accessible_method(object $object, string $methodname): \ReflectionMethod {
        $reflection = new \ReflectionClass($object);
        $method = $reflection->getMethod($methodname);
        $method->setAccessible(true);
        return $method;
    }

    /**
     * Set manager singleton instance via reflection.
     *
     * @param manager|null $manager The manager instance to set
     * @return void
     */
    private function set_manager_instance(?manager $manager): void {
        $managerreflection = new \ReflectionClass(manager::class);
        $instance = $managerreflection->getProperty('instance');
        $instance->setAccessible(true);
        $instance->setValue(null, $manager);
    }

    /**
     * Reset manager singleton instance.
     *
     * @return void
     */
    private function reset_manager_instance(): void {
        $this->set_manager_instance(null);
    }
}
