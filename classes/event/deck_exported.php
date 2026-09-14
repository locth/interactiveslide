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
 * A teacher downloaded a deck as a portable file.
 *
 * Logged because the file carries every answer key in the activity out of the
 * site in one click. Nothing is destroyed, but it is worth being able to say
 * afterwards who took a copy and when.
 *
 * @package    mod_interactiveslide
 * @copyright  2026 Interactive Slide contributors
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class deck_exported extends base {

    /**
     * Build the event from the deck being exported.
     *
     * @param context_module $context
     * @param stdClass $instance the deck record
     * @param int $slides how many slides went into the file
     * @return base
     */
    public static function create_from_deck(context_module $context, stdClass $instance,
            int $slides): base {
        return self::create([
            'context' => $context,
            'objectid' => (int)$instance->id,
            'other' => ['slides' => $slides],
        ]);
    }

    /**
     * Initialise the event metadata.
     *
     * @return void
     */
    protected function init() {
        $this->data['crud'] = 'r';
        $this->data['edulevel'] = self::LEVEL_TEACHING;
        $this->data['objecttable'] = 'interactiveslide';
    }

    /**
     * Human readable event name.
     *
     * @return string
     */
    public static function get_name() {
        return get_string('eventdeckexported', 'mod_interactiveslide');
    }

    /**
     * Description for the log.
     *
     * @return string
     */
    public function get_description() {
        $slides = (int)($this->other['slides'] ?? 0);

        return "The user with id '{$this->userid}' exported {$slides} slide(s) from " .
            "the interactiveslide activity with course module id '{$this->contextinstanceid}'.";
    }
}
