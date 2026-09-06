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
 * Creating and editing the interaction attached to a slide.
 *
 * @package    mod_interactiveslide
 * @copyright  2026 Interactive Slide contributors
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class interaction_manager {

    /** @var string A wordcloud of free text entries. */
    public const TYPE_WORDCLOUD = 'wordcloud';

    /** @var string A single or multiple answer choice question. */
    public const TYPE_MULTICHOICE = 'multichoice';

    /** @var string One or more blanks to fill in. */
    public const TYPE_FILLBLANK = 'fillblank';

    /** @var string A free text answer, gathered and shown as a wall of cards. */
    public const TYPE_OPENENDED = 'openended';

    /** @var int Longest answer an open ended question may accept. */
    public const MAX_OPENENDED_LENGTH = 1000;

    /** @var string Difficulty meaning "use the explicit points value". */
    public const DIFFICULTY_CUSTOM = 'custom';

    /** @var int Hard cap on options per question, mirrored in the editor UI. */
    public const MAX_OPTIONS = 10;

    /** @var int Hard cap on blanks per slide, mirrored in the editor UI. */
    public const MAX_BLANKS = 10;

    /** @var int Hard cap on accepted answers per blank. */
    public const MAX_ANSWERS_PER_BLANK = 20;

    /**
     * The interaction types this plugin can run.
     *
     * @return string[]
     */
    public static function get_types(): array {
        return [
            self::TYPE_WORDCLOUD,
            self::TYPE_MULTICHOICE,
            self::TYPE_FILLBLANK,
            self::TYPE_OPENENDED,
        ];
    }

    /**
     * Only these types can be marked as having a correct answer.
     *
     * A word cloud and an open ended question are both about gathering what the
     * room thinks, so there is nothing to mark them against.
     *
     * @param string $qtype
     * @return bool
     */
    public static function type_supports_answers(string $qtype): bool {
        return in_array($qtype, [self::TYPE_MULTICHOICE, self::TYPE_FILLBLANK], true);
    }

    /**
     * Types that pay the same stars to everyone who takes part.
     *
     * @param string $qtype
     * @return bool
     */
    public static function type_is_participation(string $qtype): bool {
        return in_array($qtype, [self::TYPE_WORDCLOUD, self::TYPE_OPENENDED], true);
    }

    /**
     * Map a difficulty name onto its configured star value.
     *
     * @param string $difficulty easy|medium|hard|custom
     * @param int $custompoints value used when the difficulty is custom
     * @return int
     */
    public static function points_for_difficulty(string $difficulty, int $custompoints = 1): int {
        $defaults = [
            'easy' => (int)(get_config('mod_interactiveslide', 'points_easy') ?: 1),
            'medium' => (int)(get_config('mod_interactiveslide', 'points_medium') ?: 2),
            'hard' => (int)(get_config('mod_interactiveslide', 'points_hard') ?: 3),
        ];

        if (isset($defaults[$difficulty])) {
            return max(0, $defaults[$difficulty]);
        }

        return max(0, min(100, $custompoints));
    }

    /**
     * Load an interaction with its options and blanks attached.
     *
     * @param int $interactionid
     * @return stdClass|null
     */
    public static function get_interaction(int $interactionid): ?stdClass {
        global $DB;

        $interaction = $DB->get_record('interactiveslide_interaction', ['id' => $interactionid]);
        if (!$interaction) {
            return null;
        }

        return self::attach_children($interaction);
    }

    /**
     * Load the interaction belonging to a slide, if any.
     *
     * @param int $slideid
     * @return stdClass|null
     */
    public static function get_interaction_for_slide(int $slideid): ?stdClass {
        global $DB;

        $interaction = $DB->get_record('interactiveslide_interaction', ['slideid' => $slideid], '*', IGNORE_MULTIPLE);
        if (!$interaction) {
            return null;
        }

        return self::attach_children($interaction);
    }

    /**
     * Attach the options and blanks of an interaction to its record.
     *
     * @param stdClass $interaction
     * @return stdClass the same object, mutated
     */
    public static function attach_children(stdClass $interaction): stdClass {
        global $DB;

        $interaction->options = array_values($DB->get_records('interactiveslide_option',
            ['interactionid' => $interaction->id], 'sortorder ASC, id ASC'));

        $interaction->blanks = array_values($DB->get_records('interactiveslide_blank',
            ['interactionid' => $interaction->id], 'sortorder ASC, id ASC'));

        foreach ($interaction->blanks as $blank) {
            $blank->answerlist = text_util::decode_answers($blank->answers);
        }

        return $interaction;
    }

    /**
     * Total stars a fully correct answer is worth.
     *
     * @param stdClass $interaction with options and blanks attached
     * @return int
     */
    public static function max_stars(stdClass $interaction): int {
        if ($interaction->qtype === self::TYPE_FILLBLANK) {
            $total = 0;
            foreach ($interaction->blanks ?? [] as $blank) {
                $total += (int)$blank->points;
            }
            return $total;
        }

        return (int)$interaction->points;
    }

    /**
     * Create or update the interaction of a slide from an editor payload.
     *
     * @param int $slideid
     * @param array $data decoded editor payload
     * @return int the interaction id
     * @throws moodle_exception when the payload is not a usable question
     */
    public static function save_from_payload(int $slideid, array $data): int {
        global $DB;

        $qtype = (string)($data['qtype'] ?? '');
        if (!in_array($qtype, self::get_types(), true)) {
            throw new moodle_exception('errorinvalidqtype', 'mod_interactiveslide');
        }

        $hasanswer = self::type_supports_answers($qtype) ? (int)!empty($data['hasanswer']) : 0;
        $difficulty = (string)($data['difficulty'] ?? 'easy');
        if (!in_array($difficulty, ['easy', 'medium', 'hard', self::DIFFICULTY_CUSTOM], true)) {
            $difficulty = 'easy';
        }

        $record = new stdClass();
        $record->slideid = $slideid;
        $record->qtype = $qtype;
        $record->questiontext = clean_param((string)($data['questiontext'] ?? ''), PARAM_TEXT);
        $record->hasanswer = $hasanswer;
        $record->difficulty = $difficulty;
        $record->timerseconds = max(0, min(3600, (int)($data['timerseconds'] ?? 0)));
        $record->autoclose = (int)!empty($data['autoclose']);
        $record->showliveresult = (int)!empty($data['showliveresult']);
        $record->showleaderboard = (int)!empty($data['showleaderboard']);
        $record->allowmultiple = (int)!empty($data['allowmultiple']);
        $record->shuffleoptions = (int)!empty($data['shuffleoptions']);
        $record->maxentries = max(1, min(10, (int)($data['maxentries'] ?? 3)));

        // An open ended answer is a sentence or two, not a single word, so it
        // gets a much longer ceiling than the other types.
        $lengthcap = $qtype === self::TYPE_OPENENDED ? self::MAX_OPENENDED_LENGTH : 120;
        $lengthdefault = $qtype === self::TYPE_OPENENDED ? 300 : 30;
        $record->maxwordlength = max(3, min($lengthcap,
            (int)($data['maxwordlength'] ?? $lengthdefault)));
        $record->casesensitive = (int)!empty($data['casesensitive']);
        $record->allowretry = (int)!empty($data['allowretry']);
        $record->timemodified = time();

        // These always pay the same participation stars; difficulty is meaningless there.
        if (self::type_is_participation($qtype)) {
            $record->points = max(0, min(100, (int)($data['points'] ?? 1)));
            $record->difficulty = self::DIFFICULTY_CUSTOM;
        } else if ($hasanswer) {
            $record->points = self::points_for_difficulty($difficulty, (int)($data['points'] ?? 1));
        } else {
            $record->points = max(0, min(100, (int)($data['points'] ?? 1)));
        }

        $options = self::sanitise_options($data['options'] ?? [], $qtype, $hasanswer, (bool)$record->allowmultiple);
        $blanks = self::sanitise_blanks($data['blanks'] ?? [], $qtype, $hasanswer);

        $existing = $DB->get_record('interactiveslide_interaction', ['slideid' => $slideid], '*', IGNORE_MULTIPLE);

        $transaction = $DB->start_delegated_transaction();

        if ($existing) {
            $record->id = $existing->id;
            $DB->update_record('interactiveslide_interaction', $record);
            $interactionid = (int)$existing->id;
        } else {
            $record->timecreated = $record->timemodified;
            $interactionid = (int)$DB->insert_record('interactiveslide_interaction', $record);
        }

        self::replace_options($interactionid, $options);
        self::replace_blanks($interactionid, $blanks);

        $transaction->allow_commit();

        return $interactionid;
    }

    /**
     * Validate and clean the multiple choice options of a payload.
     *
     * @param mixed $rawoptions
     * @param string $qtype
     * @param int $hasanswer
     * @param bool $allowmultiple
     * @return array[] cleaned option rows
     * @throws moodle_exception
     */
    private static function sanitise_options($rawoptions, string $qtype, int $hasanswer, bool $allowmultiple): array {
        if ($qtype !== self::TYPE_MULTICHOICE) {
            return [];
        }
        if (!is_array($rawoptions)) {
            $rawoptions = [];
        }

        $options = [];
        foreach ($rawoptions as $raw) {
            $text = clean_param((string)($raw['optiontext'] ?? ''), PARAM_TEXT);
            $text = trim($text);
            if ($text === '') {
                continue;
            }
            $options[] = [
                'optiontext' => \core_text::substr($text, 0, 500),
                'iscorrect' => (int)!empty($raw['iscorrect']),
            ];
            if (count($options) >= self::MAX_OPTIONS) {
                break;
            }
        }

        if (count($options) < 2) {
            throw new moodle_exception('errorneedtwooptions', 'mod_interactiveslide');
        }

        if ($hasanswer) {
            $correct = array_filter($options, static fn($option) => $option['iscorrect'] === 1);
            if (!$correct) {
                throw new moodle_exception('errorneedcorrectoption', 'mod_interactiveslide');
            }
            if (!$allowmultiple && count($correct) > 1) {
                throw new moodle_exception('errortoomanycorrect', 'mod_interactiveslide');
            }
        } else {
            // Without a marked answer nothing should be flagged correct.
            foreach ($options as &$option) {
                $option['iscorrect'] = 0;
            }
            unset($option);
        }

        return $options;
    }

    /**
     * Validate and clean the fill in the blank definitions of a payload.
     *
     * @param mixed $rawblanks
     * @param string $qtype
     * @param int $hasanswer
     * @return array[] cleaned blank rows
     * @throws moodle_exception
     */
    private static function sanitise_blanks($rawblanks, string $qtype, int $hasanswer): array {
        if ($qtype !== self::TYPE_FILLBLANK) {
            return [];
        }
        if (!is_array($rawblanks)) {
            $rawblanks = [];
        }

        $blanks = [];
        foreach ($rawblanks as $index => $raw) {
            $answers = $raw['answers'] ?? [];
            if (is_string($answers)) {
                $answers = preg_split('/[\r\n]+/u', $answers) ?: [];
            }
            if (!is_array($answers)) {
                $answers = [];
            }
            $answers = array_slice($answers, 0, self::MAX_ANSWERS_PER_BLANK);

            $difficulty = (string)($raw['difficulty'] ?? 'easy');
            if (!in_array($difficulty, ['easy', 'medium', 'hard', self::DIFFICULTY_CUSTOM], true)) {
                $difficulty = 'easy';
            }

            $encoded = text_util::encode_answers($answers);
            if ($hasanswer && text_util::decode_answers($encoded) === []) {
                throw new moodle_exception('errorblankneedsanswer', 'mod_interactiveslide', '', $index + 1);
            }

            $blanks[] = [
                'label' => \core_text::substr(clean_param((string)($raw['label'] ?? ''), PARAM_TEXT), 0, 255),
                'answers' => $encoded,
                'points' => $hasanswer
                    ? self::points_for_difficulty($difficulty, (int)($raw['points'] ?? 1))
                    : 0,
                'difficulty' => $difficulty,
                'casesensitive' => (int)!empty($raw['casesensitive']),
            ];

            if (count($blanks) >= self::MAX_BLANKS) {
                break;
            }
        }

        if (!$blanks) {
            throw new moodle_exception('errorneedoneblank', 'mod_interactiveslide');
        }

        return $blanks;
    }

    /**
     * Replace the stored options of an interaction.
     *
     * Rows are deleted and recreated rather than diffed. Editing a question
     * after it has been answered would invalidate the recorded tallies anyway,
     * and the editor blocks that case before we get here.
     *
     * @param int $interactionid
     * @param array[] $options
     * @return void
     */
    private static function replace_options(int $interactionid, array $options): void {
        global $DB;

        $DB->delete_records('interactiveslide_option', ['interactionid' => $interactionid]);

        foreach ($options as $index => $option) {
            $DB->insert_record('interactiveslide_option', (object)[
                'interactionid' => $interactionid,
                'sortorder' => $index,
                'optiontext' => $option['optiontext'],
                'iscorrect' => $option['iscorrect'],
            ]);
        }
    }

    /**
     * Replace the stored blanks of an interaction.
     *
     * @param int $interactionid
     * @param array[] $blanks
     * @return void
     */
    private static function replace_blanks(int $interactionid, array $blanks): void {
        global $DB;

        $DB->delete_records('interactiveslide_blank', ['interactionid' => $interactionid]);

        foreach ($blanks as $index => $blank) {
            $DB->insert_record('interactiveslide_blank', (object)[
                'interactionid' => $interactionid,
                'sortorder' => $index,
                'label' => $blank['label'],
                'answers' => $blank['answers'],
                'points' => $blank['points'],
                'difficulty' => $blank['difficulty'],
                'casesensitive' => $blank['casesensitive'],
            ]);
        }
    }

    /**
     * Whether an interaction has already been run and answered.
     *
     * Editing such a question would silently rewrite the meaning of stored
     * responses, so the editor refuses it.
     *
     * @param int $interactionid
     * @return bool
     */
    public static function has_responses(int $interactionid): bool {
        global $DB;

        return $DB->record_exists_sql(
            'SELECT 1
               FROM {interactiveslide_response} r
               JOIN {interactiveslide_round} rd ON rd.id = r.roundid
              WHERE rd.interactionid = ?',
            [$interactionid]
        );
    }

    /**
     * Throw away everything students have answered for an interaction, keeping
     * the question itself.
     *
     * This is what unlocks a question for editing after a live run. Deleting the
     * whole slide and re-importing was the only way out before, which cost the
     * teacher every other interaction on the deck.
     *
     * @param int $interactionid
     * @return int how many responses were removed
     */
    public static function clear_responses(int $interactionid): int {
        global $DB;

        $roundids = $DB->get_fieldset_select('interactiveslide_round', 'id',
            'interactionid = ?', [$interactionid]);
        if (!$roundids) {
            return 0;
        }

        [$insql, $params] = $DB->get_in_or_equal($roundids);

        // Remember who was affected before the rows go, so their running totals
        // can be rebuilt afterwards.
        $affected = $DB->get_records_sql(
            "SELECT DISTINCT " . $DB->sql_concat('sessionid', "'-'", 'userid') . " AS pairkey,
                    sessionid, userid
               FROM {interactiveslide_response}
              WHERE roundid $insql",
            $params
        );

        $count = $DB->count_records_select('interactiveslide_response', "roundid $insql", $params);

        $transaction = $DB->start_delegated_transaction();

        $DB->delete_records_select('interactiveslide_answer', "roundid $insql", $params);
        $DB->delete_records_select('interactiveslide_response', "roundid $insql", $params);
        $DB->delete_records_select('interactiveslide_round', "id $insql", $params);

        foreach ($affected as $pair) {
            session_manager::recalculate_participant((int)$pair->sessionid, (int)$pair->userid);
        }

        $transaction->allow_commit();

        return $count;
    }

    /**
     * Delete an interaction, its definition rows and every recorded round.
     *
     * @param int $interactionid
     * @return void
     */
    public static function delete_interaction(int $interactionid): void {
        global $DB;

        // Goes through clear_responses so the stars those answers paid are taken
        // back off every participant's running total. Deleting the rows directly
        // left the totals counting answers that no longer existed, which showed
        // up as a leaderboard and a report that would not add up.
        self::clear_responses($interactionid);

        $DB->delete_records('interactiveslide_option', ['interactionid' => $interactionid]);
        $DB->delete_records('interactiveslide_blank', ['interactionid' => $interactionid]);
        $DB->delete_records('interactiveslide_interaction', ['id' => $interactionid]);
    }

    /**
     * Build the definition sent to a student, with every correct answer removed.
     *
     * @param stdClass $interaction with options and blanks attached
     * @param bool $revealed true once the presenter has revealed the answer
     * @return array
     */
    public static function export_for_student(stdClass $interaction, bool $revealed = false): array {
        $options = [];
        foreach ($interaction->options ?? [] as $option) {
            $options[] = [
                'id' => (int)$option->id,
                'optiontext' => (string)$option->optiontext,
                // Only ever leak correctness once the teacher has revealed it.
                'iscorrect' => $revealed ? (int)$option->iscorrect : 0,
            ];
        }

        $blanks = [];
        foreach ($interaction->blanks ?? [] as $blank) {
            $answerlist = $blank->answerlist ?? text_util::decode_answers($blank->answers);
            $blanks[] = [
                'id' => (int)$blank->id,
                'label' => (string)$blank->label,
                'points' => (int)$blank->points,
                'answers' => $revealed ? array_values($answerlist) : [],
            ];
        }

        return [
            'id' => (int)$interaction->id,
            'qtype' => (string)$interaction->qtype,
            'questiontext' => (string)$interaction->questiontext,
            'hasanswer' => (int)$interaction->hasanswer,
            'points' => (int)$interaction->points,
            'maxstars' => self::max_stars($interaction),
            'timerseconds' => (int)$interaction->timerseconds,
            'showliveresult' => (int)$interaction->showliveresult,
            'showleaderboard' => (int)$interaction->showleaderboard,
            'allowmultiple' => (int)$interaction->allowmultiple,
            'shuffleoptions' => (int)$interaction->shuffleoptions,
            'maxentries' => (int)$interaction->maxentries,
            'maxwordlength' => (int)$interaction->maxwordlength,
            'allowretry' => (int)$interaction->allowretry,
            'options' => $options,
            'blanks' => $blanks,
        ];
    }
}
