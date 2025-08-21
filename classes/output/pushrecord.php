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

use local_sitsgradepush\assessment\assessment;
use local_sitsgradepush\assessment\assessmentfactory;
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
    /** @var string mark transferred as absent */
    public string $absent;

    /** @var string SITS component grade */
    public string $componentgrade = '';

    /** @var int Course id */
    public int $courseid;

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

    /** @var string Student equivalent grade */
    public string $equivalentgrade = '-';

    /** @var string Student raw marks */
    public string $rawmarks = '';

    /** @var string Student hand in date time */
    public string $handindatetime = '-';

    /** @var string Submission hand in status */
    public string $handinstatus = '-';

    /** @var string Submission export staff */
    public string $exportstaff = '-';

    /** @var string|null Last grade push result */
    public ?string $lastgradepushresult = null;

    /** @var string|null Last grade push result label */
    public ?string $lastgradepushresultlabel = null;

    /** @var int Last grade push error type */
    public int $lastgradepusherrortype = 0;

    /** @var string Last grade push time string */
    public string $lastgradepushtimestring = '-';

    /** @var int Last grade push time */
    public int $lastgradepushtime = 0;

    /** @var string|null Last submission log push result */
    public ?string $lastsublogpushresult = null;

    /** @var string|null Last submission log push result label */
    public ?string $lastsublogpushresultlabel = null;


    /** @var int Last submission log push error type */
    public int $lastsublogpusherrortype = 0;

    /** @var string Last submission log push time string */
    public string $lastsublogpushtimestring = '-';

    /** @var int Last submission log push time */
    public int $lastsublogpushtime = 0;

    /** @var bool Is grade pushed */
    public bool $isgradepushed = false;

    /** @var bool Is submission log pushed */
    public bool $issublogpushed = false;

    /** @var bool Is marks updated after transfer */
    public bool $marksupdatedaftertransfer = false;

    /** @var string Transferred mark */
    public string $transferredmark = '-';

    /** @var manager|null Grade push manager */
    protected ?manager $manager;

    /**
     * Constructor.
     *
     * @param \stdClass $student
     * @param assessment $assessment
     * @param \stdClass|null $mapping
     * @throws \coding_exception
     * @throws \dml_exception
     * @throws \moodle_exception
     */
    public function __construct(\stdClass $student, assessment $assessment, ?\stdClass $mapping = null) {
        // Get manager.
        $this->manager = manager::get_manager();

        // Set course id.
        $this->courseid = $assessment->get_course_id();

        // Set student data.
        $this->set_student_info($student);

        // Set grade.
        $this->set_grade($assessment, $student->id);

        // Set submission.
        $this->set_submission($assessment, $student->id);

        if (!empty($mapping)) {
            // Set transfer records.
            $this->set_transfer_records($mapping->id, $student->id);
        }

        // Set is pushed.
        $this->set_is_grade_pushed();

        // Set is submission log pushed.
        $this->set_is_submission_log_pushed();
    }

    /**
     * Check if the student record from SITS matches the mapping type, e.g. main or reassessment.
     *
     * @param \stdClass $mapping Current assessment mapping
     * @param array $students Student records from SITS
     * @return bool
     */
    public function check_record_from_sits(\stdClass $mapping, array $students): bool {
        // Check if this student exists in the student records from SITS.
        if (!array_key_exists($this->idnumber, $students)) {
            return false;
        }

        // Check the student's identifier in case the student has a different term.
        $identifierarray = explode('-', $students[$this->idnumber]['assessment']['identifier']);
        $studentterm = $identifierarray[2] ?? '';
        if (str_replace('/', '_', $mapping->periodslotcode) != $studentterm) {
            return false;
        }

        if ($mapping->reassessment == 1) {
            return $students[$this->idnumber]['assessment']['resit_number'] > 0;
        } else {
            return $students[$this->idnumber]['assessment']['resit_number'] == 0;
        }
    }

    /**
     * Should transfer mark for this student or not.
     *
     * @return bool
     */
    public function should_transfer_mark(): bool {
        return $this->marks != '-' && !($this->isgradepushed && $this->lastgradepushresult === 'success');
    }

    /**
     * Check if the student record is valid for non-submitted marks transfer.
     *
     * @return bool
     */
    public function is_non_submitted(): bool {
        // Student has not submitted, marks is not given and grade is not pushed successfully yet.
        return $this->marks == '-' && $this->handindatetime == '-' &&
            !($this->isgradepushed && $this->lastgradepushresult === 'success');
    }

    /**
     * Set grade.
     *
     * @param assessment $assessment
     * @param int $studentid
     * @return void
     */
    protected function set_grade(assessment $assessment, int $studentid): void {
        [$rawmarks, $equivalentgrade, $formattedmarks] = $assessment->get_user_grade($studentid);
        $this->rawmarks = $rawmarks ?? '-';
        $this->equivalentgrade = $equivalentgrade ?? '-';
        $this->marks = $formattedmarks ?? '-';
    }

    /**
     * Set student info.
     * @param \stdClass $student
     * @return void
     */
    protected function set_student_info(\stdClass $student): void {
        $this->userid = $student->id;
        $this->idnumber = $student->idnumber;
        $this->firstname = $student->firstname;
        $this->lastname = $student->lastname;
    }

    /**
     * Set submission.
     *
     * @param assessment $source
     * @param int $studentid
     * @return void
     * @throws \coding_exception
     * @throws \dml_exception
     * @throws \moodle_exception
     */
    protected function set_submission(assessment $source, int $studentid): void {
        // Exit if source is not a course module.
        if ($source->get_type() !== assessmentfactory::SOURCETYPE_MOD) {
            return;
        }

        // Get submission.
        $submission = submissionfactory::get_submission($source->get_id(), $studentid);
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

        // Exit if no transfer logs to set.
        if (empty($transferlogs)) {
            return;
        }

        // The Easikit Get Student API will remove the students whose marks had been transferred successfully.
        // Find the assessment component <MAP CODE>-<MAB SEQ>-<TERM> for that transfer log,
        // so that we can display the transfer status of mark transfer in the corresponding assessment component mapping.
        $mab = $this->manager->get_mab_and_map_info_by_mapping_id($assessmentmappingid);
        if (!empty($mab)) {
            $this->componentgrade = $mab->mapcode . '-' . $mab->mabseq . '-' . str_replace('/', '_', $mab->periodslotcode);
        }

        foreach ($transferlogs as $log) {
            $response = json_decode($log->response);
            $result = ($response->code == '0') ? 'success' : 'failed';
            $errortype = $log->errlogid ? ($log->errortype ?: errormanager::ERROR_UNKNOWN) : 0;

            if ($log->type == manager::PUSH_GRADE) {
                // Check if marks updated after transfer.
                if ($response->code == 0) {
                    $requestbody = json_decode($log->requestbody);
                    $this->transferredmark = $this->manager->get_formatted_marks($this->courseid, $requestbody->actual_mark);
                    $this->marksupdatedaftertransfer =
                        $this->is_marks_updated_after_transfer($this->rawmarks, $requestbody->actual_mark);
                    $this->absent = ($requestbody->actual_grade === assessment::GRADE_ABSENT);
                    $this->marks = $this->absent ? $this->manager->get_formatted_marks($this->courseid, 0) : $this->marks;
                }
                $this->lastgradepushresult = $result;
                $this->lastgradepusherrortype = $errortype;
                $this->lastgradepushtimestring = date('Y-m-d H:i:s', $log->timecreated);
                $this->lastgradepushtime = $log->timecreated;
            } else if ($log->type == manager::PUSH_SUBMISSION_LOG) {
                $this->lastsublogpushresult = $result;
                $this->lastsublogpusherrortype = $errortype;
                $this->lastsublogpushtimestring = date('Y-m-d H:i:s', $log->timecreated);
                $this->lastsublogpushtime = $log->timecreated;
            }
        }
    }

    /**
     * Set is grade pushed.
     *
     * @return void
     */
    protected function set_is_grade_pushed(): void {
        if (!is_null($this->lastgradepushresult) && $this->lastgradepusherrortype == 0) {
            $this->isgradepushed = true;
        }
    }

    /**
     * Set is submission log pushed.
     *
     * @return void
     */
    protected function set_is_submission_log_pushed(): void {
        if (!is_null($this->lastsublogpushresult) && $this->lastsublogpusherrortype == 0) {
            $this->issublogpushed = true;
        }
    }

    /**
     * Check if the marks are updated after transfer.
     *
     * @param string $rawmarks
     * @param string $transferredmarks
     * @return bool
     */
    private function is_marks_updated_after_transfer(string $rawmarks, string $transferredmarks): bool {
        // Return false if the raw marks is not numeric.
        // For example, the raw marks is '-' when no mark is given.
        if (!is_numeric($rawmarks)) {
            return false;
        }

        // As some of the marks were not transferred in raw marks, e.g. 66.67 instead of 66.66666
        // so need to format the raw marks to the same decimal places as the transferred marks for comparison.
        // Future marks transfer will be all in 5 decimal places as raw marks is stored in 5 decimal places.
        $transferredmarksdecimalplaces = (int) strpos(strrev($transferredmarks), ".");
        return number_format((float)$rawmarks, $transferredmarksdecimalplaces, '.') != $transferredmarks;
    }
}
