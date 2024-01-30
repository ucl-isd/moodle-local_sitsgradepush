import {schedulePushTask, getAssessmentsUpdate, updateProgressBar} from "./sitsgradepush_helper";
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
        if (assessmentmappingid !== null && assessmentmappingid !== 'all') {
            // Single transfer.
            await pushMarks(assessmentmappingid);
        } else if (assessmentmappingid === 'all') {
            // Bulk transfer.
            await pushAllMarks(page);
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
 * Schedule a push task when the user clicks on a push button.
 *
 * @param {int} assessmentmappingid The button element.
 * @return {Promise|boolean} Promise.
 */
async function pushMarks(assessmentmappingid) {
    try {
        // Schedule a push task.
        let result = await schedulePushTask(assessmentmappingid);

        // Check if the push task is successfully scheduled.
        if (result.success) {
            updateAssessments(globalCourseid);
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
 * @return {Promise<void>}
 */
async function pushAllMarks(page) {
    let assessmentmappings = Array.from(document.querySelectorAll('.marks-col-field'))
        .filter(element =>
            parseInt(element.getAttribute('data-markscount'), 10) > 0 &&
            element.getAttribute('data-task-running') === 'false'
        );

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
        let promise = pushMarks(assessmentmappingid)
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

            // Marks count element that displays the number of marks.
            let marksCountElement = marksColumnField.querySelector('.marks-count');

            // Update the marks count.
            marksCountElement.innerHTML = assessment.markscount;

            // Show the transfer button if there are marks to transfer.
            let transferButton = marksColumnField.querySelector('.js-btn-transfer-marks');
            if (assessment.markscount > 0) {
                transferButton.style.display = 'block';
            } else {
                transferButton.style.display = 'none';
            }

            // Show marks information if no task running.
            if (assessment.task === null) {
                marksColumnField.setAttribute('data-task-running', false);
                taskContainer.style.display = 'none';
                marksContainer.style.display = 'block';
            } else {
                // Show task information if task running.
                marksColumnField.setAttribute('data-task-running', true);
                marksContainer.style.display = 'none';
                taskContainer.style.display = 'block';
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
