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
 * Turns stored answers into the tallies the overlay draws.
 *
 * @package    mod_interactiveslide
 * @copyright  2026 Interactive Slide contributors
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class aggregator {

    /** @var int How many distinct words a wordcloud shows at once. */
    public const WORDCLOUD_LIMIT = 60;

    /** @var int How many distinct answers each blank lists. */
    public const BLANK_LIMIT = 12;

    /** @var int How many open ended answers the wall carries at once. */
    public const OPENENDED_LIMIT = 60;

    /**
     * How many students have submitted an answer to a round.
     *
     * @param int $roundid
     * @return int
     */
    public static function response_count(int $roundid): int {
        global $DB;

        return $DB->count_records('interactiveslide_response', ['roundid' => $roundid]);
    }

    /**
     * The aggregated result of a round, shaped for the client.
     *
     * @param stdClass $interaction with options and blanks attached
     * @param stdClass $round
     * @param bool $includecorrect include which answers are correct
     * @param bool $withauthors name who wrote each open ended answer
     * @return array
     */
    public static function get_results(stdClass $interaction, stdClass $round,
            bool $includecorrect, bool $withauthors = false): array {
        $result = [
            'qtype' => (string)$interaction->qtype,
            'responsecount' => self::response_count((int)$round->id),
            'words' => [],
            'choices' => [],
            'blanks' => [],
            'texts' => [],
            'correctcount' => 0,
            'incorrectcount' => 0,
        ];

        switch ($interaction->qtype) {
            case interaction_manager::TYPE_WORDCLOUD:
                $result['words'] = self::wordcloud((int)$round->id);
                break;

            case interaction_manager::TYPE_MULTICHOICE:
                $result['choices'] = self::choice_tally($interaction, (int)$round->id, $includecorrect);
                break;

            case interaction_manager::TYPE_FILLBLANK:
                $result['blanks'] = self::blank_tally($interaction, (int)$round->id, $includecorrect);
                break;

            case interaction_manager::TYPE_OPENENDED:
                $result['texts'] = self::openended_wall((int)$round->id, $withauthors);
                break;
        }

        if (!empty($interaction->hasanswer)) {
            $counts = self::correctness_counts((int)$round->id);
            $result['correctcount'] = $counts['correct'];
            $result['incorrectcount'] = $counts['incorrect'];
        }

        return $result;
    }

    /**
     * The individual answers to an open ended question, newest first.
     *
     * Unlike a word cloud these are not grouped: the point is to read what each
     * person actually wrote.
     *
     * @param int $roundid
     * @param bool $withauthors attach who wrote each answer
     * @param int $limit
     * @return array[] each with text, count, userid and fullname
     */
    public static function openended_wall(int $roundid, bool $withauthors = false,
            int $limit = self::OPENENDED_LIMIT): array {
        global $DB;

        $rows = $DB->get_records_sql(
            'SELECT a.id, a.answertext, a.normtext, r.userid
               FROM {interactiveslide_answer} a
               JOIN {interactiveslide_response} r ON r.id = a.responseid
              WHERE a.roundid = :roundid AND a.blankid = 0 AND a.optionid = 0
           ORDER BY a.id DESC',
            ['roundid' => $roundid],
            0,
            $limit
        );

        if (!$rows) {
            return [];
        }

        if ($withauthors) {
            // One card per person. The teacher rewards individuals here, so two
            // students who happened to write the same thing must stay two cards
            // rather than collapse into one that only one of them could be
            // credited for.
            $users = userinfo::load(array_map(static fn($row) => (int)$row->userid, $rows));

            $wall = [];
            foreach ($rows as $row) {
                $userid = (int)$row->userid;
                $wall[] = [
                    'text' => (string)$row->answertext,
                    'count' => 1,
                    'userid' => $userid,
                    'fullname' => fullname($users[$userid] ?? userinfo::placeholder($userid)),
                ];
            }

            return $wall;
        }

        // Without names there is nothing to tell two identical answers apart, so
        // group them and badge how often each came up.
        $counts = [];
        foreach ($rows as $row) {
            $key = (string)$row->normtext;
            $counts[$key] = ($counts[$key] ?? 0) + 1;
        }

        $texts = [];
        $seen = [];
        foreach ($rows as $row) {
            $key = (string)$row->normtext;
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $texts[] = [
                'text' => (string)$row->answertext,
                'count' => $counts[$key],
                'userid' => 0,
                'fullname' => '',
            ];
        }

        return $texts;
    }

    /**
     * Word frequencies for a wordcloud, most frequent first.
     *
     * @param int $roundid
     * @param int $limit
     * @return array[] each with text, count and a 1..10 weight for sizing
     */
    public static function wordcloud(int $roundid, int $limit = self::WORDCLOUD_LIMIT): array {
        global $DB;

        $rows = $DB->get_records_sql(
            "SELECT normtext, COUNT(id) AS entrycount, MAX(" . $DB->sql_compare_text('answertext', 255) . ") AS displaytext
               FROM {interactiveslide_answer}
              WHERE roundid = :roundid AND normtext IS NOT NULL AND normtext <> ''
           GROUP BY normtext
           ORDER BY entrycount DESC, normtext ASC",
            ['roundid' => $roundid],
            0,
            $limit
        );

        if (!$rows) {
            return [];
        }

        $maxcount = 0;
        foreach ($rows as $row) {
            $maxcount = max($maxcount, (int)$row->entrycount);
        }

        $words = [];
        foreach ($rows as $row) {
            $count = (int)$row->entrycount;
            // Weight 1..10 drives the font size ramp in the renderer.
            $weight = $maxcount > 1
                ? (int)round(1 + 9 * (($count - 1) / ($maxcount - 1)))
                : 10;

            $words[] = [
                'text' => (string)($row->displaytext !== null ? $row->displaytext : $row->normtext),
                'count' => $count,
                'weight' => max(1, min(10, $weight)),
            ];
        }

        return $words;
    }

    /**
     * Vote counts per multiple choice option, in the authored order.
     *
     * @param stdClass $interaction
     * @param int $roundid
     * @param bool $includecorrect
     * @return array[]
     */
    public static function choice_tally(stdClass $interaction, int $roundid, bool $includecorrect): array {
        global $DB;

        $counts = $DB->get_records_sql(
            'SELECT optionid, COUNT(id) AS votes
               FROM {interactiveslide_answer}
              WHERE roundid = :roundid AND optionid > 0
           GROUP BY optionid',
            ['roundid' => $roundid]
        );

        $total = 0;
        foreach ($counts as $row) {
            $total += (int)$row->votes;
        }

        $choices = [];
        foreach ($interaction->options ?? [] as $option) {
            $votes = isset($counts[$option->id]) ? (int)$counts[$option->id]->votes : 0;
            $choices[] = [
                'id' => (int)$option->id,
                'text' => (string)$option->optiontext,
                'count' => $votes,
                'percent' => $total > 0 ? (int)round($votes * 100 / $total) : 0,
                'iscorrect' => $includecorrect ? (int)$option->iscorrect : 0,
            ];
        }

        return $choices;
    }

    /**
     * The most common answers given for each blank.
     *
     * @param stdClass $interaction
     * @param int $roundid
     * @param bool $includecorrect
     * @return array[]
     */
    public static function blank_tally(stdClass $interaction, int $roundid, bool $includecorrect): array {
        global $DB;

        $blanks = [];
        foreach ($interaction->blanks ?? [] as $blank) {
            $rows = $DB->get_records_sql(
                "SELECT normtext,
                        COUNT(id) AS entrycount,
                        MAX(" . $DB->sql_compare_text('answertext', 255) . ") AS displaytext,
                        MAX(iscorrect) AS iscorrect
                   FROM {interactiveslide_answer}
                  WHERE roundid = :roundid AND blankid = :blankid AND normtext <> ''
               GROUP BY normtext
               ORDER BY entrycount DESC, normtext ASC",
                ['roundid' => $roundid, 'blankid' => (int)$blank->id],
                0,
                self::BLANK_LIMIT
            );

            $entries = [];
            $correct = 0;
            $answered = 0;
            foreach ($rows as $row) {
                $count = (int)$row->entrycount;
                $answered += $count;
                if ((int)$row->iscorrect === 1) {
                    $correct += $count;
                }
                $entries[] = [
                    'text' => (string)($row->displaytext !== null ? $row->displaytext : $row->normtext),
                    'count' => $count,
                    'iscorrect' => $includecorrect ? (int)$row->iscorrect : 0,
                ];
            }

            $answerlist = $blank->answerlist ?? text_util::decode_answers($blank->answers);

            $blanks[] = [
                'id' => (int)$blank->id,
                'label' => (string)$blank->label,
                'points' => (int)$blank->points,
                'answers' => $includecorrect ? array_values($answerlist) : [],
                'entries' => $entries,
                'correctcount' => $correct,
                'answeredcount' => $answered,
            ];
        }

        return $blanks;
    }

    /**
     * How many submissions of a round were fully correct.
     *
     * @param int $roundid
     * @return array{correct: int, incorrect: int}
     */
    public static function correctness_counts(int $roundid): array {
        global $DB;

        $row = $DB->get_record_sql(
            'SELECT COALESCE(SUM(iscorrect), 0) AS correct, COUNT(id) AS total
               FROM {interactiveslide_response}
              WHERE roundid = :roundid',
            ['roundid' => $roundid]
        );

        $correct = (int)($row->correct ?? 0);
        $total = (int)($row->total ?? 0);

        return ['correct' => $correct, 'incorrect' => max(0, $total - $correct)];
    }
}
