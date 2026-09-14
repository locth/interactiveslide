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
 * Downloads a deck as one portable zip.
 *
 * Its own entry point rather than an arm of upload.php: that file is an
 * AJAX_SCRIPT and every arm of it ends in a JSON reply, which is how you ship an
 * archive with `{"status":"ok"}` glued to the front of it.
 *
 * The session is kept, unlike pdfjs.php. That file may run without one because
 * it serves a public library; a deck carries every answer key in the activity.
 *
 * @package    mod_interactiveslide
 * @copyright  2026 Interactive Slide contributors
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/filelib.php');

use mod_interactiveslide\local\deck_archive;

$id = required_param('id', PARAM_INT);

[$course, $cm] = get_course_and_cm_from_cmid($id, 'interactiveslide');
$context = context_module::instance($cm->id);

require_login($course, false, $cm);
require_sesskey();
require_capability('mod/interactiveslide:manage', $context);

$instance = $DB->get_record('interactiveslide', ['id' => $cm->instance], '*', MUST_EXIST);

$built = deck_archive::build_manifest($context, $instance);

if (!$built['manifest']['slides']) {
    throw new moodle_exception('errornothingtoexport', 'mod_interactiveslide',
        new moodle_url('/mod/interactiveslide/edit.php', ['id' => $cm->id]));
}

$json = json_encode($built['manifest'],
    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);

// A request directory is cleaned up when the request ends, whatever happens in
// between, so a failed export leaves nothing behind on disk.
$zippath = make_request_directory() . '/deck.zip';

// The packer takes an array of strings as literal file content, so the manifest
// never needs a temp file of its own; the images go in as stored_file objects
// and stream straight out of the file store.
$contents = [deck_archive::MANIFEST => [$json]] + $built['files'];

$packer = get_file_packer('application/zip');
if (!$packer->archive_to_pathname($contents, $zippath)) {
    throw new moodle_exception('errorexportfailed', 'mod_interactiveslide',
        new moodle_url('/mod/interactiveslide/edit.php', ['id' => $cm->id]));
}

\mod_interactiveslide\event\deck_exported::create_from_deck(
    $context, $instance, count($built['manifest']['slides']))->trigger();

// clean_filename because the activity's name is written by a teacher and this
// one is going into a Content-Disposition header.
$stem = clean_filename(format_string($instance->name));
if (trim($stem) === '') {
    $stem = 'deck';
}

send_file($zippath, $stem . '-' . date('Ymd') . '.zip', 0, 0, false, true, 'application/zip');
