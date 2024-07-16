import {tableHelperInit} from './table_helper';
import notification from "core/notification";
import ModalEvents from 'core/modal_events';
import ModalSaveCancel from 'core/modal_save_cancel';
import {mapAssessment} from './sitsgradepush_helper';

export const init = () => {
    // Initialise the table helper.
    tableHelperInit('existing-activity-table', 2, 'filterInput');

    // Get all the select assessment buttons.
    let selectAssessmentButtons = document.querySelectorAll('.select-assessment-button');

    if (selectAssessmentButtons) {
        // Add an event listener to each select assessment button.
        // When the user clicks on each button, map the assessment to the selected component grade.
        selectAssessmentButtons.forEach(function(button) {
            button.addEventListener('click', function() {
                selectAssessment(button).then(
                    (result) => {
                        return result;
                    }
                ).catch((error) => {
                    notification.addNotification({
                        message: error.message,
                        type: 'error'
                    });
                    window.console.error(error);
                });
            });
        });
    }
};

/**
 * Show a modal to confirm the user wants to map the assessment to the selected component grade.
 *
 * @param {HTMLElement} button The select assessment button element.
 * @return {Promise<void>}
 */
async function selectAssessment(button) {
    // Get assessment component data from the button.
    let mapcode = button.getAttribute('data-mapcode');
    let mabseq = button.getAttribute('data-mabseq');
    let mabid = button.getAttribute('data-mabid');
    let courseid = button.getAttribute('data-courseid');
    let sourcetype = button.getAttribute('data-sourcetype');
    let sourceid = button.getAttribute('data-sourceid');
    let reassess = button.getAttribute('data-reassess');
    let partid = button.getAttribute('data-partid');

    // Find the closest row to the button.
    let currentrow = button.closest('tr');
    let type = currentrow.getElementsByTagName('td')[0].innerHTML;
    let name = currentrow.getElementsByTagName('td')[1].innerHTML;
    let endDate = currentrow.getElementsByTagName('td')[3].innerHTML;

    const modal = await ModalSaveCancel.create({
        title: 'Confirmation',
        body: getModalBody(type, name, endDate, mapcode, mabseq),
        large: true,
        buttons: {'save': 'Confirm', 'cancel': 'Cancel'}
    });

    await modal.show();
    modal.getRoot().on(ModalEvents.save, () => {
        mapAssessment(courseid, sourcetype, sourceid, mabid, reassess, partid).then(
            (result) => {
                if (result.success) {
                    // Store the success message in localStorage for display on the dashboard page.
                    localStorage.setItem('successMessage', result.message);

                    // Redirect to the dashboard page.
                    let url = '/local/sitsgradepush/dashboard.php?id=' + courseid;
                    if (reassess === '1') {
                        url += '&reassess=' + reassess;
                    }
                    window.location.href = url;
                } else {
                    notification.addNotification({
                        message: result.message,
                        type: 'error'
                    });
                }
                return result;
            }
        ).catch((error) => {
            window.console.error(error);
        });
    });
}

/**
 * Get the modal body.
 *
 * @param {string} type
 * @param {string} name
 * @param {string} endDate
 * @param {string} mapcode
 * @param {string} mabseq
 * @return {string}
 */
function getModalBody(type, name, endDate, mapcode, mabseq) {
    return `
    <div class="modal-body">
      <p>Confirm you want to return marks from:</p>
      <table class="table table-hover table-bordered">
        <thead style="background-color: lightgrey">
          <th>Type</th>
          <th>Name</th>
          <th>End Date</th>
        </thead>
        <tr>
          <td>${type}</td>
          <td>${name}</td>
          <td>${endDate}</td>
        </tr>
      </table>
      <p>to</p>
      <table class="table table-hover table-bordered">
        <thead style="background-color: lightgrey">
          <th>MAB</th>
          <th>SEQ</th>
        </thead>
        <tr>
          <td>${mapcode}</td>
          <td>${mabseq}</td>
        </tr>
      </table>
    </div>`;
}
