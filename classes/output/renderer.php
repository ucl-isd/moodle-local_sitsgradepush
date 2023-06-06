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

use local_sitsgradepush\errormanager;
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
     * Render a simple link.
     *
     * @param string $id
     * @param string $name
     * @param string $url
     * @return string
     * @throws \moodle_exception
     */
    public function render_link(string $id, string $name, string $url) : string {
        return $this->output->render_from_template('local_sitsgradepush/link', ['id' => $id, 'name' => $name, 'url' => $url]);
    }

    /**
     * Render the assessment push status table.
     *
     * @param array $assessmentdata
     * @return string
     * @throws \moodle_exception
     */
    public function render_assessment_push_status_table(array $assessmentdata) : string {
        // Modify the timestamp format and add the label for the last push result.
        foreach ($assessmentdata as &$data) {
            // Remove the T character in the timestamp.
            $data->handin_datetime = str_replace('T', ' ', $data->handin_datetime);
            // Add the label for the last push result.
            $data->lastgradepushresultlabel =
                is_null($data->lastgradepushresult) ? '' : $this->get_label_html($data->lastgradepusherrortype);
            // Add the label for the last submission log push result.
            $data->lastsublogpushresultlabel =
                is_null($data->lastsublogpushresult) ? '' : $this->get_label_html($data->lastsublogpusherrortype);
        }

        return $this->output->render_from_template('local_sitsgradepush/assessmentgrades', [
            'assessmentdata' => $assessmentdata,
        ]);
    }

    /**
     * Get the last push result label.
     *
     * @param int|null $errortype
     * @return string
     */
    private function get_label_html(int $errortype = null) : string {
        // This is for old data that does not have the error type.
        if (is_null($errortype)) {
            return '<span class="badge badge-danger">'.errormanager::get_error_label(errormanager::ERROR_UNKNOWN).'</span> ';
        }

        // Return different label based on the error type.
        switch ($errortype) {
            case 0:
                // Success result will have the error type 0.
                return '<span class="badge badge-success">Success</span> ';
            case errormanager::ERROR_STUDENT_NOT_ENROLLED:
                // Student not enrolled error will have a warning label.
                return '<span class="badge badge-warning">'.errormanager::get_error_label($errortype).'</span> ';
            default:
                // Other errors will have a danger label.
                return '<span class="badge badge-danger">'.errormanager::get_error_label($errortype).'</span> ';
        }
    }
}
