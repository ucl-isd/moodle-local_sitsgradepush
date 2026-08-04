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
        $this->setup_mock_manager();

        return parent::setup_common_test_data($type);
    }
}
