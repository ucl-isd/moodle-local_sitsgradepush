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

namespace local_sitsgradepush\external;

use context_course;
use local_sitsgradepush\base_test_class;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/../base_test_class.php');

/**
 * Base test class providing common fixtures for the external service tests.
 *
 * @package    local_sitsgradepush
 * @copyright  2026 onwards University College London {@link https://www.ucl.ac.uk/}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @author     Alex Yeung <k.yeung@ucl.ac.uk>
 */
abstract class external_test_base extends base_test_class {
    /** @var \stdClass Test course that owns the source. */
    protected \stdClass $course1;

    /** @var \stdClass Unrelated test course used for cross-course checks. */
    protected \stdClass $course2;

    /** @var \stdClass Test assignment in course1. */
    protected \stdClass $assign1;

    /** @var \stdClass Teacher with the mapassessment capability on course1. */
    protected \stdClass $teacher;

    /** @var \stdClass Student enrolled in course1 without the mapassessment capability. */
    protected \stdClass $student;

    /**
     * Set up the common fixtures.
     *
     * @return void
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();

        $dg = $this->getDataGenerator();

        // Set the API client so the manager can be constructed without errors.
        set_config('apiclient', 'easikit', 'local_sitsgradepush');

        // Create two unrelated courses and an assignment in the first one.
        $this->course1 = $dg->create_course();
        $this->course2 = $dg->create_course();
        $this->assign1 = $dg->create_module('assign', ['course' => $this->course1->id]);

        // Create a teacher with the mapassessment capability on course1.
        $roleid = $dg->create_role(['shortname' => 'canmapassessment']);
        assign_capability(
            'local/sitsgradepush:mapassessment',
            CAP_ALLOW,
            $roleid,
            context_course::instance($this->course1->id)->id
        );
        $this->teacher = $dg->create_user();
        $dg->enrol_user($this->teacher->id, $this->course1->id, 'canmapassessment');

        // Create a student enrolled in course1 without the mapassessment capability.
        $this->student = $dg->create_user();
        $dg->enrol_user($this->student->id, $this->course1->id, 'student');
    }
}
