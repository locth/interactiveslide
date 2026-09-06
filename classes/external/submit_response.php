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
use mod_interactiveslide\local\grader;
use mod_interactiveslide\local\interaction_manager;
use mod_interactiveslide\local\session_manager;
use moodle_exception;

/**
 * Store a student's answer to the round in progress.
 *
 * @package    mod_interactiveslide
 * @copyright  2026 Interactive Slide contributors
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class submit_response extends external_api {

    /**
     * Parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'cmid' => new external_value(PARAM_INT, 'Course module id'),
            'roundid' => new external_value(PARAM_INT, 'Round the student believes is open'),
            'answer' => new external_value(PARAM_RAW, 'JSON encoded answer payload'),
        ]);
    }

    /**
     * Mark and store the answer.
     *
     * @param int $cmid
     * @param int $roundid
     * @param string $answer
     * @return array
     * @throws moodle_exception
     */
    public static function execute(int $cmid, int $roundid, string $answer): array {
        global $DB, $USER;

        $params = self::validate_parameters(self::execute_parameters(),
            ['cmid' => $cmid, 'roundid' => $roundid, 'answer' => $answer]);

        $resolved = helper::resolve($params['cmid']);
        self::validate_context($resolved['context']);
        require_capability('mod/interactiveslide:submit', $resolved['context']);

        $session = helper::require_active_session($resolved['instance']);

        $round = $DB->get_record('interactiveslide_round',
            ['id' => $params['roundid'], 'sessionid' => $session->id]);
        if (!$round) {
            throw new moodle_exception('errornoround', 'mod_interactiveslide');
        }

        // A student who lagged behind a slide change must not be able to answer
        // a question the room has already moved past.
        if ((int)$round->slideid !== (int)$session->currentslideid) {
            throw new moodle_exception('errorroundnotcurrent', 'mod_interactiveslide');
        }

        $interaction = interaction_manager::get_interaction((int)$round->interactionid);
        if (!$interaction) {
            throw new moodle_exception('errornointeraction', 'mod_interactiveslide');
        }

        $payload = json_decode($params['answer'], true);
        if (!is_array($payload)) {
            throw new moodle_exception('erroremptyanswer', 'mod_interactiveslide');
        }

        $response = grader::submit($resolved['instance'], $session, $round, $interaction,
            (int)$USER->id, $payload);

        \mod_interactiveslide\event\response_submitted::create_from_response(
            $resolved['context'], $response)->trigger();

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
