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

namespace local_sitsgradepush\output;

use local_sitsgradepush\assessment\assessmentfactory;

defined('MOODLE_INTERNAL') || die();
global $CFG;
require_once($CFG->dirroot . '/mod/assign/locallib.php');

/**
 * Tests for the pushrecord class.
 *
 * @package    local_sitsgradepush
 * @copyright  2026 onwards University College London {@link https://www.ucl.ac.uk/}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @author     Alex Yeung <k.yeung@ucl.ac.uk>
 */
final class pushrecord_test extends \advanced_testcase {
    /**
     * Data provider for test_check_record_from_sits_mapping_type.
     *
     * @return array
     */
    public static function mapping_type_provider(): array {
        return [
            'Main student on main mapping' => [0, 0, true],
            'Resit student on main mapping' => [0, 1, false],
            'Main student on re-assessment mapping' => [1, 0, false],
            'Resit student on re-assessment mapping' => [1, 1, true],
        ];
    }

    /**
     * Test students in SITS for the other mapping type are flagged and not transferred.
     *
     * @covers \local_sitsgradepush\output\pushrecord::check_record_from_sits
     * @covers \local_sitsgradepush\output\pushrecord::should_transfer_mark
     * @covers \local_sitsgradepush\output\pushrecord::is_non_submitted
     * @dataProvider mapping_type_provider
     * @param int $reassessment Mapping is re-assessment or not.
     * @param int $resitnumber SITS resit number of the students.
     * @param bool $valid Expected students are valid for the mapping.
     * @return void
     */
    public function test_check_record_from_sits_mapping_type(int $reassessment, int $resitnumber, bool $valid): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        set_config('apiclient', 'easikit', 'local_sitsgradepush');

        $dg = $this->getDataGenerator();
        $course = $dg->create_course();
        $graded = $dg->create_and_enrol($course, 'student', ['idnumber' => '12345678']);
        $ungraded = $dg->create_and_enrol($course, 'student', ['idnumber' => '12345679']);

        // Grade one student only.
        $assignmodule = $dg->create_module('assign', ['course' => $course->id]);
        $gradeitemid = $DB->get_field('grade_items', 'id', ['itemmodule' => 'assign', 'iteminstance' => $assignmodule->id]);
        $dg->create_grade_grade(['itemid' => $gradeitemid, 'userid' => $graded->id, 'grade' => 80]);

        $assessment = assessmentfactory::get_assessment('mod', $assignmodule->cmid);
        $mapping = (object)['reassessment' => $reassessment, 'periodslotcode' => 'T1/2'];
        $students = [];
        foreach ([$graded, $ungraded] as $student) {
            $students[$student->idnumber] = [
                'code' => $student->idnumber,
                'assessment' => [
                    'identifier' => $student->idnumber . '_1-2023-T1_2-' . $resitnumber,
                    'resit_number' => $resitnumber,
                ],
            ];
        }

        // Graded student is transferred only if valid for the mapping type.
        // A student in SITS for the other mapping type, e.g. a resit student on the main mapping, is flagged as
        // wrong mapping type, so the mark is not transferred to the wrong attempt record in SITS.
        $record = new pushrecord($graded, $assessment);
        $this->assertSame($valid, $record->check_record_from_sits($mapping, $students));
        $this->assertSame(!$valid, $record->wrongmappingtype);
        $this->assertSame($valid, $record->should_transfer_mark());

        // Non-submitted student is transferred only if valid for the mapping type.
        // The wrong mapping type flag blocks the non-submission transfer the same way it blocks the mark transfer.
        $record = new pushrecord($ungraded, $assessment);
        $this->assertSame($valid, $record->check_record_from_sits($mapping, $students));
        $this->assertSame($valid, $record->is_non_submitted());

        // Student not in SITS is not flagged as the other mapping type.
        // The student is not valid for the mapping as not in the MAB, but must not be treated as belonging to
        // the other mapping type. The student is listed as an invalid student instead.
        $record = new pushrecord($graded, $assessment);
        $this->assertFalse($record->check_record_from_sits($mapping, []));
        $this->assertFalse($record->wrongmappingtype);
    }
}
