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

use context_course;
use core_privacy\local\metadata\collection;
use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\userlist;
use core_privacy\local\request\writer;
use local_sitsgradepush\privacy\provider;

/**
 * Unit tests for local_sitsgradepush's privacy provider class.
 *
 * @package    local_sitsgradepush
 * @copyright  2023 onwards University College London {@link https://www.ucl.ac.uk/}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @author     Alex Yeung <k.yeung@ucl.ac.uk>
 * @covers     \local_sitsgradepush\privacy\provider
 */
final class privacy_provider_test extends \advanced_testcase {
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
     * The metadata collection describes the student enrolment delivery table.
     *
     * @return void
     */
    public function test_get_metadata(): void {
        $collection = new collection('local_sitsgradepush');
        $tables = [];
        foreach (provider::get_metadata($collection)->get_collection() as $item) {
            $tables[] = $item->get_name();
        }
        $this->assertContains('local_sitsgradepush_stuenrol', $tables);
    }

    /**
     * The course context of every delivery a user belongs to is returned.
     *
     * @return void
     */
    public function test_get_contexts_for_userid(): void {
        $courseone = $this->getDataGenerator()->create_course();
        $coursetwo = $this->getDataGenerator()->create_course();
        $user = $this->getDataGenerator()->create_user();
        $other = $this->getDataGenerator()->create_user();

        $this->create_delivery($courseone->id, $user->id);
        $this->create_delivery($coursetwo->id, $user->id);
        $this->create_delivery($courseone->id, $other->id);

        $contextids = provider::get_contexts_for_userid($user->id)->get_contextids();
        sort($contextids);
        $expected = [context_course::instance($courseone->id)->id, context_course::instance($coursetwo->id)->id];
        sort($expected);

        $this->assertEquals($expected, $contextids);
    }

    /**
     * Only users with a delivery in the given course context are listed.
     *
     * @return void
     */
    public function test_get_users_in_context(): void {
        $course = $this->getDataGenerator()->create_course();
        $user = $this->getDataGenerator()->create_user();
        $other = $this->getDataGenerator()->create_user();
        $unrelated = $this->getDataGenerator()->create_user();

        $this->create_delivery($course->id, $user->id);
        $this->create_delivery($course->id, $other->id);

        $context = context_course::instance($course->id);
        $userlist = new userlist($context, 'local_sitsgradepush');
        provider::get_users_in_context($userlist);

        $userids = $userlist->get_userids();
        sort($userids);
        $expected = [$user->id, $other->id];
        sort($expected);

        $this->assertEquals($expected, $userids);
        $this->assertNotContains($unrelated->id, $userids);
    }

    /**
     * A user's delivery data is exported under the module delivery path.
     *
     * @return void
     */
    public function test_export_user_data(): void {
        $course = $this->getDataGenerator()->create_course();
        $user = $this->getDataGenerator()->create_user();

        $this->create_delivery($course->id, $user->id, 'COMP0001');
        $this->create_delivery($course->id, $user->id, 'COMP0002');

        $context = context_course::instance($course->id);
        $contextlist = new approved_contextlist($user, 'local_sitsgradepush', [$context->id]);
        provider::export_user_data($contextlist);

        $writer = writer::with_context($context);
        $this->assertTrue($writer->has_any_data());

        $data = $writer->get_data([get_string('privacy:stuenrolpath', 'local_sitsgradepush')]);
        $this->assertCount(2, $data->deliveries);

        $modcodes = array_column($data->deliveries, 'modcode');
        sort($modcodes);
        $this->assertEquals(['COMP0001', 'COMP0002'], $modcodes);
    }

    /**
     * Deleting all data for a context removes every delivery in that course only.
     *
     * @return void
     */
    public function test_delete_data_for_all_users_in_context(): void {
        global $DB;

        $courseone = $this->getDataGenerator()->create_course();
        $coursetwo = $this->getDataGenerator()->create_course();
        $user = $this->getDataGenerator()->create_user();
        $other = $this->getDataGenerator()->create_user();

        $this->create_delivery($courseone->id, $user->id);
        $this->create_delivery($courseone->id, $other->id);
        $this->create_delivery($coursetwo->id, $user->id);

        provider::delete_data_for_all_users_in_context(context_course::instance($courseone->id));

        $this->assertFalse($DB->record_exists('local_sitsgradepush_stuenrol', ['courseid' => $courseone->id]));
        $this->assertTrue($DB->record_exists('local_sitsgradepush_stuenrol', ['courseid' => $coursetwo->id]));
    }

    /**
     * Deleting data for a user removes only that user's deliveries in the approved contexts.
     *
     * @return void
     */
    public function test_delete_data_for_user(): void {
        global $DB;

        $courseone = $this->getDataGenerator()->create_course();
        $coursetwo = $this->getDataGenerator()->create_course();
        $user = $this->getDataGenerator()->create_user();
        $other = $this->getDataGenerator()->create_user();

        $this->create_delivery($courseone->id, $user->id);
        $this->create_delivery($courseone->id, $other->id);
        $this->create_delivery($coursetwo->id, $user->id);

        $context = context_course::instance($courseone->id);
        $contextlist = new approved_contextlist($user, 'local_sitsgradepush', [$context->id]);
        provider::delete_data_for_user($contextlist);

        $this->assertFalse($DB->record_exists('local_sitsgradepush_stuenrol', [
            'courseid' => $courseone->id,
            'userid' => $user->id,
        ]));
        $this->assertTrue($DB->record_exists('local_sitsgradepush_stuenrol', [
            'courseid' => $courseone->id,
            'userid' => $other->id,
        ]));
        $this->assertTrue($DB->record_exists('local_sitsgradepush_stuenrol', [
            'courseid' => $coursetwo->id,
            'userid' => $user->id,
        ]));
    }

    /**
     * Deleting data for a set of users removes only their deliveries in the given context.
     *
     * @return void
     */
    public function test_delete_data_for_users(): void {
        global $DB;

        $course = $this->getDataGenerator()->create_course();
        $coursetwo = $this->getDataGenerator()->create_course();
        $userone = $this->getDataGenerator()->create_user();
        $usertwo = $this->getDataGenerator()->create_user();
        $keep = $this->getDataGenerator()->create_user();

        $this->create_delivery($course->id, $userone->id);
        $this->create_delivery($course->id, $usertwo->id);
        $this->create_delivery($course->id, $keep->id);
        $this->create_delivery($coursetwo->id, $userone->id);

        $context = context_course::instance($course->id);
        $userlist = new approved_userlist($context, 'local_sitsgradepush', [$userone->id, $usertwo->id]);
        provider::delete_data_for_users($userlist);

        $this->assertFalse($DB->record_exists('local_sitsgradepush_stuenrol', [
            'courseid' => $course->id,
            'userid' => $userone->id,
        ]));
        $this->assertFalse($DB->record_exists('local_sitsgradepush_stuenrol', [
            'courseid' => $course->id,
            'userid' => $usertwo->id,
        ]));
        $this->assertTrue($DB->record_exists('local_sitsgradepush_stuenrol', [
            'courseid' => $course->id,
            'userid' => $keep->id,
        ]));
        $this->assertTrue($DB->record_exists('local_sitsgradepush_stuenrol', [
            'courseid' => $coursetwo->id,
            'userid' => $userone->id,
        ]));
    }

    /**
     * Insert a student delivery record for the given course and user.
     *
     * @param int $courseid The course id.
     * @param int $userid The user id.
     * @param string $modcode The SITS module code.
     * @return int The inserted record id.
     */
    private function create_delivery(int $courseid, int $userid, string $modcode = 'COMP0001'): int {
        global $DB;

        return $DB->insert_record('local_sitsgradepush_stuenrol', (object) [
            'courseid' => $courseid,
            'userid' => $userid,
            'modcode' => $modcode,
            'modocc' => 'A6U',
            'academicyear' => '2025',
            'periodslotcode' => 'T1',
            'timecreated' => 1710000000, // 2024-03-09 16:00:00 UTC.
        ]);
    }
}
