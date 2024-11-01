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

namespace local_sitsgradepush\task;

use local_sitsgradepush\extension\sora_queue_processor;

/**
 * Scheduled task to process AWS SORA updates.
 *
 * @package    local_sitsgradepush
 * @copyright  2024 onwards University College London {@link https://www.ucl.ac.uk/}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @author     Alex Yeung <k.yeung@ucl.ac.uk>
 */
class process_aws_sora_updates extends \core\task\scheduled_task {
    /**
     * Return name of the task.
     *
     * @return string
     * @throws \coding_exception
     */
    public function get_name() {
        return get_string('task:process_aws_sora_updates', 'local_sitsgradepush');
    }

    /**
     * Execute the task.
     * @throws \Exception
     */
    public function execute(): void {
        // Skip if extension is not enabled.
        if (!get_config('local_sitsgradepush', 'extension_enabled')) {
            mtrace('Extension processing is not enabled. Exiting...');
            return;
        }

        $processor = new sora_queue_processor();
        $processor->execute();
    }
}
