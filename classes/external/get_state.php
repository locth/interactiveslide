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

/**
 * Poll the live state of a deck.
 *
 * @package    mod_interactiveslide
 * @copyright  2026 Interactive Slide contributors
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class get_state extends external_api {

    /**
     * Parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'cmid' => new external_value(PARAM_INT, 'Course module id'),
            'knownrevision' => new external_value(PARAM_INT,
                'Revision the caller already has; -1 always returns the full state', VALUE_DEFAULT, -1),
        ]);
    }

    /**
     * Return the current state, or nothing when the caller is already up to date.
     *
     * @param int $cmid
     * @param int $knownrevision
     * @return array
     */
    public static function execute(int $cmid, int $knownrevision = -1): array {
        global $USER;

        $params = self::validate_parameters(self::execute_parameters(),
            ['cmid' => $cmid, 'knownrevision' => $knownrevision]);

        $resolved = helper::resolve($params['cmid']);
        self::validate_context($resolved['context']);

        // A whole class asks this question every couple of seconds, so answer the
        // cheap version first: read the revision counter, and only assemble the
        // full state document when the caller is actually behind. A running timer
        // is interpolated in the browser, so nothing is lost by staying quiet.
        // The presenter is one person and needs live participant counts, which do
        // not move the revision, so they always get the full document.
        $ispresenter = has_capability('mod/interactiveslide:present', $resolved['context']);

        if (!$ispresenter && $params['knownrevision'] >= 0) {
            $revision = session_manager::poll_revision(
                $resolved['instance'], $resolved['context'], (int)$USER->id);

            if ($revision === $params['knownrevision']) {
                return ['revision' => $revision, 'state' => '', 'changed' => 0];
            }
        }

        $response = helper::state_response($resolved['instance'], $resolved['context']);
        $response['changed'] = 1;

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
            'state' => new external_value(PARAM_RAW, 'The live state document as JSON, empty when unchanged'),
            'changed' => new external_value(PARAM_INT, '1 when the state document is included'),
        ]);
    }
}
