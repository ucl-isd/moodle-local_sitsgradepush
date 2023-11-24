import {schedulePushTask} from "./push_tasks";
import notification from 'core/notification';

export const init = (coursemoduleid, mappingids) => {
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
                    let taskstatus = document.getElementById('taskstatus-' + mappingid);
                    if (result.success) {
                        count = count + 1;
                        taskstatus.innerHTML = '<i class="fa-solid fa-hourglass-start"></i> ' + result.status;
                    } else {
                        // Create an error message row.
                        let errormessagerow = document.createElement("tr");
                        errormessagerow.setAttribute("class", "error-message-row");
                        errormessagerow.innerHTML =
                            '<td colspan="6>">' +
                            '<div class="alert alert-danger" role="alert">' + result.message + '</div>' +
                            '</td>';

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

        // Display a notification.
        notification.addNotification({
            message: count + ' of ' + total + ' push tasks have been scheduled.',
            type: (count === total) ? 'success' : 'warning'
        });
    });
};
