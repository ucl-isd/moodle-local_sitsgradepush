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
$PAGE->set_title(get_string('pluginname', 'local_sitsgradepush'));
$PAGE->activityheader->disable();

// Set the breadcrumbs.
$PAGE->navbar->add(get_string('pluginname', 'local_sitsgradepush'),
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

// Check if asynchronous grade push is enabled.
$async = get_config('local_sitsgradepush', 'async');

if (!empty($content)) {
    // Transfer marks if it is a sync transfer and pushgrade is set.
    if (!$async && $pushgrade == 1) {
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
        $content = $manager->get_assessment_data($coursemoduleid);
    }

    // Render the page.
    echo $renderer->render_marks_transfer_history_page($content, $coursemodule->course);
} else {
    echo '<p class="alert alert-info">' . get_string('error:assessmentisnotmapped', 'local_sitsgradepush') . '</p>';
}
echo '</div>';

// Initialize javascript.
$PAGE->requires->js_call_amd('local_sitsgradepush/sitsgradepush', 'init', [$coursemodule->course, $coursemoduleid]);

// And the page footer.
echo $OUTPUT->footer();
