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

use local_sitsgradepush\manager;

/**
 * Display settings in activity's settings.
 *
 * @param moodleform $formwrapper
 * @param MoodleQuickForm $mform
 * @return void
 * @throws coding_exception
 * @throws dml_exception
 */
function local_sitsgradepush_coursemodule_standard_elements($formwrapper, $mform) {
    // Do not add settings if the plugin is disabled.
    if (get_config('local_sitsgradepush', 'enabled') !== '1') {
        return;
    }

    // Do not show settings if user does not have the capability.
    if (!has_capability(
        'local/sitsgradepush:mapassessment',
        context_course::instance($formwrapper->get_course()->id))) {
        return;
    }

    $manager = manager::get_manager();
    // Display settings for certain type of activities only.
    $modulename = $formwrapper->get_current()->modulename;
    if (in_array($modulename, $manager->get_allowed_activities())) {
        // Add setting header.
        $mform->addElement('header', 'gradepushheader', 'Grade Push');

        // Autocomplete options.
        $options = [
            'multiple' => true,
            'noselectionstring' => get_string('option:none', 'local_sitsgradepush'),
        ];

        // Add autocomplete element to form.
        $select = $mform->addElement(
            'autocomplete',
            'gradepushassessmentselect',
            get_string('label:gradepushassessmentselect', 'local_sitsgradepush'),
            [],
            $options
        );

        // Add component grades options to the dropdown list.
        if (empty($manager->get_api_errors())) {
            // Get module deliveries with component grades data.
            $moduledeliveries = $manager->get_component_grade_options(
                $formwrapper->get_course()->id,
                $formwrapper->get_current()->coursemodule
            );

            $options = [];
            // Combine all component grades into a single array.
            if (!empty($moduledeliveries)) {
                foreach ($moduledeliveries as $moduledelivery) {
                    if (empty($moduledelivery->componentgrades)) {
                        continue;
                    }
                    $options = array_merge($options, $moduledelivery->componentgrades);
                }
            }

            if (empty($options)) {
                $mform->addElement(
                    'html',
                    "<p class=\"alert-info alert\">". get_string('form:alert_no_mab_found', 'local_sitsgradepush') ."</p>"
                );
            } else {
                foreach ($options as $option) {
                    $select->addOption($option->text, $option->value, [$option->selected]);
                }

                // Notify user multiple parts Turnitin assignment is not supported by Grade Push.
                if ($modulename === 'turnitintooltwo') {
                    $mform->addElement(
                        'html',
                        "<p class=\"alert-info alert\">". get_string('form:info_turnitin_numparts', 'local_sitsgradepush') ."</p>"
                    );
                }

                // Notify user this activity is not in the current academic year.
                if (!$manager->is_current_academic_year_activity($formwrapper->get_course()->id)) {
                    $mform->addElement(
                        'html',
                        "<p class=\"alert-info alert\">" . get_string('error:pastactivity', 'local_sitsgradepush') . "</p>"
                    );
                }
            }
        }

        // Disable the settings if this user does not have permission to map assessment.
        if (!has_capability(
            'local/sitsgradepush:mapassessment',
            context_course::instance($formwrapper->get_course()->id))) {
            $mform->addElement(
                'html',
                "<p class=\"alert-info alert\">" .
                get_string('error:mapassessment', 'local_sitsgradepush') .
                "</p>"
            );
            $select->updateAttributes(['disabled' => 'disabled']);
        }

        // Display any API error.
        foreach ($manager->get_api_errors() as $msg) {
            $mform->addElement('html', "<p class=\"alert alert-danger\">" . $msg . "</p>");
        }
    }
}

/**
 * Process data from submitted form.
 *
 * @param stdClass $data
 * @param stdClass $course
 * @return mixed
 * @throws dml_exception
 */
function local_sitsgradepush_coursemodule_edit_post_actions($data, $course) {
    $manager = manager::get_manager();
    // Save grade push settings if 'gradepushassessmentselect' is set.
    if (isset($data->gradepushassessmentselect) && is_array($data->gradepushassessmentselect)) {
        $manager->save_assessment_mappings($data);
    }

    return $data;
}

/**
 * Validate the data in the new field when the form is submitted
 *
 * @param moodleform_mod $fromform
 * @param array $fields
 * @return string[]|void
 * @throws dml_exception
 */
function local_sitsgradepush_coursemodule_validation($fromform, $fields) {
    $manager = manager::get_manager();
    // Extract activity type from form class name e.g. assign, quiz etc.
    $activitytype = explode('_', get_class($fromform));

    // Exit if the activity type is not allowed.
    if (!in_array($activitytype[1], $manager->get_allowed_activities())) {
        return;
    }

    // This field should be set if grade push is enabled and settings loaded.
    if (!isset($fields['gradepushassessmentselect']) || !is_array($fields['gradepushassessmentselect'])) {
        return;
    }

    // Remove any empty values.
    $componentgrades = array_filter($fields['gradepushassessmentselect']);

    // Validate component grades and return any error message.
    // Course module id is empty if this is a new activity.
    $coursemoduleid = $fields['coursemodule'] ?? null;
    $result = $manager->validate_component_grades($componentgrades, $coursemoduleid);
    if ($result->errormessages) {
        return ['gradepushassessmentselect' => implode('<br>', $result->errormessages)];
    }

    // For Turnitin assignment, check if the number of parts is greater than 1.
    if ($activitytype[1] === 'turnitintooltwo' && !empty($componentgrades)) {
        if ($fields['numparts'] > 1) {
            return ['numparts' => get_string('error:turnitin_numparts', 'local_sitsgradepush')];
        }
    }
}

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
    if (!in_array($cm->modname, manager::ALLOWED_ACTIVITIES)) {
        return null;
    }

    // Must have permission to push grade.
    if (!has_capability('local/sitsgradepush:pushgrade', $context)) {
        return null;
    }

    // Build the grade push page url.
    $url = new moodle_url('/local/sitsgradepush/index.php', [
        'id' => $cm->id,
        'modname' => $cm->modname,
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
