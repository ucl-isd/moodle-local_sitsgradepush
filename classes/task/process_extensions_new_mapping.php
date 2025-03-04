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
use local_sitsgradepush\extensionmanager;
use local_sitsgradepush\logger;
use local_sitsgradepush\manager;

/**
 * Ad-hoc task to process extensions, i.e. SORA and EC.
 *
 * @package    local_sitsgradepush
 * @copyright  2024 onwards University College London {@link https://www.ucl.ac.uk/}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @author     Alex Yeung <k.yeung@ucl.ac.uk>
 */
class process_extensions_new_mapping extends adhoc_task {

    /**
     * Return name of the task.
     *
     * @return string
     * @throws \coding_exception
     */
    public function get_name() {
        return get_string('task:process_extensions_new_mapping', 'local_sitsgradepush');
    }

    /**
     * Execute the task.
     */
    public function execute() {
        try {
            // Get task data.
            $data = $this->get_custom_data();

            // Check the assessment component id is set.
            if (!isset($data->mapid)) {
                throw new \moodle_exception('error:customdatamapidnotset', 'local_sitsgradepush');
            }

            // Check assessment mapping exists.
            if (!$mapping = manager::get_manager()->get_mab_and_map_info_by_mapping_id($data->mapid, true)) {
                throw new \moodle_exception('error:mab_or_mapping_not_found', 'local_sitsgradepush', '', $data->mapid);
            }

            $students = manager::get_manager()->get_students_from_sits($mapping, true);

            // Process SORA extension.
            extensionmanager::update_sora_for_mapping($mapping, $students);

            // Process EC extension (To be implemented).
        } catch (\Exception $e) {
            $mapid = $data->mapid ? 'Map ID: ' . $data->mapid : '';
            logger::log($e->getMessage(), null, $mapid);
        }
    }
}
