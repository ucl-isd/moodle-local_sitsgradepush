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

namespace local_sitsgradepush\output;

use local_sitsgradepush\assessment\assessment;
use local_sitsgradepush\manager;
use plugin_renderer_base;

/**
 * Output renderer for local_sitsgradepush.
 *
 * @package    local_sitsgradepush
 * @copyright  2023 onwards University College London {@link https://www.ucl.ac.uk/}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @author     Alex Yeung <k.yeung@ucl.ac.uk>
 */
class renderer extends plugin_renderer_base {
    /**
     * Render a simple button.
     *
     * @param string $id
     * @param string $name
     * @param string $disabled
     * @return string
     * @throws \moodle_exception
     */
    public function render_button(string $id, string $name, string $disabled = '') : string {
        return $this->output->render_from_template(
            'local_sitsgradepush/button',
            ['id' => $id, 'name' => $name, 'disabled' => $disabled]
        );
    }

    /**
     * Render the assessment push status table.
     *
     * @param array $assessmentdata
     * @return string
     * @throws \moodle_exception
     */
    public function render_assessment_push_status_table(array $assessmentdata) : string {
        // Remove the T character in the timestamp.
        foreach ($assessmentdata as &$data) {
            $data->handin_datetime = str_replace('T', ' ', $data->handin_datetime);
        }

        return $this->output->render_from_template('local_sitsgradepush/assessmentgrades', [
            'assessmentdata' => $assessmentdata,
        ]);
    }
}
