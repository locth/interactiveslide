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
 * The live session state machine: sessions, rounds and participants.
 *
 * A session is one lecture. A round is one run of one interaction inside that
 * session. Students may only see and answer an interaction while its round is
 * open and the session is parked on that slide.
 *
 * @package    mod_interactiveslide
 * @copyright  2026 Interactive Slide contributors
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class session_manager {

    /** @var string The session is running. */
    public const STATUS_ACTIVE = 'active';

    /** @var string The session is finished and read only. */
    public const STATUS_ENDED = 'ended';

    /** @var string The round accepts submissions. */
    public const ROUND_OPEN = 'open';

    /** @var string The round no longer accepts submissions. */
    public const ROUND_CLOSED = 'closed';

    /** @var int How stale a participant's lastseen may get before it is rewritten. */
    public const PRESENCE_WRITE_SECONDS = 10;

    /**
     * The active session of a deck, if one is running.
     *
     * @param int $interactiveslideid
     * @return stdClass|null
     */
    public static function get_active_session(int $interactiveslideid): ?stdClass {
        global $DB;

        // Two presenters pressing Start in the same instant can leave two rows
        // active. Picking the newest deterministically means every request in
        // the room agrees on which session that is, rather than each one being
        // handed whatever the database returned first.
        $sessions = $DB->get_records('interactiveslide_session',
            ['interactiveslideid' => $interactiveslideid, 'status' => self::STATUS_ACTIVE],
            'timecreated DESC, id DESC', '*', 0, 1);

        return $sessions ? reset($sessions) : null;
    }

    /**
     * Start a session, ending any other session already running on the deck.
     *
     * Only one session per deck may be active: two presenters driving the same
     * students at once has no sensible meaning.
     *
     * @param stdClass $instance the deck record
     * @param int $userid presenter
     * @param string $name optional label shown in reports
     * @return stdClass the new session record
     */
    public static function start_session(stdClass $instance, int $userid, string $name = ''): stdClass {
        global $DB;

        foreach ($DB->get_records('interactiveslide_session',
                ['interactiveslideid' => $instance->id, 'status' => self::STATUS_ACTIVE]) as $running) {
            self::end_session((int)$running->id);
        }

        $slides = slide_manager::get_slides((int)$instance->id);
        $firstslide = $slides ? reset($slides) : null;

        $session = new stdClass();
        $session->interactiveslideid = (int)$instance->id;
        $session->name = $name !== '' ? \core_text::substr($name, 0, 255) : userdate(time());
        $session->status = self::STATUS_ACTIVE;
        $session->currentslideid = $firstslide ? (int)$firstslide->id : 0;
        $session->joincode = self::generate_joincode();
        $session->createdby = $userid;
        $session->statechanged = 1;
        $session->timecreated = time();
        $session->timeend = 0;
        $session->id = $DB->insert_record('interactiveslide_session', $session);

        return $session;
    }

    /**
     * End a session and close any round still open in it.
     *
     * @param int $sessionid
     * @return void
     */
    public static function end_session(int $sessionid): void {
        global $DB;

        $DB->set_field_select('interactiveslide_round', 'status', self::ROUND_CLOSED,
            'sessionid = ? AND status = ?', [$sessionid, self::ROUND_OPEN]);
        $DB->set_field_select('interactiveslide_round', 'timeclose', time(),
            'sessionid = ? AND timeclose = 0', [$sessionid]);

        $DB->update_record('interactiveslide_session', (object)[
            'id' => $sessionid,
            'status' => self::STATUS_ENDED,
            'timeend' => time(),
        ]);

        self::bump($sessionid);
    }

    /**
     * Delete every session of a deck along with its responses.
     *
     * @param int $interactiveslideid
     * @return void
     */
    public static function delete_all_sessions(int $interactiveslideid): void {
        global $DB;

        $sessionids = $DB->get_fieldset_select('interactiveslide_session', 'id',
            'interactiveslideid = ?', [$interactiveslideid]);

        self::delete_sessions($sessionids);
    }

    /**
     * Delete one session and everything recorded during it.
     *
     * Stars are not stored as a running total anywhere else: every star a
     * student holds is a participant row, a response row or an award row that
     * belongs to a session. Removing the session removes all three, so the stars
     * it paid out disappear from every leaderboard and every report at once,
     * with nothing left to recalculate.
     *
     * @param int $sessionid
     * @return void
     */
    public static function delete_session(int $sessionid): void {
        self::delete_sessions([$sessionid]);
    }

    /**
     * Delete a set of sessions and everything recorded during them.
     *
     * @param int[] $sessionids
     * @return void
     */
    private static function delete_sessions(array $sessionids): void {
        global $DB;

        $sessionids = array_values(array_filter(array_map('intval', $sessionids)));
        if (!$sessionids) {
            return;
        }

        [$insql, $params] = $DB->get_in_or_equal($sessionids);

        $transaction = $DB->start_delegated_transaction();

        $roundids = $DB->get_fieldset_select('interactiveslide_round', 'id', "sessionid $insql", $params);
        if ($roundids) {
            [$roundsql, $roundparams] = $DB->get_in_or_equal($roundids);
            $DB->delete_records_select('interactiveslide_answer', "roundid $roundsql", $roundparams);
            $DB->delete_records_select('interactiveslide_response', "roundid $roundsql", $roundparams);
            $DB->delete_records_select('interactiveslide_round', "id $roundsql", $roundparams);
        }
        $DB->delete_records_select('interactiveslide_award', "sessionid $insql", $params);
        $DB->delete_records_select('interactiveslide_participant', "sessionid $insql", $params);
        $DB->delete_records_select('interactiveslide_session', "id $insql", $params);

        $transaction->allow_commit();
    }

    /**
     * Move the session to a slide.
     *
     * @param stdClass $session
     * @param int $slideid
     * @return void
     * @throws moodle_exception when the slide is not part of this deck
     */
    public static function set_current_slide(stdClass $session, int $slideid): void {
        global $DB;

        if ($slideid > 0 && !$DB->record_exists('interactiveslide_slide',
                ['id' => $slideid, 'interactiveslideid' => $session->interactiveslideid])) {
            throw new moodle_exception('errorslidenotfound', 'mod_interactiveslide');
        }

        if ((int)$session->currentslideid === $slideid) {
            return;
        }

        $DB->set_field('interactiveslide_session', 'currentslideid', $slideid, ['id' => $session->id]);
        $session->currentslideid = $slideid;

        self::bump((int)$session->id);
    }

    /**
     * The round that students on this session are currently looking at.
     *
     * @param stdClass $session
     * @return stdClass|null null when the current slide has no round yet
     */
    public static function get_current_round(stdClass $session): ?stdClass {
        global $DB;

        if (empty($session->currentslideid)) {
            return null;
        }

        $rounds = $DB->get_records('interactiveslide_round',
            ['sessionid' => $session->id, 'slideid' => $session->currentslideid],
            'id DESC', '*', 0, 1);

        return $rounds ? reset($rounds) : null;
    }

    /**
     * Open the round of the session's current slide, creating it on first use.
     *
     * Re-opening an existing round is deliberate: it keeps every response for a
     * slide in one bucket so a student cannot farm stars by asking the teacher
     * to run the same question twice.
     *
     * @param stdClass $session
     * @param int $slideid
     * @return stdClass the round record
     * @throws moodle_exception when the slide has no interaction
     */
    public static function open_round(stdClass $session, int $slideid): stdClass {
        global $DB;

        $interaction = interaction_manager::get_interaction_for_slide($slideid);
        if (!$interaction) {
            throw new moodle_exception('errornointeraction', 'mod_interactiveslide');
        }

        $now = time();
        $round = $DB->get_record('interactiveslide_round',
            ['sessionid' => $session->id, 'slideid' => $slideid], '*', IGNORE_MULTIPLE);

        if ($round) {
            $round->status = self::ROUND_OPEN;
            $round->timeopen = $now;
            $round->timeclose = 0;
            $round->timelimit = (int)$interaction->timerseconds;
            $round->statechanged = (int)$round->statechanged + 1;
            $DB->update_record('interactiveslide_round', $round);
        } else {
            $round = new stdClass();
            $round->sessionid = (int)$session->id;
            $round->interactionid = (int)$interaction->id;
            $round->slideid = $slideid;
            $round->status = self::ROUND_OPEN;
            $round->revealed = 0;
            // Never pushed to phones by default; the teacher decides when the
            // room should see the tally on their own screens.
            $round->showresult = 0;
            $round->timelimit = (int)$interaction->timerseconds;
            $round->timeopen = $now;
            $round->timeclose = 0;
            $round->statechanged = 1;
            $round->id = $DB->insert_record('interactiveslide_round', $round);
        }

        self::bump((int)$session->id);

        return $round;
    }

    /**
     * Stop accepting submissions for a round.
     *
     * @param stdClass $round
     * @return void
     */
    public static function close_round(stdClass $round): void {
        global $DB;

        if ($round->status === self::ROUND_CLOSED) {
            return;
        }

        $DB->update_record('interactiveslide_round', (object)[
            'id' => $round->id,
            'status' => self::ROUND_CLOSED,
            'timeclose' => time(),
            'statechanged' => (int)$round->statechanged + 1,
        ]);

        self::bump((int)$round->sessionid);
    }

    /**
     * Close a round if its timer has run out.
     *
     * Timers are enforced here rather than in the browser so a student cannot
     * gain extra time by pausing their JavaScript.
     *
     * @param stdClass $round
     * @return bool true when this call closed the round
     */
    public static function close_if_expired(stdClass $round): bool {
        if ($round->status !== self::ROUND_OPEN || (int)$round->timelimit <= 0) {
            return false;
        }

        if (time() < (int)$round->timeopen + (int)$round->timelimit) {
            return false;
        }

        self::close_round($round);
        $round->status = self::ROUND_CLOSED;

        return true;
    }

    /**
     * Seconds left on a round's timer.
     *
     * @param stdClass $round
     * @return int|null null when the round has no timer
     */
    public static function seconds_remaining(stdClass $round): ?int {
        if ((int)$round->timelimit <= 0) {
            return null;
        }
        if ($round->status !== self::ROUND_OPEN) {
            return 0;
        }

        return max(0, (int)$round->timeopen + (int)$round->timelimit - time());
    }

    /**
     * Reveal the correct answer of a round to everyone.
     *
     * @param stdClass $round
     * @param bool $revealed
     * @return void
     */
    public static function set_revealed(stdClass $round, bool $revealed): void {
        global $DB;

        $DB->update_record('interactiveslide_round', (object)[
            'id' => $round->id,
            'revealed' => (int)$revealed,
            'statechanged' => (int)$round->statechanged + 1,
        ]);

        self::bump((int)$round->sessionid);
    }

    /**
     * Push or hide the aggregated result on the student screens.
     *
     * @param stdClass $round
     * @param bool $show
     * @return void
     */
    public static function set_showresult(stdClass $round, bool $show): void {
        global $DB;

        $DB->update_record('interactiveslide_round', (object)[
            'id' => $round->id,
            'showresult' => (int)$show,
            'statechanged' => (int)$round->statechanged + 1,
        ]);

        self::bump((int)$round->sessionid);
    }

    /**
     * Throw away everything collected for a round and reopen it.
     *
     * @param stdClass $round
     * @return void
     */
    public static function reset_round(stdClass $round): void {
        global $DB;

        $userids = $DB->get_fieldset_select('interactiveslide_response', 'DISTINCT userid',
            'roundid = ?', [(int)$round->id]);

        $transaction = $DB->start_delegated_transaction();

        $DB->delete_records('interactiveslide_answer', ['roundid' => (int)$round->id]);
        $DB->delete_records('interactiveslide_response', ['roundid' => (int)$round->id]);

        $DB->update_record('interactiveslide_round', (object)[
            'id' => $round->id,
            'status' => self::ROUND_OPEN,
            'revealed' => 0,
            'timeopen' => time(),
            'timeclose' => 0,
            'statechanged' => (int)$round->statechanged + 1,
        ]);

        foreach ($userids as $userid) {
            self::recalculate_participant((int)$round->sessionid, (int)$userid);
        }

        $transaction->allow_commit();

        self::bump((int)$round->sessionid);
    }

    /**
     * Whether this user may still become a participant in a running session.
     *
     * With late joining switched off the door closes when the first question
     * opens, not when the session starts: people trickle into a lecture hall
     * for several minutes and none of that should cost them the session.
     * Anyone already in the room stays in it.
     *
     * @param stdClass $instance the deck record
     * @param stdClass $session
     * @param int $userid
     * @return bool
     */
    public static function can_join(stdClass $instance, stdClass $session, int $userid): bool {
        global $DB;

        if (!empty($instance->allowlatejoin)) {
            return true;
        }

        if ($DB->record_exists('interactiveslide_participant',
                ['sessionid' => $session->id, 'userid' => $userid])) {
            return true;
        }

        return !$DB->record_exists('interactiveslide_round', ['sessionid' => $session->id]);
    }

    /**
     * Record that a user is present in a session, creating their row on first sight.
     *
     * @param stdClass $session
     * @param int $userid
     * @return stdClass the participant record
     */
    public static function touch_participant(stdClass $session, int $userid): stdClass {
        global $DB;

        $now = time();
        $participant = $DB->get_record('interactiveslide_participant',
            ['sessionid' => $session->id, 'userid' => $userid]);

        if ($participant) {
            // This runs on every poll of every student. One write per user per
            // PRESENCE_WRITE_SECONDS is plenty to drive an "online now" count.
            if ($now - (int)$participant->lastseen >= self::PRESENCE_WRITE_SECONDS) {
                $DB->set_field('interactiveslide_participant', 'lastseen', $now, ['id' => $participant->id]);
                $participant->lastseen = $now;
            }
            return $participant;
        }

        // Stars for turning up, paid once when the row is first created.
        $attendance = (int)$DB->get_field('interactiveslide', 'attendancestars',
            ['id' => $session->interactiveslideid]);
        $attendance = max(0, $attendance);

        $participant = new stdClass();
        $participant->sessionid = (int)$session->id;
        $participant->interactiveslideid = (int)$session->interactiveslideid;
        $participant->userid = $userid;
        $participant->totalstars = $attendance;
        $participant->bonusstars = 0;
        $participant->attendancestars = $attendance;
        $participant->correctcount = 0;
        $participant->responsecount = 0;
        $participant->streak = 0;
        $participant->beststreak = 0;
        $participant->timejoined = $now;
        $participant->lastseen = $now;

        try {
            $participant->id = $DB->insert_record('interactiveslide_participant', $participant);
        } catch (\dml_exception $e) {
            // Two polls can race on the first request of a session; the unique
            // index on (sessionid, userid) makes one of them lose harmlessly.
            $participant = $DB->get_record('interactiveslide_participant',
                ['sessionid' => $session->id, 'userid' => $userid], '*', MUST_EXIST);
        }

        return $participant;
    }

    /**
     * Rebuild a participant's totals from their stored responses.
     *
     * Recomputing is cheap at classroom scale and keeps the leaderboard correct
     * after a reset, a regrade or an edited answer key.
     *
     * @param int $sessionid
     * @param int $userid
     * @return void
     */
    public static function recalculate_participant(int $sessionid, int $userid): void {
        global $DB;

        $totals = $DB->get_record_sql(
            'SELECT COALESCE(SUM(stars + bonusstars), 0) AS totalstars,
                    COALESCE(SUM(iscorrect), 0) AS correctcount,
                    COUNT(id) AS responsecount
               FROM {interactiveslide_response}
              WHERE sessionid = :sessionid AND userid = :userid',
            ['sessionid' => $sessionid, 'userid' => $userid]
        );

        $awarded = (int)$DB->get_field_sql(
            'SELECT COALESCE(SUM(stars), 0) FROM {interactiveslide_award} WHERE sessionid = ? AND userid = ?',
            [$sessionid, $userid]
        );

        $participant = $DB->get_record('interactiveslide_participant',
            ['sessionid' => $sessionid, 'userid' => $userid]);
        if (!$participant) {
            return;
        }

        $streak = self::calculate_streak($sessionid, $userid);

        $DB->update_record('interactiveslide_participant', (object)[
            'id' => $participant->id,
            // totalstars is what the leaderboard ranks on, so everything a
            // student has earned lives inside it; the other columns keep each
            // source visible on its own for the reports.
            'totalstars' => (int)$totals->totalstars + $awarded + (int)$participant->attendancestars,
            'bonusstars' => $awarded,
            'correctcount' => (int)$totals->correctcount,
            'responsecount' => (int)$totals->responsecount,
            'streak' => $streak['current'],
            'beststreak' => $streak['best'],
        ]);
    }

    /**
     * Current and best run of consecutive correct answers in a session.
     *
     * @param int $sessionid
     * @param int $userid
     * @return array{current: int, best: int}
     */
    private static function calculate_streak(int $sessionid, int $userid): array {
        global $DB;

        $flags = $DB->get_fieldset_sql(
            'SELECT iscorrect
               FROM {interactiveslide_response}
              WHERE sessionid = :sessionid AND userid = :userid
           ORDER BY timecreated ASC, id ASC',
            ['sessionid' => $sessionid, 'userid' => $userid]
        );

        $current = 0;
        $best = 0;
        foreach ($flags as $flag) {
            if ((int)$flag === 1) {
                $current++;
                $best = max($best, $current);
            } else {
                $current = 0;
            }
        }

        return ['current' => $current, 'best' => $best];
    }

    /**
     * Give a student bonus stars by hand.
     *
     * @param stdClass $session
     * @param int $userid recipient
     * @param int $stars may be negative to take stars back
     * @param string $reason
     * @param int $awardedby
     * @return void
     */
    public static function award_stars(stdClass $session, int $userid, int $stars, string $reason, int $awardedby): void {
        global $DB;

        $stars = max(-100, min(100, $stars));
        if ($stars === 0) {
            return;
        }

        self::touch_participant($session, $userid);

        $DB->insert_record('interactiveslide_award', (object)[
            'sessionid' => (int)$session->id,
            'userid' => $userid,
            'stars' => $stars,
            'reason' => \core_text::substr(clean_param($reason, PARAM_TEXT), 0, 255),
            'awardedby' => $awardedby,
            'timecreated' => time(),
        ]);

        self::recalculate_participant((int)$session->id, $userid);
        self::bump((int)$session->id);
    }

    /**
     * Bump the session revision so pollers notice something changed.
     *
     * @param int $sessionid
     * @return void
     */
    public static function bump(int $sessionid): void {
        global $DB;

        $DB->execute('UPDATE {interactiveslide_session} SET statechanged = statechanged + 1 WHERE id = ?',
            [$sessionid]);
    }

    /**
     * The revision a poller should compare against, without building the whole
     * state document.
     *
     * A classroom spends most of its time with nothing changing, and every
     * student is asking this question every couple of seconds. Answering it with
     * two queries instead of fifteen is the difference between a lecture hall
     * that works and one that does not.
     *
     * @param stdClass $instance the deck record
     * @param context_module $context
     * @param int $userid the polling user
     * @return int 0 when no session is running
     */
    public static function poll_revision(stdClass $instance, \context_module $context, int $userid): int {
        $session = self::get_active_session((int)$instance->id);
        if (!$session) {
            return 0;
        }

        // Timers have to expire even when nobody is looking at the presenter.
        $round = self::get_current_round($session);
        if ($round && self::close_if_expired($round)) {
            $session->statechanged = self::get_revision((int)$session->id);
        }

        if (has_capability('mod/interactiveslide:submit', $context)
                && self::can_join($instance, $session, $userid)) {
            self::touch_participant($session, $userid);
        }

        return (int)$session->statechanged;
    }

    /**
     * The revision numbers a poller compares against.
     *
     * @param int $sessionid
     * @return int
     */
    public static function get_revision(int $sessionid): int {
        global $DB;

        return (int)$DB->get_field('interactiveslide_session', 'statechanged', ['id' => $sessionid]);
    }

    /**
     * Generate a short human readable join code.
     *
     * @return string
     */
    private static function generate_joincode(): string {
        global $DB;

        // No 0/O/1/I: these are read aloud and typed from the back of a lecture hall.
        $alphabet = '23456789ABCDEFGHJKLMNPQRSTUVWXYZ';

        for ($attempt = 0; $attempt < 20; $attempt++) {
            $code = '';
            for ($i = 0; $i < 6; $i++) {
                $code .= $alphabet[random_int(0, strlen($alphabet) - 1)];
            }
            if (!$DB->record_exists('interactiveslide_session', ['joincode' => $code, 'status' => self::STATUS_ACTIVE])) {
                return $code;
            }
        }

        return strtoupper(substr(md5(uniqid('', true)), 0, 6));
    }
}
