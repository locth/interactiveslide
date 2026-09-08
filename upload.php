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
 * Receives one rendered PDF page at a time from the import wizard.
 *
 * The PDF itself is rasterised in the teacher's browser by pdf.js, so the
 * server never needs Ghostscript or ImageMagick. Each page arrives here as a
 * PNG together with its page number, and becomes one slide.
 *
 * @package    mod_interactiveslide
 * @copyright  2026 Interactive Slide contributors
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('AJAX_SCRIPT', true);

require(__DIR__ . '/../../config.php');

use mod_interactiveslide\local\slide_manager;

$cmid = required_param('cmid', PARAM_INT);
$action = required_param('action', PARAM_ALPHA);

[$course, $cm] = get_course_and_cm_from_cmid($cmid, 'interactiveslide');
$context = context_module::instance($cm->id);

$PAGE->set_context($context);
$PAGE->set_url('/mod/interactiveslide/upload.php', ['cmid' => $cmid]);

require_login($course, false, $cm);
require_sesskey();
require_capability('mod/interactiveslide:manage', $context);

$instance = $DB->get_record('interactiveslide', ['id' => $cm->instance], '*', MUST_EXIST);

/**
 * Send a JSON reply and stop.
 *
 * @param array $payload
 * @return never
 */
function interactiveslide_reply(array $payload): void {
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    die();
}

switch ($action) {

    case 'begin':
        // Starting a fresh import replaces the deck: mixing pages from two
        // different PDFs has never been what anyone meant.
        $replace = optional_param('replace', 1, PARAM_INT);
        $pdfname = optional_param('pdfname', '', PARAM_FILE);

        if ($replace) {
            slide_manager::delete_all_slides($context, (int)$instance->id);
        }

        $DB->update_record('interactiveslide', (object)[
            'id' => $instance->id,
            'pdffilename' => $pdfname,
            'timemodified' => time(),
        ]);

        interactiveslide_reply(['status' => 'ok']);
        break;

    case 'page':
        $pageno = required_param('pageno', PARAM_INT);
        $width = required_param('width', PARAM_INT);
        $height = required_param('height', PARAM_INT);
        $title = optional_param('title', '', PARAM_TEXT);

        $maxpages = (int)(get_config('mod_interactiveslide', 'maxpages') ?: 200);
        if ($pageno < 1 || $pageno > $maxpages) {
            interactiveslide_reply([
                'status' => 'error',
                'message' => get_string('errortoomanypages', 'mod_interactiveslide', $maxpages),
            ]);
        }

        if (empty($_FILES['image']) || $_FILES['image']['error'] !== UPLOAD_ERR_OK
                || !is_uploaded_file($_FILES['image']['tmp_name'])) {
            interactiveslide_reply([
                'status' => 'error',
                'message' => get_string('erroruploadfailed', 'mod_interactiveslide'),
            ]);
        }

        // Trust the bytes, not the browser or the file name. Both encodings the
        // import wizard can produce are accepted, so changing the site setting
        // between imports never strands a half finished deck.
        $imageinfo = @getimagesize($_FILES['image']['tmp_name']);
        $allowedtypes = [IMAGETYPE_PNG => 'png', IMAGETYPE_JPEG => 'jpg'];

        if (!$imageinfo || !isset($allowedtypes[$imageinfo[2]])) {
            interactiveslide_reply([
                'status' => 'error',
                'message' => get_string('errornotanimage', 'mod_interactiveslide'),
            ]);
        }

        $slideid = slide_manager::create_slide((int)$instance->id, $pageno, $title);
        slide_manager::store_slide_image(
            $context,
            $slideid,
            $_FILES['image']['tmp_name'],
            'page-' . $pageno . '.' . $allowedtypes[$imageinfo[2]],
            (int)$imageinfo[0],
            (int)$imageinfo[1]
        );

        $slide = $DB->get_record('interactiveslide_slide', ['id' => $slideid], '*', MUST_EXIST);

        interactiveslide_reply([
            'status' => 'ok',
            'slideid' => (int)$slideid,
            'imageurl' => slide_manager::get_image_url($context, $slide),
        ]);
        break;

    case 'addslide':
        // One picture becomes one slide at the end of the deck. The same checks
        // as an imported page: the bytes decide what the file is, not its name
        // and not the browser.
        $title = optional_param('title', '', PARAM_TEXT);
        // Where the teacher is standing in the deck. The new slide lands right
        // after it, which is what "add" means when you are looking at a slide
        // and have just realised one is missing after it.
        $after = optional_param('after', 0, PARAM_INT);

        if (empty($_FILES['image']) || $_FILES['image']['error'] !== UPLOAD_ERR_OK
                || !is_uploaded_file($_FILES['image']['tmp_name'])) {
            interactiveslide_reply([
                'status' => 'error',
                'message' => get_string('erroruploadfailed', 'mod_interactiveslide'),
            ]);
        }

        $imageinfo = @getimagesize($_FILES['image']['tmp_name']);
        $allowedtypes = [
            IMAGETYPE_PNG => 'png',
            IMAGETYPE_JPEG => 'jpg',
            IMAGETYPE_GIF => 'gif',
            IMAGETYPE_WEBP => 'webp',
        ];

        if (!$imageinfo || !isset($allowedtypes[$imageinfo[2]])) {
            interactiveslide_reply([
                'status' => 'error',
                'message' => get_string('errornotanimage', 'mod_interactiveslide'),
            ]);
        }

        $maxpages = (int)(get_config('mod_interactiveslide', 'maxpages') ?: 200);
        if ($DB->count_records('interactiveslide_slide', ['interactiveslideid' => $instance->id]) >= $maxpages) {
            interactiveslide_reply([
                'status' => 'error',
                'message' => get_string('errortoomanypages', 'mod_interactiveslide', $maxpages),
            ]);
        }

        $slideid = slide_manager::create_slide((int)$instance->id, 0, $title);
        slide_manager::store_slide_image(
            $context,
            $slideid,
            $_FILES['image']['tmp_name'],
            'slide-' . $slideid . '.' . $allowedtypes[$imageinfo[2]],
            (int)$imageinfo[0],
            (int)$imageinfo[1]
        );

        slide_manager::insert_after((int)$instance->id, $slideid, $after);

        $slide = $DB->get_record('interactiveslide_slide', ['id' => $slideid], '*', MUST_EXIST);

        interactiveslide_reply([
            'status' => 'ok',
            'slideid' => (int)$slideid,
            'imageurl' => slide_manager::get_image_url($context, $slide),
        ]);
        break;

    case 'finish':
        slide_manager::resequence((int)$instance->id);

        interactiveslide_reply([
            'status' => 'ok',
            'slidecount' => $DB->count_records('interactiveslide_slide', ['interactiveslideid' => $instance->id]),
        ]);
        break;

    default:
        interactiveslide_reply([
            'status' => 'error',
            'message' => get_string('errorinvalidaction', 'mod_interactiveslide'),
        ]);
}
