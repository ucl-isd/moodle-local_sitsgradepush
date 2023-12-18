/**
 * Create a progress bar.
 *
 * @param {string} id
 * @param {string} classname
 * @param {int} assessmentmappingid
 * @param {int} progress
 * @param {boolean} showPercentage
 * @return {HTMLElement}
 */
export const createProgressBar = (id, classname, assessmentmappingid, progress, showPercentage = false) => {
    // Create the progress bar and set the attributes.
    let progressBar = document.createElement('div');
    progressBar.classList.add('progress', classname);
    progressBar.setAttribute('id', id);
    progressBar.setAttribute('data-assessmentmappingid', assessmentmappingid);

    // Update the progress.
    updateProgressBar(progressBar, progress, showPercentage);

    return progressBar;
};

/**
 * Update the progress bar.
 *
 * @param {HTMLElement} progressBar
 * @param {int} progress
 * @param {boolean} showPercentage
 * @return {void}
 */
export const updateProgressBar = (progressBar, progress, showPercentage = false) => {
    // Show percentage if showPercentage is true.
    let progressLabel = '';
    if (showPercentage) {
        progressLabel = progress + '%';
    }

    // Update the progress bar.
    progressBar.innerHTML = '<div class="progress-bar" role="progressbar" aria-valuenow="' +
        progress + '" aria-valuemin="0" aria-valuemax="100" style="width:' + progress + '%">' + progressLabel + '</div>';
};

/**
 * Create a spinner.
 *
 * @param {string} color
 * @param {string} size
 * @return {HTMLElement}
 */
export const createSpinner = (color, size) => {
    // Create the spinner and set the attributes.
    let spinner = document.createElement('div');
    spinner.setAttribute('role', 'status');
    spinner.classList.add('spinner-border', color, size);
    spinner.innerHTML = '<span class="sr-only">Loading...</span>';

    return spinner;
};
