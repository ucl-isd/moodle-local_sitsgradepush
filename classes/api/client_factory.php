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
namespace local_sitsgradepush\api;

/**
 * Factory class for creating API client.
 *
 * @package    local_sitsgradepush
 * @copyright  2023 onwards University College London {@link https://www.ucl.ac.uk/}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @author     Alex Yeung <k.yeung@ucl.ac.uk>
 */
class client_factory {
    /**
     * Return requested API client.
     *
     * @param string $classname
     * @return client
     * @throws \moodle_exception
     */
    public static function getapiclient(string $classname) {
        $file = __DIR__ . '/../../apiclients/' .$classname.'/'.'lib.php';
        if (file_exists($file)) {
            require_once($file);
            $class = 'sitsapiclient_' . $classname . '\\' . $classname;
            if (class_exists($class)) {
                return new $class();
            }
        }
        throw new \moodle_exception('API client ' . $classname . ' not found.');
    }
}
