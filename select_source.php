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
 * Select source pages for local_sitsgradepush to select source for assessment component.
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
$courseid = required_param('courseid', PARAM_INT);
$mabid = required_param('mabid', PARAM_INT);
$source = optional_param('source', '', PARAM_TEXT);

// Get course context.
$context = context_course::instance($courseid);

// Get course instance.
if (!$course = get_course($courseid)) {
    throw new moodle_exception('course not found.', 'local_sitsgradepush');
}

// Get the component grades.
$manager = manager::get_manager();

// Check MAB exists.
if ($manager->get_local_component_grade_by_id($mabid) === false) {
    throw new moodle_exception('error:mab_not_found', 'local_sitsgradepush', '', $mabid);
}

// Make sure user is authenticated.
require_login();

// Check user's capability.
require_capability('local/sitsgradepush:mapassessment', $context);

// Set the required data into the PAGE object.
$param = ['courseid' => $courseid, 'mabid' => $mabid];
$url = new moodle_url('/local/sitsgradepush/select_source.php', $param);
$PAGE->set_context($context);
$PAGE->set_url($url);
$PAGE->set_title(get_string('selectsource:title', 'local_sitsgradepush'));
$PAGE->set_secondary_navigation(false);

// Set the breadcrumbs.
$PAGE->navbar->add(get_string('courses'), new moodle_url('/course/index.php'));
$PAGE->navbar->add($course->fullname, new moodle_url('/course/view.php', ['id' => $courseid]));
$PAGE->navbar->add(get_string('pluginname', 'local_sitsgradepush'),
    new moodle_url('/local/sitsgradepush/dashboard.php', ['id' => $courseid]));
$PAGE->navbar->add(get_string('selectsource:header', 'local_sitsgradepush'),
    new moodle_url('/local/sitsgradepush/select_source.php', $param));
if (!empty($source)) {
    $param['source'] = $source;
    $PAGE->navbar->add(get_string('existingactivity:navbar', 'local_sitsgradepush'),
        new moodle_url('/local/sitsgradepush/select_source.php', $param));
}

// Page header.
echo $OUTPUT->header();

// Get renderer.
$renderer = $PAGE->get_renderer('local_sitsgradepush');

if (empty($source)) {
    // Render the page.
    echo $renderer->render_select_source_page();
    // Initialise the javascript.
    $PAGE->requires->js_call_amd('local_sitsgradepush/select_source', 'init', [$courseid, $mabid]);
} else {
    switch ($source) {
        case 'existing':
            // Render the page.
            echo $renderer->render_existing_activity_page($param);
            // Initialise the javascript.
            $PAGE->requires->js_call_amd('local_sitsgradepush/existing_activity', 'init', []);
            break;
        default:
            throw new moodle_exception('error:invalid_source_type', 'local_sitsgradepush', '', $source);
    }
}

// Page footer.
echo $OUTPUT->footer();
