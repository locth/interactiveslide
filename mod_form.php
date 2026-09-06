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
 * Settings form for an Interactive Slide activity.
 *
 * @package    mod_interactiveslide
 * @copyright  2026 Interactive Slide contributors
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/course/moodleform_mod.php');

use mod_interactiveslide\local\grading;
use mod_interactiveslide\local\leaderboard;

/**
 * The activity settings form.
 *
 * @package    mod_interactiveslide
 * @copyright  2026 Interactive Slide contributors
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class mod_interactiveslide_mod_form extends moodleform_mod {

    /**
     * Build the form.
     *
     * @return void
     */
    public function definition() {
        $mform = $this->_form;

        $mform->addElement('header', 'general', get_string('general', 'form'));

        $mform->addElement('text', 'name', get_string('activityname', 'mod_interactiveslide'), ['size' => 64]);
        $mform->setType('name', PARAM_TEXT);
        $mform->addRule('name', null, 'required', null, 'client');
        $mform->addRule('name', get_string('maximumchars', '', 255), 'maxlength', 255, 'client');

        $this->standard_intro_elements();

        // Gamification.
        $mform->addElement('header', 'gamificationheader', get_string('gamification', 'mod_interactiveslide'));
        $mform->setExpanded('gamificationheader');

        $mform->addElement('select', 'showleaderboard', get_string('showleaderboard', 'mod_interactiveslide'), [
            leaderboard::VISIBILITY_TEACHER => get_string('leaderboardteacheronly', 'mod_interactiveslide'),
            leaderboard::VISIBILITY_FULL => get_string('leaderboardeveryone', 'mod_interactiveslide'),
            leaderboard::VISIBILITY_OWNRANK => get_string('leaderboardownrank', 'mod_interactiveslide'),
        ]);
        $mform->setType('showleaderboard', PARAM_INT);
        $mform->setDefault('showleaderboard', leaderboard::VISIBILITY_FULL);
        $mform->addHelpButton('showleaderboard', 'showleaderboard', 'mod_interactiveslide');

        $sizes = [];
        foreach ([3, 5, 10, 15, 20, 30] as $size) {
            $sizes[$size] = $size;
        }
        $mform->addElement('select', 'leaderboardsize', get_string('leaderboardsize', 'mod_interactiveslide'), $sizes);
        $mform->setType('leaderboardsize', PARAM_INT);
        $mform->setDefault('leaderboardsize', 10);
        $mform->hideIf('leaderboardsize', 'showleaderboard', 'neq', leaderboard::VISIBILITY_FULL);

        $mform->addElement('advcheckbox', 'speedbonus', get_string('speedbonus', 'mod_interactiveslide'));
        $mform->setType('speedbonus', PARAM_INT);
        $mform->setDefault('speedbonus', 0);
        $mform->addHelpButton('speedbonus', 'speedbonus', 'mod_interactiveslide');

        $mform->addElement('select', 'speedbonusmax', get_string('speedbonusmax', 'mod_interactiveslide'),
            [1 => 1, 2 => 2, 3 => 3]);
        $mform->setType('speedbonusmax', PARAM_INT);
        $mform->setDefault('speedbonusmax', 1);
        $mform->hideIf('speedbonusmax', 'speedbonus', 'notchecked');

        $stars = [];
        foreach ([0, 1, 2, 3, 5] as $value) {
            $stars[$value] = $value;
        }
        $mform->addElement('select', 'attendancestars',
            get_string('attendancestars', 'mod_interactiveslide'), $stars);
        $mform->setType('attendancestars', PARAM_INT);
        $mform->setDefault('attendancestars', 1);
        $mform->addHelpButton('attendancestars', 'attendancestars', 'mod_interactiveslide');

        $mform->addElement('advcheckbox', 'anonymousresults',
            get_string('anonymousresults', 'mod_interactiveslide'));
        $mform->setType('anonymousresults', PARAM_INT);
        $mform->setDefault('anonymousresults', 0);
        $mform->addHelpButton('anonymousresults', 'anonymousresults', 'mod_interactiveslide');

        $mform->addElement('advcheckbox', 'allowlatejoin', get_string('allowlatejoin', 'mod_interactiveslide'));
        $mform->setType('allowlatejoin', PARAM_INT);
        $mform->setDefault('allowlatejoin', 1);
        $mform->addHelpButton('allowlatejoin', 'allowlatejoin', 'mod_interactiveslide');

        // Grade.
        $this->standard_grading_coursemodule_elements();

        $methods = [];
        foreach (grading::get_methods() as $value => $stringkey) {
            $methods[$value] = get_string($stringkey, 'mod_interactiveslide');
        }
        $mform->addElement('select', 'grademethod', get_string('grademethod', 'mod_interactiveslide'), $methods);
        $mform->setType('grademethod', PARAM_INT);
        $mform->setDefault('grademethod', grading::METHOD_TOTAL);
        $mform->addHelpButton('grademethod', 'grademethod', 'mod_interactiveslide');
        $mform->hideIf('grademethod', 'grade[modgrade_type]', 'eq', 'none');

        $this->standard_coursemodule_elements();
        $this->add_action_buttons();
    }

    /**
     * Fill in defaults that are not plain database columns.
     *
     * @param array $defaultvalues
     * @return void
     */
    public function data_preprocessing(&$defaultvalues) {
        parent::data_preprocessing($defaultvalues);

        if (empty($defaultvalues['grademethod'])) {
            $defaultvalues['grademethod'] = grading::METHOD_TOTAL;
        }
    }
}
