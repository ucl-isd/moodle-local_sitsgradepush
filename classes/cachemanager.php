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

namespace local_sitsgradepush;

use cache;
use cache_application;
use cache_session;
use cache_store;

/**
 * Cache manager class for handling caches.
 *
 * @package     local_sitsgradepush
 * @copyright   2023 onwards University College London {@link https://www.ucl.ac.uk/}
 * @license     https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @author      Alex Yeung <k.yeung@ucl.ac.uk>
 */
class cachemanager {
    /** @var string Cache area for storing students in an assessment component.*/
    const CACHE_AREA_STUDENTSPR = 'studentspr';

    /** @var string Cache area for storing students in an assessment component.*/
    const CACHE_AREA_COMPONENTGRADES = 'componentgrades';

    /** @var string Cache area for storing marking schemes.*/
    const CACHE_AREA_MARKINGSCHEMES = 'markingschemes';

    /**
     * Get cache.
     *
     * @param string $area
     * @param string $key
     * @return cache_application|cache_session|cache_store|null
     * @throws \coding_exception
     */
    public static function get_cache(string $area, string $key) {
        // Check if cache exists or expired.
        $cache = cache::make('local_sitsgradepush', $area)->get($key);
        // Expire key.
        $expires = 'expires_' . $key;
        if (empty($cache) || empty($expires) || time() >= $expires) {
            return null;
        } else {
            return $cache;
        }
    }

    /**
     * Set cache.
     *
     * @param string $area
     * @param string $key
     * @param mixed $value
     * @param int $expiresafter
     * @return void
     */
    public static function set_cache(string $area, string $key, mixed $value, int $expiresafter): void {
        $cache = cache::make('local_sitsgradepush', $area);
        $cache->set($key, $value);
        $cache->set('expires_' . $key, $expiresafter);
    }

    /**
     * Purge cache.
     *
     * @param  string  $area
     * @param  string  $key
     *
     * @return void
     * @throws \coding_exception
     */
    public static function purge_cache(string $area, string $key): void {
        $cache = cache::make('local_sitsgradepush', $area);
        if (!empty($cache->get($key))) {
            $cache->delete($key);
            $cache->delete('expires_' . $key);
        }
    }
}
