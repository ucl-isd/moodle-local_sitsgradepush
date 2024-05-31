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
use moodle_url;

require_once('../../config.php');

// Make sure user is authenticated.
require_login();

// Get the course id.
$courseid = required_param('courseid', PARAM_INT);

// Get the source type.
$sourcetype = required_param('sourcetype', PARAM_TEXT);

// Course source id, e.g. course module id for activity, grade item id for grade book item.
$id = required_param('id', PARAM_INT);

// Initiate grade push and show push result.
$pushgrade = optional_param('pushgrade', 0, PARAM_INT);

// Get the source object.
$source = assessment\assessmentfactory::get_assessment($sourcetype, $id);

// Get course context.
$coursecontext = context_course::instance($courseid);

// Check user's capability.
require_capability('local/sitsgradepush:pushgrade', $coursecontext);

$showassessmentname = false;
// Set the required data into the PAGE object.
// Source type is course module.
if ($source->get_type() === assessmentfactory::SOURCETYPE_MOD) {
    $PAGE->set_cm($source->get_course_module());
    $PAGE->activityheader->disable();
} else {
    // Get course.
    $course = get_course($courseid);

    // Set up page.
    $PAGE->set_context($coursecontext);
    $PAGE->set_secondary_navigation(false);
    $PAGE->navbar->add(get_string('courses'), new moodle_url('/course/index.php'));
    $PAGE->navbar->add($course->fullname, new moodle_url('/course/view.php', ['id' => $courseid]));
    $PAGE->navbar->add($source->get_assessment_name(), $source->get_assessment_url(true));
    $PAGE->navbar->add(get_string('pluginname', 'local_sitsgradepush'),
        $source->get_assessment_transfer_history_url(true));
    $showassessmentname = true;
}
$PAGE->set_url($source->get_assessment_transfer_history_url(true));
$PAGE->set_title(get_string('pluginname', 'local_sitsgradepush'));

// Page header.
echo $OUTPUT->header();

// Get renderer.
$renderer = $PAGE->get_renderer('local_sitsgradepush');

echo '<div class="container py-5">';

// Page header.
if ($showassessmentname) {
    echo '<h2 class="mb-4">' . $source->get_assessment_name() . '</h2>';
}
echo '<h3 class="mb-4">' . get_string('index:header', 'local_sitsgradepush') . '</h3>';

$manager = manager::get_manager();

// Get page content.
$content = $manager->get_assessment_data($sourcetype, $id);

// Check sync threshold.
$syncthreshold = get_config('local_sitsgradepush', 'sync_threshold');

if (!empty($content)) {
    // Get total number of marks to be pushed.
    $totalmarkscount = 0;
    foreach ($content['mappings'] as $mapping) {
        $totalmarkscount += $mapping->markscount;
    }

    // Transfer marks if it is a sync transfer and pushgrade is set.
    if ($totalmarkscount <= $syncthreshold && $pushgrade == 1) {
        // Loop through each mapping.
        foreach ($content['mappings'] as $mapping) {
            // Skip if there is no student in the mapping.
            if (empty($mapping->students)) {
                continue;
            }
            // Push grades for each student in the mapping.
            foreach ($mapping->students as $student) {
                $manager->push_grade_to_sits($mapping, $student->userid);
                $manager->push_submission_log_to_sits($mapping, $student->userid);
            }
        }

        // Refresh data after completed all pushes.
        $content = $manager->get_assessment_data($sourcetype, $id);
    }

    // Render the page.
    echo $renderer->render_marks_transfer_history_page($content, $courseid);
} else {
    echo '<p class="alert alert-info">' . get_string('error:assessmentisnotmapped', 'local_sitsgradepush') . '</p>';
}
echo '</div>';

// Initialize javascript.
$PAGE->requires->js_call_amd('local_sitsgradepush/sitsgradepush', 'init', [$courseid, $sourcetype, $id]);

// And the page footer.
echo $OUTPUT->footer();
