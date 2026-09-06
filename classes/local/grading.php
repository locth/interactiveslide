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
 * Translating stars into a gradebook grade.
 *
 * Stars are a motivation device first: the mapping is deliberately generous and
 * always capped at the activity maximum so a speed bonus can never overflow it.
 *
 * @package    mod_interactiveslide
 * @copyright  2026 Interactive Slide contributors
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class grading {

    /** @var int Total stars over every session, against what the deck can pay per session. */
    public const METHOD_TOTAL = 1;

    /** @var int The student's best single session. */
    public const METHOD_BEST = 2;

    /** @var int The student's most recent session. */
    public const METHOD_LAST = 3;

    /** @var int Total stars relative to the highest scoring student. */
    public const METHOD_RELATIVE = 4;

    /**
     * The grading methods offered on the settings form.
     *
     * @return array<int, string> method constant to language string key
     */
    public static function get_methods(): array {
        return [
            self::METHOD_TOTAL => 'grademethodtotal',
            self::METHOD_BEST => 'grademethodbest',
            self::METHOD_LAST => 'grademethodlast',
            self::METHOD_RELATIVE => 'grademethodrelative',
        ];
    }

    /**
     * The most stars one full run through the deck can pay out.
     *
     * @param int $interactiveslideid
     * @return int 0 when the deck has no scoring interaction yet
     */
    public static function get_deck_max_stars(int $interactiveslideid): int {
        global $DB;

        $interactions = $DB->get_records_sql(
            'SELECT i.id, i.qtype, i.points
               FROM {interactiveslide_interaction} i
               JOIN {interactiveslide_slide} s ON s.id = i.slideid
              WHERE s.interactiveslideid = :instanceid',
            ['instanceid' => $interactiveslideid]
        );

        if (!$interactions) {
            return (int)$DB->get_field('interactiveslide', 'attendancestars', ['id' => $interactiveslideid]);
        }

        $blanktotals = $DB->get_records_sql(
            'SELECT b.interactionid, SUM(b.points) AS total
               FROM {interactiveslide_blank} b
               JOIN {interactiveslide_interaction} i ON i.id = b.interactionid
               JOIN {interactiveslide_slide} s ON s.id = i.slideid
              WHERE s.interactiveslideid = :instanceid
           GROUP BY b.interactionid',
            ['instanceid' => $interactiveslideid]
        );

        $max = 0;
        foreach ($interactions as $interaction) {
            if ($interaction->qtype === interaction_manager::TYPE_FILLBLANK) {
                $max += isset($blanktotals[$interaction->id]) ? (int)$blanktotals[$interaction->id]->total : 0;
            } else {
                $max += (int)$interaction->points;
            }
        }

        // Everyone who joins collects these, so they belong in the denominator.
        $max += (int)$DB->get_field('interactiveslide', 'attendancestars', ['id' => $interactiveslideid]);

        return $max;
    }

    /**
     * Work out the gradebook grade for one user or for everyone.
     *
     * @param stdClass $instance the deck record
     * @param int $userid 0 for every participant
     * @return array<int, stdClass> userid to grade object
     */
    public static function calculate_grades(stdClass $instance, int $userid = 0): array {
        global $DB;

        $grademax = (float)$instance->grade;
        if ($grademax <= 0) {
            return [];
        }

        $params = ['instanceid' => (int)$instance->id];
        $where = 'p.interactiveslideid = :instanceid';
        if ($userid) {
            $where .= ' AND p.userid = :userid';
            $params['userid'] = $userid;
        }

        $rows = $DB->get_records_sql(
            "SELECT p.id, p.userid, p.sessionid, p.totalstars, s.timecreated
               FROM {interactiveslide_participant} p
               JOIN {interactiveslide_session} s ON s.id = p.sessionid
              WHERE $where
           ORDER BY p.userid ASC, s.timecreated ASC",
            $params
        );

        if (!$rows) {
            return [];
        }

        $byuser = [];
        foreach ($rows as $row) {
            $byuser[(int)$row->userid][] = $row;
        }

        $deckmax = self::get_deck_max_stars((int)$instance->id);
        $method = (int)$instance->grademethod;

        // The relative method needs the whole cohort even when grading one user.
        $topstars = ($method === self::METHOD_RELATIVE) ? self::get_top_total_stars((int)$instance->id) : 0;

        $grades = [];
        foreach ($byuser as $uid => $sessions) {
            $fraction = self::fraction_for_user($method, $sessions, $deckmax, $topstars);

            $grade = new stdClass();
            $grade->userid = $uid;
            $grade->rawgrade = round(max(0.0, min(1.0, $fraction)) * $grademax, 5);
            $grades[$uid] = $grade;
        }

        return $grades;
    }

    /**
     * The 0..1 achievement fraction of one user under a given method.
     *
     * @param int $method
     * @param stdClass[] $sessions the user's participant rows, oldest first
     * @param int $deckmax stars one full run pays
     * @param int $topstars best total in the cohort, for the relative method
     * @return float
     */
    private static function fraction_for_user(int $method, array $sessions, int $deckmax, int $topstars): float {
        $total = 0;
        $best = 0;
        foreach ($sessions as $session) {
            $stars = (int)$session->totalstars;
            $total += $stars;
            $best = max($best, $stars);
        }

        switch ($method) {
            case self::METHOD_BEST:
                return $deckmax > 0 ? $best / $deckmax : 0.0;

            case self::METHOD_LAST:
                $last = (int)end($sessions)->totalstars;
                return $deckmax > 0 ? $last / $deckmax : 0.0;

            case self::METHOD_RELATIVE:
                return $topstars > 0 ? $total / $topstars : 0.0;

            case self::METHOD_TOTAL:
            default:
                // Measured against what the sessions this student attended could pay.
                $possible = $deckmax * max(1, count($sessions));
                return $possible > 0 ? $total / $possible : 0.0;
        }
    }

    /**
     * The highest star total any student has reached on a deck.
     *
     * @param int $interactiveslideid
     * @return int
     */
    private static function get_top_total_stars(int $interactiveslideid): int {
        global $DB;

        $top = $DB->get_field_sql(
            'SELECT MAX(usertotal)
               FROM (SELECT SUM(totalstars) AS usertotal
                       FROM {interactiveslide_participant}
                      WHERE interactiveslideid = :instanceid
                   GROUP BY userid) totals',
            ['instanceid' => $interactiveslideid]
        );

        return (int)($top ?: 0);
    }

    /**
     * Push every participant's grade into the gradebook.
     *
     * @param stdClass $instance
     * @return void
     */
    public static function update_gradebook(stdClass $instance): void {
        global $CFG;
        require_once($CFG->dirroot . '/mod/interactiveslide/lib.php');

        interactiveslide_update_grades($instance);
    }
}
