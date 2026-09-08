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

namespace mod_interactiveslide\local;

use context_module;
use moodle_url;
use stdClass;

/**
 * Creating, ordering and deleting the slides of a deck.
 *
 * @package    mod_interactiveslide
 * @copyright  2026 Interactive Slide contributors
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class slide_manager {

    /** @var string File area holding one rendered page image per slide. */
    public const FILEAREA_IMAGE = 'slideimage';

    /** @var string File area holding the original imported PDF. */
    public const FILEAREA_PDF = 'sourcepdf';

    /**
     * Fetch every slide of a deck in presentation order.
     *
     * @param int $interactiveslideid
     * @return stdClass[] keyed by slide id
     */
    public static function get_slides(int $interactiveslideid): array {
        global $DB;
        return $DB->get_records('interactiveslide_slide', ['interactiveslideid' => $interactiveslideid],
            'sortorder ASC, id ASC');
    }

    /**
     * Fetch every slide of a deck with its interaction (when it has one) attached
     * as an `interaction` property.
     *
     * @param int $interactiveslideid
     * @return stdClass[] keyed by slide id
     */
    public static function get_slides_with_interactions(int $interactiveslideid): array {
        global $DB;

        $slides = self::get_slides($interactiveslideid);
        if (!$slides) {
            return [];
        }

        [$insql, $params] = $DB->get_in_or_equal(array_keys($slides), SQL_PARAMS_NAMED);
        $interactions = $DB->get_records_select('interactiveslide_interaction', "slideid $insql", $params, 'id ASC');

        foreach ($slides as $slide) {
            $slide->interaction = null;
        }
        foreach ($interactions as $interaction) {
            // One interaction per slide; if duplicates ever exist the first wins.
            if (isset($slides[$interaction->slideid]) && $slides[$interaction->slideid]->interaction === null) {
                $slides[$interaction->slideid]->interaction = $interaction;
            }
        }

        return $slides;
    }

    /**
     * Append a slide to the end of a deck.
     *
     * @param int $interactiveslideid
     * @param int $pageno source page number in the imported PDF
     * @param string $title
     * @return int the new slide id
     */
    public static function create_slide(int $interactiveslideid, int $pageno = 0, string $title = ''): int {
        global $DB;

        $max = $DB->get_field_sql(
            'SELECT MAX(sortorder) FROM {interactiveslide_slide} WHERE interactiveslideid = ?',
            [$interactiveslideid]
        );

        $record = new stdClass();
        $record->interactiveslideid = $interactiveslideid;
        $record->sortorder = ($max === null || $max === false) ? 0 : ((int)$max + 1);
        $record->title = $title;
        $record->pageno = $pageno;
        $record->imagefilename = null;
        $record->imagewidth = 0;
        $record->imageheight = 0;
        $record->timecreated = time();
        $record->timemodified = $record->timecreated;

        return $DB->insert_record('interactiveslide_slide', $record);
    }

    /**
     * Attach a rendered page image to a slide, replacing any previous one.
     *
     * @param context_module $context
     * @param int $slideid
     * @param string $filepath path to the uploaded temporary file
     * @param string $filename
     * @param int $width natural pixel width of the image
     * @param int $height natural pixel height of the image
     * @return void
     */
    public static function store_slide_image(context_module $context, int $slideid, string $filepath,
            string $filename, int $width, int $height): void {
        global $DB;

        $fs = get_file_storage();
        $fs->delete_area_files($context->id, 'mod_interactiveslide', self::FILEAREA_IMAGE, $slideid);

        $filename = clean_param($filename, PARAM_FILE);
        if ($filename === '' || pathinfo($filename, PATHINFO_EXTENSION) === '') {
            $filename = 'slide-' . $slideid . '.png';
        }

        $fs->create_file_from_pathname([
            'contextid' => $context->id,
            'component' => 'mod_interactiveslide',
            'filearea'  => self::FILEAREA_IMAGE,
            'itemid'    => $slideid,
            'filepath'  => '/',
            'filename'  => $filename,
        ], $filepath);

        $DB->update_record('interactiveslide_slide', (object)[
            'id' => $slideid,
            'imagefilename' => $filename,
            'imagewidth' => $width,
            'imageheight' => $height,
            'timemodified' => time(),
        ]);
    }

    /**
     * Build the pluginfile URL of a slide image.
     *
     * @param context_module $context
     * @param stdClass $slide
     * @return string empty string when the slide has no image yet
     */
    public static function get_image_url(context_module $context, stdClass $slide): string {
        if (empty($slide->imagefilename)) {
            return '';
        }

        return moodle_url::make_pluginfile_url(
            $context->id,
            'mod_interactiveslide',
            self::FILEAREA_IMAGE,
            $slide->id,
            '/',
            $slide->imagefilename
        )->out(false);
    }

    /**
     * Delete a slide together with its interaction, image and recorded rounds.
     *
     * @param context_module $context
     * @param int $slideid
     * @return void
     */
    public static function delete_slide(context_module $context, int $slideid): void {
        global $DB;

        $interactionids = $DB->get_fieldset_select('interactiveslide_interaction', 'id', 'slideid = ?', [$slideid]);
        foreach ($interactionids as $interactionid) {
            interaction_manager::delete_interaction((int)$interactionid);
        }

        get_file_storage()->delete_area_files($context->id, 'mod_interactiveslide', self::FILEAREA_IMAGE, $slideid);

        $slide = $DB->get_record('interactiveslide_slide', ['id' => $slideid]);
        $DB->delete_records('interactiveslide_slide', ['id' => $slideid]);

        if ($slide) {
            self::resequence((int)$slide->interactiveslideid);
            // A session parked on the deleted slide would otherwise show a blank stage.
            $DB->set_field('interactiveslide_session', 'currentslideid', 0,
                ['interactiveslideid' => $slide->interactiveslideid, 'currentslideid' => $slideid]);
        }
    }

    /**
     * Apply a new slide order.
     *
     * @param int $interactiveslideid
     * @param int[] $orderedids slide ids in the wanted order
     * @return void
     */
    /**
     * Put a freshly created slide immediately after another one.
     *
     * Expressed as a reorder rather than as arithmetic on sortorder: reorder
     * already renumbers the whole deck from zero, so there is no gap to find and
     * no two slides can end up sharing a position.
     *
     * @param int $interactiveslideid
     * @param int $slideid the new slide
     * @param int $afterid the slide it should follow; 0 or unknown puts it last
     * @return void
     */
    public static function insert_after(int $interactiveslideid, int $slideid, int $afterid): void {
        global $DB;

        $existing = $DB->get_records_menu('interactiveslide_slide',
            ['interactiveslideid' => $interactiveslideid], 'sortorder ASC, id ASC', 'id, id AS sameid');

        $order = [];
        foreach (array_keys($existing) as $id) {
            $id = (int)$id;
            if ($id === $slideid) {
                // Wherever create_slide parked it; the loop below places it.
                continue;
            }
            $order[] = $id;
            if ($id === $afterid) {
                $order[] = $slideid;
            }
        }

        if (!in_array($slideid, $order, true)) {
            $order[] = $slideid;
        }

        self::reorder($interactiveslideid, $order);
    }

    public static function reorder(int $interactiveslideid, array $orderedids): void {
        global $DB;

        $existing = $DB->get_records_menu('interactiveslide_slide',
            ['interactiveslideid' => $interactiveslideid], '', 'id, id AS sameid');

        $position = 0;
        foreach ($orderedids as $slideid) {
            $slideid = (int)$slideid;
            if (!isset($existing[$slideid])) {
                continue;
            }
            $DB->set_field('interactiveslide_slide', 'sortorder', $position, ['id' => $slideid]);
            unset($existing[$slideid]);
            $position++;
        }

        // Anything the caller forgot to mention keeps its relative order at the end.
        foreach (array_keys($existing) as $slideid) {
            $DB->set_field('interactiveslide_slide', 'sortorder', $position, ['id' => $slideid]);
            $position++;
        }
    }

    /**
     * Rewrite sortorder as a dense 0..n-1 sequence.
     *
     * @param int $interactiveslideid
     * @return void
     */
    public static function resequence(int $interactiveslideid): void {
        global $DB;

        $slides = $DB->get_records('interactiveslide_slide', ['interactiveslideid' => $interactiveslideid],
            'sortorder ASC, id ASC', 'id, sortorder');

        $position = 0;
        foreach ($slides as $slide) {
            if ((int)$slide->sortorder !== $position) {
                $DB->set_field('interactiveslide_slide', 'sortorder', $position, ['id' => $slide->id]);
            }
            $position++;
        }
    }

    /**
     * Remove every slide of a deck, used before re-importing a PDF.
     *
     * @param context_module $context
     * @param int $interactiveslideid
     * @return void
     */
    public static function delete_all_slides(context_module $context, int $interactiveslideid): void {
        global $DB;

        $slideids = $DB->get_fieldset_select('interactiveslide_slide', 'id', 'interactiveslideid = ?',
            [$interactiveslideid]);
        foreach ($slideids as $slideid) {
            self::delete_slide($context, (int)$slideid);
        }
    }

    /**
     * Find the slide that follows or precedes the given one in a deck.
     *
     * @param int $interactiveslideid
     * @param int $currentslideid 0 to get the first slide
     * @param int $direction 1 for next, -1 for previous
     * @return stdClass|null null when there is nothing in that direction
     */
    public static function get_adjacent_slide(int $interactiveslideid, int $currentslideid, int $direction): ?stdClass {
        $slides = array_values(self::get_slides($interactiveslideid));
        if (!$slides) {
            return null;
        }

        if ($currentslideid <= 0) {
            return $direction >= 0 ? $slides[0] : $slides[count($slides) - 1];
        }

        foreach ($slides as $index => $slide) {
            if ((int)$slide->id === $currentslideid) {
                $target = $index + ($direction >= 0 ? 1 : -1);
                return $slides[$target] ?? null;
            }
        }

        return $slides[0];
    }
}
