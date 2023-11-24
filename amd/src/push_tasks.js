import Ajax from 'core/ajax';

/**
 * Schedule a task to push grades to SITS.
 *
 * @param {string} assessmentmappingid The assessment mapping ID.
 * @return {Promise} Promise.
 */
export const schedulePushTask = (assessmentmappingid) => {
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
