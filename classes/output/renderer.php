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

    /** @var string Push task status - has push records */
    const PUSH_STATUS_ICON_HAS_PUSH_RECORDS = 'has_push_records';

    /** @var string Push task status - no push records */
    const PUSH_STATUS_ICON_NO_PUSH_RECORDS = 'no_push_records';

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
     * Render a simple button.
     *
     * @param string $id Button ID
     * @param string $name Button name
     * @param string $disabled Disabled attribute
     * @param string $class Class attribute
     * @return string Rendered HTML
     * @throws \moodle_exception
     */
    public function render_button(string $id, string $name, string $disabled = '', string $class = '') : string {
        return $this->output->render_from_template(
            'local_sitsgradepush/button',
            ['id' => $id, 'name' => $name, 'disabled' => $disabled, 'class' => $class]
        );
    }

    /**
     * Render a simple link.
     *
     * @param string $id
     * @param string $name
     * @param string $url
     * @return string
     * @throws \moodle_exception
     */
    public function render_link(string $id, string $name, string $url) : string {
        return $this->output->render_from_template('local_sitsgradepush/link', ['id' => $id, 'name' => $name, 'url' => $url]);
    }

    /**
     * Render the marks transfer history page.
     *
     * @param array $assessmentdata Assessment data
     * @param int $courseid Course ID
     * @return string Rendered HTML
     * @throws \moodle_exception
     */
    public function render_marks_transfer_history_page(array $assessmentdata, int $courseid) : string {
        // Check if the user has the capability to see the submission log column.
        $showsublogcolumn = has_capability('local/sitsgradepush:showsubmissionlogcolumn', \context_course::instance($courseid));

        $mappingtables = [];
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

            $mappingtable = new \stdClass();
            $mappingtable->mappingid = $mapping->id;
            $mappingtable->markscount = $mapping->markscount ?? 0;
            $mappingtable->tabletitle = $mapping->formattedname;
            $mappingtable->students = $students;
            $mappingtable->showsublogcolumn = $showsublogcolumn;
            $mappingtables[] = $mappingtable;
        }

        // Handle invalid students.
        if (!empty($assessmentdata['invalidstudents']->students)) {
            $assessmentdata['invalidstudents']->tabletitle = $assessmentdata['invalidstudents']->formattedname;
            $assessmentdata['invalidstudents']->showsublogcolumn = $showsublogcolumn;
        }

        // Render the table.
        return $this->output->render_from_template('local_sitsgradepush/marks_transfer_history_page', [
            'module-delivery-tables' => $mappingtables,
            'transfer-all-button-label' => get_string('label:pushgrade', 'local_sitsgradepush'),
            'latest-transferred-text' => $this->get_latest_tranferred_text($assessmentdata['mappings']),
            'invalid-students' => !empty($assessmentdata['invalidstudents']->students) ? $assessmentdata['invalidstudents'] : null,
            'async' => get_config('local_sitsgradepush', 'async'),
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
    public function render_dashboard(array $moduledeliveries, int $courseid) : string {
        // Set default value for the select module delivery dropdown list.
        $options[] = (object) ['value' => 'none', 'name' => 'NONE'];

        $moduledeliverytables = [];
        // Prepare the content for each module delivery table.
        foreach ($moduledeliveries as $moduledelivery) {
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
                        ['courseid' => $courseid, 'mabid' => $componentgrade->id, 'source' => manager::SOURCE_EXISTING_ACTIVITY]
                    );
                    $componentgrade->selectsourceurl = $selectsourceurl->out(false);

                    // No course module ID means the MAB is not mapped to any activity.
                    if (empty($componentgrade->coursemoduleid)) {
                        // Disable the change source button and push grade button if the MAB is not mapped to any activity.
                        $componentgrade->disablechangesourcebutton = ' disabled';
                        continue;
                    }

                    // Get the assessment mapping status.
                    if ($coursemodule = get_coursemodule_from_id('', $componentgrade->coursemoduleid)) {
                        $assessmentdata = $this->manager->get_assessment_data(
                            $componentgrade->coursemoduleid,
                            $componentgrade->assessmentmappingid
                        );

                        $assessmentmapping = new \stdClass();
                        $assessmentmapping->markstotransfer = $assessmentdata->markscount ?? 0;
                        $assessmentmapping->id = $componentgrade->assessmentmappingid;
                        $assessmentmapping->type = get_module_types_names()[$coursemodule->modname];
                        $assessmentmapping->name = $coursemodule->name;
                        $coursemoduleurl = new \moodle_url(
                            '/mod/' . $coursemodule->modname . '/view.php',
                            ['id' => $coursemodule->id]
                        );
                        $assessmentmapping->url = $coursemoduleurl->out(false);
                        $transferhistoryurl = new \moodle_url('/local/sitsgradepush/index.php', ['id' => $coursemodule->id]);
                        $assessmentmapping->transferhistoryurl = $transferhistoryurl->out(false);
                        $componentgrade->assessmentmapping = $assessmentmapping;
                    } else {
                        throw new \moodle_exception('error:invalidcoursemoduleid', 'local_sitsgradepush');
                    }
                }
            }

            $moduledeliverytable = new \stdClass();
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
                'module-delivery-tables' => $moduledeliverytables,
                'jump-to-options' => $options,
                'jump-to-label' => get_string('label:jumpto', 'local_sitsgradepush'),
                'transfer-all-button-label' => get_string('label:pushall', 'local_sitsgradepush'),
            ]
        );
    }

    /**
     * Render the select existing activity page.
     *
     * @param array $param Parameters containing course ID and MAB ID
     * @return bool|string
     * @throws \dml_exception
     * @throws \moodle_exception
     */
    public function render_existing_activity_page(array $param): bool|string {
        // Make sure we have the required parameters.
        if (empty($param['courseid']) || empty($param['mabid'])) {
            throw new \moodle_exception('error:missingparams', 'local_sitsgradepush');
        }

        // Make sure the component grade exists.
        if (empty($componentgrade = $this->manager->get_local_component_grade_by_id($param['mabid']))) {
            throw new \moodle_exception('error:mab_not_found', 'local_sitsgradepush', '', $param['mabid']);
        }

        $formattedactivities = [];
        // Get all activities in the course.
        $activities = $this->manager->get_all_course_activities($param['courseid']);

        // Remove the currently mapped activity from the list.
        if ($assessmentmapping = $this->manager->is_component_grade_mapped($param['mabid'])) {
            foreach ($activities as $key => $activity) {
                if ($activity->get_coursemodule_id() == $assessmentmapping->coursemoduleid) {
                    unset($activities[$key]);
                }
            }
        }

        foreach ($activities as $activity) {
            // Skip activities mapped with the same map code.
            if (!empty($mappings = $this->manager->get_assessment_mappings($activity->get_coursemodule_id()))) {
                if (in_array($componentgrade->mapcode, array_column($mappings, 'mapcode'))) {
                    continue;
                }
            }

            $tempactivity = new \stdClass();
            $tempactivity->courseid = $param['courseid'];
            $tempactivity->coursemoduleid = $activity->get_coursemodule_id();
            $tempactivity->mabid = $param['mabid'];
            $tempactivity->mapcode = $componentgrade->mapcode;
            $tempactivity->mabseq = $componentgrade->mabseq;
            $tempactivity->type = $activity->get_module_type();
            $tempactivity->name = $activity->get_assessment_name();
            $tempactivity->startdate = !empty($activity->get_start_date()) ? date('d/m/Y H:i:s', $activity->get_start_date()) : '-';
            $tempactivity->enddate = !empty($activity->get_end_date()) ? date('d/m/Y H:i:s', $activity->get_end_date()) : '-';
            $formattedactivities[] = $tempactivity;
        }

        return $this->output->render_from_template('local_sitsgradepush/select_source_existing',
            ['activities' => $formattedactivities]);
    }

    /**
     * Get the last push result label.
     *
     * @param int|null $errortype
     * @return string
     */
    private function get_label_html(int $errortype = null) : string {
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
     * @throws \dml_exception
     */
    public function get_latest_tranferred_text(array $mappings): string {
        $lasttasktext = '';
        $lasttasktime = 0;
        foreach ($mappings as $mapping) {
            $lasttask = taskmanager::get_last_finished_push_task($mapping->id);
            if ($lasttask && $lasttask->timeupdated && $lasttask->timeupdated > $lasttasktime) {
                $lasttasktime = $lasttask->timeupdated;
                $lasttasktext = get_string(
                            'label:lastpushtext',
                            'local_sitsgradepush', [
                            'date' => date('d/m/Y', $lasttasktime),
                            'time' => date('g:i:s a', $lasttasktime), ]);
            }
        }

        return $lasttasktext;
    }
}
