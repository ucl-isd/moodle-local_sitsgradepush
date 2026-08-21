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

namespace local_sitsgradepush\task;

/**
 * Tests for the process AWS combined due date updates scheduled task.
 *
 * @package    local_sitsgradepush
 * @copyright  2026 onwards University College London {@link https://www.ucl.ac.uk/}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @author     Alex Yeung <k.yeung@ucl.ac.uk>
 */
final class process_aws_cdd_updates_test extends \advanced_testcase {
    /**
     * Test the task exits early when the combined due date feature is disabled.
     *
     * @covers \local_sitsgradepush\task\process_aws_cdd_updates::execute
     * @return void
     */
    public function test_execute_skips_when_cdd_disabled(): void {
        $this->resetAfterTest();

        // Extension enabled but combined due date disabled.
        set_config('extension_enabled', '1', 'local_sitsgradepush');
        set_config('cdd_enabled', '0', 'local_sitsgradepush');

        $task = new process_aws_cdd_updates();

        // The task should report it is not enabled and exit without touching the queue.
        $this->expectOutputRegex('/not enabled/');
        $task->execute();
    }
}
