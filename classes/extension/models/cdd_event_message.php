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

namespace local_sitsgradepush\extension\models;

use core\exception\moodle_exception;

/**
 * Model class for combined due date (CDD) event message from AWS.
 *
 * @package    local_sitsgradepush
 * @copyright  2026 onwards University College London {@link https://www.ucl.ac.uk/}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @author     Alex Yeung <k.yeung@ucl.ac.uk>
 */
class cdd_event_message {
    /** @var array Changes. */
    public array $changes = [];

    /** @var string Student programme route code, e.g. 22043599/1. */
    protected string $sprcode = '';

    /** @var string Student code derived from the student programme route code. */
    protected string $studentcode = '';

    /** @var string Module code, e.g. ELEC0129. */
    protected string $modulecode = '';

    /** @var string Module occurrence, e.g. A5U. */
    protected string $moduleoccurrence = '';

    /** @var string Academic year code, e.g. 2024. */
    protected string $academicyearcode = '';

    /** @var string Period slot code, e.g. T2. */
    protected string $periodslotcode = '';

    /** @var string Module identifier, e.g. ELEC0129A5UG. */
    protected string $moduleidentifier = '';

    /** @var string Module sequence, e.g. 001. */
    protected string $modulesequence = '';

    /**
     * Constructor.
     *
     * @param \stdClass $messagedata Data from the combined due date event message.
     * @throws moodle_exception If required fields are missing.
     */
    public function __construct(\stdClass $messagedata) {
        // Validate the message structure.
        $entity = $messagedata->entity?->combined_new_due_date ?? null;
        if (!$entity) {
            throw new moodle_exception('error:missing_or_invalid_field', 'local_sitsgradepush', '', 'combined_new_due_date');
        }

        // The student programme route code is the only student identifier in the message.
        $sprcode = $entity->student_programme_route_code ?? '';
        if (empty($sprcode)) {
            throw new moodle_exception('error:missing_or_invalid_field', 'local_sitsgradepush', '', 'student_programme_route_code');
        }

        // Set properties. The student code is the part before the slash, e.g. 22043599/1 has student code 22043599.
        $this->sprcode = $sprcode;
        $this->studentcode = explode('/', $sprcode)[0];
        $this->modulecode = $entity->module_code ?? '';
        // The entity key is misspelled "module_occurence" (single "r") in the event message.
        $this->moduleoccurrence = $entity->module_occurence ?? '';
        $this->academicyearcode = $entity->academic_year_code ?? '';
        $this->periodslotcode = $entity->period_slot_code ?? '';
        $this->moduleidentifier = $entity->module_identifier ?? '';
        $this->modulesequence = $entity->module_sequence ?? '';
        $this->changes = $messagedata->changes ?? [];
    }

    /**
     * Get the student programme route code.
     *
     * @return string
     */
    public function get_sprcode(): string {
        return $this->sprcode;
    }

    /**
     * Get the student code.
     *
     * @return string
     */
    public function get_student_code(): string {
        return $this->studentcode;
    }

    /**
     * Get the module code.
     *
     * @return string
     */
    public function get_module_code(): string {
        return $this->modulecode;
    }

    /**
     * Get the module occurrence.
     *
     * @return string
     */
    public function get_module_occurrence(): string {
        return $this->moduleoccurrence;
    }

    /**
     * Get the academic year code.
     *
     * @return string
     */
    public function get_academic_year_code(): string {
        return $this->academicyearcode;
    }

    /**
     * Get the period slot code.
     *
     * @return string
     */
    public function get_period_slot_code(): string {
        return $this->periodslotcode;
    }

    /**
     * Get the module identifier.
     *
     * @return string
     */
    public function get_module_identifier(): string {
        return $this->moduleidentifier;
    }

    /**
     * Get the module sequence.
     *
     * @return string
     */
    public function get_module_sequence(): string {
        return $this->modulesequence;
    }
}
