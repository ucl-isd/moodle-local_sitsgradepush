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

namespace sitsapiclient_stutalkdirect;

use sitsapiclient_stutalkdirect\privacy\provider;

/**
 * Unit tests for sitsapiclient_stutalkdirect's privacy provider class.
 *
 * @package    sitsapiclient_stutalkdirect
 * @copyright  2023 onwards University College London {@link https://www.ucl.ac.uk/}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @author     Alex Yeung <k.yeung@ucl.ac.uk>
 */
final class privacy_provider_test extends \advanced_testcase {

    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
    }

    /**
     * Test get_reason.
     *
     * @covers \sitsapiclient_stutalkdirect\privacy\provider::get_reason()
     * @return void
     * @throws \coding_exception
     */
    public function test_get_reason(): void {
        $reason = get_string(provider::get_reason(), 'sitsapiclient_stutalkdirect');
        $this->assertEquals('This plugin does not store any personal data.', $reason);
    }
}
