let allmaboptions = [];

export const init = (maboptions) => {
    // Save the original MAB dropdown list options.
    allmaboptions = maboptions;

    // Get the MAP dropdown list.
    let mapselect = document.getElementById('id_gradepushmapselect');

    // Exit if the MAP dropdown list is not found.
    if (mapselect === null) {
        window.console.log('MAP dropdown list not found');
        return;
    }

    // Add onchange event listener to the MAP dropdown list.
    mapselect.addEventListener('change', (e) => {
        e.preventDefault();
        // Update the MAB dropdown list.
        updateMABDropdownList(mapselect.value);
    });
};

/**
 * Schedule a task to push grades to SITS.
 * @param {string} mapid
 */
function updateMABDropdownList(mapid) {
    // Get the MAB dropdown select element.
    const mabselect = document.getElementById('id_gradepushassessmentselect');

    // Exit if the MAB dropdown list is not found.
    if (mabselect === null) {
        window.console.log('MAB dropdown list not found');
        return;
    }

    // Clear all options from the MAB dropdown list except the first one.
    mabselect.options.length = 1;

    // No filter.
    if (mapid === "0") {
        // Add all options to the MAB dropdown list.
        allmaboptions.forEach((object) => {
            addOptionToSelect(mabselect, object.text, object.value, object.disabled);
        });
    } else {
        // Add options to the MAB dropdown list based on the MAP ID.
        const filteredOptions = allmaboptions.filter((object) => object.mapcode === mapid);
        filteredOptions.forEach((object) => {
            addOptionToSelect(mabselect, object.text, object.value, object.disabled);
        });
    }
}

/**
 * Add an option to a select element.
 * @param {HTMLElement} selectElement
 * @param {string} text
 * @param {string} value
 * @param {string} disabled
 */
function addOptionToSelect(selectElement, text, value, disabled = '') {
    const option = document.createElement("option");
    option.text = text;
    option.value = value;
    option.disabled = disabled;
    selectElement.add(option);
}
