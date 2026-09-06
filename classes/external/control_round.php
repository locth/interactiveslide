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
 * Drive the round on the current slide: open, close, reveal, reset.
 *
 * @package    mod_interactiveslide
 * @copyright  2026 Interactive Slide contributors
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class control_round extends external_api {

    /** @var string[] Actions this function accepts. */
    private const ACTIONS = ['open', 'close', 'reveal', 'hide', 'showresult', 'hideresult', 'reset'];

    /**
     * Parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'cmid' => new external_value(PARAM_INT, 'Course module id'),
            'action' => new external_value(PARAM_ALPHA,
                'One of open, close, reveal, hide, showresult, hideresult, reset'),
        ]);
    }

    /**
     * Apply a control action to the round of the current slide.
     *
     * @param int $cmid
     * @param string $action
     * @return array
     * @throws moodle_exception
     */
    public static function execute(int $cmid, string $action): array {
        $params = self::validate_parameters(self::execute_parameters(),
            ['cmid' => $cmid, 'action' => $action]);

        if (!in_array($params['action'], self::ACTIONS, true)) {
            throw new moodle_exception('errorinvalidaction', 'mod_interactiveslide');
        }

        $resolved = helper::resolve_for_presenter($params['cmid']);
        self::validate_context($resolved['context']);

        $session = helper::require_active_session($resolved['instance']);

        if ($params['action'] === 'open') {
            if (empty($session->currentslideid)) {
                throw new moodle_exception('errorslidenotfound', 'mod_interactiveslide');
            }
            $round = session_manager::open_round($session, (int)$session->currentslideid);

            \mod_interactiveslide\event\round_opened::create_from_round(
                $resolved['context'], $round)->trigger();

            return helper::state_response($resolved['instance'], $resolved['context']);
        }

        $round = session_manager::get_current_round($session);
        if (!$round) {
            throw new moodle_exception('errornoround', 'mod_interactiveslide');
        }

        switch ($params['action']) {
            case 'close':
                session_manager::close_round($round);
                \mod_interactiveslide\event\round_closed::create_from_round(
                    $resolved['context'], $round)->trigger();
                break;

            case 'reveal':
                // Revealing an answer while people can still submit would hand
                // out free stars, so the round always closes first.
                session_manager::close_round($round);
                $round->status = session_manager::ROUND_CLOSED;
                session_manager::set_revealed($round, true);
                break;

            case 'hide':
                session_manager::set_revealed($round, false);
                break;

            case 'showresult':
                session_manager::set_showresult($round, true);
                break;

            case 'hideresult':
                session_manager::set_showresult($round, false);
                break;

            case 'reset':
                session_manager::reset_round($round);
                break;
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
