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
 * Admin page for processing RAA extensions for all eligible assessments.
 *
 * @package    local_sitsgradepush
 * @copyright  2026 onwards University College London {@link https://www.ucl.ac.uk/}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @author     Alex Yeung <k.yeung@ucl.ac.uk>
 */

use local_sitsgradepush\extensionmanager;
use local_sitsgradepush\form\process_extensions_form;
use local_sitsgradepush\manager;
use local_sitsgradepush\task\process_extensions_all_mappings;
use core\task\manager as coretaskmanager;
use core\output\notification;

require_once('../../config.php');

require_login();
require_capability('moodle/site:config', context_system::instance());

$url = new moodle_url('/local/sitsgradepush/process_extensions.php');
$PAGE->set_url($url);
$PAGE->set_context(context_system::instance());
$PAGE->set_title(get_string('manualprocessextensions', 'local_sitsgradepush'));

// Handle confirmed action.
$confirm = optional_param('confirm', 0, PARAM_INT);
if ($confirm && confirm_sesskey()) {
    $courseid = optional_param('courseid', 0, PARAM_INT);
    $extensiontype = optional_param('extensiontype', 'both', PARAM_ALPHA);

    try {
        if (!extensionmanager::is_extension_enabled()) {
            throw new moodle_exception('manualprocessextensions:extensiondisabled', 'local_sitsgradepush');
        }

        if ($courseid > 0) {
            if (!get_course($courseid)) {
                throw new moodle_exception('invalidcourseid');
            }

            $mappings = manager::get_manager()->get_assessment_mappings_by_courseid($courseid, true);
            if (empty($mappings)) {
                throw new moodle_exception('manualprocessextensions:nomappings', 'local_sitsgradepush');
            }
        }

        if (process_extensions_all_mappings::adhoc_task_exists($courseid, $extensiontype)) {
            throw new moodle_exception('manualprocessextensions:alreadyqueued', 'local_sitsgradepush');
        }

        $task = new process_extensions_all_mappings();
        $task->set_custom_data(['courseid' => $courseid, 'extensiontype' => $extensiontype, 'lastprocessedid' => 0]);
        coretaskmanager::queue_adhoc_task($task);

        redirect($url, get_string('manualprocessextensions:success', 'local_sitsgradepush'), null, notification::NOTIFY_SUCCESS);
    } catch (moodle_exception $e) {
        redirect($url, $e->getMessage(), null, notification::NOTIFY_ERROR);
    }
}

$form = new process_extensions_form();

if ($form->is_cancelled()) {
    redirect($url);
} else if ($data = $form->get_data()) {
    $courseid = !empty($data->courseid) ? (int)$data->courseid : 0;
    $confirmurl = new moodle_url('/local/sitsgradepush/process_extensions.php', [
        'courseid' => $courseid,
        'extensiontype' => $data->extensiontype,
        'confirm' => 1,
        'sesskey' => sesskey(),
    ]);

    $scope = $courseid > 0
        ? get_string('manualprocessextensions:confirmcourseid', 'local_sitsgradepush', $courseid)
        : get_string('manualprocessextensions:allcourses', 'local_sitsgradepush');
    $a = (object) [
        'scope' => $scope,
        'extensiontype' => get_string('manualprocessextensions:extensiontype:' . $data->extensiontype, 'local_sitsgradepush'),
    ];

    echo $OUTPUT->header();
    echo $OUTPUT->confirm(get_string('manualprocessextensions:confirm', 'local_sitsgradepush', $a), $confirmurl, $url);
    echo $OUTPUT->footer();
    die;
}

// Normal page display.
echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('manualprocessextensions', 'local_sitsgradepush'));
echo html_writer::tag('p', get_string('manualprocessextensions:desc', 'local_sitsgradepush'));
$form->display();
echo $OUTPUT->footer();
