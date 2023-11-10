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
use local_sitsgradepush\submission\submissionfactory;

/**
 * Push record object for display in the grade push page.
 *
 * @package    local_sitsgradepush
 * @copyright  2023 onwards University College London {@link https://www.ucl.ac.uk/}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @author     Alex Yeung <k.yeung@ucl.ac.uk>
 */
class pushrecord {
    /** @var string SITS component grade */
    public string $componentgrade = '';

    /** @var string User id */
    public string $userid;

    /** @var string Student idnumber */
    public string $idnumber;

    /** @var string Student firstname */
    public string $firstname;

    /** @var string Student lastname */
    public string $lastname;

    /** @var string Student marks */
    public string $marks = '-';

    /** @var string Student hand in date time */
    public string $handindatetime = '-';

    /** @var string Submission hand in status */
    public string $handinstatus = '-';

    /** @var string Submission export staff */
    public string $exportstaff = '-';

    /** @var string|null Last grade push result */
    public ?string $lastgradepushresult = null;

    /** @var int Last grade push error type */
    public int $lastgradepusherrortype = 0;

    /** @var string Last grade push time */
    public string $lastgradepushtime = '-';

    /** @var string|null Last submission log push result */
    public ?string $lastsublogpushresult = null;

    /** @var int Last submission log push error type */
    public int $lastsublogpusherrortype = 0;

    /** @var string Last submission log push time */
    public string $lastsublogpushtime = '-';

    /** @var manager|null Grade push manager */
    protected ?manager $manager;

    /**
     * Constructor.
     *
     * @param \stdClass $student
     * @param int $coursemoduleid
     * @param \stdClass|null $mapping
     * @throws \dml_exception
     * @throws \moodle_exception
     */
    public function __construct(\stdClass $student, int $coursemoduleid, \stdClass $mapping = null) {
        // Get manager.
        $this->manager = manager::get_manager();

        // Set student data.
        $this->set_student_info($student);

        // Set grade.
        $this->set_grade($coursemoduleid, $student->id);

        // Set submission.
        $this->set_submission($coursemoduleid, $student->id);

        if (!empty($mapping)) {
            // Set transfer records.
            $this->set_transfer_records($mapping->id, $student->id);
        }
    }

    /**
     * Set grade.
     *
     * @param int $coursemoduleid
     * @param int $studentid
     * @return void
     * @throws \moodle_exception
     */
    protected function set_grade (int $coursemoduleid, int $studentid): void {
        $grade = $this->manager->get_student_grade($coursemoduleid, $studentid);
        if (!empty($grade->grade)) {
            $this->marks = $grade->grade;
        }
    }

    /**
     * Set student info.
     * @param \stdClass $student
     * @return void
     */
    protected function set_student_info (\stdClass $student): void {
        $this->userid = $student->id;
        $this->idnumber = $student->idnumber;
        $this->firstname = $student->firstname;
        $this->lastname = $student->lastname;
    }

    /**
     * Set submission.
     * @param int $coursemoduleid
     * @param int $studentid
     * @return void
     * @throws \coding_exception
     * @throws \dml_exception
     * @throws \moodle_exception
     */
    protected function set_submission (int $coursemoduleid, int $studentid): void {

        // Get submission.
        $submission = submissionfactory::get_submission($coursemoduleid, $studentid);
        if ($submission->get_submission_data()) {
            $this->handindatetime = $submission->get_handin_datetime();
            $this->handinstatus = $submission->get_handin_status();
            $this->exportstaff = $submission->get_export_staff();
        }
    }

    /**
     * Set transfer records.
     * @param int $assessmentmappingid
     * @param int $studentid
     * @return void
     * @throws \dml_exception
     */
    protected function set_transfer_records(int $assessmentmappingid, int $studentid): void {
        $transferlogs = $this->manager->get_transfer_logs($assessmentmappingid, $studentid);
        if (!empty($transferlogs)) {
            foreach ($transferlogs as $log) {
                $response = json_decode($log->response);
                $result = ($response->code == '0') ? 'success' : 'failed';
                if (is_null($log->errlogid)) {
                    $errortype = 0;
                } else {
                    $errortype = $log->errortype ?: errormanager::ERROR_UNKNOWN;
                }
                $timecreated = date('Y-m-d H:i:s', $log->timecreated);
                // Get <MAP CODE>-<MAB SEQ> from request url.
                if (preg_match('#moodle/(.*?)/student#', $log->request, $matches)) {
                    $this->componentgrade = $matches[1];
                }
                if ($log->type == manager::PUSH_GRADE) {
                    $this->lastgradepushresult = $result;
                    $this->lastgradepusherrortype = $errortype;
                    $this->lastgradepushtime = $timecreated;
                } else if ($log->type == manager::PUSH_SUBMISSION_LOG) {
                    $this->lastsublogpushresult = $result;
                    $this->lastsublogpusherrortype = $errortype;
                    $this->lastsublogpushtime = $timecreated;
                }
            }
        }
    }
}