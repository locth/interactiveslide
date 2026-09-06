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
 * External function declarations for mod_interactiveslide.
 *
 * @package    mod_interactiveslide
 * @copyright  2026 Interactive Slide contributors
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$functions = [

    'mod_interactiveslide_get_state' => [
        'classname'   => 'mod_interactiveslide\external\get_state',
        'description' => 'Poll the live state of a deck.',
        'type'        => 'read',
        'ajax'        => true,
        'capabilities' => 'mod/interactiveslide:view',
    ],

    'mod_interactiveslide_start_session' => [
        'classname'   => 'mod_interactiveslide\external\start_session',
        'description' => 'Start a live session.',
        'type'        => 'write',
        'ajax'        => true,
        'capabilities' => 'mod/interactiveslide:present',
    ],

    'mod_interactiveslide_end_session' => [
        'classname'   => 'mod_interactiveslide\external\end_session',
        'description' => 'End the running session.',
        'type'        => 'write',
        'ajax'        => true,
        'capabilities' => 'mod/interactiveslide:present',
    ],

    'mod_interactiveslide_set_slide' => [
        'classname'   => 'mod_interactiveslide\external\set_slide',
        'description' => 'Move the session to another slide.',
        'type'        => 'write',
        'ajax'        => true,
        'capabilities' => 'mod/interactiveslide:present',
    ],

    'mod_interactiveslide_control_round' => [
        'classname'   => 'mod_interactiveslide\external\control_round',
        'description' => 'Open, close, reveal or reset the round on the current slide.',
        'type'        => 'write',
        'ajax'        => true,
        'capabilities' => 'mod/interactiveslide:present',
    ],

    'mod_interactiveslide_award_stars' => [
        'classname'   => 'mod_interactiveslide\external\award_stars',
        'description' => 'Give a participant bonus stars.',
        'type'        => 'write',
        'ajax'        => true,
        'capabilities' => 'mod/interactiveslide:awardstars',
    ],

    'mod_interactiveslide_submit_response' => [
        'classname'   => 'mod_interactiveslide\external\submit_response',
        'description' => 'Submit an answer to the round in progress.',
        'type'        => 'write',
        'ajax'        => true,
        'capabilities' => 'mod/interactiveslide:submit',
    ],

    'mod_interactiveslide_get_deck' => [
        'classname'   => 'mod_interactiveslide\external\get_deck',
        'description' => 'Fetch every slide with its interaction.',
        'type'        => 'read',
        'ajax'        => true,
        // Presenters need this for the filmstrip; editors need it for the editor.
        'capabilities' => 'mod/interactiveslide:present',
    ],

    'mod_interactiveslide_save_interaction' => [
        'classname'   => 'mod_interactiveslide\external\save_interaction',
        'description' => 'Create or replace the interaction of a slide.',
        'type'        => 'write',
        'ajax'        => true,
        'capabilities' => 'mod/interactiveslide:manage',
    ],

    'mod_interactiveslide_reset_interaction' => [
        'classname'   => 'mod_interactiveslide\external\reset_interaction',
        'description' => 'Clear the answers collected for a slide so its question can be edited again.',
        'type'        => 'write',
        'ajax'        => true,
        'capabilities' => 'mod/interactiveslide:manage',
    ],

    'mod_interactiveslide_delete_interaction' => [
        'classname'   => 'mod_interactiveslide\external\delete_interaction',
        'description' => 'Remove the interaction of a slide.',
        'type'        => 'write',
        'ajax'        => true,
        'capabilities' => 'mod/interactiveslide:manage',
    ],

    'mod_interactiveslide_delete_slide' => [
        'classname'   => 'mod_interactiveslide\external\delete_slide',
        'description' => 'Delete a slide.',
        'type'        => 'write',
        'ajax'        => true,
        'capabilities' => 'mod/interactiveslide:manage',
    ],

    'mod_interactiveslide_update_slide' => [
        'classname'   => 'mod_interactiveslide\external\update_slide',
        'description' => 'Rename a slide or reorder the deck.',
        'type'        => 'write',
        'ajax'        => true,
        'capabilities' => 'mod/interactiveslide:manage',
    ],
];
