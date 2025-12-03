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
 * Global library functions for local_sitsgradepush
 *
 * @package    local_sitsgradepush
 * @copyright  2023 onwards University College London {@link https://www.ucl.ac.uk/}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @author     Alex Yeung <k.yeung@ucl.ac.uk>
 */

use local_sitsgradepush\assessment\assessmentfactory;
use local_sitsgradepush\extension\extension;
use local_sitsgradepush\extensionmanager;
use local_sitsgradepush\logger;
use local_sitsgradepush\manager;
use local_sitsgradepush\taskmanager;

/**
 * Attach a grade push link in the activity's settings menu.
 *
 * @param settings_navigation $settingsnav
 * @param context $context
 * @return void|null
 * @throws coding_exception
 * @throws moodle_exception
 */
function local_sitsgradepush_extend_settings_navigation(settings_navigation $settingsnav, context $context) {
    global $PAGE;

    // Must be under a module page.
    $cm = $PAGE->cm;
    if (!$cm) {
        return null;
    }

    // Must be one of the allowed activities.
    if (!in_array($cm->modname, manager::allowed_activities())) {
        return null;
    }

    // Must have permission to push grade.
    if (!has_capability('local/sitsgradepush:pushgrade', $context)) {
        return null;
    }

    // Build the grade push page url.
    $url = new moodle_url('/local/sitsgradepush/index.php', [
        'courseid' => $PAGE->course->id,
        'sourcetype' => assessmentfactory::SOURCETYPE_MOD,
        'id' => $cm->id,
    ]);

    // Create the node.
    $node = navigation_node::create(
        get_string('pluginname', 'local_sitsgradepush'),
        $url,
        navigation_node::NODETYPE_LEAF,
        'local_sitsgradepush',
        'local_sitsgradepush',
        new pix_icon('i/grades', get_string('pluginname', 'local_sitsgradepush'))
    );

    // Get the module settings node.
    if ($modulesettings = $settingsnav->get('modulesettings')) {
        // Add node.
        $modulesettings->add_node($node);
    }
}

/**
 * Extend the course navigation with a link to the grade push dashboard.
 *
 * @param navigation_node $parentnode
 * @param stdClass $course
 * @param context_course $context
 * @return void|null
 * @throws coding_exception
 * @throws dml_exception
 * @throws moodle_exception
 */
function local_sitsgradepush_extend_navigation_course(navigation_node $parentnode, stdClass $course, context_course $context) {

    global $PAGE;

    // Only add this settings item on non-site course pages and if the user has the capability to perform a rollover.
    if (!$PAGE->course || $PAGE->course->id == SITEID || !has_capability('local/sitsgradepush:mapassessment', $context)) {
        return null;
    }

    // Is the plugin enabled?
    $enabled = (bool)get_config('local_sitsgradepush', 'enabled');
    if (!$enabled) {
        return null;
    }

    $url = new moodle_url('/local/sitsgradepush/dashboard.php', [
        'id' => $course->id,
    ]);

    $node = navigation_node::create(
        get_string('pluginname', 'local_sitsgradepush'),
        $url,
        navigation_node::NODETYPE_LEAF,
        'local_sitsgradepush',
        'local_sitsgradepush',
        new pix_icon('i/grades', get_string('pluginname', 'local_sitsgradepush'), 'moodle')
    );

    if ($PAGE->url->compare($url, URL_MATCH_BASE)) {
        $node->make_active();
    }

    $parentnode->add_node($node);
}

/**
 * Add an adhoc task to process extensions if a mapped course module is updated.
 *
 * @param stdClass $data
 * @param stdClass $course
 *
 * @return stdClass
 * @throws dml_exception
 */
function local_sitsgradepush_coursemodule_edit_post_actions(stdClass $data, stdClass $course): stdClass {
    // Check if grade push is enabled and the extension is enabled.
    if (get_config('local_sitsgradepush', 'enabled') && extensionmanager::is_extension_enabled()) {
        try {
            // Check if the course module is one of the supported activities for extensions.
            if (!extension::is_module_supported($data->modulename)) {
                return $data;
            }

            // Get the assessment mappings for the course module.
            $mappings = manager::get_manager()->get_assessment_mappings(
                assessmentfactory::get_assessment(assessmentfactory::SOURCETYPE_MOD, $data->coursemodule)
            );

            // Add the process extensions adhoc task for each mapping.
            foreach ($mappings as $mapping) {
                taskmanager::add_process_extensions_for_new_mapping_adhoc_task($mapping->id);
            }
        } catch (Exception $e) {
            // Log the exception.
            logger::log($e->getMessage());
        }
    }

    return $data;
}
