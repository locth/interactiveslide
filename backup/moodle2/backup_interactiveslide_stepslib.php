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

/**
 * Backup structure for mod_interactiveslide.
 *
 * @package    mod_interactiveslide
 * @copyright  2026 Interactive Slide contributors
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

/**
 * Defines the complete backup tree of one activity instance.
 *
 * @package    mod_interactiveslide
 * @copyright  2026 Interactive Slide contributors
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class backup_interactiveslide_activity_structure_step extends backup_activity_structure_step {

    /**
     * Build the structure.
     *
     * @return backup_nested_element
     */
    protected function define_structure() {
        $userinfo = $this->get_setting_value('userinfo');

        $interactiveslide = new backup_nested_element('interactiveslide', ['id'], [
            'name', 'intro', 'introformat', 'grade', 'grademethod', 'showleaderboard',
            'leaderboardsize', 'allowlatejoin', 'speedbonus', 'speedbonusmax',
            'attendancestars', 'anonymousresults', 'pdffilename', 'timecreated', 'timemodified',
        ]);

        $slides = new backup_nested_element('slides');
        $slide = new backup_nested_element('slide', ['id'], [
            'sortorder', 'title', 'pageno', 'imagefilename', 'imagewidth', 'imageheight',
            'timecreated', 'timemodified',
        ]);

        $interactions = new backup_nested_element('interactions');
        $interaction = new backup_nested_element('interaction', ['id'], [
            'qtype', 'questiontext', 'hasanswer', 'difficulty', 'points', 'timerseconds',
            'autoclose', 'showliveresult', 'showleaderboard', 'allowmultiple', 'shuffleoptions',
            'maxentries', 'maxwordlength', 'casesensitive', 'videourl', 'allowretry',
            'timecreated', 'timemodified',
        ]);

        $options = new backup_nested_element('options');
        $option = new backup_nested_element('option', ['id'], ['blankid', 'sortorder', 'optiontext', 'iscorrect']);

        $blanks = new backup_nested_element('blanks');
        $blank = new backup_nested_element('blank', ['id'], [
            'sortorder', 'label', 'answers', 'points', 'difficulty', 'casesensitive',
        ]);

        $sessions = new backup_nested_element('sessions');
        $session = new backup_nested_element('session', ['id'], [
            'name', 'status', 'currentslideid', 'joincode', 'createdby', 'statechanged',
            'timecreated', 'timeend',
        ]);

        $rounds = new backup_nested_element('rounds');
        $round = new backup_nested_element('round', ['id'], [
            'interactionid', 'slideid', 'status', 'revealed', 'showresult', 'timelimit',
            'timeopen', 'timeclose', 'statechanged',
        ]);

        $responses = new backup_nested_element('responses');
        $response = new backup_nested_element('response', ['id'], [
            'userid', 'iscorrect', 'stars', 'bonusstars', 'timetaken', 'timecreated', 'timemodified',
        ]);

        $answers = new backup_nested_element('answers');
        $answer = new backup_nested_element('answer', ['id'], [
            'blankid', 'optionid', 'answertext', 'normtext', 'iscorrect', 'stars',
        ]);

        $participants = new backup_nested_element('participants');
        $participant = new backup_nested_element('participant', ['id'], [
            'userid', 'totalstars', 'bonusstars', 'attendancestars', 'correctcount', 'responsecount',
            'streak', 'beststreak', 'timejoined', 'lastseen',
        ]);

        $awards = new backup_nested_element('awards');
        $award = new backup_nested_element('award', ['id'], [
            'userid', 'stars', 'reason', 'awardedby', 'timecreated',
        ]);

        // Tree.
        $interactiveslide->add_child($slides);
        $slides->add_child($slide);

        $slide->add_child($interactions);
        $interactions->add_child($interaction);

        // Blanks first: a dropdown's choices point back at the position they
        // belong to, and restore can only remap that link once the position it
        // names has been written.
        $interaction->add_child($blanks);
        $blanks->add_child($blank);

        $interaction->add_child($options);
        $options->add_child($option);

        $interactiveslide->add_child($sessions);
        $sessions->add_child($session);

        $session->add_child($rounds);
        $rounds->add_child($round);

        $round->add_child($responses);
        $responses->add_child($response);

        $response->add_child($answers);
        $answers->add_child($answer);

        $session->add_child($participants);
        $participants->add_child($participant);

        $session->add_child($awards);
        $awards->add_child($award);

        // Sources.
        $interactiveslide->set_source_table('interactiveslide', ['id' => backup::VAR_ACTIVITYID]);
        $slide->set_source_table('interactiveslide_slide',
            ['interactiveslideid' => backup::VAR_PARENTID], 'sortorder ASC, id ASC');
        $interaction->set_source_table('interactiveslide_interaction',
            ['slideid' => backup::VAR_PARENTID], 'id ASC');
        $option->set_source_table('interactiveslide_option',
            ['interactionid' => backup::VAR_PARENTID], 'sortorder ASC, id ASC');
        $blank->set_source_table('interactiveslide_blank',
            ['interactionid' => backup::VAR_PARENTID], 'sortorder ASC, id ASC');

        // Sessions hold who answered what, so they only travel with user data.
        if ($userinfo) {
            $session->set_source_table('interactiveslide_session',
                ['interactiveslideid' => backup::VAR_PARENTID], 'id ASC');
            $round->set_source_table('interactiveslide_round',
                ['sessionid' => backup::VAR_PARENTID], 'id ASC');
            $response->set_source_table('interactiveslide_response',
                ['roundid' => backup::VAR_PARENTID], 'id ASC');
            $answer->set_source_table('interactiveslide_answer',
                ['responseid' => backup::VAR_PARENTID], 'id ASC');
            $participant->set_source_table('interactiveslide_participant',
                ['sessionid' => backup::VAR_PARENTID], 'id ASC');
            $award->set_source_table('interactiveslide_award',
                ['sessionid' => backup::VAR_PARENTID], 'id ASC');
        }

        // Id annotations.
        $session->annotate_ids('user', 'createdby');
        $response->annotate_ids('user', 'userid');
        $participant->annotate_ids('user', 'userid');
        $award->annotate_ids('user', 'userid');
        $award->annotate_ids('user', 'awardedby');

        // File annotations.
        $interactiveslide->annotate_files('mod_interactiveslide', 'intro', null);
        $interactiveslide->annotate_files('mod_interactiveslide', 'sourcepdf', null);
        $slide->annotate_files('mod_interactiveslide', 'slideimage', 'id');

        return $this->prepare_activity_structure($interactiveslide);
    }
}
