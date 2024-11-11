import {schedulePushTask, getAssessmentsUpdate, updateProgressBar, removeMapping} from "./sitsgradepush_helper";
import notification from "core/notification";

let updatePageIntervalId = null; // The interval ID for updating the progress.
let globalCourseid = null; // The global variable for course ID.
let updatePageDelay = 15000; // The delay for updating the page.

/**
 * Initialize the dashboard page.
 *
 * @param {int} courseid
 */
export const init = (courseid) => {
    // If there is a saved message by successfully mapped an assessment in localStorage, display it.
    displayNotification();

    // Set the global variable course ID.
    globalCourseid = courseid;

    // Initialize the module delivery dropdown list.
    initModuleDeliverySelector(window);

    // Initialize assessment updates.
    initAssessmentUpdate(courseid);

    // Initialize confirmation modal.
    initConfirmationModal(window);

    // Initialize the remove source buttons.
    initRemoveSourceConfirmModal();
};

/**
 * Initialize the module delivery dropdown list.
 *
 * @param {Window} page
 */
function initModuleDeliverySelector(page) {
    // Find all the dropdown items.
    let dropdownitems = document.querySelectorAll('.jump-to-dropdown-item');

    // Add event listener to each dropdown item.
    dropdownitems.forEach(function(item) {
        item.addEventListener('click', function() {
            let value = item.getAttribute('data-value');
            if (value !== null) {
                // Get the scroll position of the page.
                let pagePosition = getPagePosition(page);

                // Find the selected table by ID.
                let selectedTable = document.getElementById(value);

                // Calculate the scroll position to be 100 pixels above the table.
                if (selectedTable) {
                    let offset = -100;
                    let tablePosition = selectedTable.getBoundingClientRect().top;
                    let scrollPosition = pagePosition + tablePosition + offset;

                    // Scroll to the calculated position.
                    page.scrollTo({
                        top: scrollPosition,
                        behavior: "smooth"
                    });
                }
            }
        });
    });
}

/**
 * Initialize the confirmation modal.
 *
 * @param {Window} page
 */
function initConfirmationModal(page) {
    // Find the confirmation modal.
    let confirmTransferButton = document.getElementById("js-transfer-modal-button");

    // Exit if the confirmation modal is not found.
    if (confirmTransferButton === null) {
        return;
    }

    // Add event listener to the confirmation modal.
    confirmTransferButton.addEventListener("click", async function() {
        let assessmentmappingid = confirmTransferButton.getAttribute('data-assessmentmappingid');

        // Check should we record non-submission as 0 AB.
        let recordnonsubmission = document.getElementById('recordnonsubmission').checked;

        if (assessmentmappingid !== null && assessmentmappingid !== 'all') {
            // Single transfer.
            await pushMarks(assessmentmappingid, recordnonsubmission);
        } else if (assessmentmappingid === 'all') {
            // Bulk transfer.
            await pushAllMarks(page, recordnonsubmission);
        }
    });
}

/**
 * Initialize the assessment updates.
 *
 * @param {int} courseid
 */
function initAssessmentUpdate(courseid) {
    updateAssessments(courseid);

    // Update the page every 15 seconds.
    updatePageIntervalId = setInterval(() => {
        updateAssessments(courseid);
    }, updatePageDelay);

    // Add event listener to stop update the page when the page is not visible. e.g. when the user switches to another tab.
    document.addEventListener("visibilitychange", function() {
        if (document.visibilityState === "hidden") {
            clearInterval(updatePageIntervalId);
        } else {
            updateAssessments(courseid);
            updatePageIntervalId = setInterval(() => {
                updateAssessments(courseid);
            }, updatePageDelay);
        }
    });
}

/**
 * Initialize the remove source confirmation modal.
 *
 * @return {void}
 */
function initRemoveSourceConfirmModal() {
    // Find the remove source modal confirm button.
    let removeSourceModalConfirmBtn = document.getElementById('js-remove-source-modal-button');

    // Add event listener to the remove source modal confirm button.
    removeSourceModalConfirmBtn.addEventListener('click', async function() {
        // Get assessment mapping id.
        let assessmentmappingid = removeSourceModalConfirmBtn.getAttribute('data-assessmentmappingid');

        const result = await removeMapping(globalCourseid, assessmentmappingid);
        if (result.success) {
            window.location.reload();
        } else {
            showTransferErrorMessage(assessmentmappingid, result.message);
        }
    });
}

/**
 * Schedule a push task when the user clicks on a push button.
 *
 * @param {int} assessmentmappingid The button element.
 * @param {boolean} recordnonsubmission Record non-submission as 0 AB.
 * @return {Promise|boolean} Promise.
 */
async function pushMarks(assessmentmappingid, recordnonsubmission) {
    try {
        // Schedule a push task.
        let result = await schedulePushTask(assessmentmappingid, recordnonsubmission);

        // Check if the push task is successfully scheduled.
        if (result.success) {
            // Update the UI once a task is scheduled successfully.
            updateUIOnTaskScheduling(assessmentmappingid);
        }
        let message = '';
        if (!result.success && result.message) {
            message = result.message;
        }

        // Show error message if there is any.
        showTransferErrorMessage(assessmentmappingid, message);
        return result;
    } catch (error) {
        window.console.error(error);
        return false;
    }
}

/**
 *
 * @param {HTMLElement} page
 * @param {boolean} recordnonsubmission Record non-submission as 0 AB.
 * @return {Promise<void>}
 */
async function pushAllMarks(page, recordnonsubmission) {
    // Find all the mappings that have marks to push based on the recordnonsubmission setting.
    let assessmentmappings = Array.from(document.querySelectorAll('.marks-col-field'))
        .filter(element => {
            // Get current mapping statuses.
            let marksCount = parseInt(element.getAttribute('data-markscount'), 10);
            let nonSubmittedCount = parseInt(element.getAttribute('data-nonsubmittedcount'), 10);
            let taskRunning = element.getAttribute('data-task-running') === 'true';

            // Nothing to push if there are no marks and no non-submitted records.
            if (marksCount === 0 && nonSubmittedCount === 0) {
                return false;
            }

            if (recordnonsubmission) {
                // Record non-submission enabled, return true when mapping has marks or non-submitted records and no task running.
                return (marksCount > 0 || nonSubmittedCount > 0) && !taskRunning;
            } else {
                // Record non-submission disabled, return true when mapping has marks and no task running.
                return marksCount > 0 && !taskRunning;
            }
        });

    // Number of not disabled push buttons.
    let total = assessmentmappings.length;
    let count = 0;

    // Create an array to hold all the Promises.
    let promises = [];

    // Push grades to SITS for each component grade.
    assessmentmappings.forEach(function(element) {
        // Get the assessment mapping ID.
        let assessmentmappingid = element.getAttribute('data-assessmentmappingid');
        // Create a Promise for each button and push it into the array.
        let promise = pushMarks(assessmentmappingid, recordnonsubmission)
            .then(function(result) {
                if (result.success) {
                    count = count + 1;
                }
                return result;
            }).catch(function(error) {
                window.console.error(error);
            });

        promises.push(promise);
    });

    // Wait for all Promises to resolve.
    await Promise.all(promises);

    // Scroll to the top of the page so that the user can see the notification.
    page.scrollTo({top: 0, behavior: "instant"});

    // Show the notification.
    await notification.addNotification({
        message: count + ' of ' + total + ' push tasks have been scheduled.',
        type: (count === total) ? 'success' : 'warning'
    });

    // Update the page information.
    updateAssessments(globalCourseid);
}

/**
 * Update the UI once a task is scheduled successfully.
 * e.g. hide change source button, show progress bar.
 *
 * @param {int} assessmentmappingid
 */
function updateUIOnTaskScheduling(assessmentmappingid) {
    // Find the change source button.
    let changeSourceButton = document.getElementById('change-source-button-' + assessmentmappingid);
    if (changeSourceButton) {
        // Hide the change source button.
        changeSourceButton.style.display = 'none';
    }

    // Hide the transfer button and show the progress bar immediately.
    let assessments = [
        {task: {progress: 0}, assessmentmappingid: assessmentmappingid, markscount: 0, nonsubmittedcount: 0},
    ];
    updateMarksColumn(assessments);
}

/**
 * Update the dashboard page with the latest information.
 * e.g. progress bars, push buttons, records icons.
 *
 * @param {int} courseid
 * @return {Promise<void>}
 */
async function updateAssessments(courseid) {
    // Get latest assessments information for the dashboard page.
    let update = await getAssessmentsUpdate(courseid);

    if (update.success) {
        // Parse the JSON string.
        let assessments = JSON.parse(update.assessments);

        if (assessments.length > 0) {
            updateMarksColumn(assessments);
        }
    } else {
        // Stop update the page if error occurred.
        clearInterval(updatePageIntervalId);
        window.console.error(update.message);
    }
}

/**
 * Update the marks' column for all assessments mappings.
 *
 * @param {object[]} assessments
 */
function updateMarksColumn(assessments) {
    // Update assessment components which has mappings.
    assessments.forEach(assessment => {
        let marksColumnFieldId = 'marks-col-field-' + assessment.assessmentmappingid;
        let marksColumnField = document.getElementById(marksColumnFieldId);
        if (marksColumnField) {
            let marksContainer = marksColumnField.querySelector('.marks-container');
            let taskContainer = marksColumnField.querySelector('.task-status-container');

            // Set the marks count attribute.
            marksColumnField.setAttribute('data-markscount', assessment.markscount);

            // Set the non submitted count attribute.
            marksColumnField.setAttribute('data-nonsubmittedcount', assessment.nonsubmittedcount);

            // Marks count element that displays the number of marks.
            let marksCountElement = marksColumnField.querySelector('.marks-count');

            // Update the marks count.
            marksCountElement.innerHTML = assessment.markscount;

            // Get the transfer button.
            let transferButton = marksColumnField.querySelector('.js-btn-transfer-marks');

            // Show the transfer button if there are marks or non-submitted records.
            if (assessment.markscount > 0 || assessment.nonsubmittedcount > 0) {
                transferButton.classList.remove('d-none');
            } else {
                transferButton.classList.add('d-none');
            }

            // Show marks information if no task running.
            if (assessment.task === null) {
                marksColumnField.setAttribute('data-task-running', false);
                taskContainer.classList.add('d-none');
                marksContainer.classList.remove('d-none');
            } else {
                // Show task information if task running.
                marksColumnField.setAttribute('data-task-running', true);
                marksContainer.classList.add('d-none');
                taskContainer.classList.remove('d-none');
                updateProgressBar(taskContainer, assessment.task.progress);
            }
        }
    });
}

/**
 * Show an error message at the table row under the button.
 *
 * @param {int} assessmentmappingid
 * @param {string} message
 */
function showTransferErrorMessage(assessmentmappingid, message) {
    // Find the marks column field.
    let marksColumnField = document.getElementById('marks-col-field-' + assessmentmappingid);

    // Find the closest row to the button.
    let currentrow = marksColumnField.closest("tr");

    // Remove the existing error message row if it exists.
    if (currentrow.nextElementSibling !== null &&
        currentrow.nextElementSibling.classList.contains("error-message-row")) {
        currentrow.nextElementSibling.remove();
    }

    if (message !== '') {
        // Create an error message row.
        let errormessagerow = document.createElement("tr");

        // Set the class and content of the error message row.
        errormessagerow.setAttribute("class", "error-message-row");
        errormessagerow.innerHTML =
            '<td colspan="4>">' +
            '<div class="alert alert-danger" role="alert">' + message + '</div>' +
            '</td>';

        // Insert the error message row after the current row.
        currentrow.insertAdjacentElement("afterend", errormessagerow);
    }
}

/**
 * Display a notification if a success message is available in localStorage.
 */
function displayNotification() {
    // Retrieve the success message from localStorage.
    let successMessage = localStorage.getItem('successMessage');

    // Check if a success message is available.
    if (successMessage) {
        // Display the success message using a notification library or other means.
        notification.addNotification({
            message: successMessage,
            type: 'success'
        });

        // Remove the success message from localStorage to avoid showing it again.
        localStorage.removeItem('successMessage');
    }
}

/**
 * Get the scroll position of the page.
 *
 * @param {HTMLElement} page
 * @return {*|number}
 */
function getPagePosition(page) {
    if (page instanceof Window) {
        // Get the scroll position of the page.
        return page.scrollY;
    } else {
        // Get the scroll position of the page.
        return page.scrollTop;
    }
}
