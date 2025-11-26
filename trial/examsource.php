<?php

require('../../../config.php');

// Require login.
require_login();

// Get user id from URL parameter.
$userid = optional_param('userid', $USER->id, PARAM_INT);

// Get daysahead from URL parameter.
$daysahead = optional_param('daysahead', null, PARAM_INT);

// Get context.
$context = context_system::instance();

// Verify user exists.
if (!$DB->record_exists('user', ['id' => $userid])) {
    throw new moodle_exception('invaliduserid', 'error');
}

// Get exams.
$exams = \local_sitsgradepush\manager::get_manager()->get_student_exams($userid, $daysahead);

// Set headers.
header('Content-Type: application/json');

// Output result.
echo json_encode($exams, JSON_PRETTY_PRINT);
