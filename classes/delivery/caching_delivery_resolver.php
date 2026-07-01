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
use local_sitsgradepush\delivery\models\delivery_key;

/**
 * Caches resolution in a durable store, decorating an inner resolver.
 *
 * A stored result is served without recomputing; on a miss the inner resolver runs and its result
 * is persisted. Stored mappings are never auto-deleted, so they survive SITS later dropping the
 * student.
 *
 * A resolution that finds no delivery persists nothing, so it cannot be cached in the durable
 * store. To avoid calling the membership API on every page visit for such a student, an empty
 * result is remembered in a short-lived negative cache and re-checked once the cache expires.
 *
 * @package    local_sitsgradepush
 * @copyright  2026 onwards University College London {@link https://www.ucl.ac.uk/}
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @author     Alex Yeung <k.yeung@ucl.ac.uk>
 */
class caching_delivery_resolver implements istudent_delivery_resolver {
    /** @var int Seconds a "no delivery" result is trusted before the API is consulted again. */
    private const NEGATIVE_TTL = DAYSECS;

    /**
     * Constructor.
     *
     * @param istudent_delivery_resolver $inner The resolver to delegate to on a cache miss.
     * @param istudent_delivery_store $store The durable store to read and write.
     */
    public function __construct(
        /** @var istudent_delivery_resolver The resolver to delegate to on a cache miss. */
        private readonly istudent_delivery_resolver $inner,
        /** @var istudent_delivery_store The durable store to read and write. */
        private readonly istudent_delivery_store $store,
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
        $stored = $this->store->find($courseid, $userid);
        if (!empty($stored)) {
            return $stored;
        }

        $cachekey = $courseid . '_' . $userid;
        if (cachemanager::get_cache(cachemanager::CACHE_AREA_DELIVERYRESOLUTION, $cachekey)) {
            return [];
        }

        $resolved = $this->inner->resolve($courseid, $userid);
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
}
