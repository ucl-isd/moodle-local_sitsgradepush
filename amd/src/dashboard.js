import {schedulePushTask} from "./push_tasks";
import notification from "core/notification";

export const init = () => {
    // Find the page element.
    let page = document.getElementById("page");

    // Find the module delivery table selector.
    let tableSelector = document.getElementById("module-delivery-selector");

    // Jump to the selected module delivery table when the user selects a module delivery.
    tableSelector.addEventListener("change", function() {
        // Find the selected table by ID.
        let selectedTable = document.getElementById(tableSelector.value);

        // Calculate the scroll position to be 100 pixels above the table.
        if (selectedTable) {
            let offset = -100;
            let tablePosition = selectedTable.getBoundingClientRect().top;
            let scrollPosition = page.scrollTop + tablePosition + offset;

            // Scroll to the calculated position.
            page.scrollTo({
                top: scrollPosition,
                behavior: "smooth"
            });
        }
    });

    // Find the back to top button.
    let backToTopButton = document.getElementById("backToTopButton");

    // Show the button when the user scrolls down 100 pixels from the top of the page.
    page.addEventListener("scroll", function() {
        if (page.scrollTop >= 100) {
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

    // Get all the push buttons that are not disabled.
    let mabpushbuttons = document.querySelectorAll(".push-mark-button:not([disabled])");

    // Push grades when the user clicks on each enabled push button.
    mabpushbuttons.forEach(function(button) {
        button.addEventListener("click", function() {
            pushgrade(this);
        });
    });

    // Get the push all button.
    let pushallbutton = document.getElementById("push-all-button");

    // Push grades for all the not disabled push buttons when the user clicks on the push all button.
    pushallbutton.addEventListener("click", async function() {
        // Get the updated not disabled push buttons.
        let mabpushbuttons = document.querySelectorAll(".push-mark-button:not([disabled])");

        // Number of not disabled push buttons.
        let total = mabpushbuttons.length;
        let count = 0;

        // Create an array to hold all the Promises.
        let promises = [];

        // Push grades to SITS for each MAB.
        mabpushbuttons.forEach(function(button) {
            // Create a Promise for each button and push it into the array.
            let promise = pushgrade(button)
                .then(function(result) {
                    if (result) {
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
        notification.addNotification({
            message: count + ' of ' + total + ' push tasks have been scheduled.',
            type: (count === total) ? 'success' : 'warning'
        });
    });
};

/**
 * Schedule a push task when the user clicks on a push button.
 *
 * @param {HTMLElement} button The button element.
 * @return {Promise} Promise.
 */
async function pushgrade(button) {
    try {
        // Get the assessment mapping ID from the button.
        let assessmentmappingid = button.getAttribute("data-assessmentmappingid");

        // Schedule a push task.
        let result = await schedulePushTask(assessmentmappingid);

        // Check if the push task is successfully scheduled.
        if (result.success) {
            // Update the icon.
            let icon = button.parentNode.parentNode.querySelector("span:last-child");
            icon.innerHTML = '<i class="fa-solid fa-hourglass-start" data-toggle="tooltip" data-placement="top" ' +
                'title="' + result.status + '"></i>';

            // Disable the push button after scheduled push task successfully.
            button.disabled = true;

            // Remove the tooltip (for Firefox and Safari).
            let tooltipid = button.getAttribute("aria-describedby");
            if (tooltipid !== null && document.getElementById(tooltipid) !== null) {
                document.getElementById(tooltipid).remove();
            }
        } else {
            // Create an error message row.
            let errormessagerow = document.createElement("tr");

            // Set the class and content of the error message row.
            errormessagerow.setAttribute("class", "error-message-row");
            errormessagerow.innerHTML =
                '<td colspan="6>">' +
                '<div class="alert alert-danger" role="alert">' + result.message + '</div>' +
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

        return result.success;
    } catch (error) {
        window.console.error(error);
        return false;
    }
}
