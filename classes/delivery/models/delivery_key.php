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

use stdClass;

/**
 * Immutable identity of a SITS module delivery.
 *
 * @package    local_sitsgradepush
 * @copyright  2026 onwards University College London {@link https://www.ucl.ac.uk/}
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @author     Alex Yeung <k.yeung@ucl.ac.uk>
 */
class delivery_key {
    /**
     * Constructor.
     *
     * @param string $modcode SITS module code.
     * @param string $modocc Module occurrence.
     * @param string $periodslotcode Period slot (PSL) code.
     * @param string $academicyear Academic year (AYR) code.
     */
    public function __construct(
        /** @var string SITS module code. */
        public readonly string $modcode,
        /** @var string Module occurrence. */
        public readonly string $modocc,
        /** @var string Period slot (PSL) code. */
        public readonly string $periodslotcode,
        /** @var string Academic year (AYR) code. */
        public readonly string $academicyear,
    ) {
    }

    /**
     * Build a key from a durable student delivery mapping record.
     *
     * @param stdClass $record
     * @return self
     */
    public static function from_record(stdClass $record): self {
        return new self(
            $record->modcode,
            $record->modocc,
            $record->periodslotcode,
            $record->academicyear,
        );
    }

    /**
     * Stable string representation, suitable for grouping or array keys.
     *
     * @return string
     */
    public function key(): string {
        return implode('-', [
            $this->modcode,
            $this->modocc,
            $this->periodslotcode,
            $this->academicyear,
        ]);
    }
}
