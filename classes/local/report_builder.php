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

/**
 * Builds the tables shown on the report page and streamed as CSV.
 *
 * @package    mod_interactiveslide
 * @copyright  2026 Interactive Slide contributors
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class report_builder {

    /**
     * One row per student, summed over every session of a deck.
     *
     * @param int $interactiveslideid
     * @param int $onlyuserid restrict to one participant, for a student reading
     *        their own report; 0 for everybody
     * @return array{0: array<string, string>, 1: array[]} columns and rows
     */
    public static function overview_rows(int $interactiveslideid, int $onlyuserid = 0): array {
        global $DB;

        // A fixed set of columns, whatever the deck grows into. The per question
        // detail lives in the single session view, where it is bounded by what
        // that session actually ran; rolling it up here only widened the sheet
        // every time a teacher added a question.
        //
        // Laid out as an arithmetic: the three sources of stars, then the total
        // they add up to, so any row can be checked by adding across it.
        $columns = [
            'idnumber' => get_string('useridnumber', 'mod_interactiveslide'),
            'fullname' => get_string('participant', 'mod_interactiveslide'),
            'sessions' => get_string('sessionsattended', 'mod_interactiveslide'),
            'questions' => get_string('questionstars', 'mod_interactiveslide'),
            'attendance' => get_string('attendancestarsshort', 'mod_interactiveslide'),
            'manual' => get_string('manualstarsshort', 'mod_interactiveslide'),
            'stars' => get_string('totalstars', 'mod_interactiveslide'),
            'correct' => get_string('correctanswers', 'mod_interactiveslide'),
            'answered' => get_string('answersgiven', 'mod_interactiveslide'),
            'beststreak' => get_string('beststreak', 'mod_interactiveslide'),
        ];

        $params = ['instanceid' => $interactiveslideid];
        $mine = '';
        if ($onlyuserid) {
            $mine = ' AND userid = :onlyuser';
            $params['onlyuser'] = $onlyuserid;
        }

        // `manualstars`, not `manual`: MySQL reserved MANUAL in 8.0.31, and an
        // unquoted reserved word is a syntax error rather than something the
        // driver can work around. Quoting it would work on MySQL and break on
        // PostgreSQL, so the alias is simply a word no dialect has taken.
        $records = $DB->get_records_sql(
            'SELECT userid,
                    COUNT(DISTINCT sessionid) AS sessions,
                    SUM(totalstars) AS stars,
                    SUM(attendancestars) AS attendance,
                    SUM(bonusstars) AS manualstars,
                    SUM(correctcount) AS correct,
                    SUM(responsecount) AS answered,
                    MAX(beststreak) AS beststreak
               FROM {interactiveslide_participant}
              WHERE interactiveslideid = :instanceid' . $mine . '
           GROUP BY userid
           ORDER BY SUM(totalstars) DESC',
            $params
        );

        if (!$records) {
            return [$columns, []];
        }

        $answered = self::interaction_stars($interactiveslideid, $onlyuserid);
        $users = userinfo::load(array_keys($records));

        $rows = [];
        foreach ($records as $userid => $record) {
            $userid = (int)$userid;

            $user = $users[$userid] ?? userinfo::placeholder($userid);

            $rows[] = [
                'idnumber' => (string)($user->idnumber ?? ''),
                'fullname' => fullname($user),
                'sessions' => (int)$record->sessions,
                'questions' => $answered[$userid] ?? 0,
                'attendance' => (int)$record->attendance,
                'manual' => (int)$record->manualstars,
                'stars' => (int)$record->stars,
                'correct' => (int)$record->correct,
                'answered' => (int)$record->answered,
                'beststreak' => (int)$record->beststreak,
            ];
        }

        return [$columns, $rows];
    }

    /**
     * Stars each student earned by answering, summed over every session of a deck.
     *
     * Counted from the answers themselves rather than derived by subtracting the
     * other two columns from the total. Deriving it would make the row add up by
     * definition and hide a disagreement between the stored total and the answers
     * behind it; measuring it turns that row into a real check.
     *
     * @param int $interactiveslideid
     * @param int $onlyuserid restrict to one participant; 0 for everybody
     * @return array<int, int> userid to stars
     */
    private static function interaction_stars(int $interactiveslideid, int $onlyuserid = 0): array {
        global $DB;

        $params = ['instanceid' => $interactiveslideid];
        $mine = '';
        if ($onlyuserid) {
            $mine = ' AND r.userid = :onlyuser';
            $params['onlyuser'] = $onlyuserid;
        }

        $rowset = $DB->get_recordset_sql(
            'SELECT r.userid, SUM(r.stars + r.bonusstars) AS stars
               FROM {interactiveslide_response} r
               JOIN {interactiveslide_round} rd ON rd.id = r.roundid
               JOIN {interactiveslide_session} s ON s.id = rd.sessionid
              WHERE s.interactiveslideid = :instanceid' . $mine . '
           GROUP BY r.userid',
            $params
        );

        $stars = [];
        foreach ($rowset as $row) {
            $stars[(int)$row->userid] = (int)$row->stars;
        }
        $rowset->close();

        return $stars;
    }

    /**
     * The heading a slide gets in a report.
     *
     * @param \stdClass $slide with sortorder and title
     * @return string
     */
    private static function slide_label(\stdClass $slide): string {
        if (trim((string)$slide->title) !== '') {
            return format_string($slide->title);
        }

        return get_string('slidenumber', 'mod_interactiveslide', (int)$slide->sortorder + 1);
    }

    /**
     * One row per student across every Interactive Slide activity in a course.
     *
     * Each activity is a deck — typically one per chapter — and a student
     * collects stars in each of them separately. This is the sheet that answers
     * "how many stars has this student earned all semester", which no single
     * activity's own report can show.
     *
     * @param int $courseid
     * @param int $onlyuserid restrict to one participant, for a student reading
     *        their own report; 0 for everybody
     * @return array{0: array<string, string>, 1: array[]} columns and rows
     */
    public static function course_rows(int $courseid, int $onlyuserid = 0): array {
        // Reading someone else's report needs the reports capability on each
        // deck; reading your own only needs the deck to be one you can open.
        $decks = $onlyuserid ? self::visible_decks($courseid) : self::readable_decks($courseid);

        return self::build_course_rows($decks, $onlyuserid);
    }

    /**
     * One student's star totals, for the screen they see before joining.
     *
     * Both numbers are read straight from the participant rows rather than from
     * the live session, so they are there whether or not a session is running:
     * a student should be able to look up what they have collected at any time.
     *
     * @param int $courseid
     * @param int $interactiveslideid
     * @param int $userid
     * @return array{activity: int, course: int, sessions: int, decks: int}
     */
    public static function own_totals(int $courseid, int $interactiveslideid, int $userid): array {
        global $DB;

        $activity = $DB->get_record_sql(
            'SELECT COUNT(id) AS sessions, COALESCE(SUM(totalstars), 0) AS stars
               FROM {interactiveslide_participant}
              WHERE interactiveslideid = :instanceid AND userid = :userid',
            ['instanceid' => $interactiveslideid, 'userid' => $userid]
        );

        $totals = [
            'activity' => (int)($activity->stars ?? 0),
            'sessions' => (int)($activity->sessions ?? 0),
            'course' => 0,
            'decks' => 0,
        ];

        $decks = self::visible_decks($courseid);
        if (!$decks) {
            return $totals;
        }

        [$insql, $params] = $DB->get_in_or_equal(array_keys($decks), SQL_PARAMS_NAMED, 'deck');
        $params['userid'] = $userid;

        $rows = $DB->get_records_sql(
            "SELECT interactiveslideid, SUM(totalstars) AS stars
               FROM {interactiveslide_participant}
              WHERE interactiveslideid $insql AND userid = :userid
           GROUP BY interactiveslideid",
            $params
        );

        foreach ($rows as $row) {
            $totals['course'] += (int)$row->stars;
            $totals['decks']++;
        }

        return $totals;
    }

    /**
     * Every deck in a course the current user can open.
     *
     * The list a student's own course total is built from. Availability
     * restrictions and hidden activities are honoured, so a deck they cannot
     * reach contributes nothing to the number they are shown.
     *
     * @param int $courseid
     * @return array<int, string> activity instance id to name
     */
    public static function visible_decks(int $courseid): array {
        $decks = [];

        foreach (get_fast_modinfo($courseid)->get_instances_of('interactiveslide') as $cm) {
            if ($cm->uservisible) {
                $decks[(int)$cm->instance] = format_string($cm->name);
            }
        }

        return $decks;
    }

    /**
     * The Interactive Slide activities in a course whose reports this user may
     * read, in the order they appear on the course page.
     *
     * The capability is checked per activity rather than once for the course: a
     * teacher of one chapter must not be handed another chapter's marks just
     * because both live in the same course.
     *
     * @param int $courseid
     * @return array<int, string> activity instance id to name
     */
    public static function readable_decks(int $courseid): array {
        $decks = [];

        foreach (get_fast_modinfo($courseid)->get_instances_of('interactiveslide') as $cm) {
            if (has_capability('mod/interactiveslide:viewreports', $cm->context)) {
                $decks[(int)$cm->instance] = format_string($cm->name);
            }
        }

        return $decks;
    }

    /**
     * Build the course sheet for a given set of decks.
     *
     * Split from course_rows so the aggregation can be exercised without a
     * course, a module info cache or a capability check standing in the way.
     *
     * @param array<int, string> $decks activity instance id to name
     * @param int $onlyuserid restrict to one participant; 0 for everybody
     * @return array{0: array<string, string>, 1: array[]} columns and rows
     */
    public static function build_course_rows(array $decks, int $onlyuserid = 0): array {
        global $DB;

        $columns = [
            'idnumber' => get_string('useridnumber', 'mod_interactiveslide'),
            'fullname' => get_string('participant', 'mod_interactiveslide'),
        ];
        foreach ($decks as $instanceid => $name) {
            $columns['deck' . $instanceid] = $name;
        }
        $columns['stars'] = get_string('totalstars', 'mod_interactiveslide');

        if (!$decks) {
            return [$columns, []];
        }

        [$insql, $params] = $DB->get_in_or_equal(array_keys($decks), SQL_PARAMS_NAMED, 'deck');

        $mine = '';
        if ($onlyuserid) {
            $mine = ' AND userid = :onlyuser';
            $params['onlyuser'] = $onlyuserid;
        }

        $rowset = $DB->get_recordset_sql(
            "SELECT userid, interactiveslideid, SUM(totalstars) AS stars
               FROM {interactiveslide_participant}
              WHERE interactiveslideid $insql" . $mine . "
           GROUP BY userid, interactiveslideid",
            $params
        );

        $perdeck = [];
        $totals = [];
        foreach ($rowset as $row) {
            $userid = (int)$row->userid;
            $perdeck[$userid][(int)$row->interactiveslideid] = (int)$row->stars;
            $totals[$userid] = ($totals[$userid] ?? 0) + (int)$row->stars;
        }
        $rowset->close();

        if (!$totals) {
            return [$columns, []];
        }

        arsort($totals);
        $users = userinfo::load(array_keys($totals));

        $rows = [];
        foreach ($totals as $userid => $total) {
            $user = $users[$userid] ?? userinfo::placeholder($userid);

            $row = [
                'idnumber' => (string)($user->idnumber ?? ''),
                'fullname' => fullname($user),
            ];

            foreach ($decks as $instanceid => $unusedname) {
                // A deck the student never took part in reads as a dash, so an
                // absence is not mistaken for a zero score.
                $row['deck' . $instanceid] = $perdeck[$userid][$instanceid] ?? '-';
            }

            $row['stars'] = $total;
            $rows[] = $row;
        }

        return [$columns, $rows];
    }

    /**
     * One row per student for a single session, with a column per question.
     *
     * @param int $sessionid
     * @param int $onlyuserid restrict to one participant, for a student reading
     *        their own report; 0 for everybody
     * @return array{0: array<string, string>, 1: array[]} columns and rows
     */
    public static function session_rows(int $sessionid, int $onlyuserid = 0): array {
        global $DB;

        $rounds = $DB->get_records_sql(
            'SELECT r.id, r.slideid, s.sortorder, s.title
               FROM {interactiveslide_round} r
               JOIN {interactiveslide_slide} s ON s.id = r.slideid
              WHERE r.sessionid = :sessionid
           ORDER BY s.sortorder ASC, r.id ASC',
            ['sessionid' => $sessionid]
        );

        // Same shape as the overview sheet: breakdown first, then the two
        // sources that belong to no slide, then the total they add up to.
        $columns = [
            'idnumber' => get_string('useridnumber', 'mod_interactiveslide'),
            'fullname' => get_string('participant', 'mod_interactiveslide'),
        ];

        foreach ($rounds as $round) {
            $columns['round' . $round->id] = self::slide_label($round);
        }

        $columns['attendance'] = get_string('attendancestarsshort', 'mod_interactiveslide');
        $columns['manual'] = get_string('manualstarsshort', 'mod_interactiveslide');
        $columns['stars'] = get_string('totalstars', 'mod_interactiveslide');
        $columns['correct'] = get_string('correctanswers', 'mod_interactiveslide');
        $columns['answered'] = get_string('answersgiven', 'mod_interactiveslide');

        $conditions = ['sessionid' => $sessionid];
        if ($onlyuserid) {
            $conditions['userid'] = $onlyuserid;
        }

        $participants = $DB->get_records('interactiveslide_participant', $conditions,
            'totalstars DESC, timejoined ASC');

        if (!$participants) {
            return [$columns, []];
        }

        $params = ['sessionid' => $sessionid];
        $mine = '';
        if ($onlyuserid) {
            $mine = ' AND userid = :onlyuser';
            $params['onlyuser'] = $onlyuserid;
        }

        $responses = $DB->get_records_sql(
            'SELECT id, roundid, userid, stars, bonusstars, iscorrect
               FROM {interactiveslide_response}
              WHERE sessionid = :sessionid' . $mine,
            $params
        );

        $byuser = [];
        foreach ($responses as $response) {
            $byuser[(int)$response->userid][(int)$response->roundid] =
                (int)$response->stars + (int)$response->bonusstars;
        }

        $users = userinfo::load(array_map(static fn($p) => (int)$p->userid, $participants));

        $rows = [];
        foreach ($participants as $participant) {
            $userid = (int)$participant->userid;

            $user = $users[$userid] ?? userinfo::placeholder($userid);

            $row = [
                'idnumber' => (string)($user->idnumber ?? ''),
                'fullname' => fullname($user),
            ];

            foreach ($rounds as $round) {
                // A dash rather than a zero: not answering and answering wrongly
                // are different things when a teacher reads the sheet.
                $row['round' . $round->id] = $byuser[$userid][(int)$round->id] ?? '-';
            }

            $row['attendance'] = (int)$participant->attendancestars;
            $row['manual'] = (int)$participant->bonusstars;
            $row['stars'] = (int)$participant->totalstars;
            $row['correct'] = (int)$participant->correctcount;
            $row['answered'] = (int)$participant->responsecount;

            $rows[] = $row;
        }

        return [$columns, $rows];
    }
}
