import {
    schedulePushTask,
    getTransferStudents,
    transferMarkForStudent,
    getAssessmentsUpdate
} from "./sitsgradepush_helper";
import {createProgressBar, updateProgressBar, createSpinner} from "./progress";
import notification from "core/notification";
import ModalFactory from 'core/modal_factory';
import ModalEvents from 'core/modal_events';

let updatePageIntervalId = null; // The interval ID for updating the progress.
let syncThreshold = 30; // The threshold which determines whether it is a sync or async marks transfer.
let globalCourseid = null; // The global variable for course ID.
let updatePageDelay = 15000; // The delay for updating the page.
let async = null; // The async config.

/**
 * Initialize the dashboard page.
 *
 * @param {int} courseid
 * @param {int} syncThresholdConfig
 * @param {int} asyncConfig
 * @param {string} moodleVersion
 */
export const init = (courseid, syncThresholdConfig, asyncConfig, moodleVersion) => {
    // If there is a saved message by successfully mapped an assessment in localStorage, display it.
    displayNotification();

    // Set the sync threshold from the plugin config.
    syncThreshold = syncThresholdConfig;

    // Set the global variable course ID.
    globalCourseid = courseid;

    // Set the async config.
    async = asyncConfig;

    let page;

    // Get the scrollable page element depending on the Moodle version.
    if (moodleVersion > '2023100900') {
        // Moodle 4.3 and above.
        page = window;
    } else {
        // Moodle 4.2 and below.
        page = document.getElementById("page");
    }

    // Initialize the module delivery dropdown list.
    let tableSelector = initModuleDeliverySelector(page);

    // Initialize the back to top button.
    initBackToTopButton(page, tableSelector);

    // Initialize the change source buttons.
    initChangeSourceButtons();

    // Initialize the push buttons.
    initPushMarkButtons(page, courseid);

    // Initialize the push all button.
    initPushAllButton(page, courseid);

    // Update the dashboard page with the latest information.
    // E.g. progress bars, push buttons, records icons.
    updateAssessments(courseid);

    // Update the page every 15 seconds.
    updatePageIntervalId = setInterval(() => {
        updateAssessments(globalCourseid);
    }, updatePageDelay);

};

/**
 * Initialize the module delivery dropdown list.
 *
 * @param {HTMLElement} page
 * @return {HTMLElement}
 */
function initModuleDeliverySelector(page) {
    // Find the module delivery table selector.
    let tableSelector = document.getElementById("module-delivery-selector");

    // Jump to the selected module delivery table when the user selects a module delivery.
    tableSelector.addEventListener("change", function() {
        // Find the selected table by ID.
        let selectedTable = document.getElementById(tableSelector.value);

        // Get the scroll position of the page.
        let pagePosition = getPagePosition(page);

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
    });

    return tableSelector;
}

/**
 * Initialize the back to top button.
 *
 * @param {HTMLElement} page
 * @param {HTMLElement} tableSelector
 */
function initBackToTopButton(page, tableSelector) {
    // Find the back to top button.
    let backToTopButton = document.getElementById("backToTopButton");

    // Show the button when the user scrolls down 100 pixels from the top of the page.
    page.addEventListener("scroll", function() {
        // Get the scroll position of the page.
        if (getPagePosition(page) >= 100) {
            backToTopButton.style.display = "block";
        } else {
            backToTopButton.style.display = "none";
        }
    });

    // Scroll to the top of the page when the button is clicked.
    backToTopButton.addEventListener("click", function() {
        page.scrollTo({top: 0, behavior: "smooth"});
        tableSelector.selectedIndex = 0;
    });
}

/**
 * Initialize the change source buttons.
 *
 */
function initChangeSourceButtons() {
    // Get all change source buttons.
    let changesourcebuttons = document.querySelectorAll(".change-source-button:not([disabled])");

    // Add event listener to each change source button.
    // When the user clicks on each change source button, redirect to the select source page.
    if (changesourcebuttons.length > 0) {
        changesourcebuttons.forEach(function(button) {
            button.addEventListener("click", function() {
                // Redirect to the change source page.
                window.location.href = button.getAttribute("data-url");
            });
        });
    }
}

/**
 * Initialize the push mark buttons.
 *
 * @param {HTMLElement} page
 * @param {int} courseid
 */
function initPushMarkButtons(page, courseid) {
    // Get all the push buttons that are not disabled.
    let mabpushbuttons = document.querySelectorAll(".push-mark-button");

    if (mabpushbuttons.length > 0) {
        // Push grades when the user clicks on each enabled push button.
        mabpushbuttons.forEach(function(button) {
            // Find the number of students to push grades.
            let studentcount = button.getAttribute("data-numberofstudents");
            let assessmentmappingid = button.getAttribute("data-assessmentmappingid");

            // Disable the push button if there is no assessment mapping ID.
            if (assessmentmappingid === null) {
                button.disabled = true;
                return;
            }

            button.addEventListener("click", async function() {
                if (studentcount === '0') {
                    // Show an error message if there is no student to push grades.
                    showErrorMessageForButton(button, 'There are no marks to transfer.');
                    return;
                }

                // Do synchronous marks transfer if the number of marks to be transferred is less than the sync threshold.
                // Or if the async config is disabled.
                if ((studentcount > 0 && studentcount < syncThreshold) || async === '0') {
                    await syncMarksTransfer(assessmentmappingid);
                } else {
                    // Schedule an asynchronous marks transfer task.
                    let result = await pushMarks(this);
                    if (result.success) {
                        // Update the page after scheduling a marks transfer task.
                        updateAssessments(courseid);
                    }
                }
            });
        });
    }
}

/**
 * Initialize the push all button.
 *
 * @param {HTMLElement} page
 * @param {int} courseid
 */
function initPushAllButton(page, courseid) {
    // Get the push all button.
    let pushallbutton = document.getElementById("push-all-button");

    // Push grades for all the not disabled push buttons when the user clicks on the push all button.
    pushallbutton.addEventListener("click", async function() {
        // Get the updated not disabled push buttons and has assessment ID.
        let mabpushbuttons = document.querySelectorAll(".push-mark-button:not([disabled])[data-assessmentmappingid]");

        // Number of not disabled push buttons.
        let total = mabpushbuttons.length;
        let count = 0;

        // Create an array to hold all the Promises.
        let promises = [];

        // Push grades to SITS for each component grade.
        mabpushbuttons.forEach(function(button) {
            // Create a Promise for each button and push it into the array.
            let promise = pushMarks(button)
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
        updateAssessments(courseid);
    });
}

/**
 * Schedule a push task when the user clicks on a push button.
 *
 * @param {HTMLElement} button The button element.
 * @return {Promise|boolean} Promise.
 */
async function pushMarks(button) {
    try {
        // Get the assessment mapping ID from the button.
        let assessmentmappingid = button.getAttribute("data-assessmentmappingid");

        // Schedule a push task.
        let result = await schedulePushTask(assessmentmappingid);

        // Check if the push task is successfully scheduled.
        if (result.success) {
            // Remove the tooltip (for Firefox and Safari).
            let tooltipid = button.getAttribute("aria-describedby");
            if (tooltipid !== null && document.getElementById(tooltipid) !== null) {
                document.getElementById(tooltipid).remove();
            }
        } else {
            // Show an error message if the transfer task is not successfully scheduled.
            showErrorMessageForButton(button, result.message);
        }

        return result;
    } catch (error) {
        window.console.error(error);
        return false;
    }
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
            // Update the all the progress bars and push buttons.
            updateTasksProgresses(assessments);

            // Update the records icons.
            updateIcon(assessments);
        }
    } else {
        // Stop update the page if error occurred.
        clearInterval(updatePageIntervalId);
        window.console.error(update.message);
    }
}

/**
 * Update all the progress bars and push buttons in the dashboard page.
 *
 * @param {object[]} assessments
 */
function updateTasksProgresses(assessments) {
    // Filter assessments that are having task in progress.
    let assessmentsHasTasks = assessments.filter(assessment => assessment.task !== null);

    // The assessment mapping IDs having task in progress.
    let assessmentIds = new Set(assessmentsHasTasks.map(item => item.assessmentmappingid));

    // Update the progress bars.
    updateProgressBars(assessmentsHasTasks, assessmentIds);

    // Update the push buttons.
    updatePushButtons(assessmentIds);
}

/**
 * Update all the progress bars in the dashboard page.
 *
 * @param {object[]} assessmentsHasTasks
 * @param {Set} assessmentIds
 */
function updateProgressBars(assessmentsHasTasks, assessmentIds) {
    let progressBars = document.querySelectorAll('.progress.async');

    // Remove the progress bars that are not in the assessmentIds.
    progressBars.forEach(progressBar => {
        if (!assessmentIds.has(progressBar.getAttribute('data-assessmentmappingid'))) {
            progressBar.remove();
        }
    });

    assessmentsHasTasks.forEach(assessment => {
        let progressBarId = 'progress-bar-' + assessment.task.assessmentmappingid;
        let progressBar = document.getElementById(progressBarId);
        let task = assessment.task;

        // If the progress bar not exists, create a new one, otherwise update the progress.
        if (!progressBar) {
            progressBar = createProgressBar(progressBarId, 'async', task.assessmentmappingid, task.progress);
            let button = document.querySelector('.push-mark-button[data-assessmentmappingid="' + task.assessmentmappingid + '"]');
            button.parentNode.parentNode.insertAdjacentElement('afterend', progressBar);
        } else {
            updateProgressBar(progressBar, task.progress);
        }
    });
}

/**
 * Update all the push buttons in the dashboard page.
 *
 * @param {Set} assessmentIds The assessment mapping IDs having task in progress.
 */
function updatePushButtons(assessmentIds) {
    // Find all push buttons.
    let pushButtons = document.querySelectorAll('.push-mark-button');

    pushButtons.forEach(function(pushButton) {
        let assessmentmappingid = pushButton.getAttribute('data-assessmentmappingid');

        if (assessmentIds.has(assessmentmappingid)) {
            // If the task ID is found, show spinner and disable button.
            let spinner = createSpinner('text-light', 'spinner-border-sm');
            pushButton.innerHTML = spinner.outerHTML;
            pushButton.disabled = true;
        } else if (assessmentmappingid !== null) {
            // Reset the button to the original state.
            pushButton.innerHTML = '<i class="fa-solid fa-upload"></i>';
            pushButton.disabled = false;
        } else {
            // No assessment mapping, disable the button.
            pushButton.disabled = true;
        }
    });
}

/**
 * Update the icons to show that there are transfer records.
 *
 * @param {object[]} assessments
 */
function updateIcon(assessments) {
    let pushButtons = document.querySelectorAll('.push-mark-button');

    // Get assessment mappings that have transfer records.
    let assessmentsHasTransferRecords = assessments.filter(update => update.transferrecords === 1);

    // Extract the assessment mapping IDs.
    let assessmentIds = new Set(assessmentsHasTransferRecords.map(assessment => assessment.assessmentmappingid));

    // Update the icons to show that there are transfer records.
    pushButtons.forEach(function(button) {
        let assessmentmappingid = button.getAttribute('data-assessmentmappingid');
        let icon = button.parentNode.parentNode.querySelector('.records-icon');
        if (assessmentIds.has(assessmentmappingid)) {
            if (icon.classList.contains('fa-circle-info')) {
                icon.classList.replace('fa-solid', 'fa-regular');
                icon.classList.replace('fa-circle-info', 'fa-file-lines');
            }
        } else {
            if (icon.classList.contains('fa-file-lines')) {
                icon.classList.replace('fa-regular', 'fa-solid');
                icon.classList.replace('fa-file-lines', 'fa-circle-info');
            }
        }
    });
}

/**
 * Transfer marks for all the students in the assessment mapping synchronously.
 *
 * @param {int} assessmentmappingid
 * @return {Promise<void>}
 */
async function syncMarksTransfer(assessmentmappingid) {
    // Stop the page update while transferring marks.
    clearInterval(updatePageIntervalId);

    // Get the students to transfer marks.
    let result = await getTransferStudents(assessmentmappingid);

    if (result.success) {
        if (result.students.length > 0) {
            let progressbar =
                createProgressBar('dashboard-progress-bar-sync', 'sync', assessmentmappingid, 0, true);

            // Create a modal to show the progress.
            let modal = await ModalFactory.create({
                type: ModalFactory.types.ALERT,
                title: 'Transferring Marks',
                body: '<div id="error-message-modal-sync"></div>' + progressbar.outerHTML,
                buttons: {'cancel': 'Cancel'}
            });

            await modal.show();
            let isModalVisible = true;
            let modalProgressbar = document.getElementById('dashboard-progress-bar-sync');

            // Destroy the modal when it is hidden.
            modal.getRoot().on(ModalEvents.hidden, () => {
                modal.destroy();
                isModalVisible = false;
            });

            let students = JSON.parse(result.students);

            let studentcount = students.length;
            let count = 0;
            let promises = [];
            for (const student of students) {
                // Stop the progress if the modal is closed.
                if (!isModalVisible) {
                    break;
                }

                // Transfer mark for each student.
                let promise = await transferMarkForStudent(assessmentmappingid, student.userid);
                if (!promise.success) {
                    // Get general error message.
                    let generalErrorMessage = promise.message;
                    let errormessage = document.getElementById('error-message-modal-sync');
                    errormessage.innerHTML = '<div class="alert alert-warning" role="alert">' + generalErrorMessage + '</div>';
                }

                promises.push(promise);

                // Increment the count by 1 for each student.
                count = count + 1;

                // Calculate the progress.
                let progress = Math.round((count / studentcount) * 100);
                updateProgressBar(modalProgressbar, progress, true);
            }
            await Promise.all(promises);
            await modal.setButtonText('cancel', 'Close');
        }
    }

    // Resume the page update.
    updatePageIntervalId = setInterval(() => {
        updateAssessments(globalCourseid);
    }, updatePageDelay);
}

/**
 * Show an error message at the table row under the button.
 *
 * @param {HTMLElement} button
 * @param {string} message
 */
function showErrorMessageForButton(button, message) {
    // Create an error message row.
    let errormessagerow = document.createElement("tr");

    // Set the class and content of the error message row.
    errormessagerow.setAttribute("class", "error-message-row");
    errormessagerow.innerHTML =
        '<td colspan="6>">' +
        '<div class="alert alert-danger" role="alert">' + message + '</div>' +
        '</td>';

    // Find the closest row to the button.
    let currentrow = button.closest("tr");

    // Remove the existing error message row if it exists.
    if (currentrow.nextElementSibling !== null &&
        currentrow.nextElementSibling.classList.contains("error-message-row")) {
        currentrow.nextElementSibling.remove();
    }

    // Insert the error message row after the current row.
    currentrow.insertAdjacentElement("afterend", errormessagerow);
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
