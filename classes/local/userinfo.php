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

use stdClass;

/**
 * Loading the user columns needed to render a name and an avatar.
 *
 * Participant rows are aggregated with GROUP BY, and mixing user columns into
 * such a query means either listing them all in the GROUP BY or aliasing around
 * an `id` collision. Fetching users separately keeps both queries simple.
 *
 * @package    mod_interactiveslide
 * @copyright  2026 Interactive Slide contributors
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class userinfo {

    /**
     * Every column fullname() and user_picture need, plus the ID number.
     *
     * `idnumber` is the student number a lecturer marks by. It is on the report
     * rather than the leaderboard on purpose: the board is read by the room.
     *
     * @var string
     */
    public const FIELDS = 'id, idnumber, picture, imagealt, email, firstname, lastname, ' .
        'firstnamephonetic, lastnamephonetic, middlename, alternatename';

    /**
     * Load the display columns of a set of participants.
     *
     * A negative id is a guest. They come back as a record shaped like a user,
     * with isguest set, so every caller that renders a name keeps one code path
     * and asks display_name() or report_name() rather than calling fullname().
     *
     * @param int[] $userids
     * @return stdClass[] keyed by participant id; missing ones are simply absent
     */
    public static function load(array $userids): array {
        global $DB;

        $userids = array_values(array_unique(array_map('intval', $userids)));
        $real = array_values(array_filter($userids, static fn($id) => $id > 0));
        $guests = array_values(array_filter($userids, static fn($id) => $id < 0));

        $loaded = $real ? $DB->get_records_list('user', 'id', $real, '', self::FIELDS) : [];

        if ($guests) {
            foreach (guest::load(array_map(static fn($id) => guest::guestid($id), $guests)) as $row) {
                $loaded[guest::userid((int)$row->id)] = self::guest_record($row);
            }
        }

        return $loaded;
    }

    /**
     * A guest row dressed as a user record.
     *
     * @param stdClass $row from interactiveslide_guest
     * @return stdClass
     */
    private static function guest_record(stdClass $row): stdClass {
        $user = self::placeholder(guest::userid((int)$row->id));
        $user->firstname = (string)$row->displayname;
        $user->isguest = true;

        return $user;
    }

    /**
     * The name a participant is shown under on a board.
     *
     * A guest's own name, as given. fullname() would rearrange it by the site's
     * name format, and a guest has no separate first and last name to arrange.
     *
     * @param stdClass $user from load() or placeholder()
     * @return string
     */
    public static function display_name(stdClass $user): string {
        return !empty($user->isguest) ? (string)$user->firstname : fullname($user);
    }

    /**
     * The name a participant is listed under in a report.
     *
     * A report outlives the lecture, and a teacher reading it later has to be able
     * to tell a visitor who called themselves after a student from the student.
     *
     * @param stdClass $user from load() or placeholder()
     * @return string
     */
    public static function report_name(stdClass $user): string {
        return !empty($user->isguest)
            ? get_string('guestnamelabel', 'mod_interactiveslide', (string)$user->firstname)
            : fullname($user);
    }

    /**
     * An initials avatar for a guest, in the markup Moodle uses for a user with no picture.
     *
     * Same class, so it takes exactly the size and shape every other avatar on
     * the board has, and the board has no second avatar rule to keep in step.
     *
     * @param stdClass $user a guest record from load()
     * @return string
     */
    public static function guest_avatar(stdClass $user): string {
        $words = preg_split('/\s+/u', trim((string)$user->firstname), -1, PREG_SPLIT_NO_EMPTY);
        $initials = '';
        if ($words) {
            $initials = \core_text::substr($words[0], 0, 1);
            if (count($words) > 1) {
                $initials .= \core_text::substr(end($words), 0, 1);
            }
        }

        return '<span class="userinitials size-64" aria-hidden="true">' .
            htmlspecialchars(\core_text::strtoupper($initials), ENT_QUOTES, 'UTF-8') . '</span>';
    }

    /**
     * A placeholder record for a user that no longer exists.
     *
     * @param int $userid
     * @return stdClass
     */
    public static function placeholder(int $userid): stdClass {
        $user = new stdClass();
        $user->id = $userid;
        $user->idnumber = '';
        $user->picture = 0;
        $user->imagealt = '';
        $user->email = '';
        $user->firstname = get_string('deleteduser', 'mod_interactiveslide');
        $user->lastname = '';
        $user->firstnamephonetic = '';
        $user->lastnamephonetic = '';
        $user->middlename = '';
        $user->alternatename = '';

        return $user;
    }
}
