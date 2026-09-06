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
 * Restore structure for mod_interactiveslide.
 *
 * @package    mod_interactiveslide
 * @copyright  2026 Interactive Slide contributors
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

/**
 * Reads one activity instance back out of a backup file.
 *
 * @package    mod_interactiveslide
 * @copyright  2026 Interactive Slide contributors
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class restore_interactiveslide_activity_structure_step extends restore_activity_structure_step {

    /**
     * Declare the paths this step handles.
     *
     * @return array
     */
    protected function define_structure() {
        $paths = [];
        $userinfo = $this->get_setting_value('userinfo');

        $paths[] = new restore_path_element('interactiveslide', '/activity/interactiveslide');
        $paths[] = new restore_path_element('interactiveslide_slide',
            '/activity/interactiveslide/slides/slide');
        $paths[] = new restore_path_element('interactiveslide_interaction',
            '/activity/interactiveslide/slides/slide/interactions/interaction');
        $paths[] = new restore_path_element('interactiveslide_option',
            '/activity/interactiveslide/slides/slide/interactions/interaction/options/option');
        $paths[] = new restore_path_element('interactiveslide_blank',
            '/activity/interactiveslide/slides/slide/interactions/interaction/blanks/blank');

        if ($userinfo) {
            $paths[] = new restore_path_element('interactiveslide_session',
                '/activity/interactiveslide/sessions/session');
            $paths[] = new restore_path_element('interactiveslide_round',
                '/activity/interactiveslide/sessions/session/rounds/round');
            $paths[] = new restore_path_element('interactiveslide_response',
                '/activity/interactiveslide/sessions/session/rounds/round/responses/response');
            $paths[] = new restore_path_element('interactiveslide_answer',
                '/activity/interactiveslide/sessions/session/rounds/round/responses/response/answers/answer');
            $paths[] = new restore_path_element('interactiveslide_participant',
                '/activity/interactiveslide/sessions/session/participants/participant');
            $paths[] = new restore_path_element('interactiveslide_award',
                '/activity/interactiveslide/sessions/session/awards/award');
        }

        return $this->prepare_activity_structure($paths);
    }

    /**
     * Restore the activity record.
     *
     * @param array $data
     * @return void
     */
    protected function process_interactiveslide($data) {
        global $DB;

        $data = (object)$data;
        $oldid = $data->id;
        $data->course = $this->get_courseid();
        $data->timecreated = $this->apply_date_offset($data->timecreated);
        $data->timemodified = $this->apply_date_offset($data->timemodified);
        // Slide ids are remapped later, so the pointer starts clear.
        $data->currentslideid = 0;

        $newid = $DB->insert_record('interactiveslide', $data);
        $this->apply_activity_instance($newid);
        $this->set_mapping('interactiveslide', $oldid, $newid);
    }

    /**
     * Restore one slide.
     *
     * @param array $data
     * @return void
     */
    protected function process_interactiveslide_slide($data) {
        global $DB;

        $data = (object)$data;
        $oldid = $data->id;
        $data->interactiveslideid = $this->get_new_parentid('interactiveslide');
        $data->timecreated = $this->apply_date_offset($data->timecreated);
        $data->timemodified = $this->apply_date_offset($data->timemodified);

        $newid = $DB->insert_record('interactiveslide_slide', $data);
        // The file annotation used the slide id as the item id.
        $this->set_mapping('interactiveslide_slide', $oldid, $newid, true);
    }

    /**
     * Restore one interaction.
     *
     * @param array $data
     * @return void
     */
    protected function process_interactiveslide_interaction($data) {
        global $DB;

        $data = (object)$data;
        $oldid = $data->id;
        $data->slideid = $this->get_new_parentid('interactiveslide_slide');
        $data->timecreated = $this->apply_date_offset($data->timecreated);
        $data->timemodified = $this->apply_date_offset($data->timemodified);

        $newid = $DB->insert_record('interactiveslide_interaction', $data);
        $this->set_mapping('interactiveslide_interaction', $oldid, $newid);
    }

    /**
     * Restore one multiple choice option.
     *
     * @param array $data
     * @return void
     */
    protected function process_interactiveslide_option($data) {
        global $DB;

        $data = (object)$data;
        $oldid = $data->id;
        $data->interactionid = $this->get_new_parentid('interactiveslide_interaction');

        $newid = $DB->insert_record('interactiveslide_option', $data);
        $this->set_mapping('interactiveslide_option', $oldid, $newid);
    }

    /**
     * Restore one blank.
     *
     * @param array $data
     * @return void
     */
    protected function process_interactiveslide_blank($data) {
        global $DB;

        $data = (object)$data;
        $oldid = $data->id;
        $data->interactionid = $this->get_new_parentid('interactiveslide_interaction');

        $newid = $DB->insert_record('interactiveslide_blank', $data);
        $this->set_mapping('interactiveslide_blank', $oldid, $newid);
    }

    /**
     * Restore one session.
     *
     * @param array $data
     * @return void
     */
    protected function process_interactiveslide_session($data) {
        global $DB;

        $data = (object)$data;
        $oldid = $data->id;
        $data->interactiveslideid = $this->get_new_parentid('interactiveslide');
        $data->createdby = $this->get_mappingid('user', $data->createdby) ?: 0;
        $data->currentslideid = $this->get_mappingid('interactiveslide_slide', $data->currentslideid) ?: 0;
        $data->timecreated = $this->apply_date_offset($data->timecreated);
        $data->timeend = $this->apply_date_offset($data->timeend);
        // A restored copy is history, never a session someone is still running.
        $data->status = 'ended';

        $newid = $DB->insert_record('interactiveslide_session', $data);
        $this->set_mapping('interactiveslide_session', $oldid, $newid);
    }

    /**
     * Restore one round.
     *
     * @param array $data
     * @return void
     */
    protected function process_interactiveslide_round($data) {
        global $DB;

        $data = (object)$data;
        $oldid = $data->id;
        $data->sessionid = $this->get_new_parentid('interactiveslide_session');
        $data->interactionid = $this->get_mappingid('interactiveslide_interaction', $data->interactionid) ?: 0;
        $data->slideid = $this->get_mappingid('interactiveslide_slide', $data->slideid) ?: 0;
        $data->status = 'closed';
        $data->timeopen = $this->apply_date_offset($data->timeopen);
        $data->timeclose = $this->apply_date_offset($data->timeclose);

        $newid = $DB->insert_record('interactiveslide_round', $data);
        $this->set_mapping('interactiveslide_round', $oldid, $newid);
    }

    /**
     * Restore one response.
     *
     * @param array $data
     * @return void
     */
    protected function process_interactiveslide_response($data) {
        global $DB;

        $data = (object)$data;
        $oldid = $data->id;
        $data->roundid = $this->get_new_parentid('interactiveslide_round');
        $data->sessionid = $this->get_mappingid('interactiveslide_session', $data->sessionid)
            ?: $this->get_new_parentid('interactiveslide_session');
        $data->userid = $this->get_mappingid('user', $data->userid);
        $data->timecreated = $this->apply_date_offset($data->timecreated);
        $data->timemodified = $this->apply_date_offset($data->timemodified);

        if (!$data->userid) {
            // Without a user the row cannot be attributed to anyone; drop it.
            return;
        }

        $newid = $DB->insert_record('interactiveslide_response', $data);
        $this->set_mapping('interactiveslide_response', $oldid, $newid);
    }

    /**
     * Restore one answer part.
     *
     * @param array $data
     * @return void
     */
    protected function process_interactiveslide_answer($data) {
        global $DB;

        $data = (object)$data;
        $responseid = $this->get_new_parentid('interactiveslide_response');
        if (!$responseid) {
            return;
        }

        $data->responseid = $responseid;
        $data->roundid = $this->get_new_parentid('interactiveslide_round');
        $data->blankid = $data->blankid
            ? ($this->get_mappingid('interactiveslide_blank', $data->blankid) ?: 0)
            : 0;
        $data->optionid = $data->optionid
            ? ($this->get_mappingid('interactiveslide_option', $data->optionid) ?: 0)
            : 0;

        $DB->insert_record('interactiveslide_answer', $data);
    }

    /**
     * Restore one participant row.
     *
     * @param array $data
     * @return void
     */
    protected function process_interactiveslide_participant($data) {
        global $DB;

        $data = (object)$data;
        $data->sessionid = $this->get_new_parentid('interactiveslide_session');
        $data->interactiveslideid = $this->get_new_parentid('interactiveslide');
        $data->userid = $this->get_mappingid('user', $data->userid);
        $data->timejoined = $this->apply_date_offset($data->timejoined);
        $data->lastseen = $this->apply_date_offset($data->lastseen);

        if (!$data->userid) {
            return;
        }

        $DB->insert_record('interactiveslide_participant', $data);
    }

    /**
     * Restore one manual star award.
     *
     * @param array $data
     * @return void
     */
    protected function process_interactiveslide_award($data) {
        global $DB;

        $data = (object)$data;
        $data->sessionid = $this->get_new_parentid('interactiveslide_session');
        $data->userid = $this->get_mappingid('user', $data->userid);
        $data->awardedby = $this->get_mappingid('user', $data->awardedby) ?: 0;
        $data->timecreated = $this->apply_date_offset($data->timecreated);

        if (!$data->userid) {
            return;
        }

        $DB->insert_record('interactiveslide_award', $data);
    }

    /**
     * Bring the annotated files across.
     *
     * @return void
     */
    protected function after_execute() {
        $this->add_related_files('mod_interactiveslide', 'intro', null);
        $this->add_related_files('mod_interactiveslide', 'sourcepdf', null);
        $this->add_related_files('mod_interactiveslide', 'slideimage', 'interactiveslide_slide');
    }
}
