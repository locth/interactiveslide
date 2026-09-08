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
 * A teacher deleted a live session and the stars collected in it.
 *
 * Logged because it is the one action in this plugin that destroys results a
 * student earned. The log is what tells them afterwards who removed it and when.
 *
 * @package    mod_interactiveslide
 * @copyright  2026 Interactive Slide contributors
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class session_deleted extends base {

    /**
     * Build the event from the session that is about to go.
     *
     * @param context_module $context
     * @param stdClass $session
     * @param int $participants how many people had results in it
     * @return base
     */
    public static function create_from_session(context_module $context, stdClass $session,
            int $participants = 0): base {
        return self::create([
            'context' => $context,
            'objectid' => (int)$session->id,
            'other' => [
                'sessionname' => (string)$session->name,
                'participants' => $participants,
            ],
        ]);
    }

    /**
     * Initialise the event metadata.
     *
     * @return void
     */
    protected function init() {
        $this->data['crud'] = 'd';
        $this->data['edulevel'] = self::LEVEL_TEACHING;
        $this->data['objecttable'] = 'interactiveslide_session';
    }

    /**
     * Human readable event name.
     *
     * @return string
     */
    public static function get_name() {
        return get_string('eventsessiondeleted', 'mod_interactiveslide');
    }

    /**
     * Description for the log.
     *
     * @return string
     */
    public function get_description() {
        $participants = (int)($this->other['participants'] ?? 0);

        return "The user with id '{$this->userid}' deleted session with id '{$this->objectid}', " .
            "removing the results of {$participants} participant(s) " .
            "in the interactiveslide activity with course module id '{$this->contextinstanceid}'.";
    }
}
