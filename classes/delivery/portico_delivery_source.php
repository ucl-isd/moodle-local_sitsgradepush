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

use block_portico_enrolments\manager as portico_manager;
use local_sitsgradepush\assessment\assessmentfactory;
use local_sitsgradepush\delivery\models\delivery;
use local_sitsgradepush\delivery\models\delivery_component;
use local_sitsgradepush\delivery\models\delivery_key;
use local_sitsgradepush\manager;
use moodle_exception;
use stdClass;

/**
 * Sources course deliveries from portico module occurrences and local component grades.
 *
 * This adapter is the single seam that touches the external SITS systems. It degrades to an empty
 * result when those systems are missing or unavailable, so the course page never fatals.
 *
 * @package    local_sitsgradepush
 * @copyright  2026 onwards University College London {@link https://www.ucl.ac.uk/}
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @author     Alex Yeung <k.yeung@ucl.ac.uk>
 */
class portico_delivery_source {
    /**
     * Get the course's SITS module deliveries, each with its resolved components.
     *
     * @param int $courseid
     * @return delivery[]
     */
    public function get_course_deliveries(int $courseid): array {
        $modocc = $this->get_modocc_mappings($courseid);
        if (empty($modocc)) {
            return [];
        }

        $manager = manager::get_manager();
        $deliveries = [];
        foreach ($manager->get_local_component_grades($modocc) as $moduledelivery) {
            $key = new delivery_key(
                $moduledelivery->modcode,
                $moduledelivery->modocc,
                $moduledelivery->periodslotcode,
                $moduledelivery->academicyear,
            );

            $components = [];
            foreach ($moduledelivery->componentgrades as $mab) {
                $components[] = $this->build_component($courseid, $mab);
            }

            $deliveries[] = new delivery($key, $moduledelivery->modoccname, $components);
        }

        return $deliveries;
    }

    /**
     * Build a delivery component from a local component grade (MAB), resolving its Moodle target.
     *
     * @param int $courseid
     * @param stdClass $mab Local component grade record.
     * @return delivery_component
     */
    private function build_component(int $courseid, stdClass $mab): delivery_component {
        global $DB;

        $mabid = (int) $mab->id;
        $name = $mab->mabname;
        $mabseq = $mab->mabseq;
        $weight = isset($mab->mabperc) ? (int) $mab->mabperc : null;

        // Find the non-reassessment mapping for this component grade in this course.
        $mapping = $DB->get_record(manager::TABLE_ASSESSMENT_MAPPING, [
            'courseid' => $courseid,
            'componentgradeid' => $mab->id,
            'reassessment' => 0,
        ], 'id, sourcetype, sourceid');

        if (!$mapping) {
            return new delivery_component($mabid, $name, $mabseq, $weight, null, null, null);
        }

        $cmid = $mapping->sourcetype === assessmentfactory::SOURCETYPE_MOD ? (int) $mapping->sourceid : null;
        $gradeitemid = $this->resolve_gradeitemid($mapping->sourcetype, (int) $mapping->sourceid);

        return new delivery_component($mabid, $name, $mabseq, $weight, $gradeitemid, $cmid, $mapping->sourcetype);
    }

    /**
     * Resolve the primary grade item id for a mapping's source, or null when unavailable.
     *
     * @param string $sourcetype
     * @param int $sourceid
     * @return int|null
     */
    private function resolve_gradeitemid(string $sourcetype, int $sourceid): ?int {
        try {
            $assessment = assessmentfactory::get_assessment($sourcetype, $sourceid);
            $gradeitems = $assessment->get_grade_items();

            if (empty($gradeitems)) {
                return null;
            }

            return reset($gradeitems)->id;
        } catch (moodle_exception $e) {
            debugging($e->getMessage(), DEBUG_DEVELOPER);
            return null;
        }
    }

    /**
     * Get the course's portico module occurrence mappings, degrading to an empty array.
     *
     * Treats an unavailable SITS database as "no deliveries" so callers never fatal when the
     * external system is missing or down.
     *
     * @param int $courseid
     * @return array
     */
    private function get_modocc_mappings(int $courseid): array {
        try {
            return portico_manager::get_modocc_mappings($courseid);
        } catch (\Throwable $e) {
            debugging($e->getMessage(), DEBUG_DEVELOPER);
            return [];
        }
    }
}
