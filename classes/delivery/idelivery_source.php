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

use local_sitsgradepush\delivery\models\delivery;

/**
 * Source of the SITS module deliveries (with components) defined for a course.
 *
 * Implementations own the "what deliveries and components exist, and where they map" concern,
 * isolating callers from the external systems (e.g. portico and SITS) behind a single seam.
 *
 * @package    local_sitsgradepush
 * @copyright  2026 onwards University College London {@link https://www.ucl.ac.uk/}
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @author     Alex Yeung <k.yeung@ucl.ac.uk>
 */
interface idelivery_source {
    /**
     * Get the course's SITS module deliveries, each with its resolved components.
     *
     * @param int $courseid
     * @return delivery[]
     */
    public function get_course_deliveries(int $courseid): array;
}
