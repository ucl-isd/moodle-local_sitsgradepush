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
 * Index page for local_sitsgradepush to handle the bulk grade push for an activity.
 *
 * @package    local_sitsgradepush
 * @copyright  2023 onwards University College London {@link https://www.ucl.ac.uk/}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @author     Alex Yeung <k.yeung@ucl.ac.uk>
 */

namespace local_sitsgradepush;

use context_course;
use local_sitsgradepush\assessment\assessmentfactory;
use moodle_exception;
use moodle_url;

require_once('../../config.php');

// Make sure user is authenticated.
require_login();

// Course module ID.
$coursemoduleid = required_param('id', PARAM_INT);

// Initiate grade push and show push result.
$pushgrade = optional_param('pushgrade', 0, PARAM_INT);

// Get manager and check course module exists.
if (!$coursemodule = get_coursemodule_from_id(null, $coursemoduleid)) {
    throw new moodle_exception('course module not found.', 'local_sitsgradepush');
}

// Get course context.
$context = context_course::instance($coursemodule->course);

// Check user's capability.
require_capability('local/sitsgradepush:pushgrade', $context);

$course = get_course($coursemodule->course);

// Set the required data into the PAGE object.
$param = ['id' => $coursemoduleid];
$url = new moodle_url('/local/sitsgradepush/index.php', $param);
$PAGE->set_context($context);
$PAGE->set_url($url);
$PAGE->set_title('SITS Grade Push');

// Get assessment.
$assessment = assessmentfactory::get_assessment($coursemodule);

// Set the breadcrumbs.
$PAGE->navbar->add(get_string('courses'), new moodle_url('/course/index.php'));
$PAGE->navbar->add($course->fullname, new moodle_url('/course/view.php', ['id' => $coursemodule->course]));
$PAGE->navbar->add(
    $assessment->get_assessment_name(),
    new moodle_url('/mod/'.$coursemodule->modname.'/view.php', ['id' => $coursemodule->id])
);
$PAGE->navbar->add('SITS Grade Push',
    new moodle_url('/local/sitsgradepush/index.php', $param));

// Page header.
echo $OUTPUT->header();

// Get renderer.
$renderer = $PAGE->get_renderer('local_sitsgradepush');
$manager = manager::get_manager();

// Assessment header.
echo '<h1>SITS Grade Push - ' . $assessment->get_assessment_name() . '</h1>';

// Get students with grades.
$studentswithgrade = $manager->get_assessment_data($assessment);

if (!empty($studentswithgrade)) {
    // Push grade and submission log.
    if ($pushgrade == 1) {
        // Push grades.
        foreach ($studentswithgrade as $student) {
            $manager->push_grade_to_sits($assessment, $student->userid);
        }
        // Refresh data after completed all pushes.
        $studentswithgrade = $manager->get_assessment_data($assessment);
        $buttonlabel = 'OK';
    } else {
        $url->param('pushgrade', 1);
        $buttonlabel = 'Push';
    }

    // Render assessment push status table.
    echo $renderer->render_assessment_push_status_table($studentswithgrade);

    // Render push button if the assessment is mapped.
    if ($manager->is_activity_mapped($coursemoduleid)) {
        echo $renderer->render_button('local_sitsgradepush_pushbutton', $buttonlabel, $url->out(false));
    } else {
        echo '<p class="alert alert-danger">' . get_string('error:assessmentisnotmapped', 'local_sitsgradepush') . '</p>';
    }
} else {
    echo '<p class="alert alert-info">' . get_string('error:nostudentgrades', 'local_sitsgradepush') . '</p>';
}

// And the page footer.
echo $OUTPUT->footer();
