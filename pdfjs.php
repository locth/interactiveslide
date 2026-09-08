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
 * Serves the pdf.js build with a JavaScript MIME type.
 *
 * Browsers refuse an ES module whose response is not a JavaScript MIME type,
 * and many servers still send `.mjs` as application/octet-stream because it is
 * not in their default type map. That is a one line fix in the server config,
 * but on a shared university install the person who needs the import wizard is
 * rarely the person who can edit httpd.conf — so the file is served from here
 * instead, where the plugin controls the header.
 *
 * The bytes are the same public library the web server would hand out from
 * thirdparty/pdfjs anyway, so this endpoint adds no exposure. It runs without a
 * session on purpose: the worker is a couple of megabytes and holding the
 * session lock for the length of that transfer would block every other request
 * the same user makes.
 *
 * @package    mod_interactiveslide
 * @copyright  2026 Interactive Slide contributors
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('NO_MOODLE_COOKIES', true);

require(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/filelib.php');

$file = required_param('file', PARAM_FILE);
$dir = optional_param('dir', 'plugin', PARAM_ALPHA);

// Both lists are closed. PARAM_FILE already removes any path, and matching the
// name against a fixed set means a request can only ever reach these files.
$allowedfiles = [
    'pdf.mjs', 'pdf.worker.mjs',
    'pdf.min.mjs', 'pdf.worker.min.mjs',
    'pdf.js', 'pdf.worker.js',
    'pdf.min.js', 'pdf.worker.min.js',
];

$alloweddirs = [
    'plugin' => $CFG->dirroot . '/mod/interactiveslide/thirdparty/pdfjs',
    'core' => $CFG->dirroot . '/lib/pdfjs/build',
];

if (!in_array($file, $allowedfiles, true) || !isset($alloweddirs[$dir])) {
    send_header_404();
    die();
}

$path = $alloweddirs[$dir] . '/' . $file;
if (!is_readable($path)) {
    send_header_404();
    die();
}

// A year is safe because the URL carries the file's own timestamp: replacing
// pdf.js changes the timestamp, which changes the URL, which misses the cache.
send_file($path, $file, YEARSECS, 0, false, false, 'text/javascript');
