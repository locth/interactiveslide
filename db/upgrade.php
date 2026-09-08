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

    if ($oldversion < 2026090800) {
        // The video interaction keeps the URL the teacher pasted; what it is
        // turned into for the page is worked out on every render, so a change to
        // the embedding rules does not need a second migration.
        $table = new xmldb_table('interactiveslide_interaction');
        $field = new xmldb_field('videourl', XMLDB_TYPE_CHAR, '1333', null,
            null, null, null, 'casesensitive');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        upgrade_mod_savepoint(true, 2026090800, 'interactiveslide');
    }

    if ($oldversion < 2026090801) {
        // A dropdown puts several selects in one sentence, and each of them owns
        // its own list of choices. The position is a blank row; this is the link
        // from a choice back to the position it belongs to. 0 keeps meaning
        // "belongs to the question itself", which is every multiple choice row
        // already in the table.
        $table = new xmldb_table('interactiveslide_option');
        $field = new xmldb_field('blankid', XMLDB_TYPE_INTEGER, '10', null,
            XMLDB_NOTNULL, null, '0', 'interactionid');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        $index = new xmldb_index('blankid', XMLDB_INDEX_NOTUNIQUE, ['blankid']);
        if (!$dbman->index_exists($table, $index)) {
            $dbman->add_index($table, $index);
        }

        upgrade_mod_savepoint(true, 2026090801, 'interactiveslide');
    }

    return true;
}
