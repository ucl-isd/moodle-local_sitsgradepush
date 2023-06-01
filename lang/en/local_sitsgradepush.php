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
$string['privacy:metadata'] = 'This plugin does not store any personal data.';
$string['settings:apiclientselect'] = 'Select API client';
$string['settings:apiclient'] = 'API client';
$string['settings:apiclient:desc'] = 'Choose which API client to use';
$string['settings:enable'] = 'Enable Grade Push';
$string['settings:enable:desc'] = 'Enable grade push to SITS';
$string['settings:enableasync'] = 'Enable Async Grade Push';
$string['settings:enableasync:desc'] = 'If enabled, grade push will be done by an ad-hoc task';
$string['settings:enablesublogpush'] = 'Enable Submission Log Push';
$string['settings:enablesublogpush:desc'] = 'Enable submission log push to SITS';
$string['settings:generalsettingsheader'] = 'General Settings';
$string['settings:subpluginheader'] = 'Subplugin Settings';
$string['settings:moodleastcode'] = 'Moodle AST Codes';
$string['settings:moodleastcode:desc'] = 'Moodle AST Codes on SITS (Separated by comma)';
$string['settings:moodleastcodeexamroom'] = 'Moodle AST Codes work with Exam Room Code';
$string['settings:moodleastcodeexamroom:desc'] = 'These AST codes work only when exam room code has been specified (Separated by comma)';
$string['settings:moodleexamroomcode'] = 'Moodle Exam Room Code';
$string['settings:moodleexamroomcode:desc'] = 'Moodle Exam Room Code on SITS';
$string['settings:concurrenttasks'] = 'Number of concurrent tasks allowed';
$string['settings:concurrenttasks:desc'] = 'Number of concurrent ad-hoc tasks allowed';
$string['settings:userprofilefield'] = 'User Profile Field';
$string['settings:userprofilefield:desc'] = 'User profile field for export staff';
$string['label:gradepushassessmentselect'] = 'Select SITS assessment to link to';
$string['label:reassessmentselect'] = 'Re-assessment';
$string['label:pushgrade'] = 'Push grades';
$string['label:ok'] = 'OK';
$string['label:lastpushtext'] = 'Last scheduled push task {$a->statustext} {$a->date} at {$a->time}';
$string['gradepushassessmentselect'] = 'Select SITS assessment';
$string['gradepushassessmentselect_help'] = 'Select SITS assessment to link to this activity.';
$string['reassessmentselect'] = 'Re-assessment';
$string['reassessmentselect_help'] = 'Select YES if it is a re-assessment.';
$string['subplugintype_sitsapiclient'] = 'API client used for data integration.';
$string['cachedef_studentspr'] = 'Student\'s SPR code per SITS assessment pattern';

// Error strings.
$string['error:assessmentmapping'] = 'No valid mapping or component grade. {$a}';
$string['error:assessmentisnotmapped'] = 'This activity is not mapped to any assessment component.';
$string['error:gradecomponentmapped'] = 'This component grade had been mapped to another activity.';
$string['error:pastactivity'] = 'It looks like this course is from a previous academic year, mappings are not allowed.';
$string['error:mapassessment'] = 'You do not have permission to map assessment.';
$string['error:nostudentgrades'] = 'No student grades found.';
$string['error:emptyresponse'] = 'Empty response received when calling {$a}.';
$string['error:turnitin_numparts'] = 'Turnitin assignment with multiple parts is not supported by Grade Push.';
$string['error:duplicatedtask'] = 'There is already a push task in queue / processing for this course module.';
$string['error:coursemodulenotfound'] = 'Course module not found.';
$string['error:tasknotfound'] = 'Push task not found.';
$string['form:alert_no_mab_found'] = 'No assessment components found';
$string['form:info_turnitin_numparts'] = 'Please note Turnitin assignment with multiple parts is not supported by Grade Push.';

// Capability strings.
$string['sitsgradepush:mapassessment'] = 'Map assessment component to Moodle activity';
$string['sitsgradepush:pushgrade'] = 'Push grades to SITS';

// Task strings.
$string['task:pushtask:name'] = 'Schedule Push Task';
$string['task:adhoctask'] = 'Adhoc Task';
$string['task:status:requested'] = 'Push task requested';
$string['task:requested:success'] = 'Push task requested successfully';
$string['task:status:queued'] = 'Push task queued';
$string['task:status:processing'] = 'Push task processing';
$string['task:status:completed'] = 'completed';
$string['task:status:failed'] = 'failed';
