import Ajax from 'core/ajax';

/**
 * Schedule a task to push grades to SITS.
 *
 * @param {int} assessmentmappingid The assessment mapping ID.
 * @return {Promise} Promise.
 */
export const schedulePushTask = async(assessmentmappingid) => {
    return new Promise((resolve, reject) => {
        Ajax.call([{
            methodname: 'local_sitsgradepush_schedule_push_task',
            args: {
                'assessmentmappingid': assessmentmappingid,
            },
        }])[0].done(function(response) {
            resolve(response);
        }).fail(function(err) {
            window.console.log(err);
            reject(err);
        });
    });
};

/**
 * Map an assessment to a component grade.
 *
 * @param {int} courseid
 * @param {string} sourcetype
 * @param {int} sourceid
 * @param {int} mabid
 * @param {int} reassess
 * @param {int|null} partid
 * @return {Promise}
 */
export const mapAssessment = async(courseid, sourcetype, sourceid, mabid, reassess, partid = null) => {
    return new Promise((resolve, reject) => {
        Ajax.call([{
            methodname: 'local_sitsgradepush_map_assessment',
            args: {
                'courseid': courseid,
                'sourcetype': sourcetype,
                'sourceid': sourceid,
                'mabid': mabid,
                'reassess': reassess,
                'partid': partid,
            },
        }])[0].done(function(response) {
            resolve(response);
        }).fail(function(err) {
            window.console.log(err);
            reject(err);
        });
    });
};

/**
 * Get the latest information about the assessment mappings of a course.
 * For updating the dashboard page and activity marks transfer page.
 *
 * @param {int} courseid
 * @param {string} sourcetype
 * @param {int} sourceid
 * @return {Promise<unknown>}
 */
export const getAssessmentsUpdate = async(courseid, sourcetype = '', sourceid = 0) => {
    return new Promise((resolve, reject) => {
        Ajax.call([{
            methodname: 'local_sitsgradepush_get_assessments_update',
            args: {
                'courseid': courseid,
                'sourcetype': sourcetype,
                'sourceid': sourceid,
            },
        }])[0].done(function(response) {
            resolve(response);
        }).fail(function(err) {
            window.console.log(err);
            reject(err);
        });
    });
};

/**
 * Update the progress bar.
 *
 * @param {HTMLElement} container
 * @param {int} progress
 * @return {void}
 */
export const updateProgressBar = (container, progress) => {
    // Get the progress bar.
    let progressLabel = container.querySelector('small');
    let progressBar = container.querySelector('.progress-bar');

    if (progressLabel && progressBar) {
        if (progress === null) {
            progress = 0;
        }
        // Update the progress bar.
        progressLabel.innerHTML = 'Progress: ' + progress + '%';
        progressBar.setAttribute('aria-valuenow', progress);
        progressBar.style.width = progress + '%';
    }
};
