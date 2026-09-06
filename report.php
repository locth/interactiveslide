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
 * Star reports for an Interactive Slide activity.
 *
 * @package    mod_interactiveslide
 * @copyright  2026 Interactive Slide contributors
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');

use mod_interactiveslide\local\report_builder;

$id = required_param('id', PARAM_INT);
// One parameter drives the whole picker: 'course' for every deck in the course,
// 'all' for every session of this deck, or 's<id>' for one session.
$view = optional_param('view', 'all', PARAM_ALPHANUM);
$download = optional_param('download', '', PARAM_ALPHA);

[$course, $cm] = get_course_and_cm_from_cmid($id, 'interactiveslide');
$context = context_module::instance($cm->id);

require_login($course, false, $cm);

// Staff read the whole room; a student reads their own line and nothing else.
// The restriction is applied in the queries rather than by filtering rows after
// the fact, so nobody else's data is ever loaded to begin with.
$canviewall = has_capability('mod/interactiveslide:viewreports', $context);
if (!$canviewall) {
    require_capability('mod/interactiveslide:view', $context);
}
$onlyuserid = $canviewall ? 0 : (int)$USER->id;

$instance = $DB->get_record('interactiveslide', ['id' => $cm->instance], '*', MUST_EXIST);

$pageurl = new moodle_url('/mod/interactiveslide/report.php', ['id' => $cm->id, 'view' => $view]);

if ($onlyuserid) {
    // Only the sessions this student was actually in: offering the rest would
    // just be a list of empty tables, and it would leak when classes were run.
    $sessions = $DB->get_records_sql(
        'SELECT s.*
           FROM {interactiveslide_session} s
           JOIN {interactiveslide_participant} p ON p.sessionid = s.id AND p.userid = :userid
          WHERE s.interactiveslideid = :instanceid
       ORDER BY s.timecreated DESC',
        ['instanceid' => $instance->id, 'userid' => $onlyuserid]
    );
} else {
    $sessions = $DB->get_records('interactiveslide_session', ['interactiveslideid' => $instance->id],
        'timecreated DESC');
}

$sessionid = 0;
if (preg_match('/^s(\\d+)$/', $view, $matches)) {
    $sessionid = (int)$matches[1];
    if (!isset($sessions[$sessionid])) {
        throw new moodle_exception('errorsessionnotfound', 'mod_interactiveslide');
    }
} else if ($view !== 'course') {
    $view = 'all';
}

if ($download) {
    if ($sessionid) {
        [$columns, $rows] = report_builder::session_rows($sessionid, $onlyuserid);
        $filename = clean_filename(format_string($instance->name) . '-' . $sessions[$sessionid]->name);
    } else if ($view === 'course') {
        [$columns, $rows] = report_builder::course_rows((int)$course->id, $onlyuserid);
        $filename = clean_filename(format_string($course->shortname) . '-interactiveslide-total');
    } else {
        [$columns, $rows] = report_builder::overview_rows((int)$instance->id, $onlyuserid);
        $filename = clean_filename(format_string($instance->name) . '-overview');
    }

    \core\dataformat::download_data($filename, $download, $columns, $rows);
    die();
}

$PAGE->set_url($pageurl);
$PAGE->set_title(format_string($instance->name) . ': ' . get_string('reports', 'mod_interactiveslide'));
$PAGE->set_heading(format_string($course->fullname));
$PAGE->set_context($context);
$PAGE->add_body_class('mod-interactiveslide');

echo $OUTPUT->header();
echo $OUTPUT->heading($onlyuserid
    ? get_string('myreports', 'mod_interactiveslide')
    : get_string('reports', 'mod_interactiveslide'));

if ($onlyuserid) {
    echo $OUTPUT->notification(get_string('myreports_desc', 'mod_interactiveslide'), 'info');
}

// View picker: the whole course, this deck, or one of its sessions.
$options = [
    'course' => get_string('reportcourse', 'mod_interactiveslide'),
    'all' => get_string('reportoverview', 'mod_interactiveslide'),
];
foreach ($sessions as $session) {
    $options['s' . $session->id] = format_string($session->name) . ' (' . userdate($session->timecreated,
        get_string('strftimedatetimeshort', 'langconfig')) . ')';
}

// The picker posts the view itself, so its base URL must not already carry one.
$baseurl = new moodle_url('/mod/interactiveslide/report.php', ['id' => $cm->id]);
$select = new single_select($baseurl, 'view', $options, $view, null, 'sessionpicker');
$select->label = get_string('chooseview', 'mod_interactiveslide');
echo $OUTPUT->render($select);

if ($sessionid) {
    [$columns, $rows] = report_builder::session_rows($sessionid, $onlyuserid);
    $heading = get_string('reportsession', 'mod_interactiveslide',
        format_string($sessions[$sessionid]->name));
} else if ($view === 'course') {
    [$columns, $rows] = report_builder::course_rows((int)$course->id, $onlyuserid);
    $heading = get_string('reportcourse', 'mod_interactiveslide');
} else {
    [$columns, $rows] = report_builder::overview_rows((int)$instance->id, $onlyuserid);
    $heading = get_string('reportoverview', 'mod_interactiveslide');
}

echo $OUTPUT->heading($heading, 3);

if (!$rows) {
    echo $OUTPUT->notification(get_string('noresponsesyet', 'mod_interactiveslide'), 'info');
} else {
    $table = new html_table();
    $table->head = array_values($columns);
    $table->attributes['class'] = 'generaltable interactiveslide-report';
    foreach ($rows as $row) {
        $table->data[] = array_map(static fn($cell) => s((string)$cell), array_values($row));
    }
    // One column per slide means the sheet can be much wider than the page.
    echo html_writer::start_div('islide-report-scroll');
    echo html_writer::table($table);
    echo html_writer::end_div();

    echo $OUTPUT->download_dataformat_selector(
        get_string('exportresults', 'mod_interactiveslide'),
        $pageurl->out_omit_querystring(),
        'download',
        $pageurl->params()
    );
}

echo $OUTPUT->footer();
