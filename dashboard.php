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

// Get course context.
$context = context_course::instance($courseid);

// Get course instance.
if (!$course = get_course($courseid)) {
    throw new moodle_exception('course not found.', 'local_sitsgradepush');
}

// Make sure user is authenticated.
require_login();

// Check user's capability.
require_capability('local/sitsgradepush:mapassessment', $context);

// Set the required data into the PAGE object.
$param = ['id' => $courseid];
$url = new moodle_url('/local/sitsgradepush/dashboard.php', $param);
$PAGE->set_context($context);
$PAGE->set_url($url);
$PAGE->set_title('SITS Grade Push Dashboard');
$PAGE->set_secondary_navigation(false);

// Set the breadcrumbs.
$PAGE->navbar->add(get_string('courses'), new moodle_url('/course/index.php'));
$PAGE->navbar->add($course->fullname, new moodle_url('/course/view.php', ['id' => $courseid]));
$PAGE->navbar->add('SITS Grade Push',
    new moodle_url('/local/sitsgradepush/dashboard.php', $param));

// Page header.
echo $OUTPUT->header();

// Display a notification if user does not have the capability to push grades.
if (!has_capability('local/sitsgradepush:pushgrade', $context)) {
    // Add notification.
    echo $OUTPUT->notification(get_string('error:pushgradespermission', 'local_sitsgradepush'), 'info');
}

// Get renderer.
$renderer = $PAGE->get_renderer('local_sitsgradepush');

// Get the component grades.
$manager = manager::get_manager();

// Get the component grades for each module delivery.
$result = $manager->get_component_grade_options($courseid, null);

// Render the dashboard.
if (!empty($result)) {
    echo '<div id="sitsgradepush-dasboard-container" class="sitsgradepush-dasboard">';
    echo $renderer->render_dashboard($result, $courseid);
    echo '</div>';
} else {
    echo get_string('error:nomoduledeliveryfound', 'local_sitsgradepush');
}

// Initialise the javascript.
$PAGE->requires->js_call_amd('local_sitsgradepush/dashboard', 'init', []);

// Page footer.
echo $OUTPUT->footer();
