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
    $manager = manager::getmanager();
    // Display settings for certain type of activities only.
    $modulename = $formwrapper->get_current()->modulename;
    if (in_array($modulename, $manager->getallowedactivities())) {
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

        // Add re-assessment dropdown list.
        $reassessment = $mform->addElement(
            'select',
            'reassessment',
            get_string('label:reassessmentselect', 'local_sitsgradepush'),
            ['0' => 'NO', '1' => 'YES']
        );
        $mform->setType('reassessment', PARAM_INT);
        $mform->addHelpButton('reassessment', 'reassessmentselect', 'local_sitsgradepush');

        // Add component grades options to the dropdown list.
        $manager = manager::getmanager();
        if (empty($manager->getapierrors())) {
            // Get component grade options.
            $options = $manager->getcomponentgradeoptions($formwrapper->get_course()->id);
            if (empty($options)) {
                $reassessment->updateAttributes(['disabled' => 'disabled']);
                $mform->addElement('html', "<p class=\"alert-info alert\">No lookup records were found </p>");
            } else {
                foreach ($options as $option) {
                    $select->addOption($option->text, $option->value, $option->disabled);
                }
                if ($cm = $formwrapper->get_coursemodule()) {
                    // Disable the settings if this activity is already mapped.
                    if ($assessmentmapping = $manager->getassessmentmapping($cm->id, $cm->module)) {
                        $select->setSelected($assessmentmapping->componentgradeid);
                        $reassessment->setSelected($assessmentmapping->reassessment);
                        $select->updateAttributes(['disabled' => 'disabled']);
                        $reassessment->updateAttributes(['disabled' => 'disabled']);
                    }
                }
            }
        }

        // Display any API error.
        foreach ($manager->getapierrors() as $msg) {
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
    $manager = manager::getmanager();
    // Save assessment mapping.
    if (!empty($data->gradepushassessmentselect)) {
        $manager->saveassessmentmapping($data);
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
    $manager = manager::getmanager();
    // Extract activity type from form class name e.g. assign, quiz etc.
    $activitytype = explode('_', get_class($fromform));

    // Check if the component grade has been mapped to another activity.
    if (in_array($activitytype[1], $manager->getallowedactivities()) && !empty($fields['gradepushassessmentselect'])) {
        if ($manager->iscomponentgrademapped($fields['gradepushassessmentselect'])) {
            return ['gradepushassessmentselect' => get_string('error:gradecomponentmapped', 'local_sitsgradepush')];
        }
    }
}
