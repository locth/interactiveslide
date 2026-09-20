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

namespace mod_interactiveslide\external;

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_multiple_structure;
use core_external\external_single_structure;
use core_external\external_value;
use mod_interactiveslide\local\session_manager;
use moodle_exception;

/**
 * Give the same bonus stars to the whole class, or to a picked group.
 *
 * The picked group is how a teacher makes good on a question they got wrong
 * themselves: open the list of who chose an answer, and credit all of them.
 *
 * @package    mod_interactiveslide
 * @copyright  2026 Interactive Slide contributors
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class award_stars_group extends external_api {

    /**
     * Parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'cmid' => new external_value(PARAM_INT, 'Course module id'),
            'everyone' => new external_value(PARAM_BOOL, 'Everyone in the session; userids is then ignored',
                VALUE_DEFAULT, false),
            'userids' => new external_multiple_structure(
                new external_value(PARAM_INT, 'Recipient'), 'Recipients', VALUE_DEFAULT, []),
            'stars' => new external_value(PARAM_INT, 'Stars each recipient receives'),
            'reason' => new external_value(PARAM_TEXT, 'Why', VALUE_DEFAULT, ''),
        ]);
    }

    /**
     * Award the stars.
     *
     * @param int $cmid
     * @param bool $everyone
     * @param int[] $userids
     * @param int $stars
     * @param string $reason
     * @return array
     * @throws moodle_exception
     */
    public static function execute(int $cmid, bool $everyone, array $userids, int $stars,
            string $reason = ''): array {
        global $DB, $USER;

        $params = self::validate_parameters(self::execute_parameters(), [
            'cmid' => $cmid,
            'everyone' => $everyone,
            'userids' => $userids,
            'stars' => $stars,
            'reason' => $reason,
        ]);

        $resolved = helper::resolve($params['cmid']);
        self::validate_context($resolved['context']);
        require_capability('mod/interactiveslide:awardstars', $resolved['context']);

        $session = helper::require_active_session($resolved['instance']);

        // Unlike a single star, a group award cannot take stars back: a slip of
        // the finger here would cost a whole class at once.
        if ($params['stars'] < 1 || $params['stars'] > session_manager::GROUP_STARS_MAX) {
            throw new moodle_exception('errorgroupstars', 'mod_interactiveslide', '',
                session_manager::GROUP_STARS_MAX);
        }

        $participants = $DB->get_fieldset_select('interactiveslide_participant', 'userid',
            'sessionid = ?', [(int)$session->id]);
        // The same rule a single star follows, checked with one query for the
        // whole class rather than one per student.
        $eligible = array_keys(get_enrolled_users($resolved['context'], 'mod/interactiveslide:submit',
            0, 'u.id'));

        $recipients = session_manager::group_recipients($participants, $params['userids'],
            (bool)$params['everyone'], $eligible);
        if (!$recipients) {
            throw new moodle_exception('errornorecipients', 'mod_interactiveslide');
        }

        $awarded = session_manager::award_stars_many($session, $recipients, $params['stars'],
            $params['reason'], (int)$USER->id);

        foreach ($recipients as $recipient) {
            \mod_interactiveslide\event\stars_awarded::create_from_award(
                $resolved['context'], (int)$session->id, $recipient, $params['stars'])->trigger();
        }

        $response = helper::state_response($resolved['instance'], $resolved['context']);
        $response['awarded'] = $awarded;

        return $response;
    }

    /**
     * Return description.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'revision' => new external_value(PARAM_INT, 'Session revision counter'),
            'state' => new external_value(PARAM_RAW, 'The live state document, JSON encoded'),
            'awarded' => new external_value(PARAM_INT, 'How many people received stars'),
        ]);
    }
}
