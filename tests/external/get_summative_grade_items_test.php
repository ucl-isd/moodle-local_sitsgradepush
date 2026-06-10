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
 * Tests for the get_summative_grade_items external service access control.
 *
 * @package    local_sitsgradepush
 * @copyright  2026 onwards University College London {@link https://www.ucl.ac.uk/}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @author     Alex Yeung <k.yeung@ucl.ac.uk>
 */
final class get_summative_grade_items_test extends external_test_base {
    /**
     * Test a user without the mapassessment capability is rejected.
     *
     * @covers \local_sitsgradepush\external\get_summative_grade_items::execute
     * @return void
     */
    public function test_execute_rejects_without_capability(): void {
        $this->setUser($this->student);

        $result = get_summative_grade_items::execute($this->course1->id);

        $this->assertFalse($result['success']);
        $this->assertEmpty($result['gradeitems']);
    }

    /**
     * Test a user with the mapassessment capability is allowed through the gate.
     *
     * @covers \local_sitsgradepush\external\get_summative_grade_items::execute
     * @return void
     */
    public function test_execute_allows_with_capability(): void {
        $this->setUser($this->teacher);

        $result = get_summative_grade_items::execute($this->course1->id);

        $this->assertTrue($result['success']);
        $this->assertIsArray($result['gradeitems']);
    }
}
