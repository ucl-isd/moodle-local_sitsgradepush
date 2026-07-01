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

use local_sitsgradepush\delivery\models\delivery;
use local_sitsgradepush\logger;
use local_sitsgradepush\manager;
use moodle_exception;

/**
 * Tests delivery membership by querying SITS for each of the delivery's components.
 *
 * @package    local_sitsgradepush
 * @copyright  2026 onwards University College London {@link https://www.ucl.ac.uk/}
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @author     Alex Yeung <k.yeung@ucl.ac.uk>
 */
class sits_membership_checker implements imembership_checker {
    /**
     * Whether the student belongs to the given delivery.
     *
     * @param int $userid
     * @param delivery $delivery
     * @return bool
     */
    public function is_member(int $userid, delivery $delivery): bool {
        global $DB;

        $manager = manager::get_manager();
        foreach ($delivery->components as $component) {
            $mab = $DB->get_record(manager::TABLE_COMPONENT_GRADE, ['id' => $component->mabid]);

            if (!$mab) {
                continue;
            }

            try {
                // Check the first assessment component is enough, student is existed either in all of them or none of them.
                $student = $manager->get_student_from_sits($mab, $userid);

                // This assessment identifier contains the PSL code (Term) information, e.g. "12345678_1-2025-T1-0".
                // As same SITS assessment component could be used for two module deliveries with just the term is different,
                // the term information in the assessment identifier is the only way to tell a student
                // is coming from which delivery currently.
                if (empty($student['assessment']['identifier'])) {
                    return false;
                }

                $pslcode = $manager->parse_pslcode_from_identifier($student['assessment']['identifier']);
                if ($delivery->key->periodslotcode === $pslcode) {
                    return true;
                }

                return false;
            } catch (moodle_exception $e) {
                logger::log_debug_error($e->getMessage());
            }
        }

        return false;
    }
}
