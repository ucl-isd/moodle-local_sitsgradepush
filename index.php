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

echo '<div class="container py-5">';
// Assessment name.
echo '<h3 class="mb-4">Sits grade push - ' . $assessment->get_assessment_name() . '</h3>';
// Get students with grades.
$studentswithgrade = $manager->get_assessment_data($assessment);

if (!empty($studentswithgrade)) {
    // Get push button label.
    $buttonlabel = get_string('label:pushgrade', 'local_sitsgradepush');
    $disabled = '';

    // Check if this course module has pending task.
    if ($task = $manager->get_pending_task_in_queue($coursemoduleid)) {
        $buttonlabel = $task->buttonlabel;
        $disabled = 'disabled';
    }

    // Render push button if the assessment is mapped.
    if ($manager->is_activity_mapped($coursemoduleid)) {
        echo $renderer->render_button('local_sitsgradepush_pushbutton', $buttonlabel, $disabled);
        if ($lastfinishedtask = $manager->get_last_finished_push_task($coursemoduleid)) {
            echo '<p>'. get_string(
                'label:lastpushtext',
                'local_sitsgradepush', [
                    'statustext' => $lastfinishedtask->statustext,
                    'date' => date('d/m/Y', $lastfinishedtask->timeupdated),
                    'time' => date('g:i:s a', $lastfinishedtask->timeupdated)]) .
                '</p>';
        }
    } else {
        echo '<p class="alert alert-danger">' . get_string('error:assessmentisnotmapped', 'local_sitsgradepush') . '</p>';
    }

    // Render assessment push status table.
    echo $renderer->render_assessment_push_status_table($studentswithgrade);
} else {
    echo '<p class="alert alert-info">' . get_string('error:nostudentgrades', 'local_sitsgradepush') . '</p>';
}
echo '</div>';

$PAGE->requires->js_call_amd('local_sitsgradepush/sitsgradepush', 'init', [$coursemoduleid]);

// And the page footer.
echo $OUTPUT->footer();
