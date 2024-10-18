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

namespace local_sitsgradepush;

use context_user;
use local_sitsgradepush\assessment\assessmentfactory;

/**
 * Manager class which handles push task.
 *
 * @package    local_sitsgradepush
 * @copyright  2023 onwards University College London {@link https://www.ucl.ac.uk/}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @author     Alex Yeung <k.yeung@ucl.ac.uk>
 */
class taskmanager {

    /** @var int Push task status - requested */
    const PUSH_TASK_STATUS_REQUESTED = 0;

    /** @var int Push task status - queued */
    const PUSH_TASK_STATUS_QUEUED = 1;

    /** @var int Push task status - processing */
    const PUSH_TASK_STATUS_PROCESSING = 2;

    /** @var int Push task status - completed */
    const PUSH_TASK_STATUS_COMPLETED = 3;

    /** @var int Push task status - failed */
    const PUSH_TASK_STATUS_FAILED = -1;

    /**
     * Run push task.
     *
     * @param int $taskid
     * @throws \dml_exception|\moodle_exception
     */
    public static function run_task(int $taskid): void {
        global $DB;

        $manager = manager::get_manager();

        // Get the task.
        if (!$task = $DB->get_record('local_sitsgradepush_tasks', ['id' => $taskid])) {
            throw new \moodle_exception('error:tasknotfound', 'local_sitsgradepush');
        }

        // Check if the assessment mapping exists.
        $assessmentmapping = $DB->get_record(manager::TABLE_ASSESSMENT_MAPPING, ['id' => $task->assessmentmappingid]);
        if (!$assessmentmapping) {
            throw new \moodle_exception('error:assessmentmapping', 'local_sitsgradepush', '', $task->assessmentmappingid);
        }

        // Log start.
        mtrace(date('Y-m-d H:i:s', time()) . ' : ' . 'Processing push task [#' . $taskid . ']');

        // Update task status to processing.
        self::update_task_status($task->id, self::PUSH_TASK_STATUS_PROCESSING);

        // Get the assessment data.
        if ($mapping = $manager->get_assessment_data(
            $assessmentmapping->sourcetype, $assessmentmapping->sourceid, $assessmentmapping->id)) {
            if (!empty($mapping->students)) {
                // Number of students in the mapping.
                $numberofstudents = count($mapping->students);

                // Update when progress is a multiple of 10%.
                $progressincrement = 10;
                $lastupdatedprogress = 0;

                $i = 0;
                // Push mark and submission log for each student in the mapping.
                foreach ($mapping->students as $student) {
                    $manager->push_grade_to_sits($mapping, $student->userid, $task);
                    $manager->push_submission_log_to_sits($mapping, $student->userid, $task->id);
                    $i++;

                    // Calculate progress.
                    $progress = ($i) / $numberofstudents * 100;

                    // Check if progress has passed the next multiple of 10%.
                    if (floor($progress / $progressincrement) > floor($lastupdatedprogress / $progressincrement)) {
                        // Update database.
                        self::update_task_progress($task->id, floor($progress));
                        $lastupdatedprogress = $progress;
                    }
                }
            }
        }

        // Log complete.
        mtrace(date('Y-m-d H:i:s', time()) . ' : ' . 'Completed push task [#' . $taskid . ']');

        // Update progress to 100%.
        self::update_task_progress($task->id, 100);

        // Update task status.
        self::update_task_status($task->id, self::PUSH_TASK_STATUS_COMPLETED);
    }

    /**
     * Update task progress.
     *
     * @param int $taskid
     * @param int $progress
     * @throws \dml_exception
     */
    public static function update_task_progress(int $taskid, int $progress) {
        global $DB;
        $DB->set_field('local_sitsgradepush_tasks', 'progress', $progress, ['id' => $taskid]);
    }

    /**
     * Get number of running tasks.
     *
     * @return int
     * @throws \dml_exception
     */
    public static function get_number_of_running_tasks(): int {
        global $DB;
        return $DB->count_records('local_sitsgradepush_tasks', ['status' => self::PUSH_TASK_STATUS_PROCESSING]);
    }

    /**
     * Get last finished push task for a course module.
     *
     * @param int $assessmentmappingid Assessment mapping id
     * @return false|mixed Returns false if no task found, otherwise return the task object with status text.
     * @throws \dml_exception|\coding_exception
     */
    public static function get_last_finished_push_task(int $assessmentmappingid): mixed {
        global $DB;

        // Get the last task for the course module.
        $sql = 'SELECT *
                FROM {' . manager::TABLE_TASKS . '}
                WHERE assessmentmappingid = :assessmentmappingid AND status IN (:status1, :status2)
                ORDER BY id DESC
                LIMIT 1';

        $params = [
            'assessmentmappingid' => $assessmentmappingid,
            'status1' => self::PUSH_TASK_STATUS_COMPLETED,
            'status2' => self::PUSH_TASK_STATUS_FAILED,
        ];

        // Add status text to the task object.
        if ($task = $DB->get_record_sql($sql, $params)) {
            switch ($task->status) {
                case self::PUSH_TASK_STATUS_COMPLETED:
                    $task->statustext = get_string('task:status:completed', 'local_sitsgradepush');
                    break;
                case self::PUSH_TASK_STATUS_FAILED:
                    $task->statustext = get_string('task:status:failed', 'local_sitsgradepush');
                    break;
            }

            return $task;
        } else {
            return false;
        }
    }

    /**
     * Get the last push task time.
     *
     * @param int $assessmentmappingid Assessment mapping ID
     * @return int|null Last push task time
     * @throws \coding_exception
     * @throws \dml_exception
     */
    public static function get_last_push_task_time(int $assessmentmappingid): ?int {
        if ($lasttask = self::get_last_finished_push_task($assessmentmappingid)) {
            if (!empty($lasttask->timeupdated)) {
                return $lasttask->timeupdated;
            }
        }

        return null;
    }

    /**
     * Returns push tasks for a given status.
     *
     * @param int $status
     * @param int $limit
     * @return array
     * @throws \dml_exception
     */
    public static function get_push_tasks(int $status, int $limit = 0): array {
        global $DB;
        return $DB->get_records('local_sitsgradepush_tasks', ['status' => $status], 'timescheduled ASC', '*', 0, $limit);
    }

    /**
     * Update task status.
     *
     * @param int $taskid
     * @param int $status
     * @param int|null $errlogid
     * @throws \dml_exception
     */
    public static function update_task_status(int $taskid, int $status, ?int $errlogid = null) {
        global $DB;

        $task = $DB->get_record('local_sitsgradepush_tasks', ['id' => $taskid]);
        $task->status = $status;
        $task->timeupdated = time();
        $task->errlogid = $errlogid;
        $DB->update_record('local_sitsgradepush_tasks', $task);
    }

    /**
     * Schedule push task.
     *
     * @param int $assessmentmappingid Assessment mapping id
     * @param array $options Extra options for the task, e.g. records non-submission as zero.
     *
     * @return bool
     * @throws \dml_exception
     * @throws \moodle_exception
     */
    public static function schedule_push_task(int $assessmentmappingid, array $options) {
        global $DB, $USER;

        // Check if the assessment mapping exists.
        $mapping = $DB->get_record(manager::TABLE_ASSESSMENT_MAPPING, ['id' => $assessmentmappingid]);
        if (!$mapping) {
            throw new \moodle_exception('error:assessmentmapping', 'local_sitsgradepush', '', $assessmentmappingid);
        }

        // Check if user has permission to transfer marks.
        if (!has_capability('local/sitsgradepush:pushgrade', \context_course::instance($mapping->courseid))) {
            throw new \moodle_exception('error:pushgradespermission', 'local_sitsgradepush');
        }

        // Check if there is already in one of the following status: added, queued, processing.
        if (self::get_pending_task_in_queue($mapping->id)) {
            throw new \moodle_exception('error:duplicatedtask', 'local_sitsgradepush');
        }

        // Check if the assessment component exists.
        if (!$DB->record_exists('local_sitsgradepush_mab', ['id' => $mapping->componentgradeid])) {
            throw new \moodle_exception('error:mab_not_found', 'local_sitsgradepush', '', $mapping->componentgradeid);
        }

        if (grade_needs_regrade_final_grades($mapping->courseid)) {
            throw new \moodle_exception('error:gradesneedregrading', 'local_sitsgradepush');
        }

        // Create and insert the task.
        $task = new \stdClass();
        $task->userid = $USER->id;
        $task->timescheduled = time();
        $task->assessmentmappingid = $assessmentmappingid;
        $task->options = json_encode($options);
        $task->status = self::PUSH_TASK_STATUS_REQUESTED;

        // Check the number of students in the mapping.
        $mapping = manager::get_manager()->get_assessment_data($mapping->sourcetype, $mapping->sourceid, $assessmentmappingid);

        // Check if the mapping has valid students for mark transfer.
        if (empty($mapping->students)) {
            throw new \moodle_exception('error:nostudentfoundformapping', 'local_sitsgradepush');
        }

        // Check grade item is valid for mark transfer.
        $validity = $mapping->source->check_assessment_validity();
        if (!$validity->valid) {
            throw new \moodle_exception($validity->errorcode, 'local_sitsgradepush');
        }

        // Failed to insert the task.
        if (!$DB->insert_record('local_sitsgradepush_tasks', $task)) {
            throw new \moodle_exception('error:inserttask', 'local_sitsgradepush');
        }

        return true;
    }

    /**
     * Get push task in status requested, queued or processing for a course module.
     *
     * @param int $assessmentmappingid Assessment mapping id
     * @return \stdClass|bool false if no task found, otherwise return the task object with button label.
     * @throws \coding_exception|\dml_exception
     */
    public static function get_pending_task_in_queue(int $assessmentmappingid): bool|\stdClass {
        global $DB;

        $sql = 'SELECT *
                FROM {' . manager::TABLE_TASKS . '}
                WHERE assessmentmappingid = :assessmentmappingid AND status IN (:status1, :status2, :status3)
                ORDER BY id DESC';
        $params = [
            'assessmentmappingid' => $assessmentmappingid,
            'status1' => self::PUSH_TASK_STATUS_REQUESTED,
            'status2' => self::PUSH_TASK_STATUS_QUEUED,
            'status3' => self::PUSH_TASK_STATUS_PROCESSING,
        ];

        // Add button label to the task object.
        if ($result = $DB->get_record_sql($sql, $params)) {
            switch ($result->status) {
                case self::PUSH_TASK_STATUS_REQUESTED:
                    $result->buttonlabel = get_string('task:status:requested', 'local_sitsgradepush');
                    break;
                case self::PUSH_TASK_STATUS_QUEUED:
                    $result->buttonlabel = get_string('task:status:queued', 'local_sitsgradepush');
                    break;
                case self::PUSH_TASK_STATUS_PROCESSING:
                    $result->buttonlabel = get_string('task:status:processing', 'local_sitsgradepush');
                    break;
            }

            return $result;
        } else {
            return false;
        }
    }

    /**
     * Email user the result of the task.
     *
     * @param int $taskid
     * @return void
     * @throws \coding_exception
     * @throws \dml_exception
     * @throws \moodle_exception
     */
    public static function send_email_notification(int $taskid): void {
        global $DB, $PAGE, $OUTPUT;

        // Get the content of the task.
        $sql = 'SELECT
                t.id as taskid,
                t.userid,
                am.id as assessmentmappingid,
                am.sourcetype,
                am.sourceid,
                CONCAT(cg.mapcode, \'-\', cg.mabseq) AS mab,
                cg.mabname
                FROM {' . manager::TABLE_TASKS . '} t
                JOIN {' . manager::TABLE_ASSESSMENT_MAPPING . '} am ON t.assessmentmappingid = am.id
                JOIN {' . manager::TABLE_COMPONENT_GRADE . '} cg ON am.componentgradeid = cg.id
                WHERE t.id = :taskid';

        $params = [
            'taskid' => $taskid,
        ];

        // Task content found.
        if ($result = $DB->get_record_sql($sql, $params)) {
            // Get the assessment.
            $assessment = assessmentfactory::get_assessment($result->sourcetype, $result->sourceid);

            // Get the user who scheduled the task.
            $user = $DB->get_record('user', ['id' => $result->userid]);
            $PAGE->set_context(context_user::instance($user->id));

            // Get transfer records for the task.
            $transferrecords = $DB->get_records(manager::TABLE_TRANSFER_LOG, ['taskid' => $taskid, 'type' => manager::PUSH_GRADE]);

            // Separate succeeded and failed transfer records.
            $succeededcount = 0;
            $failedcount = 0;
            foreach ($transferrecords as $transferrecord) {
                $response = json_decode($transferrecord->response);
                if ($response->code == "0") {
                    $succeededcount++;
                } else {
                    $failedcount++;
                }
            }

            // Render the email content.
            $content = $OUTPUT->render_from_template('local_sitsgradepush/notification_email', [
                'user_name' => fullname($user),
                'assessment_type' => $assessment->get_display_type_name(),
                'assessment_name' => $assessment->get_assessment_name(),
                'map_code' => $result->mab,
                'sits_assessment' => $result->mabname,
                'activity_url' => $assessment->get_assessment_transfer_history_url(false),
                'support_url' => get_config('local_sitsgradepush', 'support_page_url') ?? '',
                'succeeded_count' => $succeededcount,
                'failed_count' => $failedcount,
            ]);
            email_to_user($user, $user, get_string('email:subject', 'local_sitsgradepush', $result->mab), $content);
        } else {
            throw new \moodle_exception('error:tasknotfound', 'local_sitsgradepush');
        }
    }
}
