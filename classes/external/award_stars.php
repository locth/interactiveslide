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
use moodle_exception;

/**
 * Give a student bonus stars by hand during a session.
 *
 * @package    mod_interactiveslide
 * @copyright  2026 Interactive Slide contributors
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class award_stars extends external_api {

    /**
     * Parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'cmid' => new external_value(PARAM_INT, 'Course module id'),
            'userid' => new external_value(PARAM_INT, 'Recipient'),
            'stars' => new external_value(PARAM_INT, 'Stars to add, may be negative'),
            'reason' => new external_value(PARAM_TEXT, 'Why', VALUE_DEFAULT, ''),
        ]);
    }

    /**
     * Award the stars.
     *
     * @param int $cmid
     * @param int $userid
     * @param int $stars
     * @param string $reason
     * @return array
     * @throws moodle_exception
     */
    public static function execute(int $cmid, int $userid, int $stars, string $reason = ''): array {
        global $USER;

        $params = self::validate_parameters(self::execute_parameters(),
            ['cmid' => $cmid, 'userid' => $userid, 'stars' => $stars, 'reason' => $reason]);

        $resolved = helper::resolve($params['cmid']);
        self::validate_context($resolved['context']);
        require_capability('mod/interactiveslide:awardstars', $resolved['context']);

        $session = helper::require_active_session($resolved['instance']);

        // Only people who can actually take part in this activity may be given stars.
        if (!is_enrolled($resolved['context'], $params['userid'], 'mod/interactiveslide:submit')) {
            throw new moodle_exception('errornotparticipant', 'mod_interactiveslide');
        }

        session_manager::award_stars($session, $params['userid'], $params['stars'],
            $params['reason'], (int)$USER->id);

        \mod_interactiveslide\event\stars_awarded::create_from_award(
            $resolved['context'], (int)$session->id, $params['userid'], $params['stars'])->trigger();

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
