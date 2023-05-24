import Ajax from 'core/ajax';
import notification from 'core/notification';

export const init = (coursemoduleid) => {
    document.getElementById('local_sitsgradepush_pushbutton').addEventListener('click', (e) => {
        e.preventDefault();
        window.console.log('Pushing grades for course module id ' + coursemoduleid + '...');
        // Schedule a task to push grades to SITS.
        schedulePushTask(coursemoduleid);
    });
};

/**
 * Schedule a task to push grades to SITS.
 * @param {int} coursemoduleid
 */
function schedulePushTask(coursemoduleid) {
    Ajax.call([{
        methodname: 'local_sitsgradepush_schedule_push_task',
        args: {
            'coursemoduleid': coursemoduleid,
        },
    }])[0].done(function(response) {
        notification.addNotification({
            message: response.message,
            type: response.success ? 'success' : 'warning'
        });
        if (response.success) {
            // Get the push button.
            let pushbuton = document.getElementById('local_sitsgradepush_pushbutton');

            // Update the button text.
            pushbuton.innerHTML = response.status;

            // Disable the push button after scheduled push task successfully.
            pushbuton.disabled = true;
        }
    }).fail(function(err) {
        window.console.log(err);
    });
}
