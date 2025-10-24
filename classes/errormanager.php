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

/**
 * Error manager class for errors related tasks.
 *
 * @package     local_sitsgradepush
 * @copyright   2023 onwards University College London {@link https://www.ucl.ac.uk/}
 * @license     https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @author      Alex Yeung <k.yeung@ucl.ac.uk>
 */
class errormanager {
    /** @var int error type cannot be determined */
    const ERROR_UNKNOWN = -99;

    /** @var int student SPR code not found, treat as student not enrolled */
    const ERROR_STUDENT_NOT_ENROLLED = -1;

    /** @var int record type invalid */
    const ERROR_RECORD_TYPE_INVALID = -2;

    /** @var int record not exist, e.g. SAS record not exists on SITS */
    const ERROR_RECORD_NOT_EXIST = -3;

    /** @var int record on SITS is in an invalid state */
    const ERROR_RECORD_INVALID_STATE = -4;

    /** @var int no mark scheme defined */
    const ERROR_NO_MARK_SCHEME = -5;

    /** @var int attempt number blank */
    const ERROR_ATTEMPT_NUMBER_BLANK = -6;

    /** @var int overwrite existing record not allowed */
    const ERROR_OVERWRITE_EXISTING_RECORD = -7;

    /** @var int invalid marks */
    const ERROR_INVALID_MARKS = -8;

    /** @var int invalid hand in status */
    const ERROR_INVALID_HAND_IN_STATUS = -9;

    /** @var array error types and their labels */
    const ERROR_TYPES_LABEL = [
        self::ERROR_UNKNOWN => 'Failed',
        self::ERROR_STUDENT_NOT_ENROLLED => 'Student not enrolled',
        self::ERROR_RECORD_TYPE_INVALID => 'Record type invalid',
        self::ERROR_RECORD_NOT_EXIST => 'Record does not exist',
        self::ERROR_RECORD_INVALID_STATE => 'Record state invalid',
        self::ERROR_NO_MARK_SCHEME => 'No mark scheme',
        self::ERROR_ATTEMPT_NUMBER_BLANK => 'Attempt number blank',
        self::ERROR_OVERWRITE_EXISTING_RECORD => 'Overwrite not allowed',
        self::ERROR_INVALID_MARKS => 'Invalid marks',
        self::ERROR_INVALID_HAND_IN_STATUS => 'Invalid hand in status',
    ];

    /** @var array error types and their match error strings */
    const ERROR_TYPES_MATCH_STRING = [
        self::ERROR_STUDENT_NOT_ENROLLED => ['student not found'],
        self::ERROR_RECORD_TYPE_INVALID => ['recordtype is invalid'],
        self::ERROR_RECORD_NOT_EXIST => ['record does not exist'],
        self::ERROR_RECORD_INVALID_STATE => ['record not in valid state'],
        self::ERROR_NO_MARK_SCHEME => ['no mark scheme defined'],
        self::ERROR_ATTEMPT_NUMBER_BLANK => ['Attempt number blank'],
        self::ERROR_OVERWRITE_EXISTING_RECORD => [
            'Cannot overwrite existing',
            'no further update allowed',
        ],
        self::ERROR_INVALID_MARKS => ['Mark and/or Grade not valid'],
        self::ERROR_INVALID_HAND_IN_STATUS => ['handin_status provided is not in SUS table'],
    ];

    /**
     * Get error label by error code.
     *
     * @param int|null $errorcode error code
     * @return string error label
     */
    public static function get_error_label(?int $errorcode = null): string {
        // If no error code provided, return unknown error.
        if (!isset($errorcode)) {
            return self::ERROR_TYPES_LABEL[self::ERROR_UNKNOWN];
        }

        return self::ERROR_TYPES_LABEL[$errorcode];
    }

    /**
     * Identify error code by error string.
     *
     * @param string|null $errorstring
     * @return int
     */
    public static function identify_error(?string $errorstring = null): int {
        // If no error string provided, return unknown error.
        if (!isset($errorstring)) {
            return self::ERROR_UNKNOWN;
        }

        // Loop through all error strings and return the error code if matched.
        foreach (self::ERROR_TYPES_MATCH_STRING as $errorcode => $matchstrings) {
            foreach ($matchstrings as $matchstring) {
                if (stripos($errorstring, $matchstring) !== false) {
                    return $errorcode;
                }
            }
        }

        // If no match found, return unknown error.
        return self::ERROR_UNKNOWN;
    }
}
