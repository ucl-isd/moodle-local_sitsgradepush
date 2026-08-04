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

namespace sitsapiclient_easikit;

use sitsapiclient_easikit\requests\getcombinedduedate;

/**
 * Tests for the get combined due date request.
 *
 * @package    sitsapiclient_easikit
 * @copyright  2026 onwards University College London {@link https://www.ucl.ac.uk/}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @author     Alex Yeung <k.yeung@ucl.ac.uk>
 */
final class getcombinedduedate_test extends \advanced_testcase {
    /**
     * Set up the test.
     *
     * @return void
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        set_config('endpoint_combined_due_date', 'https://example.com/cdd', 'sitsapiclient_easikit');
    }

    /**
     * Test process_response returns an empty array for an empty response.
     *
     * @covers \sitsapiclient_easikit\requests\getcombinedduedate::process_response
     * @return void
     */
    public function test_process_response_empty(): void {
        $request = new getcombinedduedate($this->get_request_data());

        $this->assertSame([], $request->process_response(''));
        $this->assertSame([], $request->process_response(null));
    }

    /**
     * Test process_response returns an empty array when there are no combined due date records.
     *
     * @covers \sitsapiclient_easikit\requests\getcombinedduedate::process_response
     * @return void
     */
    public function test_process_response_no_records(): void {
        $request = new getcombinedduedate($this->get_request_data());

        $response = json_encode(['response' => ['combined_new_due_date_collection' => []]]);
        $this->assertSame([], $request->process_response($response));
    }

    /**
     * Test process_response keys records by student programme route code and skips records without one.
     *
     * @covers \sitsapiclient_easikit\requests\getcombinedduedate::process_response
     * @return void
     */
    public function test_process_response_keys_by_sprcode(): void {
        $request = new getcombinedduedate($this->get_request_data());

        $response = json_encode([
            'response' => [
                'combined_new_due_date_collection' => [
                    'combined_new_due_date' => [
                        ['student_programme_route_code' => '12345678/1', 'calc_due_dt' => '2025-02-27'],
                        ['student_programme_route_code' => '87654321/1', 'calc_due_dt' => '2025-03-01'],
                        ['calc_due_dt' => '2025-03-05'],
                    ],
                ],
            ],
        ]);

        $records = $request->process_response($response);

        $this->assertCount(2, $records);
        $this->assertArrayHasKey('12345678/1', $records);
        $this->assertArrayHasKey('87654321/1', $records);
        $this->assertEquals('2025-02-27', $records['12345678/1']['calc_due_dt']);
    }

    /**
     * Test the endpoint URL is built with the module filter and no student clause by default.
     *
     * @covers \sitsapiclient_easikit\requests\getcombinedduedate::get_endpoint_url_with_params
     * @return void
     */
    public function test_get_endpoint_url_without_sprcode(): void {
        $request = new getcombinedduedate($this->get_request_data());
        $filter = $this->extract_filter($request->get_endpoint_url_with_params());

        $this->assertStringContainsString("year eq '2023'", $filter);
        $this->assertStringContainsString("module_code eq 'LAWS0024'", $filter);
        $this->assertStringContainsString("module_occurrence eq 'A6U'", $filter);
        $this->assertStringContainsString("module_sequence eq '001'", $filter);
        // The slash in the period slot code is replaced with @@.
        $this->assertStringContainsString("period_slot_code eq 'T1@@2'", $filter);
        $this->assertStringNotContainsString('student_programme_route_code', $filter);
    }

    /**
     * Test the endpoint URL includes a student clause with slash replacement when a SPR code is given.
     *
     * @covers \sitsapiclient_easikit\requests\getcombinedduedate::get_endpoint_url_with_params
     * @covers \sitsapiclient_easikit\requests\getcombinedduedate::replace_invalid_characters
     * @return void
     */
    public function test_get_endpoint_url_with_sprcode(): void {
        $data = $this->get_request_data();
        $data->sprcode = '12345678/1';
        $request = new getcombinedduedate($data);
        $filter = $this->extract_filter($request->get_endpoint_url_with_params());

        // The slash in the student programme route code is replaced with @@.
        $this->assertStringContainsString("student_programme_route_code eq '12345678@@1'", $filter);
    }

    /**
     * Get valid request data.
     *
     * @return \stdClass
     */
    private function get_request_data(): \stdClass {
        return (object)[
            'academicyear' => '2023',
            'modcode' => 'LAWS0024',
            'modocc' => 'A6U',
            'mabseq' => '001',
            'periodslotcode' => 'T1/2',
        ];
    }

    /**
     * Extract and decode the OData filter from an endpoint URL.
     *
     * @param string $url The endpoint URL.
     * @return string The decoded filter.
     */
    private function extract_filter(string $url): string {
        $marker = '$filter=';
        return rawurldecode(substr($url, strpos($url, $marker) + strlen($marker)));
    }
}
