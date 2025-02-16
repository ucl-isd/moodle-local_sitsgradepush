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

$string['cachedef_componentgrades'] = 'SITS assessment components';
$string['cachedef_mappingmabinfo'] = 'SITS Mapping and MAB information';
$string['cachedef_markingschemes'] = 'SITS marking schemes';
$string['cachedef_studentspr'] = 'Student\'s SPR code per SITS assessment pattern';
$string['confirmmodal:body:partone'] = '
<p>After a student mark has been transferred to SITS, it cannot be recalled or overwritten.</p>
<p>If you need to make a change to a transferred mark, you can do this in the usual way in Portico.</p>';
$string['confirmmodal:body:parttwo'] = '
<p>Please be patient waiting for the marks transfer job to complete as a large set of marks can take some time.</p>
<p>You will receive an email once the task has completed, and can check the marks transfer status page for progress.</p>';
$string['confirmmodal:cancel'] = 'Cancel';
$string['confirmmodal:confirm'] = 'Confirm';
$string['confirmmodal:header'] = 'Confirm mark transfer?';
$string['confirmmodal:nonsubmission'] = 'Record non-submissions as 0 AB - only use after you have checked the EC and DAPs reports and taken into account any SoRA extended deadlines.';
$string['confirmmodal:warning'] = 'Warning';
$string['dashboard:academicyear'] = 'ACADEMIC YEAR: {$a}';
$string['dashboard:actions'] = 'ACTIONS';
$string['dashboard:asttype'] = 'AST TYPE';
$string['dashboard:changesource'] = 'Change source';
$string['dashboard:details'] = 'Details';
$string['dashboard:extensions'] = 'Extensions';
$string['dashboard:extensions:desc'] = 'SoRA extensions will automatically be applied to this assessment.';
$string['dashboard:extensions:view'] = 'View Extensions';
$string['dashboard:header'] = 'SITS assessment mapping and mark transfer';
$string['dashboard:header:desc'] = 'Map SITS assessments to Moodle activites and transfer marks from Moodle to SITS.';
$string['dashboard:header:reassess'] = 'SITS assessment mapping and mark transfer (Re-assessment)';
$string['dashboard:level'] = 'LEVEL: {$a}';
$string['dashboard:mab_perc'] = '{$a}%';
$string['dashboard:main_page'] = 'Main assessment';
$string['dashboard:mapcode'] = 'MAP CODE: {$a}';
$string['dashboard:marks_to_transfer'] = 'Marks to transfer';
$string['dashboard:marks_transfer_in_progress'] = 'Marks transfer in progress';
$string['dashboard:moduledelivery'] = 'MODULE DELIVERY';
$string['dashboard:moodle_activity'] = 'Moodle activity';
$string['dashboard:reassessment_page'] = 'Re-assessment';
$string['dashboard:remove_btn_content'] = 'Are you sure you want to remove source <strong>{$a->sourcename}</strong> for SITS assessment <strong>({$a->mabseq}) {$a->mabname}</strong>?';
$string['dashboard:remove_btn_title'] = 'Remove source';
$string['dashboard:seq'] = 'SEQ';
$string['dashboard:sits_assessment'] = 'SITS assessment';
$string['dashboard:source'] = 'Source';
$string['dashboard:transfermarks'] = 'Transfer marks';
$string['dashboard:type'] = 'Dashboard Type';
$string['dashboard:view_details'] = 'View details';
$string['dashboard:view_marks_to_transfer'] = 'View marks to transfer';
$string['dashboard:weight'] = 'Weight';
$string['email:assessment_name'] = 'Assessment name:';
$string['email:assessment_type'] = 'Assessment type:';
$string['email:best_regards'] = 'Best regards, <br>Digital Education Team';
$string['email:map_code'] = 'MAP-SEQ code:';
$string['email:no_of_failed_transfers'] = '<strong>Number of Marks that have failed to transfer:</strong> {$a}';
$string['email:no_of_succeeded_transfers'] = '<strong>Number of Marks transferred succesfully:</strong> {$a}';
$string['email:part_one'] = 'The following marks transfer task has now been completed:';
$string['email:sits_assessment'] = 'SITS assessment:';
$string['email:subject'] = 'Marks transfer task completed - {$a}';
$string['email:summary'] = 'Summary:';
$string['email:support_text'] = 'For guidance on how to resolve, transfer failures please visit our <a href="{$a}">Support Page</a>.';
$string['email:transfer_history_text'] = '<a href="{$a}">View marks transfer details</a> for this activity.';
$string['email:username'] = 'Dear {$a},';
$string['error:assessmentclassnotfound'] = 'Assessment class not found. Classname: {$a}';
$string['error:assessmentdatesnotset'] = 'Assessment start date or end date date is not set.';
$string['error:assessmentisnotmapped'] = 'This activity is not mapped to any assessment component.';
$string['error:assessmentmapping'] = 'Assessment mapping is not found. ID: {$a}';
$string['error:assessmentnotfound'] = 'Error getting assessment. ID: {$a}';
$string['error:ast_code_exam_room_code_not_matched'] = 'Centrally managed exam NOT due to take place in Moodle';
$string['error:ast_code_not_supported'] = 'Assessment Type {$a} is not expected to take place in Moodle';
$string['error:cannot_change_source'] = 'Cannot change source as marks have already been transferred for this assessment component.';
$string['error:cannotaddusertogroup'] = 'Cannot add user to the SORA group';
$string['error:cannotdisplaygradesforgradebookwhileregrading'] = 'Cannot display grades for gradebook item or category while grades are being recalculated.';
$string['error:cannotgetsoragroupid'] = 'Cannot get SORA group ID';
$string['error:componentgrademapped'] = '{$a} had been mapped to another activity.';
$string['error:componentgradepushed'] = '{$a} cannot be removed because it has Marks Transfer records.';
$string['error:course_data_not_set.'] = 'Course data not set.';
$string['error:coursemodulenotfound'] = 'Course module not found.';
$string['error:customdatamapidnotset'] = 'Mapping ID is not set in the task custom data.';
$string['error:duplicatedtask'] = 'There is already a transfer task in queue / processing for this assessment mapping.';
$string['error:duplicatemapping'] = 'Cannot map multiple assessment components with same module delivery to an activity. Mapcode: {$a}';
$string['error:ecextensionnotsupported'] = 'EC extension is not supported for this assessment.';
$string['error:empty_json_data'] = 'Empty JSON data';
$string['error:emptyresponse'] = 'Empty response received when calling {$a}.';
$string['error:extension_not_enabled_for_mapping'] = 'Extension is not enabled for this mapping. Mapping ID: {$a}';
$string['error:extensiondataisnotset'] = 'Extension data is not set.';
$string['error:failtomapassessment'] = 'Failed to map assessment component to source.';
$string['error:grade_items_not_found'] = 'Grade items not found.';
$string['error:gradebook_disabled'] = 'Gradebook transfer feature is disabled.';
$string['error:grademinmax'] = 'Grade min/max values are not 0 and 100.';
$string['error:gradesneedregrading'] = 'Marks transfer is not available while grades are being recalculated.';
$string['error:gradetype_not_supported'] = 'The grade type of this assessment is not supported for marks transfer.';
$string['error:inserttask'] = 'Failed to insert task.';
$string['error:invalid_json_data'] = 'Invalid JSON data: {$a}';
$string['error:invalid_message'] = 'Invalid message received.';
$string['error:invalid_sora_datasource'] = 'Invalid SORA data source.';
$string['error:invalid_source_type'] = 'Invalid source type. {$a}';
$string['error:lesson_practice'] = 'Practice lessons have no grades';
$string['error:lti_no_grades'] = 'LTI activity is set to not send grades to gradebook';
$string['error:mab_has_push_records'] = 'Assessment component mapping cannot be updated as marks have been transferred for {$a}';
$string['error:mab_invalid_for_mapping'] = 'This assessment component is not valid for mapping due to the following reasons: {$a}.';
$string['error:mab_not_found'] = 'Assessment component not found. ID: {$a}';
$string['error:mab_or_mapping_not_found'] = 'Mab or mapping not found. Mapping ID: {$a}';
$string['error:mapassessment'] = 'You do not have permission to map assessment.';
$string['error:mapping_locked'] = 'Mapping is locked as it passed the change source cut-off time.';
$string['error:marks_transfer_failed'] = 'Marks transfer failed.';
$string['error:missingparams'] = 'Missing parameters.';
$string['error:missingrequiredconfigs'] = 'Missing required configs.';
$string['error:mks_scheme_not_supported'] = 'Marking Scheme is not supported for marks transfer';
$string['error:multiplemappingsnotsupported'] = 'Multiple assessment component mappings is not supported by {$a}';
$string['error:no_mab_found'] = 'No assessment component found for this module delivery.';
$string['error:no_update_for_same_mapping'] = 'Nothing to update as the assessment component is already mapped to this activity.';
$string['error:nomoduledeliveryfound'] = 'No module delivery found.';
$string['error:nostudentfoundformapping'] = 'No student found for this assessment component.';
$string['error:nostudentgrades'] = 'No student marks found.';
$string['error:otherexceptions'] = 'Other exceptions message for phpunit test';
$string['error:pastactivity'] = 'It looks like this course is from a previous academic year, mappings are not allowed.';
$string['error:pastcourse'] = 'It looks like this course is from a previous academic year, marks transfer is not allowed.';
$string['error:pushgradespermission'] = 'You do not have permission to transfer marks.';
$string['error:reassessmentdisabled'] = 'Re-assessment marks transfer is disabled.';
$string['error:remove_mapping'] = 'You do not have permission to remove mapping.';
$string['error:resit_number_zero_for_reassessment'] = 'Student resit number should not be zero for a reassessment.';
$string['error:same_map_code_for_same_activity'] = 'An activity cannot be mapped to more than one assessment component with same map code';
$string['error:soraextensionnotsupported'] = 'SORA extension is not supported for this assessment.';
$string['error:studentnotfound'] = 'Student with idnumber {$a->idnumber} not found for component grade {$a->componentgrade}';
$string['error:submission_log_transfer_failed'] = 'Submission Transfer failed.';
$string['error:tasknotfound'] = 'Transfer task not found.';
$string['error:turnitin_numparts'] = 'Turnitin assignment with multiple parts is not supported by Marks Transfer.';
$string['error:user_data_not_set.'] = 'User data is not set.';
$string['event:assessment_mapped'] = 'Assessment mapped';
$string['event:assessment_mapped_desc'] = 'An assessment is mapped to a SITS assessment component.';
$string['form:alert_no_mab_found'] = 'No assessment components found';
$string['form:info_turnitin_numparts'] = 'Please note Turnitin assignment with multiple parts is not supported by Marks Transfer.';
$string['gradepushassessmentselect'] = 'Select SITS assessment';
$string['gradepushassessmentselect_help'] = 'Select SITS assessment to link to this activity.';
$string['index:absent'] = 'Absent';
$string['index:grade'] = 'Mark';
$string['index:header'] = 'SITS Marks Transfer Status';
$string['index:lastmarktransfer'] = 'Mark Transferred';
$string['index:lastsublogtransfer'] = 'Submission Date recorded in SITS';
$string['index:mark_changed_to'] = 'Mark changed to {$a} after transfer';
$string['index:porticonumber'] = 'Portico number';
$string['index:student'] = 'Student';
$string['index:submissiondate'] = 'Submission date';
$string['invalidstudents'] = 'Students not valid for the mapped assessment components';
$string['label:gradepushassessmentselect'] = 'Select SITS assessment to link to';
$string['label:jumpto'] = 'Jump to';
$string['label:lastpushtext'] = 'Last transferred {$a->date} at {$a->time}';
$string['label:mainassessment'] = 'Main assessment';
$string['label:ok'] = 'OK';
$string['label:pushall'] = 'Transfer All';
$string['label:pushgrade'] = 'Transfer Marks';
$string['label:reassessment'] = 'Re-assessment';
$string['marks_transferred_successfully'] = 'Marks Transferred Successfully';
$string['option:none'] = 'NONE';
$string['pluginname'] = 'SITS Marks Transfer';
$string['privacy:metadata'] = 'This plugin does not store any personal data.';
$string['privacy:metadata:local_sitsgradepush_err_log'] = 'Stores the error logs.';
$string['privacy:metadata:local_sitsgradepush_err_log:data'] = 'The data sent to SITS.';
$string['privacy:metadata:local_sitsgradepush_err_log:errortype'] = 'The error type.';
$string['privacy:metadata:local_sitsgradepush_err_log:message'] = 'The error message.';
$string['privacy:metadata:local_sitsgradepush_err_log:requesturl'] = 'The request\'s URL.';
$string['privacy:metadata:local_sitsgradepush_err_log:response'] = 'The response received from SITS.';
$string['privacy:metadata:local_sitsgradepush_err_log:userid'] = 'The user having the error.';
$string['privacy:metadata:local_sitsgradepush_tasks'] = 'Stores the transfer tasks.';
$string['privacy:metadata:local_sitsgradepush_tasks:info'] = 'Additional information about the transfer task.';
$string['privacy:metadata:local_sitsgradepush_tasks:status'] = 'The status of the transfer task.';
$string['privacy:metadata:local_sitsgradepush_tasks:userid'] = 'The user who requested the transfer task.';
$string['privacy:metadata:local_sitsgradepush_tfr_log'] = 'Stores the marks transfer records.';
$string['privacy:metadata:local_sitsgradepush_tfr_log:request'] = 'The request\'s URL';
$string['privacy:metadata:local_sitsgradepush_tfr_log:requestbody'] = 'The request\'s body';
$string['privacy:metadata:local_sitsgradepush_tfr_log:response'] = 'The response received from SITS';
$string['privacy:metadata:local_sitsgradepush_tfr_log:type'] = 'The type of the transfer task.';
$string['privacy:metadata:local_sitsgradepush_tfr_log:userid'] = 'Whose this transfer task is for.';
$string['privacy:metadata:local_sitsgradepush_tfr_log:usermodified'] = 'The user who requested the transfer task.';
$string['progress'] = 'Progress:';
$string['pushrecordsexist'] = 'Transfer records exist';
$string['pushrecordsnotexist'] = 'No transfer records';
$string['reassessmentselect'] = 'Re-assessment';
$string['reassessmentselect_help'] = 'Select YES if it is a re-assessment.';
$string['select'] = 'Select';
$string['selectsource:existing'] = 'Select an existing activity';
$string['selectsource:gradeitem'] = 'Select a Gradebook item';
$string['selectsource:header'] = 'Select source';
$string['selectsource:mul_turnitin'] = 'Advanced multiple Turnitin activity selector';
$string['selectsource:new'] = 'Create a new activity';
$string['selectsource:no_match_row'] = 'No matching rows found.';
$string['selectsource:title'] = 'SITS Marks Transfer - Select source';
$string['settings:apiclient'] = 'API client';
$string['settings:apiclient:desc'] = 'Choose which API client to use';
$string['settings:apiclientselect'] = 'Select API client';
$string['settings:astcodessoraapiv1'] = 'SITS assessment AST Codes for SORA API V1';
$string['settings:astcodessoraapiv1:desc'] = 'SITS assessment AST Codes that would have SORA data returned by assessment component API V1';
$string['settings:awsdelayprocesstime'] = 'AWS message delay process time';
$string['settings:awsdelayprocesstime:desc'] = 'Number of seconds to delay processing of AWS messages';
$string['settings:awskey'] = 'AWS Key';
$string['settings:awskey:desc'] = 'AWS access key id';
$string['settings:awsregion'] = 'AWS Region';
$string['settings:awsregion:desc'] = 'AWS Server Region';
$string['settings:awssecret'] = 'AWS Secret';
$string['settings:awssecret:desc'] = 'AWS secret access key';
$string['settings:awssettings'] = 'AWS';
$string['settings:awssettings:desc'] = 'Settings for AWS';
$string['settings:awssoraqueueurl'] = 'AWS SORA Queue URL';
$string['settings:awssoraqueueurl:desc'] = 'URL for receiving SORA SQS messages';
$string['settings:change_source_cutoff_time'] = 'Change source cut-off time';
$string['settings:change_source_cutoff_time:desc'] = 'Cut-off time in hours before the start / end date (depends on activity type) of the assessment to allow changing the source';
$string['settings:concurrenttasks'] = 'Number of concurrent tasks allowed';
$string['settings:concurrenttasks:desc'] = 'Number of concurrent ad-hoc tasks allowed';
$string['settings:enable'] = 'Enable Marks Transfer';
$string['settings:enable:desc'] = 'Enable Marks Transfer to SITS';
$string['settings:enableextension'] = 'Enable assessment extension';
$string['settings:enableextension:desc'] = 'Allow extension (EC / SORA) to be applied to assessments automatically';
$string['settings:enablesublogpush'] = 'Enable Submission Log transfer';
$string['settings:enablesublogpush:desc'] = 'Enable submission log transfer to SITS';
$string['settings:extension_support_page_url'] = 'URL for extension support page';
$string['settings:extension_support_page_url:desc'] = 'URL for extension support page';
$string['settings:generalsettingsheader'] = 'General Settings';
$string['settings:gradebook_enabled'] = 'Gradebook Enabled';
$string['settings:gradebook_enabled:desc'] = 'Enable gradebook item / category for marks transfer';
$string['settings:moodleastcode'] = 'Moodle AST Codes';
$string['settings:moodleastcode:desc'] = 'Moodle AST Codes on SITS (Separated by comma)';
$string['settings:moodleastcodeexamroom'] = 'Moodle AST Codes work with Exam Room Code';
$string['settings:moodleastcodeexamroom:desc'] = 'These AST codes work only when exam room code has been specified (Separated by comma)';
$string['settings:moodleexamroomcode'] = 'Moodle Exam Room Code';
$string['settings:moodleexamroomcode:desc'] = 'Moodle Exam Room Code on SITS';
$string['settings:reassessment_enabled'] = 'Enable Re-assessment Marks Transfer';
$string['settings:reassessment_enabled:desc'] = 'Allow Re-assessment Marks Transfer';
$string['settings:subpluginheader'] = 'Subplugin Settings';
$string['settings:support_page_url'] = 'Support Page URL';
$string['settings:support_page_url:desc'] = 'Used in the notification email to provide a link to the support page';
$string['settings:sync_threshold'] = 'Sync Threshold';
$string['settings:sync_threshold:desc'] = 'The threshold to allow for running synchronous mark transfer task';
$string['settings:userprofilefield'] = 'User Profile Field';
$string['settings:userprofilefield:desc'] = 'User profile field for export staff';
$string['sitsgradepush:mapassessment'] = 'Map assessment component to Moodle activity';
$string['sitsgradepush:pushgrade'] = 'Transfer Marks to SITS';
$string['sitsgradepush:showsubmissionlogcolumn'] = 'Show Submission Log column';
$string['subplugintype_sitsapiclient'] = 'API client used for data integration.';
$string['subplugintype_sitsapiclient_plural'] = 'API clients used for data integration.';
$string['task:adhoctask'] = 'Adhoc Task';
$string['task:assesstype:name'] = 'Insert Assessment Type for Pre-mapped Assessments';
$string['task:process_aws_sora_updates'] = 'Process AWS SORA updates';
$string['task:process_extensions_new_enrolment'] = 'Process SORA and EC extensions for new student enrolment';
$string['task:process_extensions_new_mapping'] = 'Process SORA and EC extensions for new assessment mapping';
$string['task:pushtask:name'] = 'Schedule Transfer Task';
$string['task:requested:success'] = 'Transfer task requested successfully';
$string['task:status:completed'] = 'completed';
$string['task:status:failed'] = 'failed';
$string['task:status:processing'] = 'Transfer task processing';
$string['task:status:queued'] = 'Transfer task queued';
$string['task:status:requested'] = 'Transfer task requested';
