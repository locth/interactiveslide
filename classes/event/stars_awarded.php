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

/**
 * A teacher gave a student bonus stars by hand.
 *
 * @package    mod_interactiveslide
 * @copyright  2026 Interactive Slide contributors
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class stars_awarded extends base {

    /**
     * Build the event from the award details.
     *
     * @param context_module $context
     * @param int $sessionid
     * @param int $userid recipient
     * @param int $stars
     * @return base
     */
    public static function create_from_award(context_module $context, int $sessionid, int $userid, int $stars): base {
        return self::create([
            'context' => $context,
            'relateduserid' => $userid,
            'other' => ['sessionid' => $sessionid, 'stars' => $stars],
        ]);
    }

    /**
     * Initialise the event metadata.
     *
     * @return void
     */
    protected function init() {
        $this->data['crud'] = 'c';
        $this->data['edulevel'] = self::LEVEL_TEACHING;
    }

    /**
     * Human readable event name.
     *
     * @return string
     */
    public static function get_name() {
        return get_string('eventstarsawarded', 'mod_interactiveslide');
    }

    /**
     * Description for the log.
     *
     * @return string
     */
    public function get_description() {
        $stars = $this->other['stars'];
        return "The user with id '{$this->userid}' awarded {$stars} stars to the user with id " .
            "'{$this->relateduserid}' in the interactiveslide activity with course module id " .
            "'{$this->contextinstanceid}'.";
    }
}
