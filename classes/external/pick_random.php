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
 * Call one or more people in the room to the board, at random.
 *
 * @package    mod_interactiveslide
 * @copyright  2026 Interactive Slide contributors
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class pick_random extends external_api {

    /**
     * Parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'cmid' => new external_value(PARAM_INT, 'Course module id'),
            'count' => new external_value(PARAM_INT, 'How many people to call, 1 to 5', VALUE_DEFAULT, 1),
            'onlineonly' => new external_value(PARAM_BOOL, 'Only people whose device is connected now',
                VALUE_DEFAULT, true),
            'norepeat' => new external_value(PARAM_BOOL, 'Leave out people called earlier in the session',
                VALUE_DEFAULT, true),
        ]);
    }

    /**
     * Make the call.
     *
     * @param int $cmid
     * @param int $count
     * @param bool $onlineonly
     * @param bool $norepeat
     * @return array
     */
    public static function execute(int $cmid, int $count = 1, bool $onlineonly = true, bool $norepeat = true): array {
        global $USER;

        $params = self::validate_parameters(self::execute_parameters(), [
            'cmid' => $cmid,
            'count' => $count,
            'onlineonly' => $onlineonly,
            'norepeat' => $norepeat,
        ]);

        $resolved = helper::resolve_for_presenter($params['cmid']);
        self::validate_context($resolved['context']);

        $session = helper::require_active_session($resolved['instance']);

        // Whoever may answer may be called: the rule a group award follows,
        // checked with one query for the whole class.
        $eligible = array_keys(get_enrolled_users($resolved['context'], 'mod/interactiveslide:submit',
            0, 'u.id'));

        $picks = picker::pick($session, (int)$params['count'], (bool)$params['onlineonly'],
            (bool)$params['norepeat'], $eligible, (int)$USER->id);

        $response = helper::state_response($resolved['instance'], $resolved['context']);
        $response['picks'] = json_encode(picker::export_picks($picks, $resolved['context']),
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return $response;
    }

    /**
     * Return description.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'revision' => new external_value(PARAM_INT, 'Session revision counter'),
            'state' => new external_value(PARAM_RAW, 'The live state document, JSON encoded'),
            'picks' => new external_value(PARAM_RAW, 'The people called, in reveal order, JSON encoded'),
        ]);
    }
}
