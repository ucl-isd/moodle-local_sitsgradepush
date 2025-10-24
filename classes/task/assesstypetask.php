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

namespace local_sitsgradepush\task;

use core\task\scheduled_task;
use local_sitsgradepush\assessment\assessmentfactory;
use local_sitsgradepush\manager;
use local_sitsgradepush\assesstype;

/**
 * Scheduled task to add assessment type records to local_assess_type plugin.
 *
 * @package    local_sitsgradepush
 * @copyright  2024 onwards University College London {@link https://www.ucl.ac.uk/}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @author     Alex Yeung <k.yeung@ucl.ac.uk>
 */
class assesstypetask extends scheduled_task {
    /** @var string date time format */
    const DATE_TIME_FORMAT = 'Y-m-d H:i:s';

    /**
     * Get a descriptive name for this task.
     *
     * @return string
     * @throws \coding_exception
     */
    public function get_name(): string {
        return get_string('task:assesstype:name', 'local_sitsgradepush');
    }

    /**
     * Run the scheduled task.
     *
     * @return void
     */
    public function execute() {
        global $DB;

        // Check if the local_assess_type plugin is installed.
        if (!assesstype::is_assess_type_installed()) {
            mtrace(date(self::DATE_TIME_FORMAT, time()) . ' : local_assess_type plugin is not installed.');
            return;
        }

        // Get the SITS mappings that need to be processed, e.g. mapped but not in local_assess_type.
        $sql = "SELECT m.*
                FROM {" . manager::TABLE_ASSESSMENT_MAPPING . "} m
                LEFT JOIN {local_assess_type} a
                ON m.courseid = a.courseid
                AND (
                    (m.sourcetype = '" . assessmentfactory::SOURCETYPE_MOD . "' AND m.sourceid = a.cmid)
                    OR
                    (m.sourcetype = '" . assessmentfactory::SOURCETYPE_GRADE_ITEM . "' AND m.sourceid = a.gradeitemid)
                    OR
                    (m.sourcetype = '" . assessmentfactory::SOURCETYPE_GRADE_CATEGORY . "' AND a.gradeitemid = (
                        SELECT gi.id
                        FROM {grade_items} gi
                        WHERE gi.iteminstance = m.sourceid
                        AND gi.itemtype = 'category'
                        AND gi.courseid = m.courseid
                    ))
                )
                WHERE a.id IS NULL";

        $mappings = $DB->get_records_sql($sql);

        if (empty($mappings)) {
            // No mappings to process, exit.
            mtrace(date(self::DATE_TIME_FORMAT, time()) . ' : ' . 'No mappings to process.');
            return;
        }

        // Process the mappings.
        foreach ($mappings as $mapping) {
            // Add assessment type records.
            mtrace(date(self::DATE_TIME_FORMAT, time()) . ' : Adding assessment type record for mapping [#' . $mapping->id . ']');
            assesstype::update_assess_type($mapping, assesstype::ACTION_LOCK);
        }
    }
}
