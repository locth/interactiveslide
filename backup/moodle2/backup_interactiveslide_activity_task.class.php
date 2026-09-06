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
 * Backup task for mod_interactiveslide.
 *
 * @package    mod_interactiveslide
 * @copyright  2026 Interactive Slide contributors
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/mod/interactiveslide/backup/moodle2/backup_interactiveslide_stepslib.php');

/**
 * Wires the backup steps of one activity instance together.
 *
 * @package    mod_interactiveslide
 * @copyright  2026 Interactive Slide contributors
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class backup_interactiveslide_activity_task extends backup_activity_task {

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
        $this->add_step(new backup_interactiveslide_activity_structure_step(
            'interactiveslide_structure', 'interactiveslide.xml'));
    }

    /**
     * Make links to this activity portable.
     *
     * @param string $content
     * @return string
     */
    public static function encode_content_links($content) {
        return self::encode_content_links_helper($content);
    }

    /**
     * Replace the site's own URLs with portable placeholders.
     *
     * @param string $content
     * @return string
     */
    private static function encode_content_links_helper($content) {
        global $CFG;

        $base = preg_quote($CFG->wwwroot, '/');

        $content = preg_replace(
            '/(' . $base . '\/mod\/interactiveslide\/index.php\?id\=)([0-9]+)/',
            '$@INTERACTIVESLIDEINDEX*$2@$',
            $content
        );

        $content = preg_replace(
            '/(' . $base . '\/mod\/interactiveslide\/view.php\?id\=)([0-9]+)/',
            '$@INTERACTIVESLIDEVIEWBYID*$2@$',
            $content
        );

        return $content;
    }
}
