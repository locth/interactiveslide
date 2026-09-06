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

/**
 * Restore task for mod_interactiveslide.
 *
 * @package    mod_interactiveslide
 * @copyright  2026 Interactive Slide contributors
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/mod/interactiveslide/backup/moodle2/restore_interactiveslide_stepslib.php');

/**
 * Wires the restore steps of one activity instance together.
 *
 * @package    mod_interactiveslide
 * @copyright  2026 Interactive Slide contributors
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class restore_interactiveslide_activity_task extends restore_activity_task {

    /**
     * No settings beyond the standard activity ones.
     *
     * @return void
     */
    protected function define_my_settings() {
    }

    /**
     * Add the structure step.
     *
     * @return void
     */
    protected function define_my_steps() {
        $this->add_step(new restore_interactiveslide_activity_structure_step(
            'interactiveslide_structure', 'interactiveslide.xml'));
    }

    /**
     * File areas whose contents need their links decoded.
     *
     * @return array
     */
    public static function define_decode_contents() {
        return [
            new restore_decode_content('interactiveslide', ['intro'], 'interactiveslide'),
        ];
    }

    /**
     * Rules turning backup placeholders back into site URLs.
     *
     * @return array
     */
    public static function define_decode_rules() {
        return [
            new restore_decode_rule('INTERACTIVESLIDEVIEWBYID',
                '/mod/interactiveslide/view.php?id=$1', 'course_module'),
            new restore_decode_rule('INTERACTIVESLIDEINDEX',
                '/mod/interactiveslide/index.php?id=$1', 'course'),
        ];
    }

    /**
     * Log entry rules for the restore of legacy logs.
     *
     * @return array
     */
    public static function define_restore_log_rules() {
        return [];
    }
}
