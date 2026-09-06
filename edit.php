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
 * Slide and interaction editor.
 *
 * @package    mod_interactiveslide
 * @copyright  2026 Interactive Slide contributors
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');

$id = required_param('id', PARAM_INT);

[$course, $cm] = get_course_and_cm_from_cmid($id, 'interactiveslide');
$context = context_module::instance($cm->id);

require_login($course, false, $cm);
require_capability('mod/interactiveslide:manage', $context);

$instance = $DB->get_record('interactiveslide', ['id' => $cm->instance], '*', MUST_EXIST);

$PAGE->set_url('/mod/interactiveslide/edit.php', ['id' => $cm->id]);
$PAGE->set_title(format_string($instance->name) . ': ' . get_string('editslides', 'mod_interactiveslide'));
$PAGE->set_heading(format_string($course->fullname));
$PAGE->set_context($context);
$PAGE->set_secondary_active_tab('modulepage');
$PAGE->add_body_class('mod-interactiveslide mod-interactiveslide-editor');

$renderer = $PAGE->get_renderer('mod_interactiveslide');

echo $OUTPUT->header();
echo $renderer->render_editor($instance, $cm, $context);
echo $OUTPUT->footer();
