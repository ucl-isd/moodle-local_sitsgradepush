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

/**
 * Tests for the error manager class.
 *
 * @package    local_sitsgradepush
 * @copyright  2024 onwards University College London {@link https://www.ucl.ac.uk/}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @author     Alex Yeung <k.yeung@ucl.ac.uk>
 */
final class errormanager_test extends \advanced_testcase {

    /**
     * Set up the test.
     *
     * @return void
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
    }

    /**
     * Test get error label.
     *
     * @covers \local_sitsgradepush\errormanager::get_error_label
     * @return void
     */
    public function test_get_error_label(): void {
        // Test getting correct error label.
        $this->assertEquals('Failed', errormanager::get_error_label());
        $this->assertEquals('Failed', errormanager::get_error_label(errormanager::ERROR_UNKNOWN));
        $this->assertEquals('Student not enrolled', errormanager::get_error_label(errormanager::ERROR_STUDENT_NOT_ENROLLED));
        $this->assertEquals('Record type invalid', errormanager::get_error_label(errormanager::ERROR_RECORD_TYPE_INVALID));
        $this->assertEquals('Record does not exist', errormanager::get_error_label(errormanager::ERROR_RECORD_NOT_EXIST));
        $this->assertEquals('Record state invalid', errormanager::get_error_label(errormanager::ERROR_RECORD_INVALID_STATE));
        $this->assertEquals('No mark scheme', errormanager::get_error_label(errormanager::ERROR_NO_MARK_SCHEME));
        $this->assertEquals('Attempt number blank', errormanager::get_error_label(errormanager::ERROR_ATTEMPT_NUMBER_BLANK));
        $this->assertEquals('Overwrite not allowed', errormanager::get_error_label(errormanager::ERROR_OVERWRITE_EXISTING_RECORD));
        $this->assertEquals('Invalid marks', errormanager::get_error_label(errormanager::ERROR_INVALID_MARKS));
        $this->assertEquals('Invalid hand in status', errormanager::get_error_label(errormanager::ERROR_INVALID_HAND_IN_STATUS));
    }

    /**
     * Test identify error.
     *
     * @covers \local_sitsgradepush\errormanager::identify_error
     * @return void
     */
    public function test_identify_error(): void {
        // Test identifying errors.
        $this->assertEquals(errormanager::ERROR_UNKNOWN, errormanager::identify_error());
        $this->assertEquals(errormanager::ERROR_STUDENT_NOT_ENROLLED, errormanager::identify_error('student not found'));
        $this->assertEquals(errormanager::ERROR_RECORD_TYPE_INVALID, errormanager::identify_error('recordtype is invalid'));
        $this->assertEquals(errormanager::ERROR_RECORD_NOT_EXIST, errormanager::identify_error('record does not exist'));
        $this->assertEquals(errormanager::ERROR_RECORD_INVALID_STATE, errormanager::identify_error('record not in valid state'));
        $this->assertEquals(errormanager::ERROR_NO_MARK_SCHEME, errormanager::identify_error('no mark scheme defined'));
        $this->assertEquals(errormanager::ERROR_ATTEMPT_NUMBER_BLANK, errormanager::identify_error('Attempt number blank'));
        $this->assertEquals(
          errormanager::ERROR_OVERWRITE_EXISTING_RECORD,
          errormanager::identify_error('Cannot overwrite existing')
        );
        $this->assertEquals(
          errormanager::ERROR_OVERWRITE_EXISTING_RECORD,
          errormanager::identify_error('no further update allowed')
        );
        $this->assertEquals(errormanager::ERROR_INVALID_MARKS, errormanager::identify_error('Mark and/or Grade not valid'));
        $this->assertEquals(
          errormanager::ERROR_INVALID_HAND_IN_STATUS,
          errormanager::identify_error('handin_status provided is not in SUS table')
        );

        // Test unknown error is returned if no match is found.
        $this->assertEquals(errormanager::ERROR_UNKNOWN, errormanager::identify_error('unknown error'));
    }
}
