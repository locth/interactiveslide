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
use core_external\external_multiple_structure;
use core_external\external_single_structure;
use core_external\external_value;
use mod_interactiveslide\local\slide_manager;

/**
 * Rename a slide or reorder the deck.
 *
 * @package    mod_interactiveslide
 * @copyright  2026 Interactive Slide contributors
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class update_slide extends external_api {

    /**
     * Parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'cmid' => new external_value(PARAM_INT, 'Course module id'),
            'slideid' => new external_value(PARAM_INT, 'Slide to rename, 0 when only reordering', VALUE_DEFAULT, 0),
            'title' => new external_value(PARAM_TEXT, 'New slide title', VALUE_DEFAULT, ''),
            'order' => new external_multiple_structure(
                new external_value(PARAM_INT, 'Slide id'),
                'Slide ids in the wanted order, empty to leave the order alone',
                VALUE_DEFAULT,
                []
            ),
        ]);
    }

    /**
     * Apply the rename and the new order.
     *
     * @param int $cmid
     * @param int $slideid
     * @param string $title
     * @param int[] $order
     * @return array
     */
    public static function execute(int $cmid, int $slideid = 0, string $title = '', array $order = []): array {
        global $DB;

        $params = self::validate_parameters(self::execute_parameters(),
            ['cmid' => $cmid, 'slideid' => $slideid, 'title' => $title, 'order' => $order]);

        $resolved = helper::resolve_for_editor($params['cmid']);
        self::validate_context($resolved['context']);

        if ($params['slideid'] > 0) {
            $slide = $DB->get_record('interactiveslide_slide',
                ['id' => $params['slideid'], 'interactiveslideid' => $resolved['instance']->id], '*', MUST_EXIST);

            $DB->update_record('interactiveslide_slide', (object)[
                'id' => $slide->id,
                'title' => \core_text::substr($params['title'], 0, 255),
                'timemodified' => time(),
            ]);
        }

        if ($params['order']) {
            slide_manager::reorder((int)$resolved['instance']->id, $params['order']);
        }

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
