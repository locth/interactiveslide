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

namespace mod_interactiveslide\local;

use context_module;
use stdClass;

/**
 * Who is asking, and whether they may take part.
 *
 * The one place that decides it. Answering, being counted as present and seeing
 * your own stars all follow from this, so a guest cannot end up allowed by one of
 * them and refused by another.
 *
 * @package    mod_interactiveslide
 * @copyright  2026 Interactive Slide contributors
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class participation {

    /**
     * Resolve the current user into a participant.
     *
     * Students take part through the capability, exactly as before. Anyone else
     * only takes part when guests are allowed and this browser opened the running
     * session's link, and even then only while they are a guest of the course:
     * an enrolled student whose capability was taken away must not get it back
     * by finding the link on the projector.
     *
     * @param stdClass $instance the deck record
     * @param context_module $context
     * @param stdClass|null $session the session running now
     * @return stdClass userid (negative for a guest, 0 before a guest has a name),
     *         canparticipate, isguest, needsname, removed and name
     */
    public static function resolve(stdClass $instance, context_module $context, ?stdClass $session): stdClass {
        global $USER;

        $me = (object)[
            'userid' => (int)$USER->id,
            'canparticipate' => false,
            'isguest' => false,
            'needsname' => false,
            'removed' => false,
            'name' => '',
        ];

        if (has_capability('mod/interactiveslide:submit', $context)) {
            $me->canparticipate = true;
            return $me;
        }

        if (!$session || !guest::enabled($instance) || !guest::has_pass($session) || !is_guest($context)) {
            return $me;
        }

        if (!isguestuser()) {
            // Signed in but not enrolled, and in through the room's link: they
            // take part as themselves, under their own name.
            $me->canparticipate = true;
            return $me;
        }

        // The shared guest account is nobody in particular until a name is given.
        $me->userid = 0;

        $row = guest::current($session);
        if (!$row) {
            $me->needsname = true;
            return $me;
        }

        if (!empty($row->removed)) {
            $me->removed = true;
            return $me;
        }

        $me->userid = guest::userid((int)$row->id);
        $me->isguest = true;
        $me->canparticipate = true;
        $me->name = (string)$row->displayname;

        return $me;
    }
}
