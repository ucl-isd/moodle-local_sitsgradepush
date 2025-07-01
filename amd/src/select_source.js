import {tableHelperInit} from './table_helper';
import notification from "core/notification";
import ModalEvents from 'core/modal_events';
import ModalSaveCancel from 'core/modal_save_cancel';
import {mapAssessment} from './sitsgradepush_helper';
import {getString} from 'core/str';

// The global variable for extension information page URL.
let extensionInfoPageUrl = null;

export const init = (extensioninfopageurl) => {
    // Set extension Information page url.
    extensionInfoPageUrl = extensioninfopageurl;

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
    let mabname = button.getAttribute('data-mabname');
    let courseid = button.getAttribute('data-courseid');
    let sourcetype = button.getAttribute('data-sourcetype');
    let sourceid = button.getAttribute('data-sourceid');
    let reassess = button.getAttribute('data-reassess');
    let partid = button.getAttribute('data-partid');
    let extensioneligible = button.getAttribute('data-extensioneligible');

    // Find the closest row to the button.
    let currentrow = button.closest('tr');
    let type = currentrow.getElementsByTagName('td')[0].innerHTML;
    let name = currentrow.getElementsByTagName('td')[1].innerHTML;
    let endDate = currentrow.getElementsByTagName('td')[3].innerHTML;

    const modal = await ModalSaveCancel.create({
        title: 'Confirmation',
        body: getModalBody(type, name, endDate, mapcode, mabseq, mabname, extensioneligible),
        large: true,
        buttons: {'save': 'Confirm', 'cancel': 'Cancel'}
    });

    await modal.show();

    // Store a reference to the modal root element.
    const modalRoot = modal.getRoot();

    modal.getRoot().on(ModalEvents.save, () => {
        // Get the current value of the import SoRA extensions checkbox directly from the modal DOM.
        const soraCheckbox = modalRoot.find('#import-sora')[0];
        const extensions = soraCheckbox ? soraCheckbox.checked : false;
        mapAssessment(courseid, sourcetype, sourceid, mabid, reassess, extensions, partid).then(
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
 * @param {string} mabname
 * @param {string} extensioneligible
 * @return {string}
 */
async function getModalBody(type, name, endDate, mapcode, mabseq, mabname, extensioneligible) {
    // Fetch all strings in parallel.
    const [
        titleSubtitle, titleMapcode, titleSequence, titleSitsAssessment,
        titleType, titleName, titleEndDate, titleExtensionsText, titleImportSoraExtension, titleViewGuide, textToMoodleActivity
    ] = await Promise.all([
        getString('selectsource:modal:subtitle', 'local_sitsgradepush'),
        getString('selectsource:modal:mapcode', 'local_sitsgradepush'),
        getString('selectsource:modal:sequence', 'local_sitsgradepush'),
        getString('selectsource:modal:sitsassessment', 'local_sitsgradepush'),
        getString('selectsource:modal:type', 'local_sitsgradepush'),
        getString('selectsource:modal:name', 'local_sitsgradepush'),
        getString('selectsource:modal:enddate', 'local_sitsgradepush'),
        getString('selectsource:modal:extensions', 'local_sitsgradepush'),
        getString('selectsource:modal:importextension', 'local_sitsgradepush'),
        getString('selectsource:modal:viewguide', 'local_sitsgradepush'),
        getString('selectsource:modal:tomoodleactivity', 'local_sitsgradepush')
    ]);

    // Handle SoRA extension checkbox conditionally.
    const extensionSection = extensioneligible === '1' ? `
        <th>${titleExtensionsText}</th>
    ` : '';

    const extensionContent = extensioneligible === '1' ? `
        <td>
            <div class="form-check">
                <input class="form-check-input" type="checkbox" id="import-sora">
                <label class="form-check-label" for="import-sora">${titleImportSoraExtension}</label>
            </div>
            <p>
                <a href="${extensionInfoPageUrl}" target="_blank">
                    ${titleViewGuide}
                </a>
            </p>
        </td>
    ` : '';

    return `
        <div class="modal-body">
            <p>${titleSubtitle}</p>

            <table class="table table-bordered">
                <thead class="thead-light">
                    <th>${titleMapcode}</th>
                    <th>${titleSequence}</th>
                    <th>${titleSitsAssessment}</th>
                    ${extensionSection}
                </thead>
                <tr>
                    <td>${mapcode}</td>
                    <td>${mabseq}</td>
                    <td>${mabname}</td>
                    ${extensionContent}
                </tr>
            </table>

            <p>${textToMoodleActivity}</p>

            <table class="table table-bordered">
                <thead class="thead-light">
                    <th>${titleType}</th>
                    <th>${titleName}</th>
                    <th>${titleEndDate}</th>
                </thead>
                <tr>
                    <td>${type}</td>
                    <td>${name}</td>
                    <td>${endDate}</td>
                </tr>
            </table>
        </div>`;
}
