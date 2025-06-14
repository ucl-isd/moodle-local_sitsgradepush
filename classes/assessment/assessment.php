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

namespace local_sitsgradepush\assessment;

use core\clock;
use core\di;
use local_sitsgradepush\extension\ec;
use local_sitsgradepush\extension\extension;
use local_sitsgradepush\extension\sora;
use local_sitsgradepush\extensionmanager;
use local_sitsgradepush\manager;

/**
 * Parent class for assessment.
 *
 * @package    local_sitsgradepush
 * @copyright  2023 onwards University College London {@link https://www.ucl.ac.uk/}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @author     Alex Yeung <k.yeung@ucl.ac.uk>
 */
abstract class assessment implements iassessment {

    /** @var string Grade failed */
    const GRADE_FAIL = 'F';

    /** @var string Grade absent */
    const GRADE_ABSENT = 'AB';

    /** @var int Source instance id. E.g. course module id for activities, grade item id for grade items. */
    public int $id;

    /** @var string Source instance type. E.g. mod, gradeitem, gradecategory. */
    public string $type;

    /** @var mixed Source instance. */
    protected mixed $sourceinstance;

    /** @var clock Clock instance. */
    protected readonly clock $clock;

    /** @var int SITS mapping id */
    protected int $sitsmappingid = 0;

    /**
     * Constructor.
     *
     * @param string $sourcetype
     * @param int $sourceid
     */
    public function __construct(string $sourcetype, int $sourceid) {
        $this->id = $sourceid;
        $this->type = $sourcetype;
        $this->clock = di::get(clock::class);
        $this->set_instance();
    }

    /**
     * Apply extension to the assessment.
     *
     * @param extension $extension
     * @return void
     * @throws \moodle_exception
     */
    public function apply_extension(extension $extension): void {
        $check = $this->is_valid_for_extension();
        if (!$check->valid) {
            throw new \moodle_exception($check->errorcode, 'local_sitsgradepush');
        }

        // Skip extension if there is an extension added by exam guard.
        if ($this->has_exam_guard_extension()) {
            return;
        }

        // Do extension base on the extension type.
        if ($extension instanceof ec) {
            $this->apply_ec_extension($extension);
        } else if ($extension instanceof sora) {
            // Skip SORA overrides if the end date of the assessment is in the past.
            if ($this->get_end_date() < $this->clock->time()) {
                return;
            }

            // Remove user from all SORA groups in this assessment.
            if ($extension->get_time_extension() == 0) {
                $this->remove_user_from_previous_sora_groups($extension->get_userid());
                return;
            }

            $this->apply_sora_extension($extension);
        }
    }

    /**
     * Get the source id.
     *
     * @return int
     */
    public function get_id(): int {
        return $this->id;
    }

    /**
     * Get the source type.
     *
     * @return string
     */
    public function get_type(): string {
        return $this->type;
    }

    /**
     * Get the URL to the marks transfer history page.
     *
     * @param bool $escape
     * @return string
     * @throws \moodle_exception
     */
    public function get_assessment_transfer_history_url(bool $escape): string {
        $url = new \moodle_url(
            '/local/sitsgradepush/index.php',
            [
                'courseid' => $this->get_course_id(),
                'sourcetype' => $this->get_type(),
                'id' => $this->get_id(),
            ]
        );

        return $url->out($escape);
    }

    /**
     * Check if this assessment can be mapped to a given mab.
     *
     * @param int|\stdClass $mab
     * @param int $reassess
     * @return bool
     * @throws \dml_exception
     */
    public function can_map_to_mab(int|\stdClass $mab, int $reassess): bool {
        // Check the assessment is valid for marks transfer.
        $validity = $this->check_assessment_validity();
        if (!$validity->valid) {
            return false;
        }

        $manager = manager::get_manager();
        // Variable $mab is an integer.
        if (is_int($mab)) {
            // Get the mab object.
            $mab = $manager->get_local_component_grade_by_id($mab);
        }

        // Get all mappings for this assessment.
        $mappings = $manager->get_assessment_mappings($this);

        // Check if the mab is valid for a new mapping if existing mappings are found.
        if (!empty($mappings)) {
            foreach ($mappings as $mapping) {
                // An assessment can only map to one marks transfer type, i.e. normal or re-assessment.
                if ($mapping->reassessment != $reassess) {
                    return false;
                }
                // Check this assessment does not map to the same mab.
                if ($mapping->componentgradeid == $mab->id) {
                    return false;
                }
                // Check this assessment does not map to a mab that has the same map code.
                if ($mapping->mapcode == $mab->mapcode) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * Get the source instance.
     *
     * @return mixed
     */
    public function get_source_instance(): mixed {
        return $this->sourceinstance;
    }

    /**
     * Get the start date of the assessment.
     *
     * @return int|null
     */
    public function get_start_date(): ?int {
        return null;
    }

    /**
     * Get the end date of the assessment.
     *
     * @return int|null
     */
    public function get_end_date(): ?int {
        return null;
    }

    /**
     * Get module name. Return empty string if not applicable.
     *
     * @return string
     */
    public function get_module_name(): string {
        return '';
    }

    /**
     * Check if the assessment is valid for marks transfer.
     *
     * @return \stdClass
     */
    public function check_assessment_validity(): \stdClass {
        $result = $this->set_validity_result(true);

        // Get all grade items related to this assessment.
        $gradeitems = $this->get_grade_items();

        // No grade items found.
        if (empty($gradeitems)) {
            return $this->set_validity_result(false, 'error:grade_items_not_found');
        }

        // Check if any grade items are valid.
        foreach ($gradeitems as $gradeitem) {
            if ($gradeitem->gradetype != GRADE_TYPE_VALUE) {
                $result = $this->set_validity_result(false, 'error:gradetype_not_supported');
                break;
            }

            // Check the grade min and grade max.
            if ($gradeitem->grademin != 0 || $gradeitem->grademax != 100) {
                $result = $this->set_validity_result(false, 'error:grademinmax');
                break;
            }
        }

        return $result;
    }

    /**
     * Check if the assessment is valid for EC or SORA extension.
     *
     * @return \stdClass
     */
    public function is_valid_for_extension(): \stdClass {
        if ($this->get_start_date() === null || $this->get_end_date() === null) {
            return $this->set_validity_result(false, 'error:assessmentdatesnotset');
        }

        return $this->set_validity_result(true);
    }

    /**
     * Delete all SORA override for a Moodle assessment.
     * It is used to delete all SORA overrides for an assessment when the mapping is removed.
     *
     * @return void
     * @throws \moodle_exception
     */
    public function delete_all_sora_overrides(): void {
        // Default not supported. Override in child class if needed.
        throw new \moodle_exception('error:soraextensionnotsupported', 'local_sitsgradepush');
    }

    /**
     * Check if extension is supported for this assessment type.
     *
     * @return bool
     */
    public function is_extension_supported(): bool {
        return in_array($this->get_module_name(), extension::SUPPORTED_MODULE_TYPES);
    }

    /**
     * Get the URL to the overrides page.
     * Override in child class if needed.
     *
     * @param string $mode
     * @param bool $escape
     * @return string
     */
    public function get_overrides_page_url(string $mode, bool $escape = true): string {
        return '#';
    }

    /**
     * Check if the assessment has automated SORA override groups created.
     * Override in child class if needed.
     *
     * @return bool
     */
    public function has_sora_override_groups(): bool {
        return false;
    }

    /**
     * Delete applied EC override and restore original override if any.
     *
     * @param \stdClass $mtsavedoverride - Override record saved in marks transfer overrides table.
     * @return void
     */
    public function delete_ec_override(\stdClass $mtsavedoverride): void {
        // Default not supported. Override in child class if needed.
    }

    /**
     * Save override to marks transfer overrides table.
     *
     * @param int $mapid SITS mapping ID.
     * @param int $userid Moodle user ID.
     * @param \stdClass|false $mtoverride Marks transfer override record.
     * @param \stdClass $override Current override.
     * @param \stdClass|false $preexistsoverride Pre-existing override.
     * @param int|null $groupid Moodle group ID.
     *
     * @return void
     * @throws \dml_exception
     * @throws \moodle_exception
     */
    public function save_override(
        int $mapid,
        int $userid,
        \stdClass|false $mtoverride,
        \stdClass $override,
        \stdClass|false $preexistsoverride,
        ?int $groupid = null,
    ): void {
        global $DB, $USER;
        if (!$this->is_extension_supported()) {
            throw new \moodle_exception('error:extension_not_supported', 'local_sitsgradepush');
        }

        // Current override data to be saved.
        $data = [
            'overrideid' => $override->id,
            'override_data' => json_encode($override),
            'created_by' => $USER->id,
        ];
        $extensiontype = ($groupid) ? extensionmanager::EXTENSION_SORA : extensionmanager::EXTENSION_EC;

        // Update existing record without updating original override data field.
        if ($mtoverride) {
            // Update existing record.
            $data['timemodified'] = $this->clock->time();
            $DB->update_record(extensionmanager::TABLE_OVERRIDES, (object)array_merge((array)$mtoverride, $data));
        } else {
            // Prepare data for new record.
            $data = array_merge($data, [
                'mapid' => $mapid,
                'cmid' => $this->get_id(),
                'moduletype' => $this->get_module_name(),
                'moduleinstanceid' => $this->get_source_instance()->id,
                'userid' => $userid,
                'groupid' => $groupid,
                'extensiontype' => $extensiontype,
                'timecreated' => $this->clock->time(),
            ]);

            // Add original override data if we have pre-existing override data.
            if (!empty($preexistsoverride)) {
                $data['ori_override_data'] = json_encode($preexistsoverride);
            }
            $DB->insert_record(extensionmanager::TABLE_OVERRIDES, (object)$data);
        }
    }

    /**
     * Set the SITS mapping id.
     *
     * @param int $mappingid SITS mapping ID.
     * @return void
     */
    public function set_sits_mapping_id(int $mappingid): void {
        $this->sitsmappingid = $mappingid;
    }

    /**
     * Mark the saved override record in local_sitsgradepush_override table as restored.
     *
     * @param int $overrideid
     * @return void
     */
    protected function mark_override_restored(int $overrideid): void {
        global $DB, $USER;
        $DB->update_record(extensionmanager::TABLE_OVERRIDES,
            [
                'id' => $overrideid,
                'restored_by' => $USER->id,
                'timerestored' => $this->clock->time(),
            ]);
    }

    /**
     * Check if there is an extension record added by the exam guard plugin.
     *
     * @return bool
     */
    protected function has_exam_guard_extension(): bool {
        // Check if local_examguard plugin is installed and the moodle activity is an exam guard supported.
        if (\core_plugin_manager::instance()->get_plugin_info('local_examguard') &&
            $this->get_type() === assessmentfactory::SOURCETYPE_MOD &&
            \local_examguard\manager::is_exam_guard_supported_activity($this->get_module_name())) {
            // Check if there is an existing extension record for this course module.
            $examactivity = \local_examguard\examactivity\examactivityfactory::get_exam_activity(
                $this->get_id(),
                $this->get_module_name()
            );

            // An exam guard time extension was added to the moodle activity if current extension is greater than 0.
            return $examactivity->get_latest_extension() > 0;
        }
        return false;
    }

    /**
     * Set validity result.
     *
     * @param bool $valid
     * @param string $errorcode
     * @return \stdClass
     */
    protected function set_validity_result(bool $valid, string $errorcode = ''): \stdClass {
        $result = new \stdClass();
        $result->valid = $valid;
        $result->errorcode = $errorcode;
        return $result;
    }

    /**
     * Get the equivalent grade for a given mark.
     *
     * @param float $marks
     * @return string|null
     */
    protected function get_equivalent_grade_from_mark(float $marks): ?string {
        $equivalentgrade = null;
        if ($marks == 0) {
            $equivalentgrade = self::GRADE_FAIL;
        }
        return $equivalentgrade;
    }

    /**
     * Apply EC extension.
     *
     * @param ec $ec
     * @return void
     * @throws \moodle_exception
     */
    protected function apply_ec_extension(ec $ec): void {
        // Default not supported. Override in child class if needed.
        throw new \moodle_exception('error:ecextensionnotsupported', 'local_sitsgradepush');
    }

    /**
     * Apply SORA extension.
     *
     * @param sora $sora
     * @return void
     * @throws \moodle_exception
     */
    protected function apply_sora_extension(sora $sora): void {
        // Default not supported. Override in child class if needed.
        throw new \moodle_exception('error:soraextensionnotsupported', 'local_sitsgradepush');
    }

    /**
     * Get the override record.
     *
     * @param int $userid
     * @param int|null $groupid
     * @return mixed
     */
    protected function get_override_record(int $userid, ?int $groupid = null): mixed {
        return false;
    }

    /**
     * Get all participants for the assessment.
     *
     * @return array
     */
    abstract public function get_all_participants(): array;
}
