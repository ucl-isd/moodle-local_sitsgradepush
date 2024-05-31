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

namespace local_sitsgradepush\assessment;

/**
 * Factory class for assessment.
 *
 * @package    local_sitsgradepush
 * @copyright  2023 onwards University College London {@link https://www.ucl.ac.uk/}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @author     Alex Yeung <k.yeung@ucl.ac.uk>
 */
class assessmentfactory {

    /** @var string Course module source type */
    const SOURCETYPE_MOD = 'mod';

    /** @var string Grade item source type */
    const SOURCETYPE_GRADE_ITEM = 'gradeitem';

    /** @var string Grade category source type */
    const SOURCETYPE_GRADE_CATEGORY = 'gradecategory';

    /**
     * Return assessment object by a given mod name.
     *
     * @param string $sourcetype
     * @param int $id
     * @return assessment
     * @throws \moodle_exception
     */
    public static function get_assessment(string $sourcetype, int $id): Assessment {
        try {
            if ($sourcetype === self::SOURCETYPE_MOD) {
                $coursemodule = get_coursemodule_from_id('', $id);
                if (!$coursemodule) {
                    throw new \moodle_exception('error:coursemodulenotfound', 'local_sitsgradepush', '', $id);
                }
                $classname = $coursemodule->modname;
                $data = $coursemodule;
            } else {
                $classname = $sourcetype;
                $data = $id;
            }

            $fullclassname = __NAMESPACE__ . '\\' . $classname;
            if (!class_exists($fullclassname)) {
                throw new \moodle_exception('error:assessmentclassnotfound', 'local_sitsgradepush', '', $fullclassname);
            }

            return new $fullclassname($data);
        } catch (\Exception $e) {
            throw new \moodle_exception($e->getMessage());
        }
    }
}
