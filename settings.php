<?php
// This file is part of Moodle - https://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

/**
 * Plugin administration pages are defined here.
 *
 * @package     local_sitsgradepush
 * @category    admin
 * @copyright   2023 onwards University College London {@link https://www.ucl.ac.uk/}
 * @license     https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @author      Alex Yeung <k.yeung@ucl.ac.uk>
 */

use local_sitsgradepush\manager;
use local_sitsgradepush\plugininfo\sitsapiclient;

defined('MOODLE_INTERNAL') || die();

if ($hassiteconfig) {
    $ADMIN->add('localplugins', new admin_category('localsitssettings', 'SITS'));
    $ADMIN->add('localsitssettings', new admin_category('apiclients', 'API Clients'));
    $settings = new admin_settingpage('local_sitsgradepush_settings', new lang_string('pluginname', 'local_sitsgradepush'));
    $ADMIN->add('localsitssettings', $settings);

    if ($ADMIN->fulltree) {
        // General settings.
        $settings->add(new admin_setting_heading('local_sitsgradepush_general_settings',
            get_string('settings:generalsettingsheader', 'local_sitsgradepush'),
            ''
        ));
        // Setting to enable/disable the plugin.
        $settings->add(new admin_setting_configcheckbox(
            'local_sitsgradepush/enabled',
            get_string('settings:enable', 'local_sitsgradepush'),
            get_string('settings:enable:desc', 'local_sitsgradepush'),
            '1'
        ));

        // Threshold to allow for synchronous mark transfer.
        $settings->add(new admin_setting_configtext('local_sitsgradepush/sync_threshold',
            get_string('settings:sync_threshold', 'local_sitsgradepush'),
            get_string('settings:sync_threshold:desc', 'local_sitsgradepush'),
            30,
            PARAM_INT
        ));

        // Setting to enable/disable submission log push.
        $settings->add(new admin_setting_configcheckbox(
            'local_sitsgradepush/sublogpush',
            get_string('settings:enablesublogpush', 'local_sitsgradepush'),
            get_string('settings:enablesublogpush:desc', 'local_sitsgradepush'),
            '1'
        ));

        // Setting to select API client.
        $manager = manager::get_manager();
        $options = ['' => get_string('settings:apiclientselect', 'local_sitsgradepush')] + $manager->get_api_client_list();

        $settings->add(new admin_setting_configselect(
            'local_sitsgradepush/apiclient',
            get_string('settings:apiclient', 'local_sitsgradepush'),
            get_string('settings:apiclient:desc', 'local_sitsgradepush'),
            null,
            $options
        ));

        // Set SITS moodle AST codes, separated by comma.
        $settings->add(new admin_setting_configtext('local_sitsgradepush/moodle_ast_codes',
            get_string('settings:moodleastcode', 'local_sitsgradepush'),
            get_string('settings:moodleastcode:desc', 'local_sitsgradepush'),
            ''
        ));

        // Set SITS moodle AST codes work with exam room code, separated by comma.
        $settings->add(new admin_setting_configtext('local_sitsgradepush/moodle_ast_codes_exam_room',
            get_string('settings:moodleastcodeexamroom', 'local_sitsgradepush'),
            get_string('settings:moodleastcodeexamroom:desc', 'local_sitsgradepush'),
            ''
        ));

        // Set SITS moodle exam room code.
        $settings->add(new admin_setting_configtext('local_sitsgradepush/moodle_exam_room_code',
            get_string('settings:moodleexamroomcode', 'local_sitsgradepush'),
            get_string('settings:moodleexamroomcode:desc', 'local_sitsgradepush'),
            'EXAMMDLE'
        ));

        // Set the concurrent running ad-hoc tasks.
        $settings->add(new admin_setting_configtext('local_sitsgradepush/concurrent_running_tasks',
            get_string('settings:concurrenttasks', 'local_sitsgradepush'),
            get_string('settings:concurrenttasks:desc', 'local_sitsgradepush'),
            10,
            PARAM_INT,
            5
        ));

        // Set the user profile field for export staff's source.
        $options = [];
        $manager = manager::get_manager();
        $fields = $manager->get_user_profile_fields();
        if (!empty($fields)) {
            foreach ($fields as $field) {
                $options[$field->id] = $field->name;
            }
        }
        $settings->add(new admin_setting_configselect(
                'local_sitsgradepush/user_profile_field',
                get_string('settings:userprofilefield', 'local_sitsgradepush'),
                get_string('settings:userprofilefield:desc', 'local_sitsgradepush'),
                '',
                $options)
        );

        // Set the support page URL.
        $settings->add(new admin_setting_configtext('local_sitsgradepush/support_page_url',
            get_string('settings:support_page_url', 'local_sitsgradepush'),
            get_string('settings:support_page_url:desc', 'local_sitsgradepush'),
            'https://ucldata.atlassian.net/wiki/spaces/MoodleResourceCentre/pages/96567347/FAQs+errors+support' .
            '#Error-labels-for-Mark-Transfer',
            PARAM_URL,
            50
        ));

        // Setting to enable/disable the gradebook transfer feature.
        $settings->add(new admin_setting_configcheckbox(
            'local_sitsgradepush/gradebook_enabled',
            get_string('settings:gradebook_enabled', 'local_sitsgradepush'),
            get_string('settings:gradebook_enabled:desc', 'local_sitsgradepush'),
            '1'
        ));

        // Setting to enable/disable the re-assessment transfer feature.
        $settings->add(new admin_setting_configcheckbox(
          'local_sitsgradepush/reassessment_enabled',
          get_string('settings:reassessment_enabled', 'local_sitsgradepush'),
          get_string('settings:reassessment_enabled:desc', 'local_sitsgradepush'),
          '0'
        ));

        // Setting to enable/disable extension feature.
        $settings->add(new admin_setting_configcheckbox(
            'local_sitsgradepush/extension_enabled',
            get_string('settings:enableextension', 'local_sitsgradepush'),
            get_string('settings:enableextension:desc', 'local_sitsgradepush'),
            '0'
        ));

        // Set SITS moodle AST codes that have SORA data returned by the assessment component API V1.
        $settings->add(new admin_setting_configtext('local_sitsgradepush/ast_codes_sora_api_v1',
            get_string('settings:astcodessoraapiv1', 'local_sitsgradepush'),
            get_string('settings:astcodessoraapiv1:desc', 'local_sitsgradepush'),
            'BC02, HC01, EC03, EC04, ED03, ED04'
        ));

        // Set the extension support page URL.
        $settings->add(new admin_setting_configtext('local_sitsgradepush/extension_support_page_url',
            get_string('settings:extension_support_page_url', 'local_sitsgradepush'),
            get_string('settings:extension_support_page_url:desc', 'local_sitsgradepush'),
            'https://ucldata.atlassian.net/wiki/spaces/MoodleResourceCentre/pages/96567347/FAQs+errors+support' .
            '#Error-labels-for-Mark-Transfer',
            PARAM_URL,
            50
        ));

        // Switch to enable/disable local assessment type update.
        $settings->add(new admin_setting_configcheckbox(
            'local_sitsgradepush/local_assess_type_update_enabled',
            get_string('settings:enableassesstypeupdate', 'local_sitsgradepush'),
            get_string('settings:enableassesstypeupdate:desc', 'local_sitsgradepush'),
            '1'
        ));

        // Setting to enable/disable new feature notification.
        $settings->add(new admin_setting_configcheckbox(
            'local_sitsgradepush/new_feature_notification_enabled',
            get_string('settings:new_feature_notification_enabled', 'local_sitsgradepush'),
            get_string('settings:new_feature_notification_enabled:desc', 'local_sitsgradepush'),
            '0'
        ));

        // New feature notification message in HTML.
        $settings->add(new admin_setting_configtextarea(
            'local_sitsgradepush/new_feature_notification_html',
            get_string('settings:new_feature_notification_html', 'local_sitsgradepush'),
            get_string('settings:new_feature_notification_html:desc', 'local_sitsgradepush'),
            get_string('settings:new_feature_notification_template_html', 'local_sitsgradepush'),
            PARAM_RAW, 100, 8));

        // Setting for AWS.
        $settings->add(new admin_setting_heading('local_sitsgradepush_aws_settings',
            get_string('settings:awssettings', 'local_sitsgradepush'),
            get_string('settings:awssettings:desc', 'local_sitsgradepush')
        ));

        // AWS region.
        $settings->add(new admin_setting_configtext('local_sitsgradepush/aws_region',
            get_string('settings:awsregion', 'local_sitsgradepush'),
            get_string('settings:awsregion:desc', 'local_sitsgradepush'),
            'eu-west-2'
        ));

        // AWS key.
        $settings->add(new admin_setting_configtext('local_sitsgradepush/aws_key',
            get_string('settings:awskey', 'local_sitsgradepush'),
            get_string('settings:awskey:desc', 'local_sitsgradepush'),
            'AKIAX3UE2A7B2VLXHL2O'
        ));

        // AWS secret.
        $settings->add(new admin_setting_configpasswordunmask('local_sitsgradepush/aws_secret',
            get_string('settings:awssecret', 'local_sitsgradepush'),
            get_string('settings:awssecret:desc', 'local_sitsgradepush'),
            'CHANGEME'
        ));

        // AWS SORA queue URL.
        $settings->add(new admin_setting_configtext('local_sitsgradepush/aws_sora_sqs_queue_url',
            get_string('settings:awssoraqueueurl', 'local_sitsgradepush'),
            get_string('settings:awssoraqueueurl:desc', 'local_sitsgradepush'),
            'https://sqs.eu-west-2.amazonaws.com/540370667459/person-sora-dev'
        ));

        // AWS delay process time.
        $settings->add(new admin_setting_configtext('local_sitsgradepush/aws_delay_process_time',
            get_string('settings:awsdelayprocesstime', 'local_sitsgradepush'),
            get_string('settings:awsdelayprocesstime:desc', 'local_sitsgradepush'),
            3900, // 1 hour and 5 minutes.
            PARAM_INT,
            10
        ));
    }

    $subplugins = core_plugin_manager::instance()->get_plugins_of_type('sitsapiclient');
    foreach ($subplugins as $plugin) {
        /** @var sitsapiclient $plugin */
        $plugin->load_settings($ADMIN, 'apiclients', $hassiteconfig);
    }
}
