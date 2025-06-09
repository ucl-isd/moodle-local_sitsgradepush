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

use local_sitsgradepush\extension\ec;
use local_sitsgradepush\extension_common;
use local_sitsgradepush\extensionmanager;
use local_sitsgradepush\manager;
use local_sitsgradepush\tests_data_provider;

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
     * Set up the test.
     * @return void
     */
    public function setUp(): void {
        parent::setUp();
        $this->setup_for_ec_testing();
    }

    /**
     * Tear down the test.
     * @return void
     */
    public function tearDown(): void {
        parent::tearDown();
        $managerreflection = new \ReflectionClass(manager::class);
        $instance = $managerreflection->getProperty('instance');
        $instance->setAccessible(true);
        $instance->setValue(null, null);
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

        // Load test data.
        $eventdata = file_get_contents(__DIR__ . '/../fixtures/ec_event_data.json');
        $studentdata = json_decode(file_get_contents(__DIR__ . '/../fixtures/ec_test_students.json'), true);

        // Mock manager class.
        $manager = $this->createMock(manager::class);
        $manager->method('get_students_from_sits')
            ->willReturn([$studentdata]);

        // Set manager instance.
        $managerreflection = new \ReflectionClass(manager::class);
        $instance = $managerreflection->getProperty('instance');
        $instance->setAccessible(true);
        $instance->setValue(null, $manager);

        // Create EC queue processor instance.
        $processor = new ec_queue_processor();

        // Use reflection to access protected method.
        $reflection = new \ReflectionClass($processor);
        $method = $reflection->getMethod('process_message');
        $method->setAccessible(true);

        // Test case 1: Process a valid message.
        $result = $method->invoke($processor, ['Message' => $eventdata]);
        $this->assertEquals(aws_queue_processor::STATUS_PROCESSED, $result);

        $override = $DB->get_record('assign_overrides', ['assignid' => $this->assign1->id, 'userid' => $this->student1->id]);
        $this->assertNotEmpty($override);

        // Check the new deadline is set correctly.
        $this->assertEquals(strtotime('2025-02-27 12:00'), $override->duedate);

        // Test case 2: Test message that should be ignored.
        $ignoredmessage = json_decode($eventdata, true);
        $ignoredmessage['entity']['student_extenuating_circumstances']['extenuating_circumstances']['request']['status'] =
            'PENDING';
        $ignoredmessage['entity']['student_extenuating_circumstances']['extenuating_circumstances']['request']['decision_type'] =
            'PANEL';

        $result = $method->invoke($processor, ['Message' => json_encode($ignoredmessage)]);
        $this->assertEquals(aws_queue_processor::STATUS_IGNORED, $result);

        // Test case 3: Test exception when student not found.
        $manager = $this->createMock(manager::class);
        $manager->method('get_students_from_sits')
            ->willReturn([]);
        $instance->setValue(null, $manager);
        $this->expectException(\moodle_exception::class);
        $method->invoke($processor, ['Message' => $eventdata]);
    }

    /**
     * Set up the environment for EC testing.
     * @return void
     * @throws \dml_exception
     */
    protected function setup_for_ec_testing(): void {
        global $DB;
        $mab1 = $DB->get_record('local_sitsgradepush_mab', ['mapcode' => 'LAWS0024A6UF', 'mabseq' => '001']);
        $this->insert_mapping($mab1->id, $this->course1->id, $this->assign1, 'assign');
    }
}
