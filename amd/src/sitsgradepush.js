import {schedulePushTask, getAssessmentsUpdate} from "./sitsgradepush_helper";
import {createProgressBar, updateProgressBar, createSpinner} from "./progress";
import notification from 'core/notification';

let updatePageIntervalId = null; // The interval ID for updating the progress.
let updatePageDelay = 15000; // The delay for updating the page.

/**
 * Initialize the course module marks transfer page (index.php).
 *
 * @param {int} courseid
 * @param {int} coursemoduleid
 * @param {int[]} mappingids
 */
export const init = (courseid, coursemoduleid, mappingids) => {
    // Initialize the transfer marks button.
    initPushButton(courseid, mappingids);

    // Update the tasks progresses.
    updateTasksInfo(courseid);

    // Update the tasks progresses every 15 seconds.
    updatePageIntervalId = setInterval(() => {
        updateTasksInfo(courseid);
    }, updatePageDelay);

    // Add event listener to stop update the page when the page is not visible. e.g. when the user switches to another tab.
    document.addEventListener("visibilitychange", function() {
        if (document.visibilityState === "hidden") {
            clearInterval(updatePageIntervalId);
        } else {
            updateTasksInfo(courseid);
            updatePageIntervalId = setInterval(() => {
                updateTasksInfo(courseid);
            }, updatePageDelay);
        }
    });
};

/**
 * Initialize the transfer marks button.
 *
 * @param {int} courseid
 * @param {int[]} mappingids
 */
function initPushButton(courseid, mappingids) {
    // Get the push button.
    let pushbuton = document.getElementById('local_sitsgradepush_pushbutton_async');

    // Exit if the push button is not found.
    if (pushbuton === null) {
        return;
    }

    let promises = [];

    // Schedule a push task for each assessment mapping.
    pushbuton.addEventListener('click', async(e) => {
        e.preventDefault();

        // Number of assessment mappings.
        let total = mappingids.length;
        let count = 0;

        // Schedule a task to push grades to SITS for each assessment mapping.
        mappingids.forEach(function(mappingid) {
            let promise = schedulePushTask(mappingid)
                .then(function(result) {
                    if (result.success) {
                        count = count + 1;
                    } else {
                        // Create an error message row.
                        let errormessagerow = document.createElement("tr");
                        errormessagerow.setAttribute("class", "error-message-row");
                        errormessagerow.innerHTML =
                            '<td colspan="6>">' +
                            '<div class="alert alert-danger" role="alert">' + result.message + '</div>' +
                            '</td>';

                        // Find the task status container.
                        let taskstatus = document.getElementById('task-status-container-' + mappingid);

                        // Find the closest row to the assessment mapping.
                        let currentrow = taskstatus.closest("tr");

                        // Remove the existing error message row if it exists.
                        if (currentrow.nextElementSibling !== null &&
                            currentrow.nextElementSibling.classList.contains("error-message-row")) {
                            currentrow.nextElementSibling.remove();
                        }

                        // Insert the error message row for the assessment mapping.
                        currentrow.insertAdjacentElement("afterend", errormessagerow);
                    }
                    return result.success;
                })
                .catch(function(error) {
                    window.console.error(error);
                });

            promises.push(promise);
        });

        // Wait for all the push tasks to be scheduled.
        await Promise.all(promises);

        await updateTasksInfo(courseid);

        // Display a notification.
        await notification.addNotification({
            message: count + ' of ' + total + ' push tasks have been scheduled.',
            type: (count === total) ? 'success' : 'warning'
        });
    });
}

/**
 * Update all marks transfer tasks information.
 * e.g. progress bars, spinners and last transferred task date.
 *
 * @param {int} courseid
 * @return {void}
 */
async function updateTasksInfo(courseid) {
    // Get all latest tasks statuses.
    let update = await getAssessmentsUpdate(courseid);
    if (update.success) {
        // Parse the JSON string.
        let assessments = JSON.parse(update.assessments);

        if (assessments.length > 0) {
            // Update the progress bars and spinners.
            updateProgress(assessments);

            // Update the last transferred task date.
            updateLastTransferredTaskDate(assessments);
        } else {
            clearInterval(updatePageIntervalId);
        }
    } else {
        // Stop updating the tasks information if there is an error getting the updated tasks information.
        clearInterval(updatePageIntervalId);
        window.console.error(update.message);
    }
}

/**
 * Update the progress bars and spinners.
 *
 * @param {object[]} assessments
 */
function updateProgress(assessments) {
    // Get the task status containers.
    let taskStatusContainers = document.querySelectorAll('.task-status');

    // Filter assessments that are having task in progress.
    let assessmentsHasTasks = assessments.filter(assessment => assessment.task !== null);

    // The assessment mapping IDs having task in progress.
    let assessmentIds = new Set(assessmentsHasTasks.map(item => item.assessmentmappingid));

    // Remove the progress bars and spinners for the assessment mappings that are not having task in progress.
    taskStatusContainers.forEach(taskStatusContainer => {
        if (!assessmentIds.has(taskStatusContainer.getAttribute('data-assessmentmappingid'))) {
            taskStatusContainer.innerHTML = '';
        }
    });

    // Update the task status containers with progress bars and spinner.
    assessmentsHasTasks.forEach(assessment => {
        let task = assessment.task;
        let progressBarId = 'progress-bar-' + task.assessmentmappingid;
        let progressBar = document.getElementById(progressBarId);

        // If the progress bar not exists, create a new one, otherwise update the progress.
        if (!progressBar) {
            progressBar = createProgressBar(progressBarId, 'async', task.assessmentmappingid, 0);
            let taskStatusContainer = document.getElementById('task-status-container-' + task.assessmentmappingid);
            if (taskStatusContainer) {
                let spinner = createSpinner('text-primary', 'spinner-border-sm');
                taskStatusContainer.appendChild(spinner);
                taskStatusContainer.appendChild(progressBar);
            }
        } else {
            updateProgressBar(progressBar, task.progress);
        }
    });
}

/**
 * Update the last transferred task date.
 *
 * @param {object[]} assessments
 */
function updateLastTransferredTaskDate(assessments) {
    let containers = document.querySelectorAll('.last-transfer-task-date');

    containers.forEach(container => {
        let assessment = assessments.find(
            assessment => assessment.assessmentmappingid === container.getAttribute('data-assessmentmappingid')
        );

        if (assessment && assessment.lasttransfertime !== null) {
            container.innerHTML = assessment.lasttransfertime;
        }
    });
}
