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
 * Page for managing extension tier configuration via CSV import.
 *
 * @package    local_sitsgradepush
 * @copyright  2025 onwards University College London {@link https://www.ucl.ac.uk/}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @author     Alex Yeung <k.yeung@ucl.ac.uk>
 */

use core\clock;
use core\di;
use local_sitsgradepush\form\import_tiers_form;
use local_sitsgradepush\extensionmanager;
use core\output\notification;

require_once('../../config.php');
require_once($CFG->libdir . '/csvlib.class.php');

require_login();

// Get system context.
$context = context_system::instance();

// Check user's capability.
require_capability('local/sitsgradepush:manageextensiontiers', $context);

// Get action.
$action = optional_param('action', '', PARAM_ALPHA);
$confirm = optional_param('confirm', 0, PARAM_INT);

$url = new moodle_url('/local/sitsgradepush/manage_extension_tiers.php');

// Handle export action.
if ($action === 'export') {
    $csv = extensionmanager::export_extension_tiers_csv();
    $filename = 'extension_tiers_' . di::get(clock::class)->now()->format('Y-m-d_H-i-s') . '.csv';

    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Length: ' . strlen($csv));
    echo $csv;
    exit;
}

// Handle template download action.
if ($action === 'downloadtemplate') {
    $templatefile = $CFG->dirroot . '/local/sitsgradepush/sample/extension_tiers_template.csv';

    if (file_exists($templatefile)) {
        $content = file_get_contents($templatefile);
        $filename = 'extension_tiers_template.csv';

        header('Content-Type: text/csv');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . strlen($content));
        echo $content;
        exit;
    } else {
        redirect($url, get_string('tier:error:templatenotfound', 'local_sitsgradepush'), null, notification::NOTIFY_ERROR);
    }
}

// Set the required data into the PAGE object.
$PAGE->set_context($context);
$PAGE->set_url($url);
$PAGE->set_pagelayout('admin');
$PAGE->set_title(get_string('tier:manageextensiontiers', 'local_sitsgradepush'));
$PAGE->set_heading(get_string('tier:manageextensiontiers', 'local_sitsgradepush'));

// Handle import confirmation.
if ($action === 'confirmimport' && $confirm === 1 && confirm_sesskey()) {
    $importdata = $SESSION->local_sitsgradepush_import_data ?? null;
    $importmode = $SESSION->local_sitsgradepush_import_mode ?? 'replace';

    if ($importdata) {
        try {
            // Import the data.
            extensionmanager::import_extension_tiers($importdata, $importmode);

            // Clear session data.
            unset($SESSION->local_sitsgradepush_import_data);
            unset($SESSION->local_sitsgradepush_import_mode);

            // Redirect with success message.
            redirect(
                $url,
                get_string('tier:importsuccessful', 'local_sitsgradepush', count($importdata)),
                null,
                notification::NOTIFY_SUCCESS
            );
        } catch (Exception $e) {
            redirect(
                $url,
                get_string('tier:importfailed', 'local_sitsgradepush', $e->getMessage()),
                null,
                notification::NOTIFY_ERROR
            );
        }
    }
}

// Handle cancel.
if ($action === 'cancel') {
    unset($SESSION->local_sitsgradepush_import_data);
    unset($SESSION->local_sitsgradepush_import_mode);
    redirect($url);
}

// Create form instance.
$mform = new import_tiers_form();

// Get renderer.
$renderer = $PAGE->get_renderer('local_sitsgradepush');

// Handle form submission.
if ($mform->is_cancelled()) {
    redirect($url);
} else if ($data = $mform->get_data()) {
    // Process CSV file.
    $content = $mform->get_file_content('csvfile');

    if ($content !== false) {
        try {
            // Parse and validate CSV.
            $importdata = extensionmanager::parse_extension_tiers_csv($content);

            // Store in session for confirmation.
            $SESSION->local_sitsgradepush_import_data = $importdata;
            $SESSION->local_sitsgradepush_import_mode = $data->importmode;

            // Get current tiers for preview.
            $currenttiers = extensionmanager::get_all_extension_tiers();

            // Prepare URLs for confirmation buttons.
            $confirmurl = new moodle_url($url, [
                'action' => 'confirmimport',
                'confirm' => 1,
                'sesskey' => sesskey(),
            ]);
            $cancelurl = new moodle_url($url, ['action' => 'cancel']);

            // Output header.
            echo $OUTPUT->header();

            // Display preview with confirmation buttons.
            echo $renderer->render_import_preview(
                $currenttiers,
                $importdata,
                $data->importmode,
                $confirmurl->out(false),
                $cancelurl->out(false)
            );

            // Output footer.
            echo $OUTPUT->footer();
            exit;
        } catch (Exception $e) {
            redirect(
                $url,
                get_string('tier:csvparseerror', 'local_sitsgradepush', $e->getMessage()),
                null,
                notification::NOTIFY_ERROR
            );
        }
    }
}

// Output header.
echo $OUTPUT->header();

// Get current tier configuration.
$currenttiers = extensionmanager::get_all_extension_tiers();

// Prepare export URL.
$exporturl = new moodle_url($url, ['action' => 'export']);

// Prepare template download URL.
$templateurl = new moodle_url($url, ['action' => 'downloadtemplate']);

// Render the page using mustache template.
$pagedata = [
    'description' => get_string('tier:manageextensiontiers_desc', 'local_sitsgradepush'),
    'currenttierstable' => $renderer->render_extension_tiers_table($currenttiers),
    'importform' => $mform->render(),
    'exporturl' => $exporturl->out(false),
    'templateurl' => $templateurl->out(false),
    'hastiers' => !empty($currenttiers),
];

echo $OUTPUT->render_from_template('local_sitsgradepush/manage_extension_tiers', $pagedata);

// Output footer.
echo $OUTPUT->footer();
