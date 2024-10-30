<?php
// This file is part of Moodle - http://moodle.org/
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
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

namespace local_sitsgradepush\aws;

use Aws\Sqs\SqsClient;
use core\aws\client_factory;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/lib/aws-sdk/src/functions.php');
require_once($CFG->dirroot . '/lib/guzzlehttp/guzzle/src/functions.php');

/**
 * Class for SQS client.
 *
 * @package    local_sitsgradepush
 * @copyright  2024 onwards University College London {@link https://www.ucl.ac.uk/}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @author     Alex Yeung <k.yeung@ucl.ac.uk>
 */
class sqs {

    /** @var SqsClient AWS client */
    protected SqsClient $client;

    /**
     * Constructor.
     *
     * @throws \moodle_exception
     */
    public function __construct() {
        // Check required configs are set.
        $configs = $this->check_required_configs_are_set();

        $this->client = client_factory::get_client('\Aws\Sqs\SqsClient', [
            'region' => $configs->aws_region,
            'version' => 'latest',
            'credentials' => [
                'key' => $configs->aws_key,
                'secret' => $configs->aws_secret,
            ],
        ]);
    }

    /**
     * Get the client.
     *
     * @return SqsClient
     */
    public function get_client(): SqsClient {
        return $this->client;
    }

    /**
     * Check required configs are set.
     *
     * @return object
     * @throws \moodle_exception
     */
    private function check_required_configs_are_set(): \stdClass {
        $requiredfields = ['aws_region', 'aws_key', 'aws_secret'];

        $configs = [];
        foreach ($requiredfields as $field) {
            // Get the config value.
            $config = get_config('local_sitsgradepush', $field);

            // Check if the config is empty.
            if (empty($config)) {
                throw new \moodle_exception('error:missingrequiredconfigs', 'local_sitsgradepush');
            }
            $configs[$field] = $config;
        }
        return (object) $configs;
    }
}
