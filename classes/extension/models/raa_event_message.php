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
 * Model class for RAA event message from AWS.
 *
 * @package    local_sitsgradepush
 * @copyright  2026 onwards University College London {@link https://www.ucl.ac.uk/}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @author     Alex Yeung <k.yeung@ucl.ac.uk>
 */
class raa_event_message {
    /** @var string RAA status field name. */
    const RAA_STATUS_FIELD = 'accessibility_assessment_status';

    /** @var array Changes. */
    public array $changes = [];

    /** @var string|null RAA type code. */
    public ?string $typecode = null;

    /** @var string|null SORA type name. */
    public ?string $typename = null;

    /** @var string|null Student code. */
    public ?string $studentcode = null;

    /** @var raa_required_provisions|null Required provisions. */
    public ?raa_required_provisions $requiredprovisions = null;

    /** @var string|null RAA status. */
    public ?string $raastatus = null;

    /**
     * Constructor.
     *
     * @param \stdClass $messagedata Data from the RAA event message.
     * @throws \moodle_exception If required fields are missing.
     */
    public function __construct(\stdClass $messagedata) {
        // Validate message structure.
        $personsora = $messagedata->entity?->person_sora ?? null;
        if (!$personsora) {
            throw new \moodle_exception('error:missing_or_invalid_field', 'local_sitsgradepush', '', 'person_sora');
        }

        $studentcode = $personsora->person?->student_code ?? null;
        if (empty($studentcode)) {
            throw new \moodle_exception('error:missing_or_invalid_field', 'local_sitsgradepush', '', 'student_code');
        }

        $requiredprovisions = $personsora->required_provisions ?? null;
        if (empty($requiredprovisions)) {
            throw new \moodle_exception('error:missing_or_invalid_field', 'local_sitsgradepush', '', 'required_provisions');
        }

        // Set properties.
        $this->changes = $messagedata->changes ?? [];
        $this->typecode = $personsora->type?->code ?? null;
        $this->studentcode = $studentcode;
        $this->raastatus = $personsora->accessibility_assessment_status ?? null;
        // Extract first element if required provisions is an array with single element.
        if (is_array($requiredprovisions) && count($requiredprovisions) === 1) {
            $requiredprovisions = reset($requiredprovisions);
            $requiredprovisions->accessibility_assessment_status = $this->raastatus;
            $this->requiredprovisions = new raa_required_provisions((array) $requiredprovisions);
        }
    }

    /**
     * Get the student code.
     *
     * @return string|null
     */
    public function get_student_code(): ?string {
        return $this->studentcode;
    }

    /**
     * Get the RAA type code.
     *
     * @return string|null
     */
    public function get_type_code(): ?string {
        return $this->typecode;
    }

    /**
     * Get the required provisions.
     *
     * @return raa_required_provisions|null
     */
    public function get_required_provisions(): ?raa_required_provisions {
        return $this->requiredprovisions;
    }

    /**
     * Check if the message has changes.
     *
     * @return bool
     */
    public function has_changes(): bool {
        return !empty($this->changes);
    }

    /**
     * Check if the RAA status has changed.
     *
     * @return bool
     */
    public function has_status_changed(): bool {
        foreach ($this->changes as $change) {
            if (isset($change->attribute) && str_contains($change->attribute, self::RAA_STATUS_FIELD)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if the RAA status has changed to approved.
     *
     * @return bool
     */
    public function status_changed_to_approved(): bool {
        return $this->has_status_changed() && $this->raastatus === sora::RAA_STATUS_APPROVED;
    }
}
