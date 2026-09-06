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
 * Star rankings for a session and for a deck as a whole.
 *
 * @package    mod_interactiveslide
 * @copyright  2026 Interactive Slide contributors
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class leaderboard {

    /** @var int Students never see the leaderboard. */
    public const VISIBILITY_TEACHER = 0;

    /** @var int Students see the whole board. */
    public const VISIBILITY_FULL = 1;

    /** @var int Students see only their own position. */
    public const VISIBILITY_OWNRANK = 2;

    /**
     * Ranked participants of one session.
     *
     * @param int $sessionid
     * @param context_module $context used for profile pictures
     * @param int $limit 0 for everyone
     * @param bool $anonymous replace names with a placeholder
     * @param int $selfuserid this user keeps their name even when anonymous
     * @return array[] rows with rank, userid, fullname, stars and counts
     */
    public static function get_session_board(int $sessionid, context_module $context,
            int $limit = 10, bool $anonymous = false, int $selfuserid = 0): array {
        global $DB;

        $rows = $DB->get_records_sql(
            'SELECT id, userid, totalstars, correctcount, responsecount, beststreak
               FROM {interactiveslide_participant}
              WHERE sessionid = :sessionid
           ORDER BY totalstars DESC, correctcount DESC, timejoined ASC',
            ['sessionid' => $sessionid],
            0,
            $limit > 0 ? $limit : 0
        );

        return self::decorate($rows, $context, $anonymous, $selfuserid);
    }

    /**
     * Ranked participants across every session of a deck.
     *
     * @param int $interactiveslideid
     * @param context_module $context
     * @param int $limit 0 for everyone
     * @param bool $anonymous
     * @return array[]
     */
    public static function get_overall_board(int $interactiveslideid, context_module $context,
            int $limit = 0, bool $anonymous = false): array {
        global $DB;

        $rows = $DB->get_records_sql(
            'SELECT userid AS id,
                    userid,
                    SUM(totalstars) AS totalstars,
                    SUM(correctcount) AS correctcount,
                    SUM(responsecount) AS responsecount,
                    MAX(beststreak) AS beststreak,
                    MIN(timejoined) AS timejoined
               FROM {interactiveslide_participant}
              WHERE interactiveslideid = :instanceid
           GROUP BY userid
           ORDER BY SUM(totalstars) DESC, SUM(correctcount) DESC, MIN(timejoined) ASC',
            ['instanceid' => $interactiveslideid],
            0,
            $limit > 0 ? $limit : 0
        );

        return self::decorate($rows, $context, $anonymous);
    }

    /**
     * Turn participant rows into ranked display rows, sharing ranks on ties.
     *
     * @param stdClass[] $rows each with userid and the aggregated counters
     * @param context_module $context
     * @param bool $anonymous
     * @param int $selfuserid this user keeps their name even when anonymous
     * @return array[]
     */
    private static function decorate(array $rows, context_module $context, bool $anonymous,
            int $selfuserid = 0): array {
        global $OUTPUT;

        if (!$rows) {
            return [];
        }

        // In anonymous mode only the viewer's own row is resolved, so they can
        // still find themselves on a board that names nobody else.
        $wanted = [];
        foreach ($rows as $row) {
            if (!$anonymous || (int)$row->userid === $selfuserid) {
                $wanted[] = (int)$row->userid;
            }
        }
        $users = userinfo::load($wanted);
        $courseid = $context->get_course_context()->instanceid;

        $board = [];
        $rank = 0;
        $position = 0;
        $previousstars = null;

        foreach ($rows as $row) {
            $position++;
            $stars = (int)$row->totalstars;
            if ($stars !== $previousstars) {
                $rank = $position;
                $previousstars = $stars;
            }

            $userid = (int)$row->userid;
            $entry = [
                'rank' => $rank,
                'userid' => ($anonymous && $userid !== $selfuserid) ? 0 : $userid,
                'stars' => $stars,
                'correctcount' => (int)$row->correctcount,
                'responsecount' => (int)$row->responsecount,
                'beststreak' => (int)$row->beststreak,
                'fullname' => get_string('anonymousparticipant', 'mod_interactiveslide'),
                'pictureurl' => '',
            ];

            if (isset($users[$userid])) {
                $user = $users[$userid];
                $entry['fullname'] = fullname($user);
                $entry['pictureurl'] = $OUTPUT->user_picture($user, [
                    'size' => 64,
                    'link' => false,
                    'visibletoscreenreaders' => false,
                    'courseid' => $courseid,
                ]);
            }

            $board[] = $entry;
        }

        return $board;
    }

    /**
     * Where one user sits in a session, and how many people are ranked at all.
     *
     * @param int $sessionid
     * @param int $userid
     * @return array{rank: int, stars: int, total: int} rank is 0 when the user has not joined
     */
    public static function get_user_rank(int $sessionid, int $userid): array {
        global $DB;

        $participant = $DB->get_record('interactiveslide_participant',
            ['sessionid' => $sessionid, 'userid' => $userid]);

        $total = $DB->count_records('interactiveslide_participant', ['sessionid' => $sessionid]);

        if (!$participant) {
            return ['rank' => 0, 'stars' => 0, 'total' => $total];
        }

        $ahead = $DB->count_records_select('interactiveslide_participant',
            'sessionid = :sessionid AND totalstars > :stars',
            ['sessionid' => $sessionid, 'stars' => (int)$participant->totalstars]);

        return [
            'rank' => (int)$ahead + 1,
            'stars' => (int)$participant->totalstars,
            'total' => $total,
        ];
    }

    /**
     * Whether a given user may be shown the full board.
     *
     * @param stdClass $instance
     * @param context_module $context
     * @return bool
     */
    public static function can_see_full_board(stdClass $instance, context_module $context): bool {
        if (has_capability('mod/interactiveslide:present', $context)) {
            return true;
        }

        return (int)$instance->showleaderboard === self::VISIBILITY_FULL;
    }
}
