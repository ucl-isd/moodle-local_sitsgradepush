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

use core\clock;
use PHPUnit\Framework\MockObject\MockObject;
use sitsapiclient_easikit\models\student\studentv2;

defined('MOODLE_INTERNAL') || die();
require_once(__DIR__ . '/base_test_class.php');

/**
 * Tests for the scnmanager class.
 *
 * @package    local_sitsgradepush
 * @copyright  2025 onwards University College London {@link https://www.ucl.ac.uk/}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @author     Alex Yeung <k.yeung@ucl.ac.uk>
 */
final class scnmanager_test extends base_test_class {

    /** @var string Test mapcode */
    private const TEST_MAPCODE = 'TEST001A6UH';

    /** @var string Test mabseq */
    private const TEST_MABSEQ = '001';

    /** @var string Student 1 code */
    private const STUDENT1_CODE = '12345678';

    /** @var string Student 2 code */
    private const STUDENT2_CODE = '12345679';

    /** @var string Student 1 candidate number */
    private const STUDENT1_SCN = 'JJSNS7';

    /** @var string Student 2 candidate number */
    private const STUDENT2_SCN = 'JJSNS8';

    /** @var \stdClass $course1 Default test course 1 */
    private \stdClass $course1;

    /** @var \stdClass Default test student 1 */
    private \stdClass $student1;

    /** @var \stdClass Default test student 2 */
    private \stdClass $student2;

    /** @var clock $clock */
    protected readonly clock $clock;

    /** @var array Test students data */
    private array $mockstudents;

    /**
     * Set up the test.
     *
     * @return void
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();

        // Set a frozen clock for testing.
        $this->clock = $this->mock_clock_with_frozen(strtotime('2025-08-13 09:00:00'));

        // Load test data once.
        $this->mockstudents = json_decode(file_get_contents(__DIR__ . '/fixtures/scnmanager_test_students.json'), true);

        // Set default configurations.
        set_config('apiclient', 'easikit', 'local_sitsgradepush');
        set_config('fetch_scn_enabled', '1', 'local_sitsgradepush');

        // Set up test data.
        $this->setup_test_data();
    }

    /**
     * Tear down the test.
     * @return void
     */
    protected function tearDown(): void {
        parent::tearDown();
        $this->reset_manager_singleton();
    }

    /**
     * Test fetch candidate numbers from SITS with successful response.
     *
     * @covers \local_sitsgradepush\scnmanager::fetch_candidate_numbers_from_sits
     * @covers \local_sitsgradepush\scnmanager::save_candidate_numbers
     * @return void
     * @throws \ReflectionException
     * @throws \coding_exception
     * @throws \dml_exception
     */
    public function test_fetch_candidate_numbers_from_sits_success(): void {
        global $DB;

        // Set up the manager mock with module occurrences and students.
        $this->setup_manager_mock($this->get_mock_modoccs(), $this->mockstudents);

        // Fetch candidate numbers from SITS for course 1.
        $scns = scnmanager::get_instance()->fetch_candidate_numbers_from_sits($this->course1->id);

        $expecteddata = [
            ['code' => self::STUDENT1_CODE, 'scn' => self::STUDENT1_SCN, 'userid' => $this->student1->id],
            ['code' => self::STUDENT2_CODE, 'scn' => self::STUDENT2_SCN, 'userid' => $this->student2->id],
        ];

        foreach ($expecteddata as $data) {
            // Assert that the candidate number was fetched correctly.
            $this->assertEquals($data['scn'], $scns[$data['code']]->get_candidatenumber());

            // Assert that the candidate number was saved in the database.
            $this->assertEquals($data['scn'], $DB->get_field('local_sitsgradepush_scn', 'candidate_number', [
                'userid' => $data['userid'],
                'academic_year' => $this->clock->now()->format('Y'),
            ]));

            // Assert that the candidate number was saved in the cache.
            $cachekey = 'scn_' . $data['userid'] . '_' . $this->clock->now()->format('Y');
            $cache = cachemanager::get_cache(cachemanager::CACHE_AREA_CANDIDATE_NUMBERS, $cachekey);
            $this->assertEquals($data['scn'], $cache);
        }
    }

    /**
     * Test fetch candidate numbers from SITS with no module occurrences found for the course.
     *
     * @covers \local_sitsgradepush\scnmanager::fetch_candidate_numbers_from_sits
     * @return void
     */
    public function test_fetch_candidate_numbers_from_sits_no_modocc(): void {
        // Set up the manager mock.
        $this->setup_manager_mock();
        $scns = scnmanager::get_instance()->fetch_candidate_numbers_from_sits($this->course1->id);

        // Assert no students were returned.
        $this->assertEmpty($scns);
    }

    /**
     * Test get candidate numbers with no students returned from SITS.
     *
     * @covers \local_sitsgradepush\scnmanager::fetch_candidate_numbers_from_sits
     * @return void
     */
    public function test_get_candidate_numbers_no_students(): void {
        $this->setup_manager_mock($this->get_mock_modoccs());
        $scns = scnmanager::get_instance()->fetch_candidate_numbers_from_sits($this->course1->id);

        // Assert no candidate numbers were returned.
        $this->assertEmpty($scns);
    }

    /**
     * Test get candidate number by course and student.
     *
     * @covers \local_sitsgradepush\scnmanager::get_candidate_number_by_course_student
     * @return void
     */
    public function test_get_candidate_number_by_course_student_success(): void {
        $this->setup_manager_mock($this->get_mock_modoccs(), $this->mockstudents);

        // Fetch candidate number for student 1 in course 1.
        $scn = scnmanager::get_instance()->get_candidate_number_by_course_student(
            $this->course1->id,
            $this->student1->id
        );

        $this->assertEquals(self::STUDENT1_SCN, $scn);
    }

    /**
     * Test that fetch_candidate_numbers_from_sits handles exceptions properly and logs errors when debug logging is enabled.
     *
     * @covers \local_sitsgradepush\scnmanager::fetch_candidate_numbers_from_sits
     * @covers \local_sitsgradepush\logger::log_debug_error
     * @covers \local_sitsgradepush\logger::is_debug_error_logging_enabled
     * @return void
     */
    public function test_fetch_candidate_numbers_from_sits_exception(): void {
        global $DB;

        // Enable debug error logging.
        set_config('debug_error_logging', '1', 'local_sitsgradepush');

        $mockmanager = $this->getMockBuilder(manager::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['get_component_grade_options'])
            ->getMock();
        $mockmanager->method('get_component_grade_options')
            ->willThrowException(new \Exception('Test exception'));

        $this->inject_manager_mock($mockmanager);

        // Count existing error logs before the test.
        $initiallogcount = $DB->count_records('local_sitsgradepush_err_log');

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Test exception');

        // Attempt to fetch candidate numbers, which should throw an exception.
        scnmanager::get_instance()->fetch_candidate_numbers_from_sits($this->course1->id, self::STUDENT1_CODE);

        // Verify that an error log was created.
        $finallogcount = $DB->count_records('local_sitsgradepush_err_log');
        $this->assertEquals($initiallogcount + 1, $finallogcount);

        // Verify the log entry contains expected data.
        $logrecord = $DB->get_record(
            'local_sitsgradepush_err_log',
            ['message' => get_string('error:failed_to_fetch_scn_from_sits', 'local_sitsgradepush', 'Test exception')]
        );
        $this->assertNotEmpty($logrecord);
        $logdata = json_decode($logrecord->data, true);
        $this->assertEquals($this->course1->id, $logdata['courseid']);
        $this->assertEquals(self::STUDENT1_CODE, $logdata['studentcode']);
    }

    /**
     * Test that get_candidate_number_by_course_student returns null when fetch_candidate_numbers_from_sits fails.
     *
     * @covers \local_sitsgradepush\scnmanager::get_candidate_number_by_course_student
     * @covers \local_sitsgradepush\scnmanager::fetch_candidate_numbers_from_sits
     * @return void
     */
    public function test_get_candidate_number_by_course_student_fetch_exception(): void {
        $mockmanager = $this->getMockBuilder(manager::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['get_component_grade_options'])
            ->getMock();
        $mockmanager->method('get_component_grade_options')
            ->willThrowException(new \Exception('SITS API error'));
        $this->inject_manager_mock($mockmanager);

        $result = scnmanager::get_instance()->get_candidate_number_by_course_student(
            $this->course1->id,
            $this->student1->id
        );

        $this->assertNull($result);
    }

    /**
     * Test fallback to local database when SITS returns empty student.
     *
     * @covers \local_sitsgradepush\scnmanager::get_candidate_number_by_course_student
     * @covers \local_sitsgradepush\scnmanager::get_local_candidate_number
     * @return void
     */
    public function test_fallback_to_local_when_sits_returns_empty_student(): void {
        $mockscnmanager = $this->getMockBuilder(scnmanager::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['fetch_candidate_numbers_from_sits'])
            ->getMock();
        $mockscnmanager->method('fetch_candidate_numbers_from_sits')
            ->willReturn(null);

        $localcandidatenumber = 'LOCAL123';
        $this->create_local_candidate_number($this->student1->id, $localcandidatenumber);
        $this->inject_scnmanager_mock($mockscnmanager);

        $result = $mockscnmanager->get_candidate_number_by_course_student(
            $this->course1->id,
            $this->student1->id
        );

        $this->assertEquals($localcandidatenumber, $result);
    }

    /**
     * Test fallback to local database when SITS returns student without candidate number.
     *
     * @covers \local_sitsgradepush\scnmanager::get_candidate_number_by_course_student
     * @covers \local_sitsgradepush\scnmanager::get_local_candidate_number
     * @return void
     */
    public function test_fallback_to_local_when_sits_student_has_no_candidate_number(): void {
        $mockstudent = $this->getMockBuilder(studentv2::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['get_candidatenumber'])
            ->getMock();
        $mockstudent->method('get_candidatenumber')
            ->willReturn('');

        $mockscnmanager = $this->getMockBuilder(scnmanager::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['fetch_candidate_numbers_from_sits'])
            ->getMock();
        $mockscnmanager->method('fetch_candidate_numbers_from_sits')
            ->willReturn($mockstudent);

        $localcandidatenumber = 'LOCAL456';
        $this->create_local_candidate_number($this->student1->id, $localcandidatenumber);
        $this->inject_scnmanager_mock($mockscnmanager);

        $result = $mockscnmanager->get_candidate_number_by_course_student(
            $this->course1->id,
            $this->student1->id
        );

        $this->assertEquals($localcandidatenumber, $result);
    }

    /**
     * Test no local candidate number found in SITS and local database.
     *
     * @covers \local_sitsgradepush\scnmanager::get_candidate_number_by_course_student
     * @covers \local_sitsgradepush\scnmanager::get_local_candidate_number
     * @return void
     */
    public function test_no_candidate_number_in_sits_and_local_candidate_number(): void {
        $mockscnmanager = $this->getMockBuilder(scnmanager::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['fetch_candidate_numbers_from_sits', 'get_local_candidate_number'])
            ->getMock();
        $mockscnmanager->method('fetch_candidate_numbers_from_sits')->willReturn(null);
        $mockscnmanager->method('get_local_candidate_number')->willReturn(null);
        $this->inject_scnmanager_mock($mockscnmanager);

        $result = $mockscnmanager->get_candidate_number_by_course_student(
            $this->course1->id,
            $this->student1->id
        );

        $this->assertNull($result);
    }

    /**
     * Get mock module occurrences for testing.
     *
     * @return array
     */
    private function get_mock_modoccs(): array {
        return [(object)[
            'componentgrades' => [(object)[
                'id' => 1,
                'mapcode' => self::TEST_MAPCODE,
                'mabseq' => self::TEST_MABSEQ,
            ]],
        ]];
    }

    /**
     * Set up manager mock and reset singleton.
     *
     * @return void
     */
    private function reset_manager_singleton(): void {
        $reflection = new \ReflectionClass(manager::class);
        $instance = $reflection->getProperty('instance');
        $instance->setAccessible(true);
        $instance->setValue(null, null);
    }

    /**
     * Inject manager mock into singleton.
     *
     * @param MockObject $mockmanager
     * @return void
     */
    private function inject_manager_mock(MockObject $mockmanager): void {
        $reflection = new \ReflectionClass(manager::class);
        $instance = $reflection->getProperty('instance');
        $instance->setAccessible(true);
        $instance->setValue(null, $mockmanager);
    }

    /**
     * Inject scnmanager mock into singleton.
     *
     * @param MockObject $mockscnmanager
     * @return void
     */
    private function inject_scnmanager_mock(MockObject $mockscnmanager): void {
        $reflectionscn = new \ReflectionClass(scnmanager::class);
        $instancescn = $reflectionscn->getProperty('instance');
        $instancescn->setAccessible(true);
        $instancescn->setValue(null, $mockscnmanager);

        $clockproperty = $reflectionscn->getProperty('clock');
        $clockproperty->setAccessible(true);
        $clockproperty->setValue($mockscnmanager, $this->clock);
    }

    /**
     * Create local candidate number record in database.
     *
     * @param int $userid User ID
     * @param string $candidatenumber Candidate number
     * @return void
     */
    private function create_local_candidate_number(int $userid, string $candidatenumber): void {
        global $DB;
        $DB->insert_record('local_sitsgradepush_scn', [
            'userid' => $userid,
            'academic_year' => $this->clock->now()->format('Y'),
            'student_code' => $userid === $this->student1->id ? self::STUDENT1_CODE : self::STUDENT2_CODE,
            'candidate_number' => $candidatenumber,
            'timecreated' => $this->clock->time(),
            'timemodified' => $this->clock->time(),
        ]);
    }

    /**
     * Set up the manager mock with module occurrences and students.
     *
     * @param array $mockmodoccs Mock module occurrences
     * @param array $mockstudents Mock students data
     * @return void
     */
    protected function setup_manager_mock(array $mockmodoccs = [], array $mockstudents = []): void {
        $mockmanager = $this->getMockBuilder(manager::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['get_students_from_sits', 'get_component_grade_options'])
            ->getMock();
        $mockmanager->method('get_students_from_sits')->willReturn($mockstudents);
        $mockmanager->method('get_component_grade_options')->willReturn($mockmodoccs);
        $this->inject_manager_mock($mockmanager);
    }

    /**
     * Setup test data for testing.
     *
     * @return void
     */
    private function setup_test_data(): void {
        $dg = $this->getDataGenerator();

        // Create custom field category and field.
        $dg->create_custom_field_category(['name' => 'CLC']);
        $dg->create_custom_field(['category' => 'CLC', 'shortname' => 'course_year']);

        // Create test course.
        $this->course1 = $dg->create_course([
            'shortname' => 'C1',
            'customfields' => [
                ['shortname' => 'course_year', 'value' => $this->clock->now()->format('Y')],
            ],
        ]);

        // Create students.
        $this->student1 = $dg->create_user(['idnumber' => self::STUDENT1_CODE]);
        $this->student2 = $dg->create_user(['idnumber' => self::STUDENT2_CODE]);

        // Enrol students to course.
        $dg->enrol_user($this->student1->id, $this->course1->id, 'student');
        $dg->enrol_user($this->student2->id, $this->course1->id, 'student');
    }
}
