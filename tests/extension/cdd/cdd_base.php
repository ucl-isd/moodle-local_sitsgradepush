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

namespace local_sitsgradepush\extension\cdd;

use local_sitsgradepush\cdd_mock_manager_trait;
use local_sitsgradepush\extension_common;

defined('MOODLE_INTERNAL') || die();
global $CFG;
require_once($CFG->dirroot . '/local/sitsgradepush/tests/extension/extension_common.php');
require_once($CFG->dirroot . '/local/sitsgradepush/tests/fixtures/cdd_mock_manager_trait.php');

/**
 * Base class for combined due date (CDD) extension tests.
 *
 * @package    local_sitsgradepush
 * @copyright  2026 onwards University College London {@link https://www.ucl.ac.uk/}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @author     Alex Yeung <k.yeung@ucl.ac.uk>
 */
class cdd_base extends extension_common {
    use cdd_mock_manager_trait;

    /**
     * Setup common test data, enable the combined due date feature and insert a mapping.
     *
     * @param string $type Activity type (assign/quiz/lesson/coursework).
     * @return object The activity object.
     */
    protected function setup_common_test_data(string $type = 'assign'): object {
        // Enable the combined due date feature.
        set_config('cdd_enabled', '1', 'local_sitsgradepush');

        return parent::setup_common_test_data($type);
    }
}
