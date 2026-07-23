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

use core\task\adhoc_task;
use core\task\manager as coretaskmanager;
use local_sitsgradepush\extensionmanager;
use local_sitsgradepush\logger;
use local_sitsgradepush\manager;

/**
 * Ad-hoc task to process extensions for all eligible assessment mappings.
 *
 * @package    local_sitsgradepush
 * @copyright  2026 onwards University College London {@link https://www.ucl.ac.uk/}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @author     Alex Yeung <k.yeung@ucl.ac.uk>
 */
class process_extensions_all_mappings extends adhoc_task {
    /** @var int Number of mappings to process per batch. */
    const BATCH_LIMIT = 30;

    /**
     * Return name of the task.
     *
     * @return string
     * @throws \coding_exception
     */
    public function get_name() {
        return get_string('task:process_extensions_all_mappings', 'local_sitsgradepush');
    }

    /**
     * Execute the task.
     */
    public function execute() {
        global $DB;

        $data = $this->get_custom_data();
        $courseid = $data->courseid ?? 0;
        $extensiontype = $data->extensiontype ?? 'all';
        $lastprocessedid = $data->lastprocessedid ?? 0;

        $sql = "SELECT am.*, cg.mapcode, cg.mabseq, cg.astcode
                FROM {" . manager::TABLE_ASSESSMENT_MAPPING . "} am
                JOIN {" . manager::TABLE_COMPONENT_GRADE . "} cg ON am.componentgradeid = cg.id
                WHERE am.enableextension = 1 AND am.id > :lastprocessedid";

        $params = ['lastprocessedid' => $lastprocessedid];

        if (!empty($courseid)) {
            $sql .= " AND am.courseid = :courseid";
            $params['courseid'] = $courseid;
        }

        $sql .= " ORDER BY am.id ASC";

        $mappings = $DB->get_records_sql($sql, $params, 0, self::BATCH_LIMIT);

        $lastid = $lastprocessedid;

        foreach ($mappings as $mapping) {
            try {
                $fullmapping = manager::get_manager()->get_mab_and_map_info_by_mapping_id($mapping->id);

                if (!$fullmapping) {
                    throw new \moodle_exception('error:mab_or_mapping_not_found', 'local_sitsgradepush', '', $mapping->id);
                }

                $students = manager::get_manager()->get_students_from_sits($fullmapping, true, 2);

                // Process combined due date (CDD) extension first so EC and RAA are only applied
                // to students not already handled by the combined due date.
                $cddhandled = [];
                if ($extensiontype === 'cdd' || $extensiontype === 'all') {
                    $cddhandled = extensionmanager::update_cdd_for_mapping($fullmapping);
                }

                // Exclude students handled by the combined due date from EC and RAA processing.
                $remaining = extensionmanager::filter_out_cdd_handled_students($students, $cddhandled);

                if ($extensiontype === 'raa' || $extensiontype === 'all') {
                    extensionmanager::update_sora_for_mapping($fullmapping, $remaining);
                }

                if ($extensiontype === 'ec' || $extensiontype === 'all') {
                    extensionmanager::update_ec_for_mapping($fullmapping, $remaining);
                }

                $lastid = $mapping->id;
            } catch (\Exception $e) {
                $courseinfotext = $courseid ? "Course ID: $courseid, " : "";
                logger::log($e->getMessage(), null, "{$courseinfotext}Mapping ID: {$mapping->id}");
            }
        }

        $sql = "SELECT COUNT(am.id)
                FROM {" . manager::TABLE_ASSESSMENT_MAPPING . "} am
                WHERE am.enableextension = 1 AND am.id > :lastprocessedid";

        $params = ['lastprocessedid' => $lastid];

        if (!empty($courseid)) {
            $sql .= " AND am.courseid = :courseid";
            $params['courseid'] = $courseid;
        }

        $remaining = $DB->count_records_sql($sql, $params);

        if ($remaining > 0) {
            $nexttask = new self();
            $nexttask->set_custom_data([
                'courseid' => $courseid,
                'extensiontype' => $extensiontype,
                'lastprocessedid' => $lastid,
            ]);
            coretaskmanager::queue_adhoc_task($nexttask);
        }
    }

    /**
     * Check if an ad-hoc task with an overlapping scope already exists.
     *
     * Overlap rules:
     * - Course scope overlaps if either is 0 (all courses) or both are the same course.
     * - Extension type overlaps if either is "all" or both are the same type.
     * - A task is considered overlapping if both course scope and extension type overlap.
     *
     * @param int $courseid Course ID, 0 for all courses.
     * @param string $extensiontype Extension type: "raa", "ec", "cdd", or "all".
     * @return bool
     */
    public static function adhoc_task_exists(int $courseid, string $extensiontype): bool {
        global $DB;

        $sql = "SELECT id, customdata
                FROM {task_adhoc}
                WHERE classname = :classname";

        $params = [
            'classname' => '\\' . __CLASS__,
        ];

        $tasks = $DB->get_records_sql($sql, $params);

        foreach ($tasks as $task) {
            $data = json_decode($task->customdata ?? '');
            if (empty($data)) {
                continue;
            }

            $existingcourseid = (int)($data->courseid ?? 0);
            $existingtype = $data->extensiontype ?? 'all';

            // Check course scope overlap.
            $courseoverlap = ($existingcourseid === 0
                || $courseid === 0
                || $existingcourseid === $courseid);

            // Check extension type overlap.
            $typeoverlap = ($existingtype === 'all'
                || $extensiontype === 'all'
                || $existingtype === $extensiontype);

            if ($courseoverlap && $typeoverlap) {
                return true;
            }
        }

        return false;
    }
}
