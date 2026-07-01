<?php
// This file is part of Moodle - https://moodle.org/
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
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

namespace local_sitsgradepush\delivery;

use core\clock;
use core\di;
use local_sitsgradepush\delivery\models\delivery_key;

/**
 * Stores resolved student to delivery mappings in the durable database table.
 *
 * @package    local_sitsgradepush
 * @copyright  2026 onwards University College London {@link https://www.ucl.ac.uk/}
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @author     Alex Yeung <k.yeung@ucl.ac.uk>
 */
class db_student_delivery_store {
    /** @var string DB table for the durable student to module delivery mapping. */
    const TABLE = 'local_sitsgradepush_stuenrol';

    /**
     * Get the delivery keys already stored for a student in a course.
     *
     * @param int $courseid
     * @param int $userid
     * @return delivery_key[]
     */
    public function find(int $courseid, int $userid): array {
        global $DB;

        $records = $DB->get_records(self::TABLE, ['courseid' => $courseid, 'userid' => $userid]);

        return array_map(
            fn($record) => delivery_key::from_record($record),
            array_values($records),
        );
    }

    /**
     * Persist a student to delivery mapping, ignoring duplicates.
     *
     * @param int $courseid
     * @param int $userid
     * @param delivery_key $key
     * @return void
     */
    public function save(int $courseid, int $userid, delivery_key $key): void {
        global $DB;

        $params = [
            'courseid' => $courseid,
            'userid' => $userid,
            'modcode' => $key->modcode,
            'modocc' => $key->modocc,
            'academicyear' => $key->academicyear,
            'periodslotcode' => $key->periodslotcode,
        ];

        if ($DB->record_exists(self::TABLE, $params)) {
            return;
        }

        $record = (object) $params;
        $record->timecreated = di::get(clock::class)->time();
        $DB->insert_record(self::TABLE, $record);
    }
}
