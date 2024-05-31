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
 * Class for grade item assessment.
 *
 * @package    local_sitsgradepush
 * @copyright  2023 onwards University College London {@link https://www.ucl.ac.uk/}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @author     Alex Yeung <k.yeung@ucl.ac.uk>
 */
class gradeitem extends gradebook {

    /** @var string Display type name */
    const DISPLAY_TYPE_NAME = 'Grade Item';

    /**
     * Constructor.
     *
     * @param int $instanceid
     */
    public function __construct(int $instanceid) {
        parent::__construct(assessmentfactory::SOURCETYPE_GRADE_ITEM, $instanceid);
    }

    /**
     * Get the URL to the gradebook item single view page.
     *
     * @param bool $escape
     * @return string
     * @throws \moodle_exception
     */
    public function get_assessment_url(bool $escape): string {
        $url = new \moodle_url(
            '/grade/report/singleview/index.php',
            ['id' => $this->get_course_id(), 'item' => 'grade', 'itemid' => $this->get_source_instance()->id]
        );

        return $url->out($escape);
    }

    /**
     * Get the type name to display.
     *
     * @return string
     */
    public function get_display_type_name(): string {
        return self::DISPLAY_TYPE_NAME;
    }

    /**
     * Returns the grade item in array format.
     *
     * @return array
     */
    public function get_grade_items(): array {
        return [$this->get_source_instance()];
    }

    /**
     * Set the source instance.
     *
     * @return void
     */
    protected function set_instance(): void {
        global $CFG;
        require_once($CFG->libdir . '/gradelib.php');
        $this->sourceinstance = \grade_item::fetch(['id' => $this->get_id()]);
    }
}
