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

use local_sitsgradepush\delivery\models\delivery_key;

/**
 * Resolves a student's deliveries from the live source, with no caching.
 *
 * A single-delivery course needs no membership query: the student necessarily belongs to the only
 * delivery. A multi-delivery course queries membership per delivery.
 *
 * @package    local_sitsgradepush
 * @copyright  2026 onwards University College London {@link https://www.ucl.ac.uk/}
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @author     Alex Yeung <k.yeung@ucl.ac.uk>
 */
class live_delivery_resolver implements istudent_delivery_resolver {
    /**
     * Constructor.
     *
     * @param idelivery_source $source Source of the course's deliveries.
     * @param imembership_checker $membership Tests delivery membership.
     */
    public function __construct(
        /** @var idelivery_source Source of the course's deliveries. */
        private readonly idelivery_source $source,
        /** @var imembership_checker Tests delivery membership. */
        private readonly imembership_checker $membership,
    ) {
    }

    /**
     * Resolve the deliveries a student belongs to in a course.
     *
     * @param int $courseid
     * @param int $userid
     * @return delivery_key[]
     */
    public function resolve(int $courseid, int $userid): array {
        $deliveries = $this->source->get_course_deliveries($courseid);
        if (empty($deliveries)) {
            return [];
        }

        // Single-delivery shortcut: no membership query needed.
        if (count($deliveries) === 1) {
            return [reset($deliveries)->key];
        }

        // Find out student belongs to which deliveries.
        $resolved = [];
        foreach ($deliveries as $delivery) {
            if ($this->membership->is_member($userid, $delivery)) {
                $resolved[] = $delivery->key;
            }
        }

        return $resolved;
    }
}
