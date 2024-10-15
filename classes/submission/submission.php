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

namespace local_sitsgradepush\submission;

use local_sitsgradepush\manager;

/**
 * Parent class for submission classes.
 *
 * @package    local_sitsgradepush
 * @copyright  2023 onwards University College London {@link https://www.ucl.ac.uk/}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @author     Alex Yeung <k.yeung@ucl.ac.uk>
 */
abstract class submission implements isubmission {

    /** @var string Submit status - Submitted */
    const STATUS_SUBMITTED = 'S';

    /** @var \stdClass Course module object e.g quiz, assign */
    protected $coursemodule;

    /** @var int User id */
    protected $userid;

    /** @var \stdClass Assessment submission object */
    protected $submissiondata;

    /** @var \stdClass Activity instance */
    protected $modinstance;

    /** @var string User used to update submission log */
    protected $updateuser = 'STUTALK_MDL';

    /**
     * Constructor.
     *
     * @param \stdClass $coursemodule
     * @param int $userid
     * @throws \dml_exception
     * @throws \moodle_exception
     */
    public function __construct($coursemodule, $userid) {
        // Set course module.
        $this->coursemodule = $coursemodule;
        // Set user id.
        $this->userid = $userid;
        // Set mod instance.
        $this->set_module_instance();
        // Set submission.
        $this->set_submission_data();
    }

    /**
     * Get original due datetime.
     *
     * @return string
     */
    public function get_original_due_datetime(): string {
        return "";
    }

    /**
     * Get current due datetime.
     *
     * @return string
     */
    public function get_current_due_datetime(): string {
        return "";
    }

    /**
     * Get hand in status.
     *
     * @return string
     */
    public function get_handin_status(): string {
        return self::STATUS_SUBMITTED;
    }

    /**
     * Get handed in.
     *
     * @return string
     */
    public function get_handed_in(): string {
        return "";
    }

    /**
     * Get handed in blank.
     *
     * @return string
     */
    public function get_handed_in_blank(): string {
        return "";
    }

    /**
     * Get permitted submission period.
     *
     * @return string
     */
    public function get_permitted_submission_period(): string {
        return "";
    }

    /**
     * Get export staff.
     *
     * @return string
     * @throws \dml_exception
     */
    public function get_export_staff(): string {
        $manager = manager::get_manager();
        return $manager->get_export_staff();
    }

    /**
     * Get export timestamp.
     *
     * @return string
     */
    public function get_export_timestamp(): string {
        return $this->get_iso8601_datetime();
    }

    /**
     * Get course module id.
     *
     * @return string
     */
    public function get_export_flow_id(): string {
        return "";
    }

    /**
     * Get number of items.
     *
     * @return string
     */
    public function get_no_of_items(): string {
        return "";
    }

    /**
     * Get submission.
     *
     * @return \stdClass
     */
    public function get_submission_data() {
        return $this->submissiondata;
    }

    /**
     * Set submission data.
     * The submission log transfer will be skipped without this data.
     * {@see \local_sitsgradepush\manager::push_submission_log_to_sits()}
     * @return void
     */
    abstract protected function set_submission_data(): void;

    /**
     * Get ISO 8601 format datetime.
     *
     * @param int|null $time
     * @return string
     */
    protected function get_iso8601_datetime(?int $time = null): string {
        if ($time) {
            $datetime = date('c', $time);
        } else {
            $datetime = date('c');
        }

        // Split the datetime by '+'.
        $processeddatetime = explode('+', $datetime);

        // Return the first part only to remove the timezone at the end.
        return $processeddatetime[0];
    }
}
