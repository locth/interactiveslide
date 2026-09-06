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
 * Entry point for an Interactive Slide activity.
 *
 * Staff get a hub with the deck summary and the controls to run it. Students
 * get the live player, which shows a waiting screen until a session starts.
 *
 * @package    mod_interactiveslide
 * @copyright  2026 Interactive Slide contributors
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');

use mod_interactiveslide\local\grading;
use mod_interactiveslide\local\leaderboard;
use mod_interactiveslide\local\session_manager;

$id = optional_param('id', 0, PARAM_INT);
$instanceid = optional_param('n', 0, PARAM_INT);

if ($id) {
    [$course, $cm] = get_course_and_cm_from_cmid($id, 'interactiveslide');
    $instance = $DB->get_record('interactiveslide', ['id' => $cm->instance], '*', MUST_EXIST);
} else {
    $instance = $DB->get_record('interactiveslide', ['id' => $instanceid], '*', MUST_EXIST);
    [$course, $cm] = get_course_and_cm_from_instance($instance, 'interactiveslide');
}

$context = context_module::instance($cm->id);

require_login($course, true, $cm);
require_capability('mod/interactiveslide:view', $context);

\mod_interactiveslide\event\course_module_viewed::create([
    'objectid' => $instance->id,
    'context' => $context,
])->trigger();

$completion = new completion_info($course);
$completion->set_module_viewed($cm);

$PAGE->set_url('/mod/interactiveslide/view.php', ['id' => $cm->id]);
$PAGE->set_title(format_string($instance->name));
$PAGE->set_heading(format_string($course->fullname));
$PAGE->set_context($context);
$PAGE->add_body_class('mod-interactiveslide');

$canpresent = has_capability('mod/interactiveslide:present', $context);
$canmanage = has_capability('mod/interactiveslide:manage', $context);
$canreport = has_capability('mod/interactiveslide:viewreports', $context);

$session = session_manager::get_active_session((int)$instance->id);
$slidecount = $DB->count_records('interactiveslide_slide', ['interactiveslideid' => $instance->id]);
$interactioncount = $DB->count_records_sql(
    'SELECT COUNT(i.id)
       FROM {interactiveslide_interaction} i
       JOIN {interactiveslide_slide} s ON s.id = i.slideid
      WHERE s.interactiveslideid = ?',
    [$instance->id]
);

$renderer = $PAGE->get_renderer('mod_interactiveslide');

echo $OUTPUT->header();

if (!$canpresent) {
    // Students go straight into the player; there is nothing else for them here.
    echo $renderer->render_student_player($instance, $cm, $context);
    echo $OUTPUT->footer();
    die();
}

$templatecontext = [
    'cmid' => $cm->id,
    'name' => format_string($instance->name),
    'intro' => format_module_intro('interactiveslide', $instance, $cm->id),
    'hasintro' => trim(strip_tags($instance->intro ?? '')) !== '',
    'slidecount' => $slidecount,
    'interactioncount' => $interactioncount,
    'maxstars' => grading::get_deck_max_stars((int)$instance->id),
    'hasslides' => $slidecount > 0,
    'canmanage' => $canmanage,
    'canreport' => $canreport,
    'editurl' => (new moodle_url('/mod/interactiveslide/edit.php', ['id' => $cm->id]))->out(false),
    'presenturl' => (new moodle_url('/mod/interactiveslide/present.php', ['id' => $cm->id]))->out(false),
    'reporturl' => (new moodle_url('/mod/interactiveslide/report.php', ['id' => $cm->id]))->out(false),
    'hassession' => (bool)$session,
    'joincode' => $session ? $session->joincode : '',
    'sessionname' => $session ? format_string($session->name) : '',
    'participantcount' => 0,
    'participantstext' => '',
    'leaderboard' => leaderboard::get_overall_board((int)$instance->id, $context, 10, false),
    'hasleaderboard' => false,
];
$templatecontext['hasleaderboard'] = !empty($templatecontext['leaderboard']);

if ($session) {
    $templatecontext['participantcount'] =
        $DB->count_records('interactiveslide_participant', ['sessionid' => $session->id]);
    $templatecontext['participantstext'] = get_string('participantsjoined', 'mod_interactiveslide',
        $templatecontext['participantcount']);
}

echo $OUTPUT->render_from_template('mod_interactiveslide/teacher_hub', $templatecontext);

echo $OUTPUT->footer();
