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
     * Load the display columns of a set of users.
     *
     * @param int[] $userids
     * @return stdClass[] keyed by user id; missing users are simply absent
     */
    public static function load(array $userids): array {
        global $DB;

        $userids = array_values(array_unique(array_map('intval', $userids)));
        if (!$userids) {
            return [];
        }

        return $DB->get_records_list('user', 'id', $userids, '', self::FIELDS);
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
