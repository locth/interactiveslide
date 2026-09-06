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
 * Database upgrade steps for mod_interactiveslide.
 *
 * @package    mod_interactiveslide
 * @copyright  2026 Interactive Slide contributors
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

/**
 * Run the upgrade steps needed to reach the current version.
 *
 * @param int $oldversion the currently installed version
 * @return bool
 */
function xmldb_interactiveslide_upgrade($oldversion) {
    global $DB;

    $dbman = $DB->get_manager();

    if ($oldversion < 2026090600) {
        // Stars for simply turning up and taking part in a session.
        $table = new xmldb_table('interactiveslide');
        $field = new xmldb_field('attendancestars', XMLDB_TYPE_INTEGER, '4', null,
            XMLDB_NOTNULL, null, '0', 'speedbonusmax');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        $table = new xmldb_table('interactiveslide_participant');
        $field = new xmldb_field('attendancestars', XMLDB_TYPE_INTEGER, '10', null,
            XMLDB_NOTNULL, null, '0', 'bonusstars');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        upgrade_mod_savepoint(true, 2026090600, 'interactiveslide');
    }

    return true;
}
