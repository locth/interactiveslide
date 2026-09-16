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
use moodle_url;
use stdClass;

/**
 * People taking part through the course's guest access, with no account of their own.
 *
 * Everyone who logs in as a guest is the same Moodle user, so that account cannot
 * be what a star is recorded against: the second guest to answer would overwrite
 * the first. Each guest instead gets a row of their own for the length of one
 * session, and is recorded everywhere a student is recorded under the negative of
 * that row's id. Every uniqueness rule, recalculation and ranking that already
 * holds for students then holds for guests with no second code path, and anything
 * that mistakes a guest for a Moodle user finds no such user rather than handing
 * the stars to the shared guest account.
 *
 * Which guest a browser is lives in its Moodle session, which the browser cannot
 * write to. The link that lets guests in carries a token belonging to one session,
 * so last week's link opens nothing.
 *
 * @package    mod_interactiveslide
 * @copyright  2026 Interactive Slide contributors
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class guest {

    /** @var int Longest name a guest may give, in characters. */
    public const NAME_MAX = 40;

    /** @var int Length of the token in a guest link. */
    public const TOKEN_LENGTH = 20;

    /**
     * Whether guests may take part in this activity.
     *
     * Two switches, and both must be on: the site's, because this opens a way to
     * write data to people without an account, and the activity's, because only
     * the teacher knows whether this lecture is one to invite visitors to.
     *
     * @param stdClass $instance
     * @return bool
     */
    public static function enabled(stdClass $instance): bool {
        return !empty($instance->allowguests) && settings::guests_allowed_on_site();
    }

    /**
     * Whether a participant id stands for a guest rather than a Moodle user.
     *
     * @param int $userid
     * @return bool
     */
    public static function is_guest_userid(int $userid): bool {
        return $userid < 0;
    }

    /**
     * The participant id a guest is recorded under.
     *
     * @param int $guestid
     * @return int
     */
    public static function userid(int $guestid): int {
        return -abs($guestid);
    }

    /**
     * The guest row behind a participant id, or 0 for a Moodle user.
     *
     * @param int $userid
     * @return int
     */
    public static function guestid(int $userid): int {
        return $userid < 0 ? -$userid : 0;
    }

    /**
     * A fresh token for a session's guest link.
     *
     * @return string
     */
    public static function new_token(): string {
        return random_string(self::TOKEN_LENGTH);
    }

    /**
     * The token of a session's guest link, creating it for a session that has none.
     *
     * Sessions get their token when they start. This covers a session already
     * running when the plugin was upgraded. The write only lands when the column
     * is still empty, and the value is read back, so two presenter screens asking
     * at the same moment agree on one link rather than each showing its own.
     *
     * @param stdClass $session
     * @return string
     */
    public static function token(stdClass $session): string {
        global $DB;

        if (!empty($session->guesttoken)) {
            return (string)$session->guesttoken;
        }

        $DB->set_field_select('interactiveslide_session', 'guesttoken', self::new_token(),
            'id = ? AND guesttoken IS NULL', [(int)$session->id]);
        $session->guesttoken = (string)$DB->get_field('interactiveslide_session', 'guesttoken',
            ['id' => (int)$session->id]);

        return $session->guesttoken;
    }

    /**
     * The link a guest opens, or scans, to join a session.
     *
     * @param int $cmid
     * @param stdClass $session
     * @return moodle_url
     */
    public static function join_url(int $cmid, stdClass $session): moodle_url {
        return new moodle_url('/mod/interactiveslide/view.php', ['id' => $cmid, 'guest' => self::token($session)]);
    }

    /**
     * Remember that this browser opened a session's guest link.
     *
     * @param stdClass $instance
     * @param stdClass|null $session the session running now
     * @param string $token from the link
     * @return bool whether the link was the running session's
     */
    public static function accept_link(stdClass $instance, ?stdClass $session, string $token): bool {
        global $SESSION;

        if (!$session || $token === '' || !self::enabled($instance)) {
            return false;
        }

        $expected = (string)($session->guesttoken ?? '');
        if ($expected === '' || !hash_equals($expected, $token)) {
            return false;
        }

        if (!isset($SESSION->interactiveslide_guestpass) || !is_array($SESSION->interactiveslide_guestpass)) {
            $SESSION->interactiveslide_guestpass = [];
        }
        $SESSION->interactiveslide_guestpass[(int)$session->id] = true;

        return true;
    }

    /**
     * Whether this browser opened the guest link of a session.
     *
     * @param stdClass $session
     * @return bool
     */
    public static function has_pass(stdClass $session): bool {
        global $SESSION;

        return !empty($SESSION->interactiveslide_guestpass[(int)$session->id]);
    }

    /**
     * The guest this browser is in a session, if it has joined as one.
     *
     * @param stdClass $session
     * @return stdClass|null the guest row, which may be marked removed
     */
    public static function current(stdClass $session): ?stdClass {
        global $DB, $SESSION;

        $guestid = (int)($SESSION->interactiveslide_guestid[(int)$session->id] ?? 0);
        if ($guestid <= 0) {
            return null;
        }

        // Looked up rather than trusted: the session may have been deleted and
        // its guests with it, which has to read as "not joined" and not as a
        // participant id nobody holds any more.
        $guest = $DB->get_record('interactiveslide_guest', ['id' => $guestid, 'sessionid' => (int)$session->id]);

        return $guest ?: null;
    }

    /**
     * Tidy a name a visitor typed into one that is safe everywhere it will appear.
     *
     * It is shown on the projector, in the teacher's report and in a spreadsheet
     * the teacher downloads, and it was typed by somebody with no account. Every
     * page escapes it; angle brackets are removed anyway, because a name never
     * needs them. A leading =, +, - or @ is removed because a spreadsheet reads
     * a cell that starts with one as a formula.
     *
     * @param string $name
     * @return string empty when nothing usable was given
     */
    public static function clean_name(string $name): string {
        // Tags go as tags first, so <b>Lan</b> is Lan and not bLan/b; whatever
        // bracket is left after that goes on its own.
        $name = strip_tags(text_util::clean_plain($name));
        $name = str_replace(['<', '>'], '', $name);
        $name = preg_replace('/[\p{Z}\s]+/u', ' ', $name);
        $name = preg_replace('/^[\s=+\-@]+/u', '', (string)$name);
        $name = trim((string)$name);

        return trim(\core_text::substr($name, 0, self::NAME_MAX));
    }

    /**
     * A name no other guest in the session is already using.
     *
     * Two guests called Lan are two people, and a board with two identical rows
     * cannot tell either of them which one they are.
     *
     * @param int $sessionid
     * @param string $name already cleaned
     * @return string
     */
    public static function unique_name(int $sessionid, string $name): string {
        global $DB;

        $taken = [];
        foreach ($DB->get_fieldset_select('interactiveslide_guest', 'displayname',
                'sessionid = ? AND removed = 0', [$sessionid]) as $existing) {
            $taken[\core_text::strtolower((string)$existing)] = true;
        }

        if (!isset($taken[\core_text::strtolower($name)])) {
            return $name;
        }

        for ($n = 2; ; $n++) {
            $suffix = ' (' . $n . ')';
            $stem = trim(\core_text::substr($name, 0, self::NAME_MAX - \core_text::strlen($suffix)));
            $candidate = $stem . $suffix;
            if (!isset($taken[\core_text::strtolower($candidate)])) {
                return $candidate;
            }
        }
    }

    /**
     * Join a session as a guest under a name.
     *
     * @param stdClass $session
     * @param string $name as typed
     * @return stdClass the guest row
     * @throws moodle_exception when the name is empty once cleaned
     */
    public static function create(stdClass $session, string $name): stdClass {
        global $DB, $SESSION;

        $clean = self::clean_name($name);
        if ($clean === '') {
            throw new moodle_exception('errorguestname', 'mod_interactiveslide');
        }

        $guest = new stdClass();
        $guest->sessionid = (int)$session->id;
        $guest->displayname = self::unique_name((int)$session->id, $clean);
        $guest->removed = 0;
        $guest->timecreated = time();
        $guest->id = (int)$DB->insert_record('interactiveslide_guest', $guest);

        if (!isset($SESSION->interactiveslide_guestid) || !is_array($SESSION->interactiveslide_guestid)) {
            $SESSION->interactiveslide_guestid = [];
        }
        $SESSION->interactiveslide_guestid[(int)$session->id] = $guest->id;

        return $guest;
    }

    /**
     * Take a guest out of a session, with everything they wrote in it.
     *
     * For the name that should never have gone up on a projector, and for the
     * word cloud it came with: their answers are deleted, so every tally drops
     * them on the next poll. The row itself stays, blanked and marked, so the
     * browser that was that guest is told it was removed instead of being handed
     * the name form again.
     *
     * @param stdClass $session
     * @param int $guestid
     * @return void
     * @throws moodle_exception when the guest is not in this session
     */
    public static function remove(stdClass $session, int $guestid): void {
        global $DB;

        if (!$DB->record_exists('interactiveslide_guest', ['id' => $guestid, 'sessionid' => (int)$session->id])) {
            throw new moodle_exception('errornotparticipant', 'mod_interactiveslide');
        }

        $userid = self::userid($guestid);
        $transaction = $DB->start_delegated_transaction();

        $responseids = $DB->get_fieldset_select('interactiveslide_response', 'id',
            'sessionid = ? AND userid = ?', [(int)$session->id, $userid]);
        if ($responseids) {
            [$insql, $params] = $DB->get_in_or_equal($responseids);
            $DB->delete_records_select('interactiveslide_answer', "responseid $insql", $params);
            $DB->delete_records_select('interactiveslide_response', "id $insql", $params);
        }

        $DB->delete_records('interactiveslide_award', ['sessionid' => (int)$session->id, 'userid' => $userid]);
        $DB->delete_records('interactiveslide_participant', ['sessionid' => (int)$session->id, 'userid' => $userid]);

        // The name is what got them removed, so it does not stay in the database either.
        $DB->update_record('interactiveslide_guest', (object)[
            'id' => $guestid,
            'displayname' => '',
            'removed' => 1,
        ]);

        $transaction->allow_commit();

        session_manager::bump((int)$session->id);
    }

    /**
     * The guest rows behind a set of guest ids.
     *
     * @param int[] $guestids
     * @return stdClass[] keyed by guest id
     */
    public static function load(array $guestids): array {
        global $DB;

        $guestids = array_values(array_unique(array_filter(array_map('intval', $guestids),
            static fn($id) => $id > 0)));
        if (!$guestids) {
            return [];
        }

        return $DB->get_records_list('interactiveslide_guest', 'id', $guestids, '',
            'id, sessionid, displayname, removed');
    }

    /**
     * What would stop a visitor from reaching the link at all, if anything.
     *
     * The plugin cannot let anyone into a course. A guest link is only worth
     * handing out when the course has guest access switched on and the site lets
     * visitors log in as a guest; otherwise the phones in the room land on a login
     * page, and the teacher should know that before putting the code on screen.
     *
     * @param int $courseid
     * @return string a language string key, or '' when nothing is in the way
     */
    public static function access_problem(int $courseid): string {
        global $CFG;

        $courseguests = false;
        foreach (enrol_get_instances($courseid, true) as $enrol) {
            if ($enrol->enrol === 'guest') {
                $courseguests = true;
                break;
            }
        }
        if (!$courseguests) {
            return 'guestwarningcourse';
        }

        if (empty($CFG->guestloginbutton) && empty($CFG->autologinguests)) {
            return 'guestwarninglogin';
        }

        return '';
    }
}
