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
 * Immutable assessment component of a SITS module delivery, with its resolved Moodle target.
 *
 * @package    local_sitsgradepush
 * @copyright  2026 onwards University College London {@link https://www.ucl.ac.uk/}
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @author     Alex Yeung <k.yeung@ucl.ac.uk>
 */
class delivery_component {
    /**
     * Constructor.
     *
     * @param int $mabid Local component grade (MAB) id.
     * @param string $name Component name.
     * @param int|null $weight Component weighting as a percentage, or null when unknown.
     * @param int|null $gradeitemid Resolved Moodle grade item id, or null when unmapped.
     * @param int|null $cmid Resolved course module id for activity-backed components, or null.
     * @param string|null $sourcetype Mapping source type, or null when unmapped.
     */
    public function __construct(
        /** @var int Local component grade (MAB) id. */
        public readonly int $mabid,
        /** @var string Component name. */
        public readonly string $name,
        /** @var string Component sequence number */
        public readonly string $mabseq,
        /** @var int|null Component weighting as a percentage, or null when unknown. */
        public readonly ?int $weight,
        /** @var int|null Resolved Moodle grade item id, or null when unmapped. */
        public readonly ?int $gradeitemid,
        /** @var int|null Resolved course module id for activity-backed components, or null. */
        public readonly ?int $cmid,
        /** @var string|null Mapping source type, or null when unmapped. */
        public readonly ?string $sourcetype,
    ) {
    }
}
