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

namespace local_sitsgradepush\extension\ec;

use local_sitsgradepush\extension_common;
use local_sitsgradepush\manager;
use local_sitsgradepush\tests_data_provider;

defined('MOODLE_INTERNAL') || die();
global $CFG;
require_once($CFG->dirroot . '/local/sitsgradepush/tests/extension/extension_common.php');

/**
 * Base class for EC extension tests.
 *
 * @package    local_sitsgradepush
 * @copyright  2025 onwards University College London {@link https://www.ucl.ac.uk/}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @author     Alex Yeung <k.yeung@ucl.ac.uk>
 */
class ec_base extends extension_common {
    /**
     * Mapping ID.
     * @var int
     */
    protected $mappingid;

    /**
     * Tear down the test.
     * @return void
     */
    public function tearDown(): void {
        parent::tearDown();
        $this->reset_manager_instance();
    }

    /**
     * Setup mock manager with optional student data.
     *
     * @param array|null $studentdata Student data to return, null loads from fixture
     * @return void
     */
    protected function setup_mock_manager(?array $studentdata = null): void {
        if ($studentdata === null) {
            $studentdata = tests_data_provider::get_ec_testing_student_data();
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
     * Setup mock manager with empty EC data.
     * @param int $studentid The student ID to setup
     * @return void
     */
    protected function setup_mock_manager_with_empty_ec(int $studentid): void {
        $this->setup_mock_manager(['moodleuserid' => $studentid, 'extenuating_circumstance' => []]);
    }

    /**
     * Setup common test data including mock manager and mapping
     *
     * @param string $type Activity type (assign/quiz/coursework)
     * @return object The activity object
     */
    protected function setup_common_test_data(string $type = 'assign'): object {
        global $DB;
        $this->setup_mock_manager();

        $mab1 = $DB->get_record('local_sitsgradepush_mab', ['mapcode' => 'LAWS0024A6UF', 'mabseq' => '001']);

        // Get the appropriate activity based on type.
        if ($type === 'assign') {
            $activity = $this->assign1;
        } else if ($type === 'quiz') {
            $activity = $this->quiz1;
        } else {
            $activity = $this->coursework1;
        }

        $this->mappingid = $this->insert_mapping($mab1->id, $this->course1->id, $activity, $type);

        return $activity;
    }

    /**
     * Get override table details for a given activity type
     *
     * @param string $type Activity type (assign/quiz/coursework)
     * @return array Table details containing table name, date field, and activity field
     */
    protected static function get_override_table_details(string $type): array {
        if ($type === 'assign') {
            return ['table' => 'assign_overrides', 'datefield' => 'duedate', 'activityfield' => 'assignid'];
        } else if ($type === 'quiz') {
            return ['table' => 'quiz_overrides', 'datefield' => 'timeclose', 'activityfield' => 'quiz'];
        } else {
            return ['table' => 'coursework_extensions', 'datefield' => 'extended_deadline', 'activityfield' => 'courseworkid'];
        }
    }

    /**
     * Verify override exists with expected date
     *
     * @param object $activity The activity object
     * @param string $type Activity type
     * @param int|null $expecteddate Expected date timestamp (null to expect no override)
     * @param string $userfield Name of the user ID field
     * @return void
     */
    protected function verify_override(object $activity, string $type, ?int $expecteddate, string $userfield): void {
        global $DB;
        $details = $this->get_override_table_details($type);

        $conditions = [
            $details['activityfield'] => $activity->id,
            $userfield => $this->student1->id,
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
     * Get accessible method from an object.
     *
     * @param object $object The object to get method from
     * @param string $methodname The method name
     * @return \ReflectionMethod The accessible method
     */
    protected function get_accessible_method(object $object, string $methodname): \ReflectionMethod {
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
    protected function set_manager_instance(?manager $manager): void {
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
    protected function reset_manager_instance(): void {
        $this->set_manager_instance(null);
    }
}
