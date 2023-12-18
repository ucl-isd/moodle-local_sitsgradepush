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
 * @param {int} coursemoduleid
 * @param {int} mabid
 * @param {int|null} partid
 * @return {Promise}
 */
export const mapAssessment = async(courseid, coursemoduleid, mabid, partid = null) => {
    return new Promise((resolve, reject) => {
        Ajax.call([{
            methodname: 'local_sitsgradepush_map_assessment',
            args: {
                'courseid': courseid,
                'coursemoduleid': coursemoduleid,
                'mabid': mabid,
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
 * @return {Promise<unknown>}
 */
export const getAssessmentsUpdate = async(courseid) => {
    return new Promise((resolve, reject) => {
        Ajax.call([{
            methodname: 'local_sitsgradepush_get_assessments_update',
            args: {
                'courseid': courseid,
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
 * Get the students information for a given assessment mapping.
 * For synchronous marks transfer.
 *
 * @param {int} assessmentmappingid
 * @return {Promise}
 */
export const getTransferStudents = (assessmentmappingid) => {
    return new Promise((resolve, reject) => {
        Ajax.call([{
            methodname: 'local_sitsgradepush_get_transfer_students',
            args: {'assessmentmappingid': assessmentmappingid},
        }])[0].done(function(response) {
            resolve(response);
        }).fail(function(err) {
            window.console.log(err);
            reject(err);
        });
    });
};

/**
 * Transfer mark for a student.
 *
 * @param {int} assessmentmappingid
 * @param {int} userid
 * @return {Promise}
 */
export const transferMarkForStudent = (assessmentmappingid, userid) => {
    return new Promise((resolve, reject) => {
        Ajax.call([{
            methodname: 'local_sitsgradepush_transfer_mark_for_student',
            args: {
                'assessmentmappingid': assessmentmappingid,
                'userid': userid
            },
        }])[0].done(function(response) {
            resolve(response);
        }).fail(function(err) {
            window.console.log(err);
            reject(err);
        });
    });
};
