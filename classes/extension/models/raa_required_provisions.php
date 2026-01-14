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

namespace local_sitsgradepush\extension\models;

use local_sitsgradepush\extension\sora;

/**
 * Model class for RAA required provisions.
 *
 * @package    local_sitsgradepush
 * @copyright  2026 onwards University College London {@link https://www.ucl.ac.uk/}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @author     Alex Yeung <k.yeung@ucl.ac.uk>
 */
class raa_required_provisions {
    /** @var string Extension by days. */
    const EXTENSION_DAYS = 'days';

    /** @var string Extension by hours. */
    const EXTENSION_HOURS = 'hours';

    /** @var string Extension by time per hour. */
    const EXTENSION_TIME_PER_HOUR = 'time_per_hour';

    /** @var string|null Provision tier. */
    public ?string $provisiontier = null;

    /** @var string|null Number of days extension. */
    public ?string $nodysext = null;

    /** @var string|null Number of hours extension. */
    public ?string $nohrsext = null;

    /** @var string|null Additional exam time. */
    public ?string $addexamtime = null;

    /** @var string|null Rest break additional time. */
    public ?string $restbrkaddtime = null;

    /** @var string|null Assessment type code. */
    public ?string $asmttypecode = null;

    /** @var string|null RAA status. */
    public ?string $raastatus = null;

    /**
     * Constructor.
     *
     * @param array $requiredprovisionsdata Data for required provisions.
     */
    public function __construct(array $requiredprovisionsdata) {
        // Set properties from the provided data.
        $this->provisiontier = $requiredprovisionsdata['provision_tier'] ?? null;
        $this->nodysext = $requiredprovisionsdata['no_dys_ext'] ?? null;
        $this->nohrsext = $requiredprovisionsdata['no_hrs_ext'] ?? null;
        $this->addexamtime = $requiredprovisionsdata['add_exam_time'] ?? null;
        $this->restbrkaddtime = $requiredprovisionsdata['rest_brk_add_time'] ?? null;
        $this->asmttypecode = $requiredprovisionsdata['asmnt_type_code'] ?? null;
        $this->raastatus = $requiredprovisionsdata['accessibility_assessment_status'] ?? null;
    }

    /**
     * Determine the extension type based on which fields have values.
     *
     * Valid cases (mutually exclusive):
     * - days: only nodysext has value
     * - hours: only nohrsext has value
     * - time_per_hour: addexamtime and/or restbrkaddtime have value
     * - null: all fields empty
     *
     * @return string|null Returns 'days', 'hours', 'time_per_hour', or null if no extension.
     * @throws \moodle_exception If invalid combination of fields.
     */
    public function get_extension_type(): ?string {
        $hasdays = $this->has_value($this->nodysext);
        $hashours = $this->has_value($this->nohrsext);
        $hastimeperhour = $this->has_value($this->addexamtime) || $this->has_value($this->restbrkaddtime);

        // Count how many extension types have values.
        $count = (int) $hasdays + (int) $hashours + (int) $hastimeperhour;

        // No extension if all fields empty.
        if ($count === 0) {
            return null;
        }

        // Invalid if more than one extension type has values.
        if ($count > 1) {
            throw new \moodle_exception('error:invalid_extension_scenario', 'local_sitsgradepush');
        }

        // Return the single valid extension type.
        if ($hasdays) {
            return self::EXTENSION_DAYS;
        }
        if ($hashours) {
            return self::EXTENSION_HOURS;
        }
        return self::EXTENSION_TIME_PER_HOUR;
    }

    /**
     * Check if any extension field has a value.
     *
     * @return bool
     */
    public function has_extension(): bool {
        return ($this->has_value($this->nodysext)
            || $this->has_value($this->nohrsext)
            || $this->has_value($this->addexamtime)
            || $this->has_value($this->restbrkaddtime))
            && $this->raastatus === sora::RAA_STATUS_APPROVED;
    }

    /**
     * Get the days extension value as integer.
     *
     * @return int|null
     */
    public function get_days_extension(): ?int {
        if (!$this->has_value($this->nodysext)) {
            return null;
        }
        return (int) $this->nodysext;
    }

    /**
     * Get the hours extension value as integer.
     *
     * @return int|null
     */
    public function get_hours_extension(): ?int {
        if (!$this->has_value($this->nohrsext)) {
            return null;
        }
        return (int) $this->nohrsext;
    }

    /**
     * Get the time per hour extension value (addexamtime + restbrkaddtime) as integer.
     *
     * @return int
     */
    public function get_time_per_hour_extension(): int {
        $addexamtime = $this->has_value($this->addexamtime) ? (int) $this->addexamtime : 0;
        $restbrkaddtime = $this->has_value($this->restbrkaddtime) ? (int) $this->restbrkaddtime : 0;
        return $addexamtime + $restbrkaddtime;
    }

    /**
     * Get the provision tier.
     *
     * @return string|null
     */
    public function get_provision_tier(): ?string {
        return $this->provisiontier;
    }

    /**
     * Get the assessment type code.
     *
     * @return string|null
     */
    public function get_assessment_type_code(): ?string {
        return $this->asmttypecode;
    }

    /**
     * Check if a value is not empty and not zero.
     *
     * @param string|null $value The value to check.
     * @return bool
     */
    private function has_value(?string $value): bool {
        return is_numeric($value) && (int) $value > 0;
    }
}
