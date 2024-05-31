import {getAssessmentsUpdate, schedulePushTask, updateProgressBar} from "./sitsgradepush_helper";
import notification from 'core/notification';

let updatePageIntervalId = null; // The interval ID for updating the progress.
let updatePageDelay = 15000; // The delay for updating the page.
let taskRunning = false;
let shouldRefresh = false;

/**
 * Initialize the course module marks transfer page (index.php).
 *
 * @param {int} courseid
 * @param {string} sourcetype
 * @param {int} sourceid
 */
export const init = (courseid, sourcetype, sourceid) => {
    // Initialize page update tasks.
    initPageUpdate(courseid, sourcetype, sourceid);

    // Initialize the confirmation modal.
    initConfirmationModal(courseid, sourcetype, sourceid);
};

/**
 * Initialize page update on course module marks transfer page (index.php).
 *
 * @param {int} courseid
 * @param {string} sourcetype
 * @param {int} sourceid
 */
function initPageUpdate(courseid, sourcetype, sourceid) {
    // Update the tasks progresses.
    updateTasksInfo(courseid, sourcetype, sourceid);

    // Update the tasks progresses every 15 seconds.
    updatePageIntervalId = setInterval(() => {
        updateTasksInfo(courseid, sourcetype, sourceid);
    }, updatePageDelay);

    // Add event listener to stop update the page when the page is not visible. e.g. when the user switches to another tab.
    document.addEventListener("visibilitychange", function() {
        if (document.visibilityState === "hidden") {
            clearInterval(updatePageIntervalId);
        } else {
            updateTasksInfo(courseid, sourcetype, sourceid);
            updatePageIntervalId = setInterval(() => {
                updateTasksInfo(courseid, sourcetype, sourceid);
            }, updatePageDelay);
        }
    });
}

/**
 * Initialize the confirmation modal.
 *
 * @param {int} courseid
 * @param {string} sourcetype
 * @param {int} sourceid
 */
function initConfirmationModal(courseid, sourcetype, sourceid) {
    // Find the confirmation modal.
    let confirmTransferButton = document.getElementById("js-transfer-modal-button");

    // Exit if the confirmation modal is not found.
    if (confirmTransferButton === null) {
        window.console.log("Confirmation modal not found.");
        return;
    }

    // Add event listener to the confirmation modal.
    confirmTransferButton.addEventListener("click", async function() {
        // Check if it is an async push button.
        let sync = confirmTransferButton.getAttribute('data-sync');
        if (sync === "1") {
            // Do sync push.
            window.location.href = `/local/sitsgradepush/index.php?courseid=${courseid}&sourcetype=${sourcetype}&id=${sourceid}` +
            `&pushgrade=1`;
        } else {
            // Do async push.
            let promises = [];

            // Find all valid assessment mapping IDs.
            let mappingtables = document.querySelectorAll('.sitsgradepush-history-table');

            // Number of assessment mappings.
            let total = mappingtables.length - 1; // Exclude the invalid students table.
            let count = 0;

            // Schedule a task to push grades to SITS for each assessment mapping.
            mappingtables.forEach(function(table) {
                let mappingid = table.getAttribute('data-assessmentmappingid');
                let markscount = table.getAttribute('data-markscount');
                if (mappingid !== null && markscount > 0) {
                    let promise = schedulePushTask(mappingid)
                        .then(function(result) {
                            if (result.success) {
                                count = count + 1;
                            } else {
                                // Create an error message row.
                                let errormessageid = "error-message-" + mappingid;
                                let errormessagerow = document.createElement("div");
                                errormessagerow.setAttribute("id", errormessageid);
                                errormessagerow.setAttribute("class", "error-message-row");
                                errormessagerow.innerHTML =
                                    '<div class="alert alert-danger" role="alert">' + result.message + '</div>';

                                // Find the closest row to the assessment mapping.
                                let currentrow = document.getElementById(errormessageid);

                                // Remove the error message row if it exists.
                                if (currentrow !== null) {
                                    currentrow.remove();
                                }

                                // Insert the error message above the table.
                                table.parentNode.insertBefore(errormessagerow, table);
                            }
                            return result.success;
                        })
                        .catch(function(error) {
                            window.console.error(error);
                        });

                    promises.push(promise);
                }
            });

            // Wait for all the push tasks to be scheduled.
            await Promise.all(promises);

            // Update the page.
            await updateTasksInfo(courseid, sourcetype, sourceid);

            // Display a notification.
            await notification.addNotification({
                message: count + ' of ' + total + ' push tasks have been scheduled.',
                type: (count === total) ? 'success' : 'warning'
            });
        }
    });
}

/**
 * Update all marks transfer tasks information, e.g. progress bars.
 *
 * @param {int} courseid
 * @param {string} sourcetype
 * @param {int} sourceid
 * @return {Promise<*>}
 */
async function updateTasksInfo(courseid, sourcetype, sourceid) {
    // Get all latest tasks statuses.
    let update = await getAssessmentsUpdate(courseid, sourcetype, sourceid);
    if (update.success) {
        // Parse the JSON string.
        let assessments = JSON.parse(update.assessments);
        if (assessments.length > 0) {
            // Update the progress bars.
            updateProgress(assessments);
        } else {
            clearInterval(updatePageIntervalId);
        }
    } else {
        // Stop updating the tasks information if there is an error getting the updated tasks information.
        clearInterval(updatePageIntervalId);
        window.console.error(update.message);
    }

    return update;
}

/**
 * Update the progress bars.
 *
 * @param {object[]} assessments
 */
function updateProgress(assessments) {
    // Check if there is any running task.
    taskRunning = hasRunningTask(assessments);

    // If there is any running task, mark page should be refreshed.
    if (taskRunning) {
        shouldRefresh = true;
    }

    // Refresh the page if there is no running task and should be refreshed.
    if (shouldRefresh && !taskRunning) {
        shouldRefresh = false;
        location.reload();
    }

    // Get the push button element.
    let pushbutton = document.getElementById('push-all-button');
    if (pushbutton) {
        // Disable the push button if there is any running task, otherwise enable it.
        pushbutton.disabled = taskRunning;
    } else {
        window.console.log('Push button not found');
    }

    assessments.forEach(assessment => {
        let progressContainer = document.getElementById('progress-container-' + assessment.assessmentmappingid);
        if (!progressContainer) {
            window.console.log('Progress container not found for assessment mapping ID: ' + assessment.assessmentmappingid);
            return;
        }
        if (assessment.task === null) {
            // Hide the progress container if there is no task in progress.
            progressContainer.classList.add('d-none');
        } else {
            progressContainer.classList.remove('d-none');
            updateProgressBar(progressContainer, assessment.task.progress);
        }
    });
}

/**
 * Check if there is a running task.
 *
 * @param {object[]} assessments
 * @return {boolean}
 */
function hasRunningTask(assessments) {
    for (let i = 0; i < assessments.length; i++) {
        if (assessments[i].task !== null) {
            return true;
        }
    }
    return false;
}
