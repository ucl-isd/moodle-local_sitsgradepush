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

namespace local_sitsgradepush\extension\raa;

use DateTimeImmutable;
use local_sitsgradepush\extension\sora;
use local_sitsgradepush\extensionmanager;
use local_sitsgradepush\manager;

defined('MOODLE_INTERNAL') || die();
global $CFG;
require_once($CFG->dirroot . '/local/sitsgradepush/tests/extension/raa/raa_base.php');

/**
 * Tests for RAA extension with teacher-created deadline group overrides for assign.
 *
 * Uses ED03 (exam) assessment type.
 *
 * @package    local_sitsgradepush
 * @copyright  2026 onwards University College London {@link https://www.ucl.ac.uk/}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @author     Alex Yeung <k.yeung@ucl.ac.uk>
 */
final class raa_deadline_group_assign_test extends raa_base {
    /** @var \stdClass The ED03 test assignment. */
    private \stdClass $assign;

    /** @var \stdClass The ED03 MAB record. */
    private \stdClass $mab;

    /**
     * Set up the test.
     *
     * @return void
     */
    public function setUp(): void {
        parent::setUp();
        global $DB;

        // Set deadline group prefix.
        set_config('deadlinegroup_prefix', self::DLG_PREFIX, 'local_sitsgradepush');

        // Create ED03 assignment (startdate=10:00, duedate=13:00, 3 hours).
        $startdate = (new DateTimeImmutable('2025-02-11 10:00:00'))->getTimestamp();
        $enddate = (new DateTimeImmutable('2025-02-11 13:00:00'))->getTimestamp();
        $this->assign = $this->create_assignment(
            $this->course1->id,
            $startdate,
            $enddate
        );

        // Get the ED03 MAB and create the SITS mapping.
        $this->mab = $DB->get_record(
            'local_sitsgradepush_mab',
            ['mapcode' => 'LAWS0024A6UF', 'mabseq' => '001']
        );
        $this->create_module_mapping(
            $this->mab,
            $this->course1->id,
            $this->assign,
            'assign'
        );
    }

    /**
     * Test students in different DLG groups each get an extension based on their own group's dates.
     * Student 1 is in DLG-A (later duedate), student 2 is in DLG-B (later startdate).
     * Each should be placed in a separate RAA group override reflecting their respective DLG dates.
     * We haven't implemented time limit extension for assignment, so duration = enddate - startdate affects extension amount.
     *
     * @covers \local_sitsgradepush\assessment\assign::get_user_deadline_group_dates
     * @covers \local_sitsgradepush\assessment\activity::calculate_raa_extension_details
     * @return void
     */
    public function test_students_in_different_dlg_groups_get_correct_extensions(): void {
        global $DB;

        // DLG-A: duedate=14:00 for student 1 (TIER1, +20 min/hr on 4hr duration = +80 min).
        // DLG-B: startdate=11:00 for student 2 (TIER2, +25 min/hr on 2hr duration = +50 min).
        // DLG-B has no duedate override, so student 2 falls back to original duedate (13:00).
        $dlgaduedate = (new DateTimeImmutable('2025-02-11 14:00:00'))->getTimestamp();
        $dlgbstartdate = (new DateTimeImmutable('2025-02-11 11:00:00'))->getTimestamp();

        $groupa = $this->create_deadline_group('DLG-A');
        $groupb = $this->create_deadline_group('DLG-B');
        groups_add_member($groupa, $this->student1->id);
        groups_add_member($groupb, $this->student2->id);

        $this->create_assign_group_override($this->assign->id, $groupa, $dlgaduedate, null, 1);
        $this->create_assign_group_override($this->assign->id, $groupb, null, $dlgbstartdate, 2);

        // Student 1: TIER1 (15+5=20 min/hr). Student 2: TIER2 (15+10=25 min/hr).
        $student1data = $this->get_test_student_data('ED03');
        $student2data = $this->get_test_student_data(
            'ED03_tier2',
            $this->student2->id
        );

        // Run RAA for both students.
        $mappings = manager::get_manager()->get_assessment_mappings_by_courseid(
            $this->course1->id
        );
        foreach ($mappings as $mapping) {
            extensionmanager::update_sora_for_mapping(
                $mapping,
                [$student1data, $student2data]
            );
        }

        $sora = new sora();

        // Student 1: DLG-A duedate (14:00), duration=4hrs, +80 min = 15:20.
        $expectedstarta = (new DateTimeImmutable('2025-02-11 10:00:00'))->getTimestamp();
        $expectedduea = (new DateTimeImmutable('2025-02-11 15:20:00'))->getTimestamp();
        $raagroupid1 = $DB->get_field('groups', 'id', [
            'name' => $sora->get_extension_group_name(
                $this->assign->cmid,
                80 * MINSECS,
                $expectedduea
            ),
        ]);
        $this->assertNotEmpty($raagroupid1, 'RAA group for student 1 should exist.');
        $this->assertTrue(
            groups_is_member($raagroupid1, $this->student1->id),
            'Student 1 should be in the DLG-A RAA group.'
        );
        $override1 = $DB->get_record('assign_overrides', [
            'assignid' => $this->assign->id,
            'userid' => null,
            'groupid' => $raagroupid1,
        ]);
        $this->assertNotEmpty(
            $override1,
            'Assign override for student 1 RAA group should exist.'
        );
        $this->assertEquals($expectedstarta, $override1->allowsubmissionsfromdate);
        $this->assertEquals($expectedduea, $override1->duedate);

        // Student 2: original duedate (13:00), duration=2hrs, +50 min = 13:50.
        // DLG-B allowsubmissionsfromdate (11:00) should be carried forward.
        $expecteddueb = (new DateTimeImmutable('2025-02-11 13:50:00'))->getTimestamp();
        $raagroupid2 = $DB->get_field('groups', 'id', [
            'name' => $sora->get_extension_group_name(
                $this->assign->cmid,
                50 * MINSECS,
                $expecteddueb
            ),
        ]);
        $this->assertNotEmpty($raagroupid2, 'RAA group for student 2 should exist.');
        $this->assertTrue(
            groups_is_member($raagroupid2, $this->student2->id),
            'Student 2 should be in the DLG-B RAA group.'
        );
        $override2 = $DB->get_record('assign_overrides', [
            'assignid' => $this->assign->id,
            'userid' => null,
            'groupid' => $raagroupid2,
        ]);
        $this->assertNotEmpty(
            $override2,
            'Assign override for student 2 RAA group should exist.'
        );
        $this->assertEquals($expecteddueb, $override2->duedate);
        $this->assertEquals(
            $dlgbstartdate,
            $override2->allowsubmissionsfromdate,
            'DLG-B allowsubmissionsfromdate should be carried forward.'
        );
    }
}
