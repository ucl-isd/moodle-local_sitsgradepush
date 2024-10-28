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

use local_sitsgradepush\extension\sora;

/**
 * Manager class for extension related operations.
 *
 * @package    local_sitsgradepush
 * @copyright  2024 onwards University College London {@link https://www.ucl.ac.uk/}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @author     Alex Yeung <k.yeung@ucl.ac.uk>
 */
class extensionmanager {

    /**
     * Update SORA extension for students in a mapping.
     *
     * @param int $mapid
     * @return void
     * @throws \dml_exception
     */
    public static function update_sora_for_mapping(int $mapid): void {
        try {
            // Find the SITS assessment component.
            $manager = manager::get_manager();
            $mab = $manager->get_mab_by_mapping_id($mapid);

            // Throw exception if the SITS assessment component is not found.
            if (!$mab) {
                throw new \moodle_exception('error:mab_not_found', 'local_sitsgradepush', '', $mapid);
            }

            // Get students information for that assessment component.
            $students = $manager->get_students_from_sits($mab);

            // If no students found, nothing to do.
            if (empty($students)) {
                return;
            }

            // Process SORA extension for each student.
            foreach ($students as $student) {
                $sora = new sora();
                $sora->set_properties_from_get_students_api($student);
                $sora->process_extension();
            }
        } catch (\Exception $e) {
            logger::log($e->getMessage(), null, "Mapping ID: $mapid");
        }
    }
}
