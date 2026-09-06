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
use mod_interactiveslide\local\slide_manager;

/**
 * Delete a slide from a deck.
 *
 * @package    mod_interactiveslide
 * @copyright  2026 Interactive Slide contributors
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class delete_slide extends external_api {

    /**
     * Parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'cmid' => new external_value(PARAM_INT, 'Course module id'),
            'slideid' => new external_value(PARAM_INT, 'Slide to delete'),
        ]);
    }

    /**
     * Delete the slide, its image and its interaction.
     *
     * @param int $cmid
     * @param int $slideid
     * @return array
     */
    public static function execute(int $cmid, int $slideid): array {
        global $DB;

        $params = self::validate_parameters(self::execute_parameters(),
            ['cmid' => $cmid, 'slideid' => $slideid]);

        $resolved = helper::resolve_for_editor($params['cmid']);
        self::validate_context($resolved['context']);

        $slide = $DB->get_record('interactiveslide_slide',
            ['id' => $params['slideid'], 'interactiveslideid' => $resolved['instance']->id], '*', MUST_EXIST);

        slide_manager::delete_slide($resolved['context'], (int)$slide->id);

        return ['status' => 1];
    }

    /**
     * Return description.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'status' => new external_value(PARAM_INT, '1 on success'),
        ]);
    }
}
