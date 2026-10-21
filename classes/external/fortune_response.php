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

use core_external\external_single_structure;
use core_external\external_value;
use mod_interactiveslide\local\picker;
use stdClass;

/**
 * What drawing or settling a fortune card answers with.
 *
 * The pick as the projector draws it, plus the live state, since a card that
 * pays stars moves the leaderboard.
 *
 * @package    mod_interactiveslide
 * @copyright  2026 Interactive Slide contributors
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class fortune_response {

    /**
     * Build the response.
     *
     * @param array $resolved from helper::resolve()
     * @param stdClass $pick
     * @return array
     */
    public static function build(array $resolved, stdClass $pick): array {
        $response = helper::state_response($resolved['instance'], $resolved['context']);
        $exported = picker::export_picks([$pick], $resolved['context']);
        $response['pick'] = json_encode($exported[0], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return $response;
    }

    /**
     * The return structure.
     *
     * @return external_single_structure
     */
    public static function returns(): external_single_structure {
        return new external_single_structure([
            'revision' => new external_value(PARAM_INT, 'Session revision counter'),
            'state' => new external_value(PARAM_RAW, 'The live state document, JSON encoded'),
            'pick' => new external_value(PARAM_RAW, 'The call and its card, JSON encoded'),
        ]);
    }
}
