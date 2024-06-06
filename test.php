<?php
require_once('../../config.php');
require_once('../../mod/turnitintooltwo/turnitintooltwo_assignment.class.php');
require_login();

$id = required_param('id', PARAM_INT);

$assignment = new stdClass();
$assignment->id = $id;

function test($assignment, $task)
{
    global $DB, $CFG;
    @include_once($CFG->dirroot . "/lib/gradelib.php");

    $turnitintooltwoassignment = new turnitintooltwo_assignment($assignment->id);
    $cm = get_coursemodule_from_instance("turnitintooltwo", $turnitintooltwoassignment->turnitintooltwo->id,
        $turnitintooltwoassignment->turnitintooltwo->course);

    if ($cm) {
        $users = get_enrolled_users(context_module::instance($cm->id),
            'mod/turnitintooltwo:submit', groups_get_activity_group($cm), 'u.id');

        foreach ($users as $user) {
            $fieldlist = array('turnitintooltwoid' => $turnitintooltwoassignment->turnitintooltwo->id,
                'userid' => $user->id);

            // Set submission_unanon when needsupdating is used.
            if ($task == "needsupdating") {
                $fieldlist['submission_unanon'] = 1;
            }

            $grades = new stdClass();

            if ($submissions = $DB->get_records('turnitintooltwo_submissions', $fieldlist)) {
                $overallgrade = $turnitintooltwoassignment->get_overall_grade($submissions, $cm);
                if ($turnitintooltwoassignment->turnitintooltwo->grade < 0) {
                    // Using a scale.
                    $grades->rawgrade = ($overallgrade == '--') ? null : $overallgrade;
                } else {
                    $grades->rawgrade = ($overallgrade == '--') ? null : number_format($overallgrade, 2);
                }
                echo 'User: ' . $user->id . ', Overall Grade: ' . $overallgrade . ', Raw Grade: ' . $grades->rawgrade . '<br />';
            }
            $grades->userid = $user->id;
            $params['idnumber'] = $cm->idnumber;

            grade_update('mod/turnitintooltwo', $turnitintooltwoassignment->turnitintooltwo->course, 'mod',
                'turnitintooltwo', $turnitintooltwoassignment->turnitintooltwo->id, 0, $grades, $params);
        }

        // Remove the "anongradebook" flag.
        $updateassignment = new stdClass();
        $updateassignment->id = $assignment->id;

        // Depending on the task we need to update a different column.
        switch($task) {
            case "needsupdating":
                $updateassignment->needs_updating = 0;
                break;

            case "anongradebook":
                $updateassignment->anongradebook = 1;
                break;
        }

        $DB->update_record("turnitintooltwo", $updateassignment);
    }
}

test($assignment, 'anongradebook');