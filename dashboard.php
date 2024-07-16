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

/**
 * Dashboard page for local_sitsgradepush to show course level assessment mappings.
 *
 * @package    local_sitsgradepush
 * @copyright  2023 onwards University College London {@link https://www.ucl.ac.uk/}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @author     Alex Yeung <k.yeung@ucl.ac.uk>
 */

namespace local_sitsgradepush;

use context_course;
use moodle_exception;
use moodle_url;

require_once('../../config.php');

// Course ID.
$courseid = required_param('id', PARAM_INT);

// Is reassessment or not.
$reassess = optional_param('reassess', 0, PARAM_INT);

// Get course context.
$context = context_course::instance($courseid);

// Get course instance.
if (!$course = get_course($courseid)) {
    throw new moodle_exception('course not found.', 'local_sitsgradepush');
}

// Make sure user is authenticated.
require_login($course);

// Check user's capability.
require_capability('local/sitsgradepush:mapassessment', $context);

$header = get_string('dashboard:header', 'local_sitsgradepush');
$param = ['id' => $courseid];
if ($reassess == 1) {
    // Check re-assessment marks transfer is enabled.
    if (get_config('local_sitsgradepush', 'reassessment_enabled') !== '1') {
        throw new moodle_exception('error:reassessmentdisabled', 'local_sitsgradepush');
    }
    $param['reassess'] = $reassess;
    $header = get_string('dashboard:header:reassess', 'local_sitsgradepush');
}

$url = new moodle_url('/local/sitsgradepush/dashboard.php', $param);

// Set the required data into the PAGE object.
$PAGE->set_context($context);
$PAGE->set_url($url);
$PAGE->set_title(get_string('dashboard:header', 'local_sitsgradepush'));
$PAGE->set_heading(format_string($course->fullname, true, ['context' => $context]));

// Page header.
echo $OUTPUT->header();

// Display a notification if user does not have the capability to push grades.
if (!has_capability('local/sitsgradepush:pushgrade', $context)) {
    // Add notification.
    echo $OUTPUT->notification(get_string('error:pushgradespermission', 'local_sitsgradepush'), 'info');
}

// Get renderer.
$renderer = $PAGE->get_renderer('local_sitsgradepush');

echo $renderer->print_dashboard_selector($url, $reassess);

// Get the component grades.
$manager = manager::get_manager();

// Get all component grade options for the current course.
$result = $manager->get_component_grade_options($courseid);

// Render the dashboard.
if (!empty($result)) {
    echo '<div id="sitsgradepush-dasboard-container" class="sitsgradepush-dasboard">';
    echo '<h2>' . $header . '</h2>
          <p>' . get_string('dashboard:header:desc', 'local_sitsgradepush') . '</p>';
    echo $renderer->render_dashboard($result, $courseid, $reassess);
    echo '</div>';
} else {
    echo get_string('error:nomoduledeliveryfound', 'local_sitsgradepush');
}

// Initialise the javascript.
$PAGE->requires->js_call_amd('local_sitsgradepush/dashboard', 'init', [$courseid]);

// Page footer.
echo $OUTPUT->footer();
