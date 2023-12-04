// Define variables to track sorting direction and icons.
let sortingDirection = [];
let iconElements = [];

export const tableHelperInit = (tableId, sortColumnNumber, filterInputId = null) => {
    // Get the target table.
    let targetTable = document.getElementById(tableId);

    // Add event listeners to filter input.
    if (filterInputId) {
        let filterInput = document.getElementById(filterInputId);
        filterInput.addEventListener("keyup", function() {
            filterTable(filterInput, targetTable);
        });
    }

    // Add event listeners to table headers.
    for (let i = 0; i < sortColumnNumber; i++) {
        let header = targetTable.rows[0].cells[i];
        iconElements.push(header.firstElementChild);
        header.addEventListener("click", function() {
            sortTable(targetTable, i);
        });
    }
};

/**
 * Sort a table.
 *
 * @param {HTMLElement} targetTable
 * @param {int} columnIndex
 */
function sortTable(targetTable, columnIndex) {
    let rows, switching, i, x, y, shouldSwitch;
    switching = true;

    // Get the icon element for the current column.
    let iconElement = targetTable.rows[0].cells[columnIndex].firstElementChild;

    // Reset sorting direction and icons for all columns.
    for (let i = 0; i < sortingDirection.length; i++) {
        if (i !== columnIndex) {
            sortingDirection[i] = undefined;
            iconElements[i].className = "fas fa-sort";
        }
    }

    // Toggle sorting direction and update icon.
    if (sortingDirection[columnIndex] === undefined || sortingDirection[columnIndex] === "desc") {
        sortingDirection[columnIndex] = "asc";
        iconElement.className = "fas fa-sort-up";
    } else {
        sortingDirection[columnIndex] = "desc";
        iconElement.className = "fas fa-sort-down";
    }

    while (switching) {
        switching = false;
        rows = targetTable.rows;

        for (i = 1; i < rows.length - 1; i++) {
            shouldSwitch = false;
            x = rows[i].getElementsByTagName("td")[columnIndex];
            y = rows[i + 1].getElementsByTagName("td")[columnIndex];

            let xText = x.textContent || x.innerText;
            let yText = y.textContent || y.innerText;

            // Compare based on sorting direction.
            if ((sortingDirection[columnIndex] === "asc" && xText.toLowerCase() > yText.toLowerCase()) ||
                (sortingDirection[columnIndex] === "desc" && xText.toLowerCase() < yText.toLowerCase())) {
                shouldSwitch = true;
                break;
            }
        }

        if (shouldSwitch) {
            rows[i].parentNode.insertBefore(rows[i + 1], rows[i]);
            switching = true;
        }
    }
}

/**
 * Filter a table.
 *
 * @param {HTMLElement} filterInput
 * @param {HTMLElement} targetTable
 */
function filterTable(filterInput, targetTable) {
    let filter, tr, td, i, j, txtValue;
    filter = filterInput.value.toUpperCase();

    tr = targetTable.getElementsByTagName("tr");
    let hasMatchingRow = false;

    // Loop through all rows in the table.
    for (i = 1; i < tr.length; i++) {
        let found = false;
        td = tr[i].getElementsByTagName("td");

        // Loop through all columns in a row.
        for (j = 0; j < td.length; j++) {
            txtValue = td[j].textContent || td[j].innerText;
            if (txtValue.toUpperCase().indexOf(filter) > -1) {
                found = true;
                break; // If a match is found in any column, no need to check other columns.
            }
        }

        // Display the row if a match is found, otherwise hide it.
        if (found) {
            tr[i].style.display = "";
            hasMatchingRow = true;
        } else {
            tr[i].style.display = "none";
        }
    }

    // Show or hide the "No matching rows found" message.
    let noMatchingRowsMessage = document.getElementById("noMatchingRowsMessage");
    if (!hasMatchingRow) {
        noMatchingRowsMessage.style.display = "block";
    } else {
        noMatchingRowsMessage.style.display = "none";
    }
}
