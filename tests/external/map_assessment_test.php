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

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/external_test_base.php');

/**
 * Tests for the map_assessment external service access control.
 *
 * @package    local_sitsgradepush
 * @copyright  2026 onwards University College London {@link https://www.ucl.ac.uk/}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @author     Alex Yeung <k.yeung@ucl.ac.uk>
 */
final class map_assessment_test extends external_test_base {
    /**
     * Test the supplied course must match the source's real course.
     *
     * @covers \local_sitsgradepush\external\map_assessment::execute
     * @return void
     */
    public function test_execute_rejects_course_mismatch(): void {
        $this->setUser($this->teacher);

        // The source lives in course1 but a different course is supplied.
        $result = map_assessment::execute($this->course2->id, 'mod', $this->assign1->cmid, 0, 0, false);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString(
            get_string('error:coursemismatch', 'local_sitsgradepush'),
            $result['message']
        );
    }

    /**
     * Test a user without the mapassessment capability is rejected.
     *
     * @covers \local_sitsgradepush\external\map_assessment::execute
     * @return void
     */
    public function test_execute_rejects_without_capability(): void {
        $this->setUser($this->student);

        $result = map_assessment::execute($this->course1->id, 'mod', $this->assign1->cmid, 0, 0, false);

        $this->assertFalse($result['success']);
    }
}
