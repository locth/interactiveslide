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

namespace mod_interactiveslide\event;

use context_module;
use core\event\base;
use stdClass;

/**
 * A student submitted an answer.
 *
 * @package    mod_interactiveslide
 * @copyright  2026 Interactive Slide contributors
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class response_submitted extends base {

    /**
     * Build the event from a stored response.
     *
     * @param context_module $context
     * @param stdClass $response
     * @return base
     */
    public static function create_from_response(context_module $context, stdClass $response): base {
        return self::create([
            'context' => $context,
            'objectid' => (int)$response->id,
            'other' => [
                'roundid' => (int)$response->roundid,
                'stars' => (int)$response->stars + (int)$response->bonusstars,
            ],
        ]);
    }

    /**
     * Initialise the event metadata.
     *
     * @return void
     */
    protected function init() {
        $this->data['crud'] = 'c';
        $this->data['edulevel'] = self::LEVEL_PARTICIPATING;
        $this->data['objecttable'] = 'interactiveslide_response';
    }

    /**
     * Human readable event name.
     *
     * @return string
     */
    public static function get_name() {
        return get_string('eventresponsesubmitted', 'mod_interactiveslide');
    }

    /**
     * Description for the log.
     *
     * @return string
     */
    public function get_description() {
        return "The user with id '{$this->userid}' submitted response with id '{$this->objectid}' " .
            "in the interactiveslide activity with course module id '{$this->contextinstanceid}'.";
    }
}
