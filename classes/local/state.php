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
 * Builds the single state document that both polling clients consume.
 *
 * Presenter and student receive the same shape so one JavaScript renderer can
 * drive both screens; the difference is which fields are populated and what the
 * student is not allowed to know yet.
 *
 * @package    mod_interactiveslide
 * @copyright  2026 Interactive Slide contributors
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class state {

    /** @var int Someone polling within this many seconds counts as present. */
    public const ONLINE_WINDOW = 25;

    /** @var int How many rows the presenter's leaderboard carries. */
    public const PRESENTER_BOARD_LIMIT = 50;

    /**
     * Assemble the state for one viewer.
     *
     * @param stdClass $instance the deck record
     * @param context_module $context
     * @param int $userid the viewer
     * @param bool $ispresenter whether the viewer drives the session
     * @return array
     */
    public static function build(stdClass $instance, context_module $context, int $userid, bool $ispresenter): array {
        global $DB;

        $session = session_manager::get_active_session((int)$instance->id);

        // The presenter always sees the deck: their screen is the projector.
        // Withholding the image from students is a bandwidth decision, and it is
        // taken here rather than in the browser so the URL never reaches the page
        // at all. Hiding it with CSS would still cost every student the download.
        $hideslides = !$ispresenter && !settings::student_slides_visible();

        $payload = [
            'servertime' => time(),
            'revision' => 0,
            'hassession' => false,
            'sessionid' => 0,
            'sessionname' => '',
            'joincode' => '',
            'sessionstatus' => '',
            'slidecount' => $DB->count_records('interactiveslide_slide', ['interactiveslideid' => $instance->id]),
            'slide' => null,
            'round' => null,
            'interaction' => null,
            'results' => null,
            'leaderboard' => [],
            'leaderboardvisible' => false,
            'myrank' => null,
            'myresponse' => null,
            'participantcount' => 0,
            'onlinecount' => 0,
            'canpresent' => $ispresenter,
            'canaward' => has_capability('mod/interactiveslide:awardstars', $context),
            'myuserid' => $userid,
            'slidehidden' => $hideslides,
        ];

        if (!$session) {
            return $payload;
        }

        $payload['hassession'] = true;
        $payload['sessionid'] = (int)$session->id;
        $payload['sessionname'] = (string)$session->name;
        $payload['sessionstatus'] = (string)$session->status;
        $payload['revision'] = (int)$session->statechanged;
        // The join code is a shortcut for the room, not a secret from students.
        $payload['joincode'] = (string)$session->joincode;

        if (has_capability('mod/interactiveslide:submit', $context)
                && session_manager::can_join($instance, $session, $userid)) {
            session_manager::touch_participant($session, $userid);
        }

        $payload['participantcount'] = $DB->count_records('interactiveslide_participant',
            ['sessionid' => $session->id]);
        $payload['onlinecount'] = $DB->count_records_select('interactiveslide_participant',
            'sessionid = :sessionid AND lastseen >= :cutoff',
            ['sessionid' => $session->id, 'cutoff' => time() - self::ONLINE_WINDOW]);

        $slide = null;
        if (!empty($session->currentslideid)) {
            $slide = $DB->get_record('interactiveslide_slide', ['id' => $session->currentslideid]);
        }

        if ($slide) {
            $payload['slide'] = self::export_slide($context, $slide, (int)$instance->id, $hideslides);
        }

        $round = session_manager::get_current_round($session);
        if ($round) {
            // Enforce the timer on read: nobody has to be watching for it to expire.
            if (session_manager::close_if_expired($round)) {
                $round = $DB->get_record('interactiveslide_round', ['id' => $round->id]);
                $session->statechanged = session_manager::get_revision((int)$session->id);
                $payload['revision'] = (int)$session->statechanged;
            }

            $interaction = interaction_manager::get_interaction($round->interactionid);
            if ($interaction) {
                $payload = self::add_round($payload, $round, $interaction, $userid, $ispresenter);
            }
        }

        $payload = self::add_leaderboard($payload, $instance, $context, $session, $userid, $ispresenter);

        return $payload;
    }

    /**
     * Add everything that describes the round in progress.
     *
     * @param array $payload
     * @param stdClass $round
     * @param stdClass $interaction
     * @param int $userid
     * @param bool $ispresenter
     * @return array
     */
    private static function add_round(array $payload, stdClass $round, stdClass $interaction,
            int $userid, bool $ispresenter): array {

        $revealed = (bool)$round->revealed;
        $closed = $round->status === session_manager::ROUND_CLOSED;

        $payload['round'] = [
            'id' => (int)$round->id,
            'status' => (string)$round->status,
            'revealed' => (int)$round->revealed,
            'showresult' => (int)$round->showresult,
            'timelimit' => (int)$round->timelimit,
            'timeopen' => (int)$round->timeopen,
            'secondsleft' => session_manager::seconds_remaining($round),
            'responsecount' => aggregator::response_count((int)$round->id),
        ];

        // The answer key is withheld until the reveal from everyone, the
        // presenter included: their screen is the projector the room is reading,
        // so an early answer there is an early answer for the whole class.
        $payload['interaction'] = interaction_manager::export_for_student($interaction, $revealed);

        if ($ispresenter) {
            // Fill in the blanks puts other students' typed answers on the
            // projector, so they stay off it until collecting has stopped no
            // matter what the question asked for. A word cloud growing live is
            // the point of a word cloud, and a choice tally gives nothing away.
            $leaksanswers = $interaction->qtype === interaction_manager::TYPE_FILLBLANK;

            $showresults = $revealed
                || $closed
                || (!$leaksanswers && (int)$interaction->showliveresult === 1);
        } else {
            // One switch, always meaningful: the two transport buttons decide
            // whether phones carry the result, and revealing the answer does not
            // quietly override them. A student still learns whether they
            // personally were right from their own response.
            $showresults = (int)$round->showresult === 1;
        }

        if ($showresults) {
            // Only the presenter is told who wrote what, so they can hand a star
            // to the student who gave a good answer.
            $payload['results'] = aggregator::get_results($interaction, $round, $revealed, $ispresenter);
        }

        if (!$ispresenter) {
            $payload['myresponse'] = self::export_my_response($round, $interaction, $userid, $revealed);
        }

        return $payload;
    }

    /**
     * What the current user has already submitted for a round.
     *
     * @param stdClass $round
     * @param stdClass $interaction
     * @param int $userid
     * @param bool $revealed
     * @return array|null null when they have not answered yet
     */
    private static function export_my_response(stdClass $round, stdClass $interaction,
            int $userid, bool $revealed): ?array {
        global $DB;

        $response = $DB->get_record('interactiveslide_response',
            ['roundid' => $round->id, 'userid' => $userid]);
        if (!$response) {
            return null;
        }

        $answers = $DB->get_records('interactiveslide_answer', ['responseid' => $response->id], 'id ASC');

        $parts = [];
        foreach ($answers as $answer) {
            $parts[] = [
                'blankid' => (int)$answer->blankid,
                'optionid' => (int)$answer->optionid,
                'text' => (string)$answer->answertext,
                // Telling a student "wrong" before the reveal spoils the round for the room.
                'iscorrect' => $revealed ? (int)$answer->iscorrect : 0,
            ];
        }

        return [
            'submitted' => 1,
            'iscorrect' => $revealed ? (int)$response->iscorrect : 0,
            'stars' => $revealed ? (int)$response->stars + (int)$response->bonusstars : 0,
            'canchange' => (int)(!empty($interaction->allowretry)
                && $round->status === session_manager::ROUND_OPEN),
            'answers' => $parts,
        ];
    }

    /**
     * Add the leaderboard and the viewer's own standing.
     *
     * @param array $payload
     * @param stdClass $instance
     * @param context_module $context
     * @param stdClass $session
     * @param int $userid
     * @param bool $ispresenter
     * @return array
     */
    private static function add_leaderboard(array $payload, stdClass $instance, context_module $context,
            stdClass $session, int $userid, bool $ispresenter): array {

        $visibility = (int)$instance->showleaderboard;
        $canseeboard = leaderboard::can_see_full_board($instance, $context);

        $payload['leaderboardvisible'] = $canseeboard;

        if ($canseeboard) {
            // The presenter's board is rebuilt on every poll and every row renders
            // an avatar, so it is capped rather than unbounded. It still reaches
            // far enough down the room to hand stars to anyone who earned them.
            $limit = $ispresenter
                ? self::PRESENTER_BOARD_LIMIT
                : max(3, (int)$instance->leaderboardsize);
            $payload['leaderboard'] = leaderboard::get_session_board(
                (int)$session->id,
                $context,
                $limit,
                !$ispresenter && !empty($instance->anonymousresults),
                $userid
            );
        }

        if (!$ispresenter && $visibility !== leaderboard::VISIBILITY_TEACHER) {
            $payload['myrank'] = leaderboard::get_user_rank((int)$session->id, $userid);
        } else if (!$ispresenter) {
            // Even with the board hidden a student should still see their own stars.
            $rank = leaderboard::get_user_rank((int)$session->id, $userid);
            $payload['myrank'] = ['rank' => 0, 'stars' => $rank['stars'], 'total' => 0];
        }

        return $payload;
    }

    /**
     * Shape one slide for the client.
     *
     * @param context_module $context
     * @param stdClass $slide
     * @param int $interactiveslideid
     * @param bool $hideimage omit the picture, keeping the position and the flags
     * @return array
     */
    public static function export_slide(context_module $context, stdClass $slide,
            int $interactiveslideid, bool $hideimage = false): array {
        global $DB;

        $position = $DB->count_records_select('interactiveslide_slide',
            'interactiveslideid = :instanceid AND (sortorder < :sortorder OR (sortorder = :samesort AND id < :id))',
            [
                'instanceid' => $interactiveslideid,
                'sortorder' => (int)$slide->sortorder,
                'samesort' => (int)$slide->sortorder,
                'id' => (int)$slide->id,
            ]);

        return [
            'id' => (int)$slide->id,
            'index' => (int)$position,
            'title' => (string)$slide->title,
            'imageurl' => $hideimage ? '' : slide_manager::get_image_url($context, $slide),
            'width' => (int)$slide->imagewidth,
            'height' => (int)$slide->imageheight,
            'hasinteraction' => (int)$DB->record_exists('interactiveslide_interaction', ['slideid' => $slide->id]),
        ];
    }
}
