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
use moodle_exception;
use stdClass;

/**
 * Calling people to the board at random, and the fortune cards they draw.
 *
 * Every draw happens here, on the server. The projector only plays back what was
 * decided, so the card a student sees on their phone is the card the room saw,
 * and nobody can steer the result from a browser console.
 *
 * @package    mod_interactiveslide
 * @copyright  2026 Interactive Slide contributors
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class picker {

    /** @var int The most people one press of the button may call. */
    public const MAX_COUNT = 5;

    /**
     * @var int Seconds the projector spends on its opening, before the first card.
     *
     * The three timings below follow the animation in amd/src/randompick.js. A
     * phone holds its news back until the projector has shown it, so the person
     * called does not learn it from their pocket before the room does.
     */
    public const REVEAL_INTRO = 1;

    /** @var int Seconds the projector spends on each name card. */
    public const REVEAL_PER_CARD = 5;

    /** @var int Seconds from drawing a fortune to its card turning over on the projector. */
    public const FORTUNE_REVEAL = 7;

    /**
     * The fortune deck.
     *
     * weight is the card's chance out of the whole deck; the weights add up to
     * 100, so each one reads directly as a percentage. prophecies is how many
     * texts the language pack holds for the card, fortune_KEY_prophecy1 onwards.
     * stars are paid when the presenter confirms the condition on the card, auto
     * stars the moment it is drawn, and redraw sends the person back to the deck.
     *
     * Time based cards are deliberately absent: the person called is standing at
     * the board working, not answering against a clock.
     *
     * @var array[]
     */
    public const FORTUNES = [
        'moon' => ['weight' => 30, 'prophecies' => 4],
        'gemini' => ['weight' => 12, 'prophecies' => 2],
        'emperor' => ['weight' => 10, 'prophecies' => 2],
        'sun' => ['weight' => 10, 'prophecies' => 2, 'stars' => 2],
        'mercury' => ['weight' => 10, 'prophecies' => 2],
        'hermit' => ['weight' => 8, 'prophecies' => 2],
        'star' => ['weight' => 8, 'prophecies' => 2],
        'wheel' => ['weight' => 6, 'prophecies' => 2, 'redraw' => true],
        'magician' => ['weight' => 4, 'prophecies' => 2, 'stars' => 1],
        'jupiter' => ['weight' => 2, 'prophecies' => 2, 'auto' => 3],
    ];

    /**
     * Who may be called.
     *
     * The same people a group award reaches: everyone on this session's board who
     * may still take part. A guest has no enrolment to check, so being on the board
     * is the whole of their claim, and a guest the presenter removed is on no board.
     *
     * @param stdClass[] $participants rows with userid and lastseen
     * @param int[] $eligible user ids enrolled with the capability to submit
     * @param int[] $called user ids already called and not yet cleared
     * @param bool $onlineonly only people whose phone checked in recently
     * @param int $cutoff the lastseen a phone must have reached to count as online
     * @return int[] ascending
     */
    public static function candidates(array $participants, array $eligible, array $called,
            bool $onlineonly, int $cutoff): array {
        $eligible = array_flip(array_map('intval', $eligible));
        $called = array_flip(array_map('intval', $called));

        $pool = [];
        foreach ($participants as $row) {
            $userid = (int)$row->userid;
            if ($userid === 0 || isset($called[$userid])) {
                continue;
            }
            if ($userid > 0 && !isset($eligible[$userid])) {
                continue;
            }
            if ($onlineonly && (int)$row->lastseen < $cutoff) {
                continue;
            }
            $pool[$userid] = $userid;
        }
        ksort($pool);

        return array_values($pool);
    }

    /**
     * Pick some of a list at random, each person at most once.
     *
     * A partial Fisher-Yates shuffle over random_int, so every person and every
     * order is equally likely.
     *
     * @param int[] $userids
     * @param int $count
     * @param callable|null $rand function(int $min, int $max): int, for tests
     * @return int[] in the order the cards will be turned over
     */
    public static function choose(array $userids, int $count, ?callable $rand = null): array {
        $rand = $rand ?? 'random_int';
        $pool = array_values($userids);
        $count = min($count, count($pool));

        for ($i = 0; $i < $count; $i++) {
            $j = $rand($i, count($pool) - 1);
            [$pool[$i], $pool[$j]] = [$pool[$j], $pool[$i]];
        }

        return array_slice($pool, 0, max(0, $count));
    }

    /**
     * The card a roll of the dice lands on.
     *
     * @param int[] $weights keyed by card
     * @param int $roll 0 up to, not including, the sum of the weights
     * @return string the card's key
     */
    public static function weighted(array $weights, int $roll): string {
        foreach ($weights as $key => $weight) {
            if ($roll < $weight) {
                return (string)$key;
            }
            $roll -= $weight;
        }

        return (string)array_key_last($weights);
    }

    /**
     * Draw a card from the deck.
     *
     * @param string|null $exclude a card that may not come up, the one being redrawn
     * @param callable|null $rand function(int $min, int $max): int, for tests
     * @return array{0: string, 1: int} the card and which of its prophecies, from 1
     */
    public static function draw(?string $exclude = null, ?callable $rand = null): array {
        $rand = $rand ?? 'random_int';

        $weights = [];
        foreach (self::FORTUNES as $key => $card) {
            if ($key !== $exclude) {
                $weights[$key] = (int)$card['weight'];
            }
        }

        $key = self::weighted($weights, $rand(0, array_sum($weights) - 1));
        $prophecy = $rand(1, (int)self::FORTUNES[$key]['prophecies']);

        return [$key, $prophecy];
    }

    /**
     * When a phone may show that its owner was called.
     *
     * @param int $timecreated
     * @param int $position 0 for the first card of the batch
     * @return int a unix time
     */
    public static function reveal_at(int $timecreated, int $position): int {
        return $timecreated + self::REVEAL_INTRO + self::REVEAL_PER_CARD * ($position + 1);
    }

    /**
     * Call people at random.
     *
     * @param stdClass $session
     * @param int $count 1..MAX_COUNT
     * @param bool $onlineonly
     * @param bool $norepeat leave out people already called since the list was last cleared
     * @param int[] $eligible user ids enrolled with the capability to submit
     * @param int $pickedby
     * @return stdClass[] the new pick rows, in the order they are revealed
     * @throws moodle_exception when there is nobody left to call
     */
    public static function pick(stdClass $session, int $count, bool $onlineonly, bool $norepeat,
            array $eligible, int $pickedby): array {
        global $DB;

        $count = max(1, min(self::MAX_COUNT, $count));

        $participants = $DB->get_records('interactiveslide_participant',
            ['sessionid' => (int)$session->id], '', 'id, userid, lastseen');
        $called = $norepeat ? self::called_userids((int)$session->id) : [];

        $pool = self::candidates($participants, $eligible, $called, $onlineonly,
            time() - state::ONLINE_WINDOW);
        if (!$pool) {
            throw new moodle_exception($called ? 'errorallcalled' : 'errornobodytocall', 'mod_interactiveslide');
        }

        $chosen = self::choose($pool, $count);

        $now = time();
        $batch = 1 + (int)$DB->get_field_sql(
            'SELECT MAX(batch) FROM {interactiveslide_pick} WHERE sessionid = ?', [(int)$session->id]);

        $picks = [];
        $transaction = $DB->start_delegated_transaction();
        foreach ($chosen as $position => $userid) {
            $pick = (object)[
                'sessionid' => (int)$session->id,
                'userid' => $userid,
                'batch' => $batch,
                'position' => $position,
                'fortune' => null,
                'prophecy' => 0,
                'fortuneapplied' => 0,
                'cleared' => 0,
                'closed' => 0,
                'pickedby' => $pickedby,
                'timecreated' => $now,
                'timefortune' => 0,
            ];
            $pick->id = $DB->insert_record('interactiveslide_pick', $pick);
            $picks[] = $pick;
        }
        $transaction->allow_commit();

        session_manager::bump((int)$session->id);

        return $picks;
    }

    /**
     * People called since the list was last cleared.
     *
     * @param int $sessionid
     * @return int[]
     */
    public static function called_userids(int $sessionid): array {
        global $DB;

        return array_map('intval', $DB->get_fieldset_select('interactiveslide_pick', 'DISTINCT userid',
            'sessionid = ? AND cleared = 0', [$sessionid]));
    }

    /**
     * Let everyone be called again.
     *
     * The rows stay: they are what the phones of the last call are still showing.
     *
     * @param stdClass $session
     * @return void
     */
    public static function clear(stdClass $session): void {
        global $DB;

        $DB->set_field('interactiveslide_pick', 'cleared', 1, ['sessionid' => (int)$session->id]);
        session_manager::bump((int)$session->id);
    }

    /**
     * Take every open call off the phones: the class has moved on.
     *
     * Called when the presenter changes slide or opens a question. Being called
     * is news for the moment it happens; left up, it covers the answer form of
     * the next question and tells someone to come to the board long after they
     * sat down again. The presenter's own overlay is untouched, and a fortune
     * may still be drawn there; it just no longer reaches the phone.
     *
     * The caller bumps the revision as part of its own change.
     *
     * @param int $sessionid
     * @return void
     */
    public static function close_calls(int $sessionid): void {
        global $DB;

        $DB->set_field_select('interactiveslide_pick', 'closed', 1,
            'sessionid = ? AND closed = 0', [$sessionid]);
    }

    /**
     * One pick of the running session.
     *
     * @param stdClass $session
     * @param int $pickid
     * @return stdClass
     * @throws moodle_exception when the pick belongs to another session
     */
    public static function get_pick(stdClass $session, int $pickid): stdClass {
        global $DB;

        $pick = $DB->get_record('interactiveslide_pick', ['id' => $pickid, 'sessionid' => (int)$session->id]);
        if (!$pick) {
            throw new moodle_exception('errorpicknotfound', 'mod_interactiveslide');
        }

        return $pick;
    }

    /**
     * Draw the fortune card of someone who was called.
     *
     * Drawing twice gives back the first card: a second click, or a second
     * presenter screen, must not reshuffle someone's luck. The one way back to
     * the deck is a card that says so.
     *
     * @param stdClass $session
     * @param stdClass $pick
     * @param bool $redraw the card drawn says to draw again
     * @param bool $canaward whether the caller may hand out stars
     * @param int $by who is drawing
     * @return int stars paid by the card on the spot
     * @throws moodle_exception when a redraw is asked for a card that offers none
     */
    public static function draw_fortune(stdClass $session, stdClass $pick, bool $redraw,
            bool $canaward, int $by): int {
        global $DB;

        $current = (string)$pick->fortune;
        if ($current !== '' && !$redraw) {
            return 0;
        }
        if ($redraw && ($current === '' || empty(self::FORTUNES[$current]['redraw']))) {
            throw new moodle_exception('errornoredraw', 'mod_interactiveslide');
        }

        [$key, $prophecy] = self::draw($redraw ? $current : null);

        $pick->fortune = $key;
        $pick->prophecy = $prophecy;
        $pick->fortuneapplied = 0;
        $pick->timefortune = time();

        $stars = 0;
        $auto = (int)(self::FORTUNES[$key]['auto'] ?? 0);
        if ($auto > 0 && $canaward) {
            $stars = $auto;
            $pick->fortuneapplied = 1;
        }

        $DB->update_record('interactiveslide_pick', (object)[
            'id' => (int)$pick->id,
            'fortune' => $pick->fortune,
            'prophecy' => $pick->prophecy,
            'fortuneapplied' => $pick->fortuneapplied,
            'timefortune' => $pick->timefortune,
        ]);

        if ($stars > 0) {
            session_manager::award_stars($session, (int)$pick->userid, $stars, self::reason($key), $by);
        } else {
            session_manager::bump((int)$session->id);
        }

        return $stars;
    }

    /**
     * Pay what a card promised, once the presenter says it came true.
     *
     * @param stdClass $session
     * @param stdClass $pick
     * @param int $by
     * @return int stars paid; 0 when they had already been paid
     * @throws moodle_exception when the card promises no stars
     */
    public static function apply_fortune(stdClass $session, stdClass $pick, int $by): int {
        global $DB;

        $key = (string)$pick->fortune;
        $stars = (int)(self::FORTUNES[$key]['stars'] ?? 0);
        if ($stars < 1) {
            throw new moodle_exception('errornofortunestars', 'mod_interactiveslide');
        }
        if (!empty($pick->fortuneapplied)) {
            return 0;
        }

        $DB->set_field('interactiveslide_pick', 'fortuneapplied', 1, ['id' => (int)$pick->id]);
        $pick->fortuneapplied = 1;
        session_manager::award_stars($session, (int)$pick->userid, $stars, self::reason($key), $by);

        return $stars;
    }

    /**
     * The reason written against stars a card paid.
     *
     * @param string $key
     * @return string
     */
    private static function reason(string $key): string {
        return get_string('fortunereason', 'mod_interactiveslide',
            get_string('fortune_' . $key, 'mod_interactiveslide'));
    }

    /**
     * A drawn card, in words, for the projector and the phone.
     *
     * @param stdClass $pick
     * @return array|null null until a card is drawn
     */
    public static function export_fortune(stdClass $pick): ?array {
        $key = (string)$pick->fortune;
        if ($key === '' || !isset(self::FORTUNES[$key])) {
            return null;
        }

        $card = self::FORTUNES[$key];
        $prophecy = max(1, min((int)$card['prophecies'], (int)$pick->prophecy));
        $stars = (int)($card['stars'] ?? 0);
        $string = static fn(string $suffix) => get_string('fortune_' . $key . $suffix, 'mod_interactiveslide');

        return [
            'key' => $key,
            'name' => $string(''),
            'arcana' => $string('_arcana'),
            'short' => $string('_short'),
            'effect' => $string('_effect'),
            'prophecy' => $string('_prophecy' . $prophecy),
            'stars' => $stars,
            'auto' => (int)($card['auto'] ?? 0),
            'redraw' => !empty($card['redraw']),
            'action' => $stars > 0 ? $string('_action') : '',
            'applied' => !empty($pick->fortuneapplied),
        ];
    }

    /**
     * The picks of one call, the way the projector draws them.
     *
     * @param stdClass[] $picks rows of one session
     * @param context_module $context
     * @return array[]
     */
    public static function export_picks(array $picks, context_module $context): array {
        global $PAGE;

        if (!$picks) {
            return [];
        }

        $users = userinfo::load(array_map(static fn($pick) => (int)$pick->userid, $picks));

        $out = [];
        foreach ($picks as $pick) {
            $userid = (int)$pick->userid;
            $user = $users[$userid] ?? userinfo::placeholder($userid);
            $rank = leaderboard::get_user_rank((int)$pick->sessionid, $userid);

            // The card is drawn large, so it wants the picture itself rather than
            // the small avatar markup the board uses. Someone with no picture gets
            // their initials on the card instead of the grey silhouette.
            $pictureurl = '';
            if (empty($user->isguest) && !empty($user->picture)) {
                $picture = new \user_picture($user);
                $picture->size = 200;
                $pictureurl = $picture->get_url($PAGE)->out(false);
            }

            $out[] = [
                'id' => (int)$pick->id,
                'userid' => $userid,
                'fullname' => userinfo::display_name($user),
                'pictureurl' => $pictureurl,
                'stars' => $rank['stars'],
                'rank' => $rank['rank'],
                'total' => $rank['total'],
                'position' => (int)$pick->position,
                'fortune' => self::export_fortune($pick),
            ];
        }

        return $out;
    }

    /**
     * What a student's phone needs to know about the latest call.
     *
     * Only the latest call counts, and only until the class moves on: once the
     * presenter has called someone else, changed slide or opened a question,
     * the news is old.
     *
     * @param stdClass $session
     * @param int $userid
     * @return array|null null when the viewer is not part of the latest call, or it is over
     */
    public static function my_call(stdClass $session, int $userid): ?array {
        global $DB;

        if ($userid === 0) {
            return null;
        }

        $batch = (int)$DB->get_field_sql(
            'SELECT MAX(batch) FROM {interactiveslide_pick} WHERE sessionid = ?', [(int)$session->id]);
        if (!$batch) {
            return null;
        }

        $pick = $DB->get_record('interactiveslide_pick',
            ['sessionid' => (int)$session->id, 'batch' => $batch, 'userid' => $userid]);
        if (!$pick || !empty($pick->closed)) {
            return null;
        }

        $size = $DB->count_records('interactiveslide_pick', ['sessionid' => (int)$session->id, 'batch' => $batch]);

        return [
            'id' => (int)$pick->id,
            'revealat' => self::reveal_at((int)$pick->timecreated, (int)$pick->position),
            'others' => max(0, $size - 1),
            'fortune' => self::export_fortune($pick),
            'fortuneat' => $pick->timefortune ? (int)$pick->timefortune + self::FORTUNE_REVEAL : 0,
        ];
    }
}
