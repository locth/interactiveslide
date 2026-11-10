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
 * Receives the picture a student attaches to an open ended answer.
 *
 * Deliberately not an arm of upload.php. Everything in that file is the
 * teacher's and it requires mod/interactiveslide:manage before it looks at the
 * action at all; adding the one thing a student may send would mean moving that
 * check inwards, where a future arm could be written without it. This file can
 * only ever do the one job, so it is the safer place for the loosest check in
 * the plugin.
 *
 * @package    mod_interactiveslide
 * @copyright  2026 Interactive Slide contributors
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('AJAX_SCRIPT', true);

require(__DIR__ . '/../../config.php');

use mod_interactiveslide\local\answer_image;
use mod_interactiveslide\local\interaction_manager;
use mod_interactiveslide\local\participation;
use mod_interactiveslide\local\session_manager;

/**
 * Send a JSON reply and stop.
 *
 * @param array $payload
 * @return never
 */
function interactiveslide_answer_reply(array $payload): void {
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    die();
}

// A phone photograph is the one upload in this plugin a student makes, and it
// is also the one most likely to be larger than the server allows. PHP empties
// $_POST and $_FILES in that case, so say what happened rather than let the
// first required_param below report a missing parameter.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && empty($_POST) && !empty($_SERVER['CONTENT_LENGTH'])) {
    interactiveslide_answer_reply([
        'status' => 'error',
        'message' => get_string('errorposttoolarge', 'mod_interactiveslide'),
    ]);
}

$cmid = required_param('cmid', PARAM_INT);
$action = required_param('action', PARAM_ALPHA);
$roundid = required_param('roundid', PARAM_INT);

[$course, $cm] = get_course_and_cm_from_cmid($cmid, 'interactiveslide');
$context = context_module::instance($cm->id);

$PAGE->set_context($context);
$PAGE->set_url('/mod/interactiveslide/answerupload.php', ['cmid' => $cmid]);

require_login($course, false, $cm);
require_sesskey();

$instance = $DB->get_record('interactiveslide', ['id' => $cm->instance], '*', MUST_EXIST);
$session = session_manager::get_active_session((int)$instance->id);
if (!$session) {
    interactiveslide_answer_reply([
        'status' => 'error',
        'message' => get_string('errornosession', 'mod_interactiveslide'),
    ]);
}

// The same decision the player screen was built from: a student answers through
// the capability, a guest through the link of the running session.
$me = participation::resolve($instance, $context, $session);
if (!$me->canparticipate) {
    require_capability('mod/interactiveslide:submit', $context);
}

$round = $DB->get_record('interactiveslide_round',
    ['id' => $roundid, 'sessionid' => $session->id]);
if (!$round) {
    interactiveslide_answer_reply([
        'status' => 'error',
        'message' => get_string('errornoround', 'mod_interactiveslide'),
    ]);
}

// A picture is part of an answer, so it may only arrive while the answer may.
session_manager::close_if_expired($round);
if ($round->status !== session_manager::ROUND_OPEN
        && !session_manager::in_grace_window($round)) {
    interactiveslide_answer_reply([
        'status' => 'error',
        'message' => get_string('errorroundclosed', 'mod_interactiveslide'),
    ]);
}

$interaction = interaction_manager::get_interaction((int)$round->interactionid);
if (!$interaction || $interaction->qtype !== interaction_manager::TYPE_OPENENDED
        || empty($interaction->allowimage)) {
    interactiveslide_answer_reply([
        'status' => 'error',
        'message' => get_string('erroranswerimagenotallowed', 'mod_interactiveslide'),
    ]);
}

if ($action === 'remove') {
    answer_image::remove($context, (int)$round->id, (int)$me->userid);
    interactiveslide_answer_reply(['status' => 'ok', 'imageurl' => '']);
}

if ($action !== 'image') {
    interactiveslide_answer_reply([
        'status' => 'error',
        'message' => get_string('erroruploadfailed', 'mod_interactiveslide'),
    ]);
}

if (empty($_FILES['image']) || $_FILES['image']['error'] !== UPLOAD_ERR_OK
        || !is_uploaded_file($_FILES['image']['tmp_name'])) {
    interactiveslide_answer_reply([
        'status' => 'error',
        'message' => get_string('erroruploadfailed', 'mod_interactiveslide'),
    ]);
}

$maxbytes = get_max_upload_file_size($CFG->maxbytes, $course->maxbytes ?? 0, $cm->maxbytes ?? 0);
if ($maxbytes > 0 && filesize($_FILES['image']['tmp_name']) > $maxbytes) {
    interactiveslide_answer_reply([
        'status' => 'error',
        'message' => get_string('errorimagetoolarge', 'mod_interactiveslide', display_size($maxbytes)),
    ]);
}

// The bytes decide what the file is, not its name and not the browser.
$imageinfo = @getimagesize($_FILES['image']['tmp_name']);
if (!$imageinfo || !isset(answer_image::TYPES[$imageinfo[2]])) {
    interactiveslide_answer_reply([
        'status' => 'error',
        'message' => get_string('errornotanimage', 'mod_interactiveslide'),
    ]);
}

$filename = answer_image::new_filename((int)$me->userid, answer_image::TYPES[$imageinfo[2]]);
answer_image::store($context, (int)$round->id, (int)$me->userid,
    $_FILES['image']['tmp_name'], $filename);

interactiveslide_answer_reply([
    'status' => 'ok',
    'width' => (int)$imageinfo[0],
    'height' => (int)$imageinfo[1],
    'imageurl' => answer_image::url($context, (int)$round->id, $filename),
]);
