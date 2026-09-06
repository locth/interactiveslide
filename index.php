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
 * Lists every Interactive Slide activity in a course.
 *
 * @package    mod_interactiveslide
 * @copyright  2026 Interactive Slide contributors
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');

$id = required_param('id', PARAM_INT);

$course = $DB->get_record('course', ['id' => $id], '*', MUST_EXIST);
require_login($course);

$context = context_course::instance($course->id);

$PAGE->set_url('/mod/interactiveslide/index.php', ['id' => $id]);
$PAGE->set_title(format_string($course->shortname) . ': ' . get_string('modulenameplural', 'mod_interactiveslide'));
$PAGE->set_heading(format_string($course->fullname));
$PAGE->set_context($context);

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('modulenameplural', 'mod_interactiveslide'));

$instances = get_all_instances_in_course('interactiveslide', $course);
if (!$instances) {
    echo $OUTPUT->notification(get_string('noinstances', 'mod_interactiveslide'), 'info');
    echo $OUTPUT->footer();
    die();
}

$table = new html_table();
$table->head = [
    get_string('activityname', 'mod_interactiveslide'),
    get_string('slides', 'mod_interactiveslide'),
    get_string('sessionstatus', 'mod_interactiveslide'),
];

foreach ($instances as $instance) {
    $url = new moodle_url('/mod/interactiveslide/view.php', ['id' => $instance->coursemodule]);
    $link = html_writer::link($url, format_string($instance->name),
        ['class' => $instance->visible ? '' : 'dimmed']);

    $slides = $DB->count_records('interactiveslide_slide', ['interactiveslideid' => $instance->id]);
    $session = \mod_interactiveslide\local\session_manager::get_active_session((int)$instance->id);

    $table->data[] = [
        $link,
        $slides,
        $session
            ? get_string('sessionlive', 'mod_interactiveslide')
            : get_string('sessionidle', 'mod_interactiveslide'),
    ];
}

echo html_writer::table($table);
echo $OUTPUT->footer();
