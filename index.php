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
use context_module;
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

// Set the required data into the PAGE object.
$param = ['id' => $coursemoduleid];
$url = new moodle_url('/local/sitsgradepush/index.php', $param);
$modulecontext = context_module::instance($coursemoduleid);
$PAGE->set_cm($coursemodule);
$PAGE->set_context($modulecontext);
$PAGE->set_url($url);
$PAGE->set_title('SITS Grade Push');
$PAGE->activityheader->disable();

// Set the breadcrumbs.
$PAGE->navbar->add('SITS Grade Push',
    new moodle_url('/local/sitsgradepush/index.php', $param));

// Page header.
echo $OUTPUT->header();

// Get renderer.
$renderer = $PAGE->get_renderer('local_sitsgradepush');

echo '<div class="container py-5">';
// Assessment name.
echo '<h3 class="mb-4">' . get_string('index:header', 'local_sitsgradepush') . '</h3>';

$manager = manager::get_manager();

// Get page content.
$content = $manager->get_assessment_data($coursemoduleid);

$mappingids = [];

if (!empty($content)) {
    // Check if asynchronous grade push is enabled.
    $async = get_config('local_sitsgradepush', 'async');

    // Check if this course module has pending task.
    if ($async) {
        // Get push button label.
        $buttonlabel = get_string('label:pushgrade', 'local_sitsgradepush');
        $disabled = '';
    } else {
        // Push grade and submission log.
        if ($pushgrade == 1) {
            // Loop through each mapping.
            foreach ($content['mappings'] as $mapping) {
                // Push grades for each student in the mapping.
                foreach ($mapping->students as $student) {
                    $manager->push_grade_to_sits($mapping, $student->userid);
                    $manager->push_submission_log_to_sits($mapping, $student->userid);
                }
            }

              // Refresh data after completed all pushes.
            $content = $manager->get_assessment_data($coursemoduleid);
            $buttonlabel = get_string('label:ok', 'local_sitsgradepush');
        } else {
            $url->param('pushgrade', 1);
            $buttonlabel = get_string('label:pushgrade', 'local_sitsgradepush');
        }
    }

    // Render push button if the assessment is mapped.
    if ($manager->is_activity_mapped($coursemoduleid)) {
        if ($async) {
            echo $renderer->render_button('local_sitsgradepush_pushbutton_async', $buttonlabel, $disabled);
        } else {
            echo $renderer->render_link('local_sitsgradepush_pushbutton', $buttonlabel, $url->out(false));
        }

    } else {
        echo '<p class="alert alert-danger">' . get_string('error:assessmentisnotmapped', 'local_sitsgradepush') . '</p>';
    }

    // Display grade push records for each mapping.
    foreach ($content['mappings'] as $mapping) {
        $mappingids[] = $mapping->id;
        echo $renderer->render_assessment_push_status_table($mapping);
    }

    // Display invalid students.
    if (!empty($content['invalidstudents']->students)) {
        echo $renderer->render_assessment_push_status_table($content['invalidstudents']);
    }
} else {
    echo '<p class="alert alert-info">' . get_string('error:assessmentisnotmapped', 'local_sitsgradepush') . '</p>';
}
echo '</div>';

// Initialize javascript.
$PAGE->requires->js_call_amd('local_sitsgradepush/sitsgradepush', 'init', [$coursemodule->course, $coursemoduleid, $mappingids]);

// And the page footer.
echo $OUTPUT->footer();
