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

// Module name.
$modname = required_param('modname', PARAM_TEXT);

// Initiate grade push and show push result.
$showresult = optional_param('showresult', 0, PARAM_INT);

// Get manager and check course module exists.
$manager = manager::get_manager();
if (!$coursemodule = $manager->get_course_module($coursemoduleid)) {
    throw new moodle_exception('course module not found.', 'local_sitsgradepush');
}

// Get course context.
$context = context_course::instance($coursemodule->course);
$course = get_course($coursemodule->course);

// Set the required data into the PAGE object.
$param = ['id' => $coursemoduleid, 'modname' => $modname];
$url = new moodle_url('/local/sitsgradepush/index.php', $param);
$PAGE->set_context($context);
$PAGE->set_url($url);
$PAGE->set_title('SITS Grade Push');

// Get assessment.
$assessment = assessmentfactory::get_assessment($modname, $coursemodule);

// Set the breadcrumbs.
$PAGE->navbar->add(get_string('courses'), new moodle_url('/course/index.php'));
$PAGE->navbar->add($course->fullname, new moodle_url('/course/view.php', ['id' => $coursemodule->course]));
$PAGE->navbar->add($assessment->get_assessment_name(), new moodle_url('/mod/'.$modname.'/view.php', ['id' => $coursemodule->id]));
$PAGE->navbar->add('SITS Grade Push',
    new moodle_url('/local/sitsgradepush/index.php', $param));

// Page header.
echo $OUTPUT->header();

// Get all grades for the assessment.
if ($grades = $assessment->get_all_grades()) {
    // Get renderer.
    $renderer = $PAGE->get_renderer('local_sitsgradepush');

    // Trigger grade push and show results.
    if ($showresult == 1) {
        foreach ($grades as &$grade) {
            $result = $manager->push_grade_to_sits($coursemodule->id, $grade);
            $grade->pushresult = $result['message'];
            $grade->pushtime = $result['timestamp'];
        }
        echo $renderer->render_push_result_table($assessment->get_assessment_name(), $grades);
        echo $renderer->render_button('local_sitsgradepush_finishbutton', 'OK', $url->out(false));
    } else {
        // Show the grades and last push status.
        echo $renderer->render_grades_table($coursemodule->id, $assessment->get_assessment_name(), $grades);
        $url->param('showresult', 1);
        echo $renderer->render_button('local_sitsgradepush_pushbutton', 'Push', $url->out(false));
    }
} else {
    echo 'No grades found for ' . $assessment->get_assessment_name() . '.';
}

// And the page footer.
echo $OUTPUT->footer();
