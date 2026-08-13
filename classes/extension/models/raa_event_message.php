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

    /** @var int Number of elements in the raw required provisions array. */
    protected int $provisionscount = 0;

    /** @var string|null Raw event timestamp string from the message. */
    protected ?string $eventtimestamp = null;

    /**
     * Constructor.
     *
     * Nothing is rejected here. A message that is missing person_sora, the student code or the
     * required provisions is still modelled, so the queue processor can decide whether the message
     * is actionable and ignore it with a reason rather than fail and retry it.
     *
     * @param \stdClass $messagedata Data from the RAA event message.
     */
    public function __construct(\stdClass $messagedata) {
        $personsora = $messagedata->entity?->person_sora ?? null;

        // Set properties.
        $this->changes = $messagedata->changes ?? [];
        $this->eventtimestamp = $messagedata->timestamp ?? null;
        $this->typecode = $personsora?->type?->code ?? null;
        $this->typename = $personsora?->type?->name ?? null;
        $this->studentcode = $personsora?->person?->student_code ?? null;
        $this->raastatus = $personsora?->accessibility_assessment_status ?? null;

        $requiredprovisions = $personsora?->required_provisions ?? null;
        if (!is_array($requiredprovisions)) {
            return;
        }
        $this->provisionscount = count($requiredprovisions);

        // Extract first element if required provisions is an array with single element.
        // There are a few event message types with multiple required_provisions. None of these messages are actionable,
        // either because the provision does not contain extension information or because the relevant fields are empty.
        if ($this->provisionscount === 1) {
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
     * Get the RAA status change reported in the message.
     *
     * @return array|null Array with 'from' and 'to' keys, or null if the status did not change.
     */
    public function get_status_change(): ?array {
        foreach ($this->changes as $change) {
            if (!isset($change->attribute) || !str_contains($change->attribute, self::RAA_STATUS_FIELD)) {
                continue;
            }

            return [
                'from' => $change->from ?? null,
                'to' => $change->to ?? null,
            ];
        }

        return null;
    }

    /**
     * Check if the reported status change crosses the approved boundary, i.e. the status moved from
     * approved to any other status, or from any other status to approved. A change between two
     * non-approved statuses does not affect any extension, so it is not a crossing.
     *
     * @return bool
     */
    public function crosses_approved_boundary(): bool {
        $change = $this->get_status_change();
        if ($change === null) {
            return false;
        }

        $wasapproved = $change['from'] === sora::RAA_STATUS_APPROVED;
        $isapproved = $change['to'] === sora::RAA_STATUS_APPROVED;

        return $wasapproved !== $isapproved;
    }

    /**
     * Check if the message carries a single required provision for a known assessment type, which is
     * the shape needed to apply an extension straight from the message data.
     *
     * @return bool
     */
    public function qualifies_for_provision_processing(): bool {
        return $this->typecode === sora::RAA_MESSAGE_TYPE_RAPAS
            && $this->provisionscount === 1
            && !empty($this->requiredprovisions?->get_assessment_type_code());
    }

    /**
     * Get the event time of the message in microseconds since the epoch. The message timestamp is
     * microsecond precision, e.g. 20260812T120338.008085UTC, whereas the SQS envelope timestamp is
     * only accurate to the second, which is too coarse to order messages published moments apart.
     *
     * @return int|null Microseconds since the epoch, or null if the timestamp cannot be parsed.
     */
    public function get_event_time_microseconds(): ?int {
        if (empty($this->eventtimestamp)) {
            return null;
        }

        // Strip the trailing timezone designator, the timezone is supplied to the parser instead.
        $timestamp = preg_replace('/UTC$/', '', trim($this->eventtimestamp));
        $datetime = \DateTimeImmutable::createFromFormat('Ymd\THis.u', $timestamp, new \DateTimeZone('UTC'));
        if ($datetime === false) {
            return null;
        }

        return (int) $datetime->format('U') * 1000000 + (int) $datetime->format('u');
    }
}
