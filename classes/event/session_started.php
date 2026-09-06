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
 * A teacher started a live session.
 *
 * @package    mod_interactiveslide
 * @copyright  2026 Interactive Slide contributors
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class session_started extends base {

    /**
     * Build the event from a session record.
     *
     * @param context_module $context
     * @param stdClass $session
     * @return base
     */
    public static function create_from_session(context_module $context, stdClass $session): base {
        $event = self::create([
            'context' => $context,
            'objectid' => (int)$session->id,
        ]);
        $event->add_record_snapshot('interactiveslide_session', $session);

        return $event;
    }

    /**
     * Initialise the event metadata.
     *
     * @return void
     */
    protected function init() {
        $this->data['crud'] = 'c';
        $this->data['edulevel'] = self::LEVEL_TEACHING;
        $this->data['objecttable'] = 'interactiveslide_session';
    }

    /**
     * Human readable event name.
     *
     * @return string
     */
    public static function get_name() {
        return get_string('eventsessionstarted', 'mod_interactiveslide');
    }

    /**
     * Description for the log.
     *
     * @return string
     */
    public function get_description() {
        return "The user with id '{$this->userid}' started session with id '{$this->objectid}' " .
            "in the interactiveslide activity with course module id '{$this->contextinstanceid}'.";
    }
}
