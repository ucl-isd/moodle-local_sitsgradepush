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

namespace local_sitsgradepush\extension;

use local_sitsgradepush\assessment\assessment;
use local_sitsgradepush\extensionmanager;

/**
 * Class for combined due date (CDD).
 * The combined due date is the final due date calculated by SITS for a student,
 * incorporating EC, DAP, RAA and manual day based extensions.
 *
 * @package    local_sitsgradepush
 * @copyright  2026 onwards University College London {@link https://www.ucl.ac.uk/}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @author     Alex Yeung <k.yeung@ucl.ac.uk>
 */
class cdd extends ec {
    /** @var string Student programme route code */
    protected string $sprcode = '';

    /** @var int EC days extension */
    protected int $ecdaysext = 0;

    /** @var int DAP days extension */
    protected int $dapdaysext = 0;

    /** @var int RAA days extension */
    protected int $raadaysext = 0;

    /** @var int Manual days extension */
    protected int $manualdaysext = 0;

    /** @var bool Indicate if the student has a combined due date */
    protected bool $hascombinedduedate = false;

    /**
     * Check if the student has a combined due date.
     *
     * @return bool
     */
    public function has_combined_due_date(): bool {
        return $this->hascombinedduedate;
    }

    /**
     * Get the student programme route code.
     *
     * @return string
     */
    public function get_sprcode(): string {
        return $this->sprcode;
    }

    /**
     * Get the extension type constant used for override lookups.
     *
     * @return string
     */
    protected function get_extension_type(): string {
        return extensionmanager::EXTENSION_CDD;
    }

    /**
     * Determine whether a mapping should be skipped during extension processing.
     * Unlike EC, reassessment mappings are not skipped as the combined due date applies to reassessments too.
     *
     * @param \stdClass $mapping
     * @return bool
     */
    protected function should_skip_mapping(\stdClass $mapping): bool {
        return false;
    }

    /**
     * Delete any active EC and RAA overrides before applying the combined due date.
     *
     * @param assessment $assessment
     * @param \stdClass $mapping
     * @return void
     */
    protected function before_apply_extension(assessment $assessment, \stdClass $mapping): void {
        // Delete any active EC override first so the combined due date override
        // snapshots the true state before any plugin applied override.
        $ecoverride = extensionmanager::get_active_user_mt_overrides_by_mapid(
            $mapping->id,
            $mapping->sourceid,
            extensionmanager::EXTENSION_EC,
            $this->userid
        );
        if (!empty($ecoverride)) {
            $assessment->delete_user_override($ecoverride);
        }

        // Delete any RAA overrides for the student as the combined due date already includes RAA days.
        $assessment->delete_raa_overrides($this->userid);
    }

    /**
     * Set the CDD properties from the combined due date API.
     *
     * @param array $cddrecord Combined due date record of the student from the combined due date API.
     * @return void
     */
    public function set_properties_from_cdd_api(array $cddrecord): void {
        // Set the student programme route code and derive the student code from it.
        // The student code is the part before the slash, e.g. 12345678/1 has student code 12345678.
        $this->sprcode = $cddrecord['student_programme_route_code'] ?? '';
        $this->studentcode = explode('/', $this->sprcode)[0];

        // Set the user ID from the student code.
        $this->set_userid($this->studentcode);

        // Resolve the effective dataset, i.e. the latest re-assessment entry if present, otherwise the top level record.
        $effective = $this->get_effective_dataset($cddrecord);

        // Set the day based extension breakdown fields.
        $this->ecdaysext = (int)($effective['ec_dys_ext'] ?? 0);
        $this->dapdaysext = (int)($effective['dap_dys_ext'] ?? 0);
        $this->raadaysext = (int)($effective['raa_dys_ext'] ?? 0);
        $this->manualdaysext = (int)($effective['manual_dys_ext'] ?? 0);

        // A combined due date exists only when there is a calculated due date and at least one day extension.
        $calcduedate = $effective['calc_due_dt'] ?? '';
        $this->hascombinedduedate = !empty($calcduedate) &&
            ($this->ecdaysext > 0 || $this->dapdaysext > 0 || $this->raadaysext > 0 || $this->manualdaysext > 0);

        // Set the new deadline only when the student has a combined due date,
        // so that a gated out record is treated the same as no combined due date.
        if ($this->hascombinedduedate) {
            $this->newdeadline = $calcduedate;
        }

        // Set data source.
        $this->datasource = self::DATASOURCE_API;
        $this->dataisset = true;
    }

    /**
     * Get the effective dataset from a combined due date record.
     * Use the re-assessment entry with the highest sequence number if present, otherwise the top level record.
     *
     * @param array $cddrecord Combined due date record of the student from the combined due date API.
     * @return array
     */
    private function get_effective_dataset(array $cddrecord): array {
        if (!empty($cddrecord['re-assessment']) && is_array($cddrecord['re-assessment'])) {
            $effective = null;
            $highestseq = -1;
            foreach ($cddrecord['re-assessment'] as $entry) {
                if (!is_array($entry)) {
                    continue;
                }
                $seq = (int)($entry['reassessment_sequence'] ?? 0);
                if ($seq > $highestseq) {
                    $highestseq = $seq;
                    $effective = $entry;
                }
            }
            if ($effective !== null) {
                return $effective;
            }
        }

        return $cddrecord;
    }
}
