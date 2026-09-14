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
 * A teacher loaded a deck from a portable file.
 *
 * Logged for the same reason session_deleted is: importing in replace mode
 * destroys an authored deck along with every response attached to it, and the
 * rows that could answer "what was there before?" are gone by the time anyone
 * thinks to ask. The counts travel on the event so the log can answer instead.
 *
 * @package    mod_interactiveslide
 * @copyright  2026 Interactive Slide contributors
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class deck_imported extends base {

    /**
     * Build the event from the import that is about to run.
     *
     * @param context_module $context
     * @param stdClass $instance the deck record
     * @param string $mode 'replace' or 'append'
     * @param int $slides slides in the file
     * @param int $removed slides the import is about to destroy
     * @return base
     */
    public static function create_from_import(context_module $context, stdClass $instance,
            string $mode, int $slides, int $removed): base {
        return self::create([
            'context' => $context,
            'objectid' => (int)$instance->id,
            'other' => ['mode' => $mode, 'slides' => $slides, 'removed' => $removed],
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
        $this->data['objecttable'] = 'interactiveslide';
    }

    /**
     * Human readable event name.
     *
     * @return string
     */
    public static function get_name() {
        return get_string('eventdeckimported', 'mod_interactiveslide');
    }

    /**
     * Description for the log.
     *
     * @return string
     */
    public function get_description() {
        $mode = (string)($this->other['mode'] ?? '');
        $slides = (int)($this->other['slides'] ?? 0);
        $removed = (int)($this->other['removed'] ?? 0);

        return "The user with id '{$this->userid}' imported {$slides} slide(s) in '{$mode}' mode, " .
            "replacing {$removed} slide(s), in the interactiveslide activity with " .
            "course module id '{$this->contextinstanceid}'.";
    }
}
