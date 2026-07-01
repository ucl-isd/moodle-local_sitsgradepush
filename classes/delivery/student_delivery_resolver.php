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

use local_sitsgradepush\cachemanager;
use local_sitsgradepush\delivery\models\delivery;
use local_sitsgradepush\delivery\models\delivery_key;

/**
 * Resolves the SITS module deliveries a student belongs to, caching the outcome.
 *
 * A stored result is served from the durable store without recomputing; on a miss the live source
 * and membership checker are consulted and the result is persisted. Stored mappings are never
 * auto-deleted, so they survive SITS later dropping the student.
 *
 * A resolution that finds no delivery persists nothing, so it cannot be cached in the durable store.
 * To avoid calling the membership API on every page visit for such a student, an empty result is
 * remembered in a short-lived negative cache and re-checked once the cache expires.
 *
 * @package    local_sitsgradepush
 * @copyright  2026 onwards University College London {@link https://www.ucl.ac.uk/}
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @author     Alex Yeung <k.yeung@ucl.ac.uk>
 */
class student_delivery_resolver {
    /** @var int Seconds a "no delivery" result is trusted before the API is consulted again. */
    private const NEGATIVE_TTL = DAYSECS;

    /**
     * Constructor.
     *
     * @param portico_delivery_source $source Source of the course's deliveries.
     * @param db_student_delivery_store $store Durable store to read and write.
     * @param sits_membership_checker $membership Tests delivery membership.
     */
    public function __construct(
        /** @var portico_delivery_source Source of the course's deliveries. */
        private readonly portico_delivery_source $source,
        /** @var db_student_delivery_store Durable store to read and write. */
        private readonly db_student_delivery_store $store,
        /** @var sits_membership_checker Tests delivery membership. */
        private readonly sits_membership_checker $membership,
    ) {
    }

    /**
     * Resolve the deliveries a student belongs to in a course, persisting newly resolved mappings.
     *
     * @param int $courseid
     * @param int $userid
     * @return delivery_key[]
     */
    public function resolve(int $courseid, int $userid): array {
        $stored = $this->store->find($courseid, $userid);
        if (!empty($stored)) {
            return $stored;
        }

        $cachekey = $courseid . '_' . $userid;
        // It was queried recently and found no delivery, so skip the API call and return empty.
        if (cachemanager::get_cache(cachemanager::CACHE_AREA_DELIVERYRESOLUTION, $cachekey)) {
            return [];
        }

        $deliveries = $this->source->get_course_deliveries($courseid);
        if (empty($deliveries)) {
            return [];
        }

        // Single-delivery course: the student necessarily belongs to the only delivery. Return it
        // for use but do not persist, so only API-confirmed memberships are ever stored.
        if (count($deliveries) === 1) {
            return [reset($deliveries)->key];
        }

        $resolved = $this->resolve_live($deliveries, $userid);
        foreach ($resolved as $key) {
            $this->store->save($courseid, $userid, $key);
        }

        if (empty($resolved)) {
            // Remember "no delivery" for the TTL so repeat page visits skip the get-students API.
            cachemanager::set_cache(
                cachemanager::CACHE_AREA_DELIVERYRESOLUTION,
                $cachekey,
                true,
                self::NEGATIVE_TTL,
            );
        }

        return $resolved;
    }

    /**
     * Resolve which of the course's deliveries a student belongs to, via the SITS get-student API.
     *
     * @param delivery[] $deliveries The course's deliveries (guaranteed non-empty, count > 1).
     * @param int $userid
     * @return delivery_key[]
     */
    private function resolve_live(array $deliveries, int $userid): array {
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
