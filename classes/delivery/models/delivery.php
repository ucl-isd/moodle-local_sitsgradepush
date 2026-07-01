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

namespace local_sitsgradepush\delivery\models;

/**
 * Immutable SITS module delivery with its assessment components.
 *
 * @package    local_sitsgradepush
 * @copyright  2026 onwards University College London {@link https://www.ucl.ac.uk/}
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @author     Alex Yeung <k.yeung@ucl.ac.uk>
 */
class delivery {
    /**
     * Constructor.
     *
     * @param delivery_key $key The delivery identity.
     * @param string $name Human readable delivery name.
     * @param delivery_component[] $components The delivery's assessment components.
     */
    public function __construct(
        /** @var delivery_key The delivery identity. */
        public readonly delivery_key $key,
        /** @var string Human readable delivery name. */
        public readonly string $name,
        /** @var delivery_component[] The delivery's assessment components. */
        public readonly array $components,
    ) {
    }
}
