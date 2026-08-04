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
 * Trait providing a mock manager for combined due date (CDD) tests.
 *
 * Used by test classes which do not share a combined due date specific ancestor.
 *
 * @package    local_sitsgradepush
 * @copyright  2026 onwards University College London {@link https://www.ucl.ac.uk/}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @author     Alex Yeung <k.yeung@ucl.ac.uk>
 */
trait cdd_mock_manager_trait {
    /**
     * Setup a mock manager returning the given combined due date records and get students API data.
     *
     * @param array $cddrecords Combined due date records to return from get_combined_due_dates_from_sits.
     * @param array $students Students to return from get_students_from_sits.
     * @return void
     */
    protected function setup_mock_manager_with_cdd(array $cddrecords, array $students = []): void {
        $mockmanager = $this->getMockBuilder(manager::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['get_combined_due_dates_from_sits', 'get_students_from_sits'])
            ->getMock();
        $mockmanager->method('get_combined_due_dates_from_sits')->willReturn($cddrecords);
        $mockmanager->method('get_students_from_sits')->willReturn($students);

        $this->set_manager_instance($mockmanager);
    }
}
