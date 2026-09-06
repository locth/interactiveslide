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
use mod_interactiveslide\local\session_manager;
use mod_interactiveslide\local\slide_manager;

/**
 * Move the running session to another slide.
 *
 * @package    mod_interactiveslide
 * @copyright  2026 Interactive Slide contributors
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class set_slide extends external_api {

    /**
     * Parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'cmid' => new external_value(PARAM_INT, 'Course module id'),
            'slideid' => new external_value(PARAM_INT, 'Slide to move to, 0 to use the direction', VALUE_DEFAULT, 0),
            'direction' => new external_value(PARAM_INT,
                'Step relative to the current slide: 1 next, -1 previous, 0 none', VALUE_DEFAULT, 0),
        ]);
    }

    /**
     * Change the current slide.
     *
     * @param int $cmid
     * @param int $slideid
     * @param int $direction
     * @return array
     */
    public static function execute(int $cmid, int $slideid = 0, int $direction = 0): array {
        $params = self::validate_parameters(self::execute_parameters(),
            ['cmid' => $cmid, 'slideid' => $slideid, 'direction' => $direction]);

        $resolved = helper::resolve_for_presenter($params['cmid']);
        self::validate_context($resolved['context']);

        $session = helper::require_active_session($resolved['instance']);

        $target = $params['slideid'];
        if ($target <= 0 && $params['direction'] !== 0) {
            $next = slide_manager::get_adjacent_slide(
                (int)$resolved['instance']->id,
                (int)$session->currentslideid,
                $params['direction'] > 0 ? 1 : -1
            );
            // At either end of the deck, stay where we are rather than wrapping.
            $target = $next ? (int)$next->id : (int)$session->currentslideid;
        }

        if ($target > 0) {
            session_manager::set_current_slide($session, $target);
        }

        return helper::state_response($resolved['instance'], $resolved['context']);
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
