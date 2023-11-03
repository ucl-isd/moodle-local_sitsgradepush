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

namespace local_sitsgradepush\submission;

/**
 * Factory class for submission.
 *
 * @package    local_sitsgradepush
 * @copyright  2023 onwards University College London {@link https://www.ucl.ac.uk/}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @author     Alex Yeung <k.yeung@ucl.ac.uk>
 */
class submissionfactory {
    /**
     * Return submission object.
     *
     * @param \int $coursemoduleid course module
     * @param int $userid user id
     * @return submission
     * @throws \coding_exception
     * @throws \dml_exception
     * @throws \moodle_exception
     */
    public static function get_submission(int $coursemoduleid, int $userid) {
        // Get course module.
        $coursemodule = get_coursemodule_from_id(null, $coursemoduleid);

        // Throw exception if the course module is not found.
        if (empty($coursemodule)) {
            throw new \moodle_exception('error:coursemodulenotfound', 'local_sitsgradepush', '', $coursemoduleid);
        }

        switch ($coursemodule->modname) {
            case 'quiz':
                return new quiz($coursemodule, $userid);
            case 'assign':
                return new assign($coursemodule, $userid);
            case 'turnitintooltwo':
                return new turnitintooltwo($coursemodule, $userid);
        }

        throw new \moodle_exception('Mod name '. $coursemodule->modulename .' not found.');
    }
}
