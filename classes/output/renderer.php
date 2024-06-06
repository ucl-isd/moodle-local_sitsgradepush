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

use local_sitsgradepush\errormanager;
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
        ]);
    }

    /**
     * Render the sits grade push dashboard.
     *
     * @param array $moduledeliveries Module deliveries
     * @param int $courseid Course ID
     * @return string Rendered HTML
     * @throws \coding_exception
     * @throws \dml_exception
     * @throws \moodle_exception
     */
    public function render_dashboard(array $moduledeliveries, int $courseid): string {
        // Set default value for the select module delivery dropdown list.
        $options[] = (object) ['value' => 'none', 'name' => 'NONE'];

        // Check if the course is in the current academic year.
        $iscurrentacademicyear = $this->manager->is_current_academic_year_activity($courseid);
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
                        ['courseid' => $courseid, 'mabid' => $componentgrade->id]
                    );
                    $componentgrade->selectsourceurl = $selectsourceurl->out(false);

                    // No assessment mapping id means the MAB is not mapped to any activity.
                    if (empty($componentgrade->assessmentmappingid)) {
                        continue;
                    }

                    // Get the assessment mapping status.
                    $assessmentdata = $this->manager->get_assessment_data(
                        $componentgrade->sourcetype,
                        $componentgrade->sourceid,
                        $componentgrade->assessmentmappingid
                    );

                    $assessmentmapping = new \stdClass();
                    $assessmentmapping->markstotransfer = $assessmentdata->markscount ?? 0;
                    $assessmentmapping->id = $componentgrade->assessmentmappingid;
                    $assessmentmapping->type = $assessmentdata->source->get_display_type_name();
                    $assessmentmapping->name = $assessmentdata->source->get_assessment_name();
                    $assessmentmapping->url = $assessmentdata->source->get_assessment_url(false);
                    $assessmentmapping->transferhistoryurl = $assessmentdata->source->get_assessment_transfer_history_url(false);

                    // Check if there is a task running for the assessment mapping.
                    $taskrunning = taskmanager::get_pending_task_in_queue($componentgrade->assessmentmappingid);
                    $assessmentmapping->taskrunning = !empty($taskrunning);
                    $assessmentmapping->taskprogress = $taskrunning && $taskrunning->progress ? $taskrunning->progress : 0;

                    // Disable the change source button if there is a task running.
                    $assessmentmapping->disablechangesource =
                        !empty($taskrunning) || $this->manager->has_grades_pushed($componentgrade->assessmentmappingid);

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
            ]
        );
    }

    /**
     * Render the select source page.
     *
     * @param int $courseid
     * @param \stdClass $mab
     * @return string
     * @throws \moodle_exception
     */
    public function render_select_source_page(int $courseid, \stdClass $mab): string {
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
            if (!$assessment->can_map_to_mab($mab->id)) {
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
}
