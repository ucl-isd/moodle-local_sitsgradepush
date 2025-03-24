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

namespace local_sitsgradepush\output;

use local_sitsgradepush\assessment\unknownassessment;
use local_sitsgradepush\assessment\gradebook;
use local_sitsgradepush\errormanager;
use local_sitsgradepush\extensionmanager;
use local_sitsgradepush\manager;
use local_sitsgradepush\taskmanager;
use moodle_page;
use plugin_renderer_base;

/**
 * Output renderer for local_sitsgradepush.
 *
 * @package    local_sitsgradepush
 * @copyright  2023 onwards University College London {@link https://www.ucl.ac.uk/}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @author     Alex Yeung <k.yeung@ucl.ac.uk>
 */
class renderer extends plugin_renderer_base {

    /** @var manager|null Manager */
    private ?manager $manager;

    /**
     * Constructor.
     *
     * @param moodle_page $page
     * @param string $target
     */
    public function __construct(moodle_page $page, $target) {
        parent::__construct($page, $target);
        $this->manager = manager::get_manager();
    }

    /**
     * Render the marks transfer history page.
     *
     * @param array $assessmentdata Assessment data
     * @param int $courseid Course ID
     * @return string Rendered HTML
     * @throws \moodle_exception
     */
    public function render_marks_transfer_history_page(array $assessmentdata, int $courseid): string {
        // Check if the user has the capability to see the submission log column.
        $showsublogcolumn = has_capability('local/sitsgradepush:showsubmissionlogcolumn', \context_course::instance($courseid));

        // Check if the course is in the current academic year.
        $iscurrentacademicyear = $this->manager->is_current_academic_year_activity($courseid);

        $mappingtables = [];
        $totalmarkscount = 0;
        $runningtasks = [];
        foreach ($assessmentdata['mappings'] as $mapping) {
            // Remove current academic year restriction for re-assessment.
            // A source can only be either normal or re-assessment, not both.
            if ($mapping->reassessment == 1) {
                $iscurrentacademicyear = true;
            }

            $students = null;
            // Modify the timestamp format and add the label for the last push result.
            if (!empty($mapping->students)) {
                foreach ($mapping->students as &$data) {
                    // Remove the T character in the timestamp.
                    $data->handindatetime = str_replace('T', ' ', $data->handindatetime);
                    // Add the label for the last push result.
                    $data->lastgradepushresultlabel =
                        is_null($data->lastgradepushresult) ? '' : $this->get_label_html($data->lastgradepusherrortype);
                    // Add the label for the last submission log push result.
                    $data->lastsublogpushresultlabel =
                        is_null($data->lastsublogpushresult) ? '' : $this->get_label_html($data->lastsublogpusherrortype);
                }
                $students = $mapping->students;
            }

            // Get the total marks count.
            $totalmarkscount += $mapping->markscount;

            // Add the mapping table.
            $mappingtable = new \stdClass();
            $mappingtable->mappingid = $mapping->id;
            $mappingtable->markscount = $mapping->markscount ?? 0;
            $mappingtable->tabletitle = $mapping->formattedname;
            $mappingtable->students = $students;
            $mappingtable->showsublogcolumn = $showsublogcolumn;
            $mappingtable->taskrunning = false;
            $mappingtable->taskprogress = 0;
            $mappingtable->warningmessage = $this->get_warning_message_for_history_page_table($mapping, $courseid);

            // Check if there is a task running for the assessment mapping.
            if ($taskrunning = taskmanager::get_pending_task_in_queue($mapping->id)) {
                $runningtasks[$mapping->id] = $taskrunning;
                $mappingtable->taskrunning = true;
                $mappingtable->taskprogress = $taskrunning->progress ?: 0;
            }
            $mappingtables[] = $mappingtable;
        }

        // Handle invalid students.
        if (!empty($assessmentdata['invalidstudents']->students)) {
            $assessmentdata['invalidstudents']->tabletitle = $assessmentdata['invalidstudents']->formattedname;
            $assessmentdata['invalidstudents']->showsublogcolumn = $showsublogcolumn;
            $assessmentdata['invalidstudents']->taskrunning = false;
        }

        // Sync threshold.
        $syncthreshold = get_config('local_sitsgradepush', 'sync_threshold');

        // Render the table.
        return $this->output->render_from_template('local_sitsgradepush/marks_transfer_history_page', [
            'currentacademicyear' => $iscurrentacademicyear,
            'module-delivery-tables' => $mappingtables,
            'transfer-all-button-label' => get_string('label:pushgrade', 'local_sitsgradepush'),
            'latest-transferred-text' => $this->get_latest_tranferred_text($assessmentdata['mappings']),
            'invalid-students' => !empty($assessmentdata['invalidstudents']->students) ? $assessmentdata['invalidstudents'] : null,
            'sync' => $totalmarkscount <= $syncthreshold ? 1 : 0,
            'taskrunning' => !empty($runningtasks),
            'gradesneedregrading' => grade_needs_regrade_final_grades($courseid),
        ]);
    }

    /**
     * Render the sits grade push dashboard.
     *
     * @param array $moduledeliveries Module deliveries
     * @param int $courseid Course ID
     * @param int $reassess Reassessment flag
     * @return string Rendered HTML
     * @throws \coding_exception
     * @throws \dml_exception
     * @throws \moodle_exception
     */
    public function render_dashboard(array $moduledeliveries, int $courseid, int $reassess): string {
        // Set default value for the select module delivery dropdown list.
        $options[] = (object) ['value' => 'none', 'name' => 'NONE'];

        // Check if the course is in the current academic year.
        $iscurrentacademicyear = $this->manager->is_current_academic_year_activity($courseid);

        // Remove the current academic year restriction for re-assessment.
        if ($reassess == 1) {
            $iscurrentacademicyear = true;
        }

        $moduledeliverytables = [];

        // For user tour.
        $tableno = 0;

        // Prepare the content for each module delivery table.
        foreach ($moduledeliveries as $moduledelivery) {
            $tableno++;
            // Set options for the select module delivery dropdown list.
            $tableid = sprintf(
                '%s-%s-%s-%s',
                $moduledelivery->modcode,
                $moduledelivery->modocc,
                $moduledelivery->periodslotcode,
                $moduledelivery->academicyear
            );
            $option = new \stdClass();
            $option->value = $option->name = $tableid;
            $options[] = $option;

            // Add assessment mapping info if any.
            $componentgrades = [];
            if (!empty($moduledelivery->componentgrades) && is_array($moduledelivery->componentgrades)) {
                $componentgrades = array_values($moduledelivery->componentgrades);
                foreach ($componentgrades as $componentgrade) {
                    // Add the select source url.
                    $selectsourceurl = new \moodle_url(
                        '/local/sitsgradepush/select_source.php',
                        ['courseid' => $courseid,
                         'mabid' => $componentgrade->id,
                         'reassess' => $reassess,
                        ]
                    );
                    $componentgrade->selectsourceurl = $selectsourceurl->out(false);

                    // No need to render mapping information if the component grade is not mapped
                    // for the given marks transfer type.
                    $mapping  = $this->manager->is_component_grade_mapped($componentgrade->id, $reassess);
                    if (empty($mapping)) {
                        continue;
                    }

                    // Get the assessment mapping status.
                    $assessmentdata = $this->manager->get_assessment_data(
                        $mapping->sourcetype,
                        $mapping->sourceid,
                        $mapping->id
                    );
                    $assessmentmapping = new \stdClass();
                    $assessmentmapping->sourcenotfound = $assessmentdata->source instanceof unknownassessment; // Source not found.
                    $assessmentmapping->markstotransfer = $assessmentdata->markscount ?? 0;
                    $assessmentmapping->nonsubmittedcount = $assessmentdata->nonsubmittedcount ?? 0;
                    $assessmentmapping->id = $mapping->id;
                    $assessmentmapping->type = $assessmentdata->source->get_display_type_name();
                    $assessmentmapping->name = $assessmentdata->source->get_assessment_name();
                    $assessmentmapping->url = $assessmentdata->source->get_assessment_url(false);
                    $assessmentmapping->transferhistoryurl = $assessmentdata->source->get_assessment_transfer_history_url(false);
                    $assessmentmapping->removesourceurl =
                        $this->get_remove_source_url($courseid, $mapping->id, $reassess)->out(false);

                    // Check if there is a task running for the assessment mapping.
                    $taskrunning = taskmanager::get_pending_task_in_queue($mapping->id);
                    $assessmentmapping->taskrunning = !empty($taskrunning);
                    $assessmentmapping->taskprogress = $taskrunning && $taskrunning->progress ? $taskrunning->progress : 0;

                    // Disable the change source button / hide the remove source button
                    // if grades have been pushed or there is a task running.
                    $assessmentmapping->disablechangesource = $assessmentmapping->hideremovesourcebutton =
                        !empty($taskrunning) || $this->manager->has_grades_pushed($mapping->id);

                    // Extension eligibility.
                    $removeextensionwarning = get_string('dashboard:remove_btn_content_extension', 'local_sitsgradepush');
                    if (extensionmanager::is_extension_eligible($componentgrade, $assessmentdata->source, $mapping)) {
                        $componentgrade->extensioneligible = new \stdClass();
                        $componentgrade->extensioneligible->overrideurl =
                            $assessmentdata->source->get_overrides_page_url('group', false);
                        $componentgrade->extensioneligible->extensioninfourl =
                            get_config('local_sitsgradepush', 'extension_support_page_url');
                        $assessmentmapping->removeextensionwarning = $removeextensionwarning;
                    } else if ($assessmentdata->source->has_sora_override_groups()) {
                        // Extension is not eligible, e.g. the extension is disabled.
                        // Still add remove extension warning if there is automated SORA override groups created for the activity.
                        $assessmentmapping->removeextensionwarning = $removeextensionwarning;
                    }
                    $componentgrade->assessmentmapping = $assessmentmapping;
                }
            }

            $moduledeliverytable = new \stdClass();
            $moduledeliverytable->tableno = $tableno;
            $moduledeliverytable->tableid = $tableid;
            $moduledeliverytable->moduledelivery = $tableid;
            $moduledeliverytable->academicyear = $moduledelivery->academicyear;
            $moduledeliverytable->level = $moduledelivery->level;
            $moduledeliverytable->graduatetype = $moduledelivery->graduatetype;
            $moduledeliverytable->mapcode = $moduledelivery->mapcode;
            $moduledeliverytable->componentgrades = $componentgrades;

            $moduledeliverytables[] = $moduledeliverytable;
        }

        return $this->output->render_from_template(
            'local_sitsgradepush/dashboard',
            [
                'currentacademicyear' => $iscurrentacademicyear,
                'module-delivery-tables' => $moduledeliverytables,
                'jump-to-options' => $options,
                'jump-to-label' => get_string('label:jumpto', 'local_sitsgradepush'),
                'transfer-all-button-label' => get_string('label:pushall', 'local_sitsgradepush'),
                'gradesneedregrading' => grade_needs_regrade_final_grades($courseid),
                'recordnonsubmission' => true, // Show the record non-submission as 0 AB checkbox.
            ]
        );
    }

    /**
     * Render the select source page.
     *
     * @param int $courseid
     * @param \stdClass $mab
     * @param int $reassess
     *
     * @return string
     * @throws \moodle_exception
     */
    public function render_select_source_page(int $courseid, \stdClass $mab, int $reassess): string {
        $validassessments = [];

        // Get all valid activities.
        $activities = $this->manager->get_all_course_activities($courseid);

        if (get_config('local_sitsgradepush', 'gradebook_enabled')) {
            // Get grade book assessments, e.g. grade items, grade categories.
            $gradebookassessments = $this->manager->get_gradebook_assessments($courseid);

            $assessments  = array_merge($activities, $gradebookassessments);
        } else {
            $assessments = $activities;
        }

        // Create the select source page.
        foreach ($assessments as $assessment) {
            if (!$assessment->can_map_to_mab($mab->id, $reassess)) {
                continue;
            }

            $formattedassessment = new \stdClass();
            $formattedassessment->courseid = $assessment->get_course_id();
            $formattedassessment->mabid = $mab->id;
            $formattedassessment->mapcode = $mab->mapcode;
            $formattedassessment->mabseq = $mab->mabseq;
            $formattedassessment->sourcetype = $assessment->get_type();
            $formattedassessment->sourceid = $assessment->get_id();
            $formattedassessment->type = $assessment->get_display_type_name();
            $formattedassessment->name = $assessment->get_assessment_name();
            $formattedassessment->startdate =
                !empty($assessment->get_start_date()) ? date('d/m/Y H:i:s', $assessment->get_start_date()) : '-';
            $formattedassessment->enddate =
                !empty($assessment->get_end_date()) ? date('d/m/Y H:i:s', $assessment->get_end_date()) : '-';
            $formattedassessment->reassess = $reassess;
            $validassessments[] = $formattedassessment;
        }

        return $this->output->render_from_template('local_sitsgradepush/select_source',
            ['assessments' => $validassessments]);
    }

    /**
     * Get the last push result label.
     *
     * @param int|null $errortype
     * @return string
     */
    private function get_label_html(?int $errortype = null): string {
        // This is for old data that does not have the error type.
        if (is_null($errortype)) {
            return '<span class="badge badge-danger">'.errormanager::get_error_label(errormanager::ERROR_UNKNOWN).'</span> ';
        }

        // Return different label based on the error type.
        switch ($errortype) {
            case 0:
                // Success result will have the error type 0.
                return '<span class="badge badge-success">Success</span> ';
            case errormanager::ERROR_STUDENT_NOT_ENROLLED:
                // Student not enrolled error will have a warning label.
                return '<span class="badge badge-warning">'.errormanager::get_error_label($errortype).'</span> ';
            default:
                // Other errors will have a danger label.
                return '<span class="badge badge-danger">'.errormanager::get_error_label($errortype).'</span> ';
        }
    }

    /**
     * Get the latest transferred text for the transfer history page.
     *
     * @param array $mappings
     * @return string
     * @throws \coding_exception
     */
    public function get_latest_tranferred_text(array $mappings): string {
        $lasttasktext = '';
        $lasttasktime = 0;

        // Get the latest transferred time among all transfer records.
        foreach ($mappings as $mapping) {
            // Skip if there is no student.
            if (empty($mapping->students)) {
                continue;
            }
            foreach ($mapping->students as $student) {
                if ($student->lastgradepushtime && $student->lastgradepushtime > $lasttasktime) {
                    $lasttasktime = $student->lastgradepushtime;
                }
            }
        }

        if ($lasttasktime > 0) {
            $lasttasktext = get_string(
                'label:lastpushtext',
                'local_sitsgradepush', [
                'date' => date('d/m/Y', $lasttasktime),
                'time' => date('g:i:s a', $lasttasktime), ]);
        }

        return $lasttasktext;
    }

    /**
     * Print the dashboard selector.
     *
     * @param  \moodle_url $url
     * @param  int $reassess
     *
     * @return string
     * @throws \coding_exception
     * @throws \moodle_exception
     */
    public function print_dashboard_selector(\moodle_url $url, int $reassess): string {
        // Do not show the selector if the reassessment feature is disabled.
        if (get_config('local_sitsgradepush', 'reassessment_enabled') !== '1') {
            return '';
        }

        $activeurl = $url->out(false);
        if ($reassess == 1) {
            $reassessdashboardurl = $url->out(false);
            $url->remove_params(['reassess']);
            $maindashboardurl = $url->out(false);
        } else {
            $maindashboardurl = $url->out(false);
            $url->params(['reassess' => 1]);
            $reassessdashboardurl = $url->out(false);
        }

        $menuarray = [
          $maindashboardurl => get_string('dashboard:main_page', 'local_sitsgradepush'),
          $reassessdashboardurl => get_string('dashboard:reassessment_page', 'local_sitsgradepush'),
        ];

        $selectmenu = new \core\output\select_menu('dashboardtype', $menuarray, $activeurl);
        $selectmenu->set_label(get_string('dashboard:type', 'local_sitsgradepush'), ['class' => 'sr-only']);
        $options = \html_writer::tag(
          'div',
          $this->output->render_from_template(
            'core/tertiary_navigation_selector',
            $selectmenu->export_for_template($this->output)
          ),
          ['class' => 'navitem']
        );

        return \html_writer::tag(
          'div',
          $options,
          [
            'class' => 'tertiary-navigation border-bottom mb-2 d-flex',
            'id'    => 'tertiary-navigation',
          ]
        );
    }

    /**
     * Render the warning message for the history page table.
     *
     * @param \stdClass $mapping
     * @param int $courseid
     *
     * @return string
     * @throws \coding_exception
     */
    private function get_warning_message_for_history_page_table(\stdClass $mapping, int $courseid): string {
        $warningmessage = '';

        // No students.
        if (empty($mapping->students)) {
            $warningmessage = get_string('error:nostudentfoundformapping', 'local_sitsgradepush');
        }

        // While course is regrading, no grade is shown for grade item / grade category on the history page.
        if ($mapping->source instanceof gradebook && grade_needs_regrade_final_grades($courseid)) {
            $warningmessage = get_string('error:cannotdisplaygradesforgradebookwhileregrading', 'local_sitsgradepush');
        }

        return $warningmessage;
    }

    /**
     * Get the remove source URL.
     *
     * @param int $courseid Course ID
     * @param int $mapid Assessment mapping ID
     * @param int $reassess Reassessment flag
     *
     * @return \moodle_url
     */
    private function get_remove_source_url(int $courseid, int $mapid, int $reassess): \moodle_url {
        $params = ['id' => $courseid, 'mapid' => $mapid, 'action' => 'removesource'];
        if ($reassess == 1) {
            $params['reassess'] = 1;
        }
        return new \moodle_url(
            '/local/sitsgradepush/dashboard.php',
            $params
        );
    }
}
