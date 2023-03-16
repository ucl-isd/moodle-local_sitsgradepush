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

/**
 * Plugin strings are defined here.
 *
 * @package     local_sitsgradepush
 * @category    string
 * @copyright   2023 onwards University College London {@link https://www.ucl.ac.uk/}
 * @license     https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @author      Alex Yeung <k.yeung@ucl.ac.uk>
 */

defined('MOODLE_INTERNAL') || die();

$string['pluginname'] = 'SITS Grade Push';
$string['settings:apiclientselect'] = 'Select API client';
$string['settings:apiclient'] = 'API client';
$string['settings:apiclient:desc'] = 'Choose which API client to use';
$string['settings:enable'] = 'Enable Grade Push';
$string['settings:enable:desc'] = 'Enable grade push to SITS';
$string['settings:generalsettingsheader'] = 'General Settings';
$string['settings:subpluginheader'] = 'Subplugin Settings';
$string['label:gradepushassessmentselect'] = 'Select SITS assessment to link to';
$string['label:reassessmentselect'] = 'Re-assessment';
$string['gradepushassessmentselect'] = 'Select SITS assessment';
$string['gradepushassessmentselect_help'] = 'Select SITS assessment to link to this activity.';
$string['reassessmentselect'] = 'Re-assessment';
$string['reassessmentselect_help'] = 'Select YES if it is a re-assessment.';
$string['subplugintype_sitsapiclient'] = 'API client used for data integration.';
$string['error:gradecomponentmapped'] = 'This component grade had been mapped to another activity.';
$string['error:pastactivity'] = 'It looks like this course is from a previous academic year, mappings are not allowed.';
