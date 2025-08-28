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

use core\clock;
use core\di;
use core\exception\moodle_exception;
use InvalidArgumentException;
use sitsapiclient_easikit\models\student\studentv2;

/**
 * Manager class which student candidate number (SCN) related operations.
 *
 * @package    local_sitsgradepush
 * @copyright  2025 onwards University College London {@link https://www.ucl.ac.uk/}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @author     Alex Yeung <k.yeung@ucl.ac.uk>
 */
class scnmanager {
    /** @var ?scnmanager Singleton instance. */
    private static ?scnmanager $instance = null;

    /** @var int Candidate number cache expires in 30 days. */
    const SCN_EXPIRY = DAYSECS * 30; // 30 days in seconds.

    /** @var clock $clock */
    private readonly clock $clock;

    /**
     * Private constructor to prevent direct instantiation.
     */
    private function __construct() {
        // Private constructor.
    }

    /**
     * Get singleton instance.
     *
     * @return scnmanager
     */
    public static function get_instance(): scnmanager {
        if (self::$instance === null) {
            self::$instance = new self();
            self::$instance->clock = di::get(clock::class);
        }
        return self::$instance;
    }

    /**
     * Fetch student candidate numbers from SITS for a given course, optionally filtered by student code.
     *
     * @param int $courseid
     * @param string $studentcode Optional student code to filter results.
     * @return array|studentv2|null Array of students or single student or null if no students found.
     */
    public function fetch_candidate_numbers_from_sits(int $courseid, string $studentcode = ''): array|studentv2|null {
        try {
            // Return null if fetching candidate numbers is not enabled.
            if (!$this->is_fetch_candidate_numbers_enabled()) {
                return [];
            }

            // Get manager instance.
            $manager = manager::get_manager();

            // Get course's module deliveries.
            $modoccs = $manager->get_component_grade_options($courseid);
            if (empty($modoccs)) {
                return [];
            }

            // Fetch student candidate numbers from SITS for each module occurrence.
            $scns = [];
            foreach ($modoccs as $modocc) {
                // Skip module occurrences without component grades.
                if (empty($modocc->componentgrades)) {
                    continue;
                }

                // Use the first mab to fetch students.
                $firstmab = reset($modocc->componentgrades);

                // Try to get students from SITS.
                $students = $this->get_students_from_sits($manager, $firstmab, $courseid);

                foreach ($students as $student) {
                    $studentv2 = new studentv2($student);
                    $scns[$studentv2->get_studentcode()] = $studentv2;
                }

                // Break the loop if student code provided is found.
                if ($studentcode && isset($scns[$studentcode])) {
                    break;
                }
            }

            if (empty($scns)) {
                // No students found for the course.
                return [];
            }

            // Save candidate numbers to local database.
            $this->save_candidate_numbers($scns);

            // Return single student if student code is provided, otherwise return all students.
            if (!empty($studentcode)) {
                return $scns[$studentcode] ?? null;
            }

            return $scns;
        } catch (\Exception $e) {
            logger::log_debug_error(
                get_string('error:failed_to_fetch_scn_from_sits', 'local_sitsgradepush', $e->getMessage()),
                data: json_encode([
                    'courseid' => $courseid,
                    'studentcode' => $studentcode,
                ]),
            );
            throw $e;
        }
    }

    /**
     * Fetch student's candidate number by course and student code.
     *
     * @param int $courseid
     * @param int $userid
     *
     * @return string|null
     */
    public function get_candidate_number_by_course_student(int $courseid, int $userid): ?string {
        global $DB;
        try {
            // Find the student's student code first, e.g. idnumber field in user table.
            $studentcode = $DB->get_field('user', 'idnumber', ['id' => $userid]);

            if (empty($studentcode)) {
                throw new moodle_exception('studentcode_not_found', 'local_sitsgradepush', '', ['userid' => $userid]);
            }

            // Get cached candidate number if available.
            $courseacademicyear = manager::get_manager()->get_course_academic_year($courseid);
            // If no academic year is found for the course, return null.
            if (empty($courseacademicyear)) {
                throw new moodle_exception(
                    'error:course_academic_year_not_set',
                    'local_sitsgradepush',
                    '',
                    ['courseid' => $courseid]
                );
            }

            $cachekey = $this->get_cache_key($courseacademicyear, $userid);
            $cachedcandidatenumber = cachemanager::get_cache(
                cachemanager::CACHE_AREA_CANDIDATE_NUMBERS,
                $cachekey
            );

            // If cached candidate number exists, return it.
            if ($cachedcandidatenumber) {
                return $cachedcandidatenumber;
            }

            // Fetch candidate numbers from SITS.
            $student = $this->fetch_candidate_numbers_from_sits($courseid, $studentcode);

            if (empty($student) || empty($student->get_candidatenumber())) {
                // No candidate number found on SITS, try to get candidate number from local database.
                return $this->get_local_candidate_number($userid, $courseacademicyear);
            }

            // Return candidate number from SITS.
            if ($student->get_academicyear() !== (int) $courseacademicyear) {
                // Candidate number does not match the course academic year.
                throw new moodle_exception(
                    'error:candidate_number_academic_year_mismatch',
                    'local_sitsgradepush',
                    '',
                    ['courseid' => $courseid, 'academicyear' => $courseacademicyear, 'userid' => $userid]
                );
            }

            return $student->get_candidatenumber();
        } catch (\Exception $e) {
            logger::log_debug_error(
                get_string('error:failed_to_get_scn_for_student', 'local_sitsgradepush', $e->getMessage()),
                data: json_encode([
                    'courseid' => $courseid,
                    'userid' => $userid,
                ]),
            );
            return null;
        }
    }

    /**
     * Get local candidate number for a user in a specific academic year.
     *
     * @param int $userid User ID.
     * @param int $academicyear Academic year.
     * @return string|null Candidate number or null if not found or expired.
     * @throws \dml_exception
     */
    public function get_local_candidate_number(int $userid, int $academicyear): ?string {
        global $DB;
        // Check if the candidate number exists in the local database.
        $scn = $DB->get_record('local_sitsgradepush_scn', [
            'userid' => $userid,
            'academic_year' => $academicyear,
        ]);

        if (empty($scn)) {
            // No candidate number found in the local database.
            return null;
        }

        // Set cache for the candidate number.
        $cachekey = $this->get_cache_key($academicyear, $userid);
        cachemanager::set_cache(
            cachemanager::CACHE_AREA_CANDIDATE_NUMBERS,
            $cachekey,
            $scn->candidate_number,
            self::SCN_EXPIRY
        );

        // Return the candidate number.
        return $scn->candidate_number;
    }

    /**
     * Save or update student candidate numbers.
     *
     * @param array $scns Array of records containing student_code, userid, academic_year, and candidate_number.
     * @return void
     * @throws \coding_exception|\dml_exception If any required fields are missing.
     */
    public function save_candidate_numbers(array $scns) {
        global $DB;
        foreach ($scns as $scn) {
            try {
                // Get values using getter methods.
                $studentcode = $scn->get_studentcode();
                $userid = $scn->get_userid();
                $academicyear = $scn->get_academicyear();
                $candidatenumber = $scn->get_candidatenumber();

                // Validate all required fields.
                $this->validate_student_data($studentcode, $userid, $academicyear, $candidatenumber);

                $existingrecord = $DB->get_record('local_sitsgradepush_scn', [
                    'userid' => $userid,
                    'academic_year' => $academicyear,
                ]);

                $record = new \stdClass();
                $record->student_code = $studentcode;
                $record->userid = $userid;
                $record->academic_year = $academicyear;
                $record->candidate_number = $candidatenumber;
                $record->timemodified = $this->clock->time();

                if ($existingrecord) {
                    if ($existingrecord->candidate_number === $candidatenumber) {
                        // SITS candidate number is the same as existing one, set cache and skip update.
                        $this->set_cache($academicyear, $userid, $candidatenumber);

                        // No need to update if the candidate number is the same.
                        continue;
                    }
                    $record->id = $existingrecord->id;
                    $DB->update_record('local_sitsgradepush_scn', $record);
                } else {
                    $record->timecreated = $this->clock->time();
                    $DB->insert_record('local_sitsgradepush_scn', $record);
                }
                // Set cache for the candidate number.
                $this->set_cache($academicyear, $userid, $candidatenumber);
            } catch (\Exception $e) {
                logger::log_debug_error(
                    get_string('error:failed_to_save_candidate_number', 'local_sitsgradepush', $e->getMessage()),
                    data: json_encode([
                        'studentcode' => $studentcode ?? '',
                        'userid' => $userid ?? '',
                        'academicyear' => $academicyear ?? '',
                        'candidatenumber' => $candidatenumber ?? '',
                    ]),
                );
                continue;
            }
        }
    }

    /**
     * Get course sync cache.
     * It is used avoid syncing candidate numbers for the same course multiple times within a short period.
     *
     * @param int $courseid Course ID.
     * @return mixed The cached value or null if not found.
     */
    public function get_course_sync_cache(int $courseid): mixed {
        // Get the course sync cache.
        return cachemanager::get_cache(
            cachemanager::CACHE_AREA_CANDIDATE_NUMBERS,
            'scn_course_sync_' . $courseid
        );
    }

    /**
     * Set course sync cache.
     * Records the time when the candidate numbers for a course were last synced. Expires after 1 hour.
     *
     * @param int $courseid Course ID.
     * @param int $time Time to set in the cache.
     * @return void
     */
    public function set_course_sync_cache(int $courseid, int $time): void {
        // Set the course sync cache.
        cachemanager::set_cache(
            cachemanager::CACHE_AREA_CANDIDATE_NUMBERS,
            'scn_course_sync_' . $courseid,
            $time,
            HOURSECS
        );
    }

    /**
     * Check if fetching candidate numbers is enabled.
     *
     * @return bool True if enabled, false otherwise.
     */
    public function is_fetch_candidate_numbers_enabled(): bool {
        // Check if the feature is enabled in the configuration.
        return get_config('local_sitsgradepush', 'fetch_scn_enabled') == '1';
    }

    /**
     * Validate student data fields.
     *
     * @param string $studentcode Student code.
     * @param int $userid User ID.
     * @param int $academicyear Academic year.
     * @param string $candidatenumber Candidate number.
     * @return void
     * @throws InvalidArgumentException If any required field is missing.
     */
    private function validate_student_data(string $studentcode, int $userid, int $academicyear, string $candidatenumber): void {
        if (empty($studentcode)) {
            throw new InvalidArgumentException(
                get_string('error:missing_or_invalid_field', 'local_sitsgradepush', 'scnmanager: studentcode')
            );
        }
        if (empty($userid)) {
            throw new InvalidArgumentException(
                get_string('error:missing_or_invalid_field', 'local_sitsgradepush', 'scnmanager: userid')
            );
        }
        if (empty($academicyear)) {
            throw new InvalidArgumentException(
                get_string('error:missing_or_invalid_field', 'local_sitsgradepush', 'scnmanager: academicyear')
            );
        }
        if (empty($candidatenumber)) {
            throw new InvalidArgumentException(
                get_string('error:missing_or_invalid_field', 'local_sitsgradepush', 'scnmanager: candidatenumber')
            );
        }
    }

    /**
     * Set candidate number in cache.
     *
     * @param int $academicyear Academic year.
     * @param int $userid User ID.
     * @param string $candidatenumber Candidate number.
     * @return void
     */
    private function set_cache(int $academicyear, int $userid, string $candidatenumber): void {
        $cachekey = $this->get_cache_key($academicyear, $userid);
        cachemanager::set_cache(
            cachemanager::CACHE_AREA_CANDIDATE_NUMBERS,
            $cachekey,
            $candidatenumber,
            self::SCN_EXPIRY
        );
    }

    /**
     * Get students from SITS with weekly caching to prevent repeated API calls.
     *
     * @param manager $manager The manager instance.
     * @param object $mab The component grade object.
     * @param int $courseid The course ID.
     * @return array Array of students.
     */
    private function get_students_from_sits(manager $manager, object $mab, int $courseid): array {
        $cachekey = 'sits_students_fetch_' . $courseid . '_' . $mab->mapcode;

        $lastfetch = cachemanager::get_cache(
            cachemanager::CACHE_AREA_CANDIDATE_NUMBERS,
            $cachekey
        );

        // Cache exists, return empty array to avoid repeated API calls within a week.
        if ($lastfetch !== null) {
            return [];
        }

        // Fetch students from SITS API.
        $students = $manager->get_students_from_sits($mab, true, 2);

        // Set cache to expire in one week.
        cachemanager::set_cache(
            cachemanager::CACHE_AREA_CANDIDATE_NUMBERS,
            $cachekey,
            $this->clock->time(),
            WEEKSECS
        );

        return $students;
    }

    /**
     * Get cache key for candidate number.
     *
     * @param int $academicyear Academic year.
     * @param int $userid User ID.
     * @return string Cache key.
     */
    private function get_cache_key(int $academicyear, int $userid): string {
        return 'scn_' . $userid . '_' . $academicyear;
    }

    /**
     * Prevent cloning of singleton instance.
     */
    private function __clone() {
        // Prevent cloning.
    }

    /**
     * Prevent unserialization of singleton instance.
     */
    public function __wakeup() {
        // Prevent unserialization.
        throw new \Exception(get_string('error:cannot_unserialize_singleton', 'local_sitsgradepush', self::class));
    }
}
