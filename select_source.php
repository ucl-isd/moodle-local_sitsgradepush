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
$reassess = optional_param('reassess', 0, PARAM_INT);

// Get course context.
$context = context_course::instance($courseid);

// Get course instance.
if (!$course = get_course($courseid)) {
    throw new moodle_exception('course not found.', 'local_sitsgradepush');
}

// Get the component grades.
$manager = manager::get_manager();

// Throw exception if not mapping for re-assessment and the course is not in current academic year.
if (!$manager->is_current_academic_year_activity($courseid) && $reassess == 0) {
    throw new moodle_exception('error:pastactivity', 'local_sitsgradepush');
}

// Check MAB exists.
if (!$mab = $manager->get_local_component_grade_by_id($mabid)) {
    throw new moodle_exception('error:mab_not_found', 'local_sitsgradepush', '', $mabid);
}

// Check if the component grade is valid for mapping.
list($mabvalid, $errormessages) = $manager->is_component_grade_valid_for_mapping($mab);
if (!$mabvalid) {
    throw new moodle_exception('error:mab_invalid_for_mapping', 'local_sitsgradepush', '', implode(', ', $errormessages));
}

// Check there is no task running and no marks transfer records.
if (!$manager->can_change_source($mabid, $reassess)) {
    throw new moodle_exception('error:cannot_change_source', 'local_sitsgradepush');
}

// Make sure user is authenticated.
require_login($course);

// Check user's capability.
require_capability('local/sitsgradepush:mapassessment', $context);

// Set the required data into the PAGE object.
$param = ['courseid' => $courseid, 'mabid' => $mabid];
if ($reassess == 1) {
    // Check re-assessment marks transfer is enabled.
    if (get_config('local_sitsgradepush', 'reassessment_enabled') !== '1') {
        throw new moodle_exception('error:reassessmentdisabled', 'local_sitsgradepush');
    }
    $param['reassess'] = $reassess;
}
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

// Page header.
echo $OUTPUT->header();

// Render the page.
$renderer = $PAGE->get_renderer('local_sitsgradepush');
echo $renderer->render_select_source_page($courseid, $mab, $reassess);

$extensioninfopageurl = get_config('local_sitsgradepush', 'extension_support_page_url');

// Include JS.
$PAGE->requires->js_call_amd('local_sitsgradepush/select_source', 'init', [$extensioninfopageurl]);

// Page footer.
echo $OUTPUT->footer();
