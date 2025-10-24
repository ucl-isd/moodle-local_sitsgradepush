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

use Aws\Sqs\SqsClient;
use local_sitsgradepush\aws\sqs;

/**
 * Tests for the sqs class.
 *
 * @package    local_sitsgradepush
 * @copyright  2024 onwards University College London {@link https://www.ucl.ac.uk/}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @author     Alex Yeung <k.yeung@ucl.ac.uk>
 */
final class sqs_test extends \advanced_testcase {
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
     * Test get_client returns sqs client.
     *
     * @covers \local_sitsgradepush\aws\sqs::get_client
     * @return void
     */
    public function test_get_client_returns_sqs_client(): void {
        $sqs = new sqs();
        $client = $sqs->get_client();
        $this->assertInstanceOf(SqsClient::class, $client);
    }

    /**
     * Test constructor throws exception if configs missing.
     *
     * @covers \local_sitsgradepush\aws\sqs::__construct
     * @return void
     * @throws \coding_exception
     */
    public function test_constructor_throws_exception_if_configs_missing(): void {
        // Set the required configs to empty.
        set_config('aws_region', '', 'local_sitsgradepush');
        set_config('aws_key', '', 'local_sitsgradepush');
        set_config('aws_secret', '', 'local_sitsgradepush');

        $this->expectException(\moodle_exception::class);
        $this->expectExceptionMessage(get_string('error:missingrequiredconfigs', 'local_sitsgradepush'));

        new sqs();
    }

    /**
     * Test check_required_configs_are_set returns configs.
     *
     * @covers \local_sitsgradepush\aws\sqs::__construct
     * @covers \local_sitsgradepush\aws\sqs::check_required_configs_are_set
     * @return void
     * @throws \ReflectionException
     */
    public function test_check_required_configs_are_set_returns_configs(): void {
        // Set the required configs.
        set_config('aws_region', 'us-east', 'local_sitsgradepush');
        set_config('aws_key', 'awskey-1234', 'local_sitsgradepush');
        set_config('aws_secret', 'secret-2468', 'local_sitsgradepush');

        $sqs = new sqs();
        $reflection = new \ReflectionClass($sqs);
        $method = $reflection->getMethod('check_required_configs_are_set');
        $method->setAccessible(true);
        $configs = $method->invoke($sqs);
        $this->assertEquals('us-east', $configs->aws_region);
        $this->assertEquals('awskey-1234', $configs->aws_key);
        $this->assertEquals('secret-2468', $configs->aws_secret);
    }
}
