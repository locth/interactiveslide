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

use moodle_exception;
use stdClass;

/**
 * Marks a student submission and stores it.
 *
 * Every decision that affects stars is made server side. The student page only
 * decides what to show; it never decides what a submission is worth.
 *
 * @package    mod_interactiveslide
 * @copyright  2026 Interactive Slide contributors
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class grader {

    /**
     * Store and mark one submission.
     *
     * @param stdClass $instance the deck record
     * @param stdClass $session
     * @param stdClass $round
     * @param stdClass $interaction with options and blanks attached
     * @param int $userid
     * @param array $payload raw submission from the student page
     * @return stdClass the stored response record
     * @throws moodle_exception when the round is not accepting this submission
     */
    public static function submit(stdClass $instance, stdClass $session, stdClass $round,
            stdClass $interaction, int $userid, array $payload): stdClass {
        global $DB;

        if ($session->status !== session_manager::STATUS_ACTIVE) {
            throw new moodle_exception('errorsessionended', 'mod_interactiveslide');
        }

        // Re-check the timer here: a slow poll may not have closed the round yet.
        session_manager::close_if_expired($round);
        if ($round->status !== session_manager::ROUND_OPEN) {
            throw new moodle_exception('errorroundclosed', 'mod_interactiveslide');
        }

        $existing = $DB->get_record('interactiveslide_response',
            ['roundid' => $round->id, 'userid' => $userid]);
        if ($existing && empty($interaction->allowretry)) {
            throw new moodle_exception('erroralreadyanswered', 'mod_interactiveslide');
        }

        $marked = self::mark($interaction, $payload);

        $now = time();
        $timetaken = max(0, ($now - (int)$round->timeopen)) * 1000;

        $bonus = self::speed_bonus($instance, $round, $marked['iscorrect'], $now);

        $transaction = $DB->start_delegated_transaction();

        if ($existing) {
            $DB->delete_records('interactiveslide_answer', ['responseid' => $existing->id]);
            $response = $existing;
            $response->iscorrect = $marked['iscorrect'];
            $response->stars = $marked['stars'];
            $response->bonusstars = $bonus;
            $response->timetaken = $timetaken;
            $response->timemodified = $now;
            $DB->update_record('interactiveslide_response', $response);
        } else {
            $response = new stdClass();
            $response->roundid = (int)$round->id;
            $response->sessionid = (int)$session->id;
            $response->userid = $userid;
            $response->iscorrect = $marked['iscorrect'];
            $response->stars = $marked['stars'];
            $response->bonusstars = $bonus;
            $response->timetaken = $timetaken;
            $response->timecreated = $now;
            $response->timemodified = $now;
            $response->id = $DB->insert_record('interactiveslide_response', $response);
        }

        foreach ($marked['answers'] as $answer) {
            $DB->insert_record('interactiveslide_answer', (object)[
                'responseid' => (int)$response->id,
                'roundid' => (int)$round->id,
                'blankid' => (int)($answer['blankid'] ?? 0),
                'optionid' => (int)($answer['optionid'] ?? 0),
                'answertext' => (string)($answer['answertext'] ?? ''),
                'normtext' => (string)($answer['normtext'] ?? ''),
                'iscorrect' => (int)($answer['iscorrect'] ?? 0),
                'stars' => (int)($answer['stars'] ?? 0),
            ]);
        }

        session_manager::touch_participant($session, $userid);
        session_manager::recalculate_participant((int)$session->id, $userid);

        $transaction->allow_commit();

        session_manager::bump((int)$session->id);

        return $response;
    }

    /**
     * Mark a submission without storing anything.
     *
     * @param stdClass $interaction with options and blanks attached
     * @param array $payload
     * @return array{iscorrect: int, stars: int, answers: array[]}
     * @throws moodle_exception when the submission is empty or malformed
     */
    public static function mark(stdClass $interaction, array $payload): array {
        switch ($interaction->qtype) {
            case interaction_manager::TYPE_WORDCLOUD:
                return self::mark_wordcloud($interaction, $payload);
            case interaction_manager::TYPE_MULTICHOICE:
                return self::mark_multichoice($interaction, $payload);
            case interaction_manager::TYPE_FILLBLANK:
                return self::mark_fillblank($interaction, $payload);
            case interaction_manager::TYPE_OPENENDED:
                return self::mark_openended($interaction, $payload);
            default:
                throw new moodle_exception('errorinvalidqtype', 'mod_interactiveslide');
        }
    }

    /**
     * Mark a wordcloud submission. There is no wrong answer, only participation.
     *
     * @param stdClass $interaction
     * @param array $payload
     * @return array{iscorrect: int, stars: int, answers: array[]}
     * @throws moodle_exception
     */
    private static function mark_wordcloud(stdClass $interaction, array $payload): array {
        $raw = $payload['entries'] ?? '';
        if (is_array($raw)) {
            $raw = implode("\n", array_map('strval', $raw));
        }

        $entries = text_util::split_entries((string)$raw,
            (int)$interaction->maxentries, (int)$interaction->maxwordlength);

        if (!$entries) {
            throw new moodle_exception('erroremptyanswer', 'mod_interactiveslide');
        }

        $answers = [];
        foreach ($entries as $entry) {
            $answers[] = [
                'answertext' => $entry,
                'normtext' => text_util::normalise($entry, (bool)$interaction->casesensitive),
                'iscorrect' => 0,
                'stars' => 0,
            ];
        }

        return [
            'iscorrect' => 0,
            'stars' => max(0, (int)$interaction->points),
            'answers' => $answers,
        ];
    }

    /**
     * Mark an open ended submission. There is nothing to be right about, so
     * everyone who writes something earns the participation stars.
     *
     * @param stdClass $interaction
     * @param array $payload
     * @return array{iscorrect: int, stars: int, answers: array[]}
     * @throws moodle_exception
     */
    private static function mark_openended(stdClass $interaction, array $payload): array {
        $text = $payload['text'] ?? '';
        if (is_array($text)) {
            $text = implode(' ', array_map('strval', $text));
        }

        $text = text_util::strip_invisible((string)$text);
        // Keep the line breaks a student typed; only collapse runs of spaces.
        $text = preg_replace('/[ \t]+/u', ' ', $text);
        $text = preg_replace('/\n{3,}/u', "\n\n", (string)$text);
        $text = trim((string)$text);

        if ($text === '') {
            throw new moodle_exception('erroremptyanswer', 'mod_interactiveslide');
        }

        $limit = max(3, min(interaction_manager::MAX_OPENENDED_LENGTH,
            (int)$interaction->maxwordlength));
        $text = \core_text::substr($text, 0, $limit);

        return [
            'iscorrect' => 0,
            'stars' => max(0, (int)$interaction->points),
            'answers' => [[
                'answertext' => $text,
                // Only used to spot two identical submissions; the wall shows
                // every card in full.
                'normtext' => text_util::normalise($text),
                'iscorrect' => 0,
                'stars' => 0,
            ]],
        ];
    }

    /**
     * Mark a multiple choice submission.
     *
     * With several correct options the student must pick exactly the right set:
     * partial credit would make "select everything" a winning strategy.
     *
     * @param stdClass $interaction
     * @param array $payload
     * @return array{iscorrect: int, stars: int, answers: array[]}
     * @throws moodle_exception
     */
    private static function mark_multichoice(stdClass $interaction, array $payload): array {
        $submitted = $payload['optionids'] ?? [];
        if (!is_array($submitted)) {
            $submitted = [$submitted];
        }

        $valid = [];
        foreach ($interaction->options ?? [] as $option) {
            $valid[(int)$option->id] = $option;
        }

        $chosen = [];
        foreach ($submitted as $optionid) {
            $optionid = (int)$optionid;
            if (isset($valid[$optionid])) {
                $chosen[$optionid] = $optionid;
            }
        }

        if (!$chosen) {
            throw new moodle_exception('erroremptyanswer', 'mod_interactiveslide');
        }
        if (empty($interaction->allowmultiple) && count($chosen) > 1) {
            throw new moodle_exception('errorsinglechoiceonly', 'mod_interactiveslide');
        }

        $correctids = [];
        foreach ($valid as $optionid => $option) {
            if ((int)$option->iscorrect === 1) {
                $correctids[$optionid] = $optionid;
            }
        }

        $iscorrect = 0;
        if (!empty($interaction->hasanswer) && $correctids) {
            sort($correctids);
            $sortedchosen = array_values($chosen);
            sort($sortedchosen);
            $iscorrect = ($sortedchosen === array_values($correctids)) ? 1 : 0;
        }

        $answers = [];
        foreach ($chosen as $optionid) {
            $answers[] = [
                'optionid' => $optionid,
                'answertext' => (string)$valid[$optionid]->optiontext,
                'normtext' => '',
                'iscorrect' => (int)$valid[$optionid]->iscorrect,
                'stars' => 0,
            ];
        }

        if (empty($interaction->hasanswer)) {
            // An opinion poll: everyone who votes earns the participation stars.
            return ['iscorrect' => 0, 'stars' => max(0, (int)$interaction->points), 'answers' => $answers];
        }

        return [
            'iscorrect' => $iscorrect,
            'stars' => $iscorrect ? max(0, (int)$interaction->points) : 0,
            'answers' => $answers,
        ];
    }

    /**
     * Mark a fill in the blank submission, one blank at a time.
     *
     * @param stdClass $interaction
     * @param array $payload
     * @return array{iscorrect: int, stars: int, answers: array[]}
     * @throws moodle_exception
     */
    private static function mark_fillblank(stdClass $interaction, array $payload): array {
        $submitted = $payload['blanks'] ?? [];
        if (!is_array($submitted)) {
            $submitted = [];
        }

        $byblankid = [];
        foreach ($submitted as $item) {
            if (!is_array($item)) {
                continue;
            }
            $byblankid[(int)($item['blankid'] ?? 0)] = (string)($item['text'] ?? '');
        }

        $answers = [];
        $stars = 0;
        $allcorrect = true;
        $anytext = false;

        foreach ($interaction->blanks ?? [] as $blank) {
            $text = trim($byblankid[(int)$blank->id] ?? '');
            if ($text !== '') {
                $anytext = true;
            }

            $casesensitive = (bool)$blank->casesensitive || (bool)$interaction->casesensitive;
            $accepted = $blank->answerlist ?? text_util::decode_answers($blank->answers);

            $iscorrect = 0;
            if (!empty($interaction->hasanswer) && $accepted) {
                $iscorrect = text_util::matches_any($text, $accepted, $casesensitive) ? 1 : 0;
            }

            $blankstars = $iscorrect ? max(0, (int)$blank->points) : 0;
            $stars += $blankstars;
            if (!$iscorrect) {
                $allcorrect = false;
            }

            $answers[] = [
                'blankid' => (int)$blank->id,
                'answertext' => \core_text::substr($text, 0, 500),
                'normtext' => text_util::normalise($text, $casesensitive),
                'iscorrect' => $iscorrect,
                'stars' => $blankstars,
            ];
        }

        if (!$anytext) {
            throw new moodle_exception('erroremptyanswer', 'mod_interactiveslide');
        }

        if (empty($interaction->hasanswer)) {
            return ['iscorrect' => 0, 'stars' => max(0, (int)$interaction->points), 'answers' => $answers];
        }

        return [
            'iscorrect' => $allcorrect ? 1 : 0,
            'stars' => $stars,
            'answers' => $answers,
        ];
    }

    /**
     * Extra stars for answering correctly with time to spare.
     *
     * The bonus needs a timer to have something to measure against, so an
     * untimed question never pays one.
     *
     * @param stdClass $instance
     * @param stdClass $round
     * @param int $iscorrect
     * @param int $now
     * @return int
     */
    private static function speed_bonus(stdClass $instance, stdClass $round, int $iscorrect, int $now): int {
        if (empty($instance->speedbonus) || !$iscorrect) {
            return 0;
        }

        $limit = (int)$round->timelimit;
        $max = max(0, (int)$instance->speedbonusmax);
        if ($limit <= 0 || $max <= 0) {
            return 0;
        }

        $elapsed = max(0, $now - (int)$round->timeopen);
        $fraction = 1 - ($elapsed / $limit);

        return max(0, min($max, (int)ceil($fraction * $max)));
    }
}
