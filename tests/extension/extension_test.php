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

use local_sitsgradepush\extension\ec;

defined('MOODLE_INTERNAL') || die();
global $CFG;
require_once($CFG->dirroot . '/local/sitsgradepush/tests/fixtures/tests_data_provider.php');
require_once($CFG->dirroot . '/local/sitsgradepush/tests/extension/extension_common.php');

/**
 * Tests for the extension class.
 *
 * @package    local_sitsgradepush
 * @copyright  2024 onwards University College London {@link https://www.ucl.ac.uk/}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @author     Alex Yeung <k.yeung@ucl.ac.uk>
 */
final class extension_test extends extension_common {
    /**
     * Test the user is enrolling a gradable role.
     *
     * @covers \local_sitsgradepush\extensionmanager::user_is_enrolling_a_gradable_role
     * @return void
     */
    public function test_user_is_enrolling_a_gradable_role(): void {
        global $CFG;

        // Test when role is gradable.
        $CFG->gradebookroles = '1,2,3';
        $roleid = 2;
        $result = extensionmanager::user_is_enrolling_a_gradable_role($roleid);
        $this->assertTrue($result);

        // Test when role is not gradable.
        $roleid = 4;
        $result = extensionmanager::user_is_enrolling_a_gradable_role($roleid);
        $this->assertFalse($result);

        // Test when gradebookroles is null.
        $CFG->gradebookroles = null;
        $roleid = 1;
        $result = extensionmanager::user_is_enrolling_a_gradable_role($roleid);
        $this->assertFalse($result);
    }

    /**
     * Test get user enrolment events.
     *
     * @covers \local_sitsgradepush\extensionmanager::get_user_enrolment_events
     * @return void
     * @throws \coding_exception
     * @throws \dml_exception
     */
    public function test_get_user_enrolment_events(): void {
        global $DB;

        // Create user enrolment events.
        $events = [];
        for ($i = 0; $i < 3; $i++) {
            $event = new \stdClass();
            $event->courseid = 1;
            $event->userid = $i + 1;
            $event->attempts = $i;
            $event->timecreated = time();
            $events[] = $event;
        }
        $DB->insert_records('local_sitsgradepush_enrol', $events);

        // Get user enrolment events. Only 2 is returned as the max attempts is set to 2.
        $result = extensionmanager::get_user_enrolment_events(1);
        $this->assertCount(2, $result);
    }

    /**
     * Test is_extension_enabled method.
     *
     * @covers \local_sitsgradepush\extensionmanager::is_extension_enabled
     * @return void
     * @throws \dml_exception
     */
    public function test_is_extension_enabled(): void {
        // Test when extension is enabled in config.
        set_config('extension_enabled', '1', 'local_sitsgradepush');
        $result = extensionmanager::is_extension_enabled();
        $this->assertTrue($result);

        // Test when extension is disabled in config.
        set_config('extension_enabled', '0', 'local_sitsgradepush');
        $result = extensionmanager::is_extension_enabled();
        $this->assertFalse($result);
    }
}
