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

use local_courserollover\manager;
use moodle_url;
use plugin_renderer_base;
use stdClass;

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
     * @param string $url
     * @return string
     * @throws \moodle_exception
     */
    public function render_button(string $id, string $name, string $url) : string {
        return $this->output->render_from_template('local_sitsgradepush/button', ['id' => $id, 'name' => $name, 'url' => $url]);
    }

    /**
     * Render the table displaying the grades of an assessment.
     *
     * @param int $coursemoduleid
     * @param string $assessmentname
     * @param array $grades
     * @return string
     * @throws \moodle_exception
     */
    public function render_grades_table(int $coursemoduleid, string $assessmentname, array $grades) : string {
        $manager = \local_sitsgradepush\manager::get_manager();
        $grades = array_values($grades);
        foreach ($grades as &$grade) {
            $grade->status = '-';
            $pushstatus = $manager->get_grade_push_status($coursemoduleid, $grade->userid);
            if (!empty($pushstatus)) {
                if ($pushstatus->responsecode == 0) {
                    $grade->status = 'Grade pushed successfully.';
                } else {
                    $grade->status = 'Last push with error code: ' . $pushstatus->responsecode;
                }
                $grade->lastpushtime = date('Y-m-d H:i:s', $pushstatus->timecreated);
            }
        }
        return $this->output->render_from_template('local_sitsgradepush/assessmentgrades', [
            'assessmentname' => $assessmentname,
            'grades' => $grades,
        ]);
    }

    /**
     * Render the table showing the grade push result.
     *
     * @param string $assessmentname
     * @param array $grades
     * @return string
     * @throws \moodle_exception
     */
    public function render_push_result_table(string $assessmentname, array $grades) : string {
        $grades = array_values($grades);
        foreach ($grades as &$grade) {
            $grade->pushtime = str_replace('T', ' ', $grade->pushtime);
        }
        return $this->output->render_from_template('local_sitsgradepush/pushresults', [
            'assessmentname' => $assessmentname,
            'grades' => $grades,
        ]);
    }
}
