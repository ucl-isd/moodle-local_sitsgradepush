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

namespace sitsapiclient_easikit\models\student;

use local_sitsgradepush\manager;
use InvalidArgumentException;

/**
 * Student model class for SITS API client version 2.
 *
 * This class represents a student entity with properties mapped from SITS API response data.
 * It provides validation and type safety for student data handling.
 *
 * @package    sitsapiclient_easikit
 * @subpackage models\student
 * @copyright  2025 onwards University College London {@link https://www.ucl.ac.uk/}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @author     Alex Yeung <k.yeung@ucl.ac.uk>
 */
class studentv2 {
    /** @var string Student's first name */
    private string $firstname;

    /** @var string Student's last name */
    private string $lastname;

    /** @var string Student code from SITS */
    private string $studentcode;

    /** @var int|false Moodle user ID, false if not found */
    private int|false $userid;

    /** @var int Academic year */
    private int $academicyear;

    /** @var string Candidate number */
    private string $candidatenumber;

    /**
     * Constructor for studentv2 model.
     *
     * Creates a student object from SITS API response data with validation.
     * Validates required fields and maps data to object properties.
     *
     * @param array $student Student data array from SITS API response
     * @throws InvalidArgumentException If required fields are missing or invalid
     */
    public function __construct(array $student) {
        $this->validate_student_data($student);
        $this->map_student_data($student);
    }

    /**
     * Get student's first name.
     *
     * @return string The student's first name
     */
    public function get_firstname(): string {
        return $this->firstname;
    }

    /**
     * Get student's last name.
     *
     * @return string The student's last name
     */
    public function get_lastname(): string {
        return $this->lastname;
    }

    /**
     * Get student's full name.
     *
     * @return string The student's full name (firstname lastname)
     */
    public function get_fullname(): string {
        return trim($this->firstname . ' ' . $this->lastname);
    }

    /**
     * Get student code.
     *
     * @return string The student code from SITS
     */
    public function get_studentcode(): string {
        return $this->studentcode;
    }

    /**
     * Get Moodle user ID.
     *
     * @return int|false The Moodle user ID, or false if not found
     */
    public function get_userid(): int|false {
        return $this->userid;
    }

    /**
     * Get academic year.
     *
     * @return int The academic year
     */
    public function get_academicyear(): int {
        return $this->academicyear;
    }

    /**
     * Get candidate number.
     *
     * @return string The candidate number
     */
    public function get_candidatenumber(): string {
        return $this->candidatenumber;
    }

    /**
     * Check if student has a valid Moodle user account.
     *
     * @return bool True if student has valid Moodle user ID, false otherwise
     */
    public function has_valid_userid(): bool {
        return $this->userid !== false && $this->userid > 0;
    }

    /**
     * Validate student data array structure and required fields.
     *
     * @param array $student Student data array to validate
     * @throws InvalidArgumentException If required fields are missing or invalid
     */
    private function validate_student_data(array $student): void {
        // Check required top-level fields.
        $requiredfields = ['forename', 'surname'];
        foreach ($requiredfields as $field) {
            if (!isset($student[$field]) || !is_string($student[$field]) || trim($student[$field]) === '') {
                throw new InvalidArgumentException(get_string('error:missing_or_invalid_field', 'sitsapiclient_easikit', $field));
            }
        }

        // Check association structure.
        if (!isset($student['association']) || !is_array($student['association'])) {
            throw new InvalidArgumentException(
                get_string('error:missing_or_invalid_field', 'sitsapiclient_easikit', 'association')
            );
        }

        // Check supplementary data structure.
        if (!isset($student['association']['supplementary']) || !is_array($student['association']['supplementary'])) {
            throw new InvalidArgumentException(
                get_string('error:missing_or_invalid_field', 'sitsapiclient_easikit', 'association.supplementary')
            );
        }

        $supplementary = $student['association']['supplementary'];

        // Check required supplementary fields.
        if (!isset($supplementary['student_code'])) {
            throw new InvalidArgumentException(
                get_string('error:missing_or_invalid_field', 'sitsapiclient_easikit', 'supplementary.student_code')
            );
        }

        if (isset($supplementary['academic_year']) && !is_numeric($supplementary['academic_year'])) {
            throw new InvalidArgumentException(
                get_string('error:missing_or_invalid_field', 'sitsapiclient_easikit', 'supplementary.academic_year')
            );
        }
    }

    /**
     * Map validated student data to object properties.
     *
     * @param array $student Validated student data array
     */
    private function map_student_data(array $student): void {
        $supplementary = $student['association']['supplementary'];

        // Map basic fields.
        $this->firstname = trim($student['forename']);
        $this->lastname = trim($student['surname']);
        $this->studentcode = $supplementary['student_code'];
        $this->academicyear = isset($supplementary['academic_year']) ? (int) $supplementary['academic_year'] : 0;
        $this->userid = manager::get_manager()->get_userid_from_student_code($this->studentcode);
        $this->candidatenumber = isset($supplementary['candidate_number']) ? trim($supplementary['candidate_number']) : '';
    }
}
