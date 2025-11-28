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

use local_sitsgradepush\manager;
use local_sitsgradepush\task\process_extensions_new_enrolment;
use local_sitsgradepush\task\process_extensions_new_mapping;

defined('MOODLE_INTERNAL') || die();
global $CFG;
require_once($CFG->dirroot . '/local/sitsgradepush/tests/extension/ec/ec_base.php');

/**
 * Tests for EC extension on quizzes.
 *
 * @package    local_sitsgradepush
 * @copyright  2025 onwards University College London {@link https://www.ucl.ac.uk/}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @author     Alex Yeung <k.yeung@ucl.ac.uk>
 */
final class ec_quiz_test extends ec_base {
    /**
     * Test process extensions for new student enrollments
     *
     * @covers \local_sitsgradepush\task\process_extensions_new_enrolment::execute
     * @return void
     */
    public function test_process_extensions_new_enrolment(): void {
        $activity = $this->setup_common_test_data('quiz');

        // Verify no initial override.
        $this->verify_override($activity, 'quiz', null, 'userid');

        // Execute new enrollment task.
        $task = new process_extensions_new_enrolment();
        $task->set_custom_data((object)['courseid' => $this->course1->id]);
        $task->execute();

        // Verify new due date is set.
        $this->verify_override($activity, 'quiz', strtotime('2025-02-27 12:00'), 'userid');

        // Remove mapping and verify override is removed.
        manager::get_manager()->remove_mapping($this->course1->id, $this->mappingid);
        $this->verify_override($activity, 'quiz', null, 'userid');
    }

    /**
     * Test process extensions for new SITS mapping
     *
     * @covers \local_sitsgradepush\task\process_extensions_new_mapping::execute
     * @covers \local_sitsgradepush\extensionmanager::update_ec_for_mapping
     * @covers \local_sitsgradepush\extensionmanager::delete_ec_overrides
     * @return void
     */
    public function test_process_extensions_new_sits_mapping(): void {
        global $DB;
        $this->setAdminUser();

        $activity = $this->setup_common_test_data('quiz');

        // Add user override with original due date.
        $override = [
            'quiz' => $activity->id,
            'userid' => $this->student1->id,
            'timeclose' => strtotime('2025-02-20 12:00'),
        ];

        $DB->insert_record('quiz_overrides', $override);

        // Execute extension new mapping task.
        $task = new process_extensions_new_mapping();
        $task->set_custom_data((object)['mapid' => $this->mappingid]);
        $task->execute();

        // Verify new due date.
        $this->verify_override($activity, 'quiz', strtotime('2025-02-27 12:00'), 'userid');

        // Remove mapping and verify original date is restored.
        manager::get_manager()->remove_mapping($this->course1->id, $this->mappingid);
        $this->verify_override($activity, 'quiz', strtotime('2025-02-20 12:00'), 'userid');
    }
}
