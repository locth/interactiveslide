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
 * A round was opened for answers.
 *
 * @package    mod_interactiveslide
 * @copyright  2026 Interactive Slide contributors
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class round_opened extends base {

    /**
     * Build the event from a round record.
     *
     * @param context_module $context
     * @param stdClass $round
     * @return base
     */
    public static function create_from_round(context_module $context, stdClass $round): base {
        return self::create([
            'context' => $context,
            'objectid' => (int)$round->id,
            'other' => ['interactionid' => (int)$round->interactionid, 'slideid' => (int)$round->slideid],
        ]);
    }

    /**
     * Initialise the event metadata.
     *
     * @return void
     */
    protected function init() {
        $this->data['crud'] = 'u';
        $this->data['edulevel'] = self::LEVEL_TEACHING;
        $this->data['objecttable'] = 'interactiveslide_round';
    }

    /**
     * Human readable event name.
     *
     * @return string
     */
    public static function get_name() {
        return get_string('eventroundopened', 'mod_interactiveslide');
    }

    /**
     * Description for the log.
     *
     * @return string
     */
    public function get_description() {
        return "The user with id '{$this->userid}' opened round with id '{$this->objectid}' " .
            "in the interactiveslide activity with course module id '{$this->contextinstanceid}'.";
    }
}
