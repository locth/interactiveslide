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

namespace mod_interactiveslide\external;

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_single_structure;
use core_external\external_value;
use mod_interactiveslide\local\guest;
use mod_interactiveslide\local\session_manager;
use moodle_exception;

/**
 * Join the running session as a guest, under a name.
 *
 * @package    mod_interactiveslide
 * @copyright  2026 Interactive Slide contributors
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class join_as_guest extends external_api {

    /**
     * Parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'cmid' => new external_value(PARAM_INT, 'Course module id'),
            // Raw here and cleaned by guest::clean_name(), which knows everywhere
            // the name is going to be shown.
            'name' => new external_value(PARAM_RAW, 'The name to take part under'),
        ]);
    }

    /**
     * Give this browser a guest identity in the running session.
     *
     * Asking twice is harmless: a browser that already joined is handed the state
     * again rather than a second identity, so a double tap on a slow phone does
     * not put the same person on the board twice.
     *
     * @param int $cmid
     * @param string $name
     * @return array
     * @throws moodle_exception
     */
    public static function execute(int $cmid, string $name): array {
        $params = self::validate_parameters(self::execute_parameters(), ['cmid' => $cmid, 'name' => $name]);

        $resolved = helper::resolve($params['cmid']);
        self::validate_context($resolved['context']);

        $instance = $resolved['instance'];
        $session = helper::require_active_session($instance);

        // Only the shared guest account needs a name; everyone else has one. And
        // only a browser that opened this session's link may ask for one.
        if (!isguestuser() || !guest::enabled($instance) || !guest::has_pass($session)) {
            throw new moodle_exception('errorguestnotallowed', 'mod_interactiveslide');
        }

        $existing = guest::current($session);
        if ($existing && !empty($existing->removed)) {
            throw new moodle_exception('errorguestremoved', 'mod_interactiveslide');
        }

        if (!$existing) {
            // The late joining rule holds for guests exactly as it does for
            // students, and it is checked before the row exists, so a guest who
            // is turned away leaves nothing behind.
            if (!session_manager::can_join($instance, $session, 0)) {
                throw new moodle_exception('errorlatejoin', 'mod_interactiveslide');
            }

            $row = guest::create($session, $params['name']);
            session_manager::touch_participant($session, guest::userid((int)$row->id));
            session_manager::bump((int)$session->id);
        }

        return helper::state_response($instance, $resolved['context']);
    }

    /**
     * Return description.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return helper::state_returns();
    }
}
