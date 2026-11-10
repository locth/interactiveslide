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
use mod_interactiveslide\local\picker;

/**
 * Pay the stars a fortune card promised, once the presenter says it came true.
 *
 * @package    mod_interactiveslide
 * @copyright  2026 Interactive Slide contributors
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class apply_fortune extends external_api {

    /**
     * Parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'cmid' => new external_value(PARAM_INT, 'Course module id'),
            'pickid' => new external_value(PARAM_INT, 'The call whose card came true'),
        ]);
    }

    /**
     * Pay the stars.
     *
     * @param int $cmid
     * @param int $pickid
     * @return array
     */
    public static function execute(int $cmid, int $pickid): array {
        global $USER;

        $params = self::validate_parameters(self::execute_parameters(), ['cmid' => $cmid, 'pickid' => $pickid]);

        $resolved = helper::resolve($params['cmid']);
        self::validate_context($resolved['context']);
        require_capability('mod/interactiveslide:present', $resolved['context']);
        require_capability('mod/interactiveslide:awardstars', $resolved['context']);

        $session = helper::require_active_session($resolved['instance']);
        $pick = picker::get_pick($session, (int)$params['pickid']);

        $stars = picker::apply_fortune($session, $pick, (int)$USER->id);

        if ($stars > 0) {
            \mod_interactiveslide\event\stars_awarded::create_from_award(
                $resolved['context'], (int)$session->id, (int)$pick->userid, $stars)->trigger();
        }

        return fortune_response::build($resolved, $pick);
    }

    /**
     * Return description.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return fortune_response::returns();
    }
}
