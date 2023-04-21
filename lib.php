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
    $manager = manager::get_manager();
    // Display settings for certain type of activities only.
    $modulename = $formwrapper->get_current()->modulename;
    if (in_array($modulename, $manager->get_allowed_activities())) {
        // Add setting header.
        $mform->addElement('header', 'gradepushheader', 'Grade Push');

        // Add component grade dropdown list.
        $select = $mform->addElement(
            'select',
            'gradepushassessmentselect',
            get_string('label:gradepushassessmentselect', 'local_sitsgradepush'),
            ['0' => 'NONE']
        );
        $mform->setType('gradepushassessmentselect', PARAM_INT);
        $mform->addHelpButton('gradepushassessmentselect', 'gradepushassessmentselect', 'local_sitsgradepush');

        // Add component grades options to the dropdown list.
        $manager = manager::get_manager();
        if (empty($manager->get_api_errors())) {
            // Get component grade options.
            $options = $manager->get_component_grade_options($formwrapper->get_course()->id);
            if (empty($options)) {
                $mform->addElement(
                    'html',
                    "<p class=\"alert-info alert\">". get_string('form:alert_no_mab_found', 'local_sitsgradepush') ."</p>"
                );
            } else {
                foreach ($options as $option) {
                    $select->addOption($option->text, $option->value, $option->disabled);
                }

                // If it's a Turnitin assignment and more than one part, disable the dropdown list.
                if ($modulename === 'turnitintooltwo') {
                    $mform->addElement(
                        'html',
                        "<p class=\"alert-info alert\">". get_string('form:info_turnitin_numparts', 'local_sitsgradepush') ."</p>"
                    );
                    $mform->disabledIf('gradepushassessmentselect', 'numparts', 'gt', 1);
                }

                if ($cm = $formwrapper->get_coursemodule()) {
                    $disableselect = false;
                    // Disable the settings if this activity is already mapped.
                    if ($assessmentmapping = $manager->get_assessment_mapping($cm->id)) {
                        $select->setSelected($assessmentmapping->componentgradeid);
                        $disableselect = $disablereassessment = true;
                    } else {
                        if (!$manager->is_current_academic_year_activity($formwrapper->get_course()->id)) {
                            $mform->addElement(
                                'html',
                                "<p class=\"alert-info alert\">" . get_string('error:pastactivity', 'local_sitsgradepush') . "</p>"
                            );
                            $disableselect = true;
                        }
                    }

                    if ($disableselect) {
                        $select->updateAttributes(['disabled' => 'disabled']);
                    }
                }
            }
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
    // Save assessment mapping.
    if (!empty($data->gradepushassessmentselect)) {
        $manager->save_assessment_mapping($data);
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

    // Run check for component grade for unmapped activity only.
    if (!$manager->is_activity_mapped($fields['coursemodule'])) {
        // Check if the component grade has been mapped to another activity.
        if (in_array($activitytype[1], $manager->get_allowed_activities()) && !empty($fields['gradepushassessmentselect'])) {
            if ($manager->is_component_grade_mapped($fields['gradepushassessmentselect'])) {
                return ['gradepushassessmentselect' => get_string('error:gradecomponentmapped', 'local_sitsgradepush')];
            }
        }
    }

    // For Turnitin assignment, check if the number of parts is greater than 1.
    if ($activitytype[1] === 'turnitintooltwo' && !empty($fields['gradepushassessmentselect'])) {
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

    // Build the grade push page url.
    $url = new moodle_url('/local/sitsgradepush/index.php', array(
        'id' => $cm->id,
        'modname' => $cm->modname
    ));

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
