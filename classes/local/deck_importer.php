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
use moodle_exception;
use stdClass;

/**
 * Writes a deck file into an activity.
 *
 * The archive arrives from a person, so nothing in it is believed: the entry
 * list is measured before anything is unpacked, every name is checked against a
 * closed pattern, and every picture is identified by its bytes. What a question
 * is allowed to be is not re-decided here — that goes through
 * {@see interaction_manager::save_from_payload()}, the same function the editor's
 * Save button uses, so there is one set of rules rather than two that drift.
 *
 * @package    mod_interactiveslide
 * @copyright  2026 Interactive Slide contributors
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class deck_importer {

    /**
     * Most entries an archive may hold before we stop looking at it.
     *
     * A deck of the largest allowed size is one manifest plus one picture per
     * slide. Anything far past that is not a deck.
     *
     * @var int
     */
    public const MAX_ENTRIES = 1100;

    /**
     * Ceiling on the unpacked size of an archive, in bytes.
     *
     * Measured from the entry list *before* extracting, which is the only moment
     * it can be measured: once a bomb is on disk it is too late to decline.
     *
     * @var int
     */
    public const MAX_UNPACKED_BYTES = 536870912;

    /**
     * Load a deck file into an activity.
     *
     * @param context_module $context
     * @param stdClass $instance the deck record
     * @param string $zippath the uploaded archive, already checked as an upload
     * @param string $mode 'replace' or 'append'
     * @return array{slides: int, removed: int, warnings: string[]}
     * @throws moodle_exception when the file is not a deck we can write here
     */
    public static function import_from_archive(context_module $context, stdClass $instance,
            string $zippath, string $mode): array {
        global $DB;

        if (!in_array($mode, ['replace', 'append'], true)) {
            throw new moodle_exception('errorinvalidmode', 'mod_interactiveslide');
        }

        $slides = self::read_archive($zippath, $directory);

        $existing = (int)$DB->count_records('interactiveslide_slide',
            ['interactiveslideid' => $instance->id]);
        $maxpages = (int)(get_config('mod_interactiveslide', 'maxpages') ?: 200);

        if (!deck_archive::fits_in_deck($existing, count($slides), $mode, $maxpages)) {
            throw new moodle_exception('errordecktoomanytotal', 'mod_interactiveslide', '', (object)[
                'total' => ($mode === 'replace') ? count($slides) : $existing + count($slides),
                'max' => $maxpages,
            ]);
        }

        // Two hundred slides is two hundred file writes and as many inserts.
        \core_php_time_limit::raise(300);

        return self::write_deck($context, $instance, $slides, $directory, $mode, $existing);
    }

    /**
     * Measure, unpack and read an archive, returning the slides it describes.
     *
     * @param string $zippath
     * @param string|null $directory set to where the archive was unpacked
     * @return array[] normalised slides
     * @throws moodle_exception
     */
    private static function read_archive(string $zippath, ?string &$directory): array {
        $packer = get_file_packer('application/zip');

        // Measured from the central directory, before a single byte is written
        // to disk. Extracting first and checking afterwards is not a check.
        $listing = $packer->list_files($zippath);
        if ($listing === false || !is_array($listing)) {
            throw new moodle_exception('errornotadeck', 'mod_interactiveslide');
        }

        if (count($listing) > self::MAX_ENTRIES) {
            throw new moodle_exception('errordecktoolarge', 'mod_interactiveslide',
                '', deck_archive::MAX_SLIDES_HARD);
        }

        $unpacked = 0;
        foreach ($listing as $entry) {
            $unpacked += (int)($entry->size ?? 0);
        }
        if ($unpacked > self::MAX_UNPACKED_BYTES) {
            throw new moodle_exception('errordecktoolarge', 'mod_interactiveslide',
                '', deck_archive::MAX_SLIDES_HARD);
        }

        // A request directory is emptied when the request ends, whatever happens
        // in between, and it is nowhere any web server will serve from.
        $directory = make_request_directory();

        $results = $packer->extract_to_pathname($zippath, $directory);
        if ($results === false || !is_array($results)) {
            throw new moodle_exception('errornotadeck', 'mod_interactiveslide');
        }
        // extract_to_pathname reports per entry. A truncated or partly encrypted
        // archive comes back with most entries true and one of them not, so the
        // return value on its own says nothing.
        foreach ($results as $result) {
            if ($result !== true) {
                throw new moodle_exception('errornotadeck', 'mod_interactiveslide');
            }
        }

        $manifestpath = $directory . '/' . deck_archive::MANIFEST;
        if (!is_readable($manifestpath)) {
            throw new moodle_exception('errornotadeck', 'mod_interactiveslide');
        }
        // Size before read: a two gigabyte deck.json must not be pulled into
        // memory just to discover that it is a two gigabyte deck.json.
        if (filesize($manifestpath) > deck_archive::MAX_MANIFEST_BYTES) {
            throw new moodle_exception('errornotadeck', 'mod_interactiveslide');
        }

        $manifest = json_decode(file_get_contents($manifestpath), true);

        return deck_archive::validate_manifest($manifest);
    }

    /**
     * Write the slides into the activity.
     *
     * @param context_module $context
     * @param stdClass $instance
     * @param array[] $slides normalised
     * @param string $directory where the archive was unpacked
     * @param string $mode
     * @param int $existing slides already in the activity
     * @return array{slides: int, removed: int, warnings: string[]}
     * @throws moodle_exception
     */
    private static function write_deck(context_module $context, stdClass $instance,
            array $slides, string $directory, string $mode, int $existing): array {
        global $DB;

        $warnings = [];
        // Moodle's file storage does not join a database transaction, so a
        // rollback would leave the pictures behind with no rows pointing at
        // them. Every slide written is remembered so the catch can undo both.
        $created = [];

        $transaction = $DB->start_delegated_transaction();

        try {
            if ($mode === 'replace') {
                slide_manager::delete_all_slides($context, (int)$instance->id);
            }

            foreach ($slides as $index => $slide) {
                $slideid = slide_manager::create_slide((int)$instance->id,
                    (int)$slide['pageno'], (string)$slide['title']);
                $created[] = $slideid;

                if ($slide['interaction'] !== null) {
                    try {
                        interaction_manager::save_from_payload($slideid, $slide['interaction']);
                    } catch (moodle_exception $e) {
                        // Deliberately fatal, where a bad picture below is not.
                        // A missing picture is obvious in the filmstrip; a
                        // question that quietly lost its answer key is not, and
                        // the teacher would find out during the lecture.
                        throw new moodle_exception('errordeckslide', 'mod_interactiveslide', '', (object)[
                            'slide' => $index + 1,
                            'reason' => $e->getMessage(),
                        ]);
                    }
                }

                if ($slide['image'] !== null) {
                    $problem = self::store_image($context, $slideid, $directory, $slide['image']);
                    if ($problem !== null) {
                        $warnings[] = $problem;
                    }
                }
            }

            slide_manager::resequence((int)$instance->id);

            $DB->update_record('interactiveslide', (object)[
                'id' => $instance->id,
                'timemodified' => time(),
            ]);

            $transaction->allow_commit();

        } catch (\Throwable $e) {
            $transaction->rollback($e instanceof \Exception ? $e : new \Exception($e->getMessage()));

            // Unreachable in practice — rollback rethrows — but the files would
            // be orphaned if it ever were reached, so the cleanup is stated.
            self::discard_images($context, $created);
            throw $e;
        }

        return [
            'slides' => count($slides),
            'removed' => ($mode === 'replace') ? $existing : 0,
            'warnings' => $warnings,
        ];
    }

    /**
     * Write one slide's picture, or explain why it was left out.
     *
     * Fails soft. Refusing a two hundred slide deck because one file inside it
     * will not decode serves nobody: the slide is still worth having, and a
     * missing picture is visible at a glance in the filmstrip.
     *
     * @param context_module $context
     * @param int $slideid
     * @param string $directory
     * @param array $image the manifest entry
     * @return string|null a warning, or null when the picture was stored
     */
    private static function store_image(context_module $context, int $slideid,
            string $directory, array $image): ?string {
        $name = (string)$image['file'];

        // Checked once in the manifest and again here. The packer refuses
        // traversal too; none of the three is trusted on its own.
        if (!deck_archive::is_valid_image_name($name)) {
            return $name;
        }

        $path = $directory . '/' . $name;
        $real = realpath($path);
        $root = realpath($directory);

        if ($real === false || $root === false || strpos($real, $root . DIRECTORY_SEPARATOR) !== 0) {
            return $name;
        }
        if (!is_readable($real)) {
            return $name;
        }

        // The bytes decide what this is. The name in the archive never does.
        $info = @getimagesize($real);
        $types = deck_archive::image_types();
        if (!$info || !isset($types[$info[2]])) {
            return $name;
        }

        if (!empty($image['sha1']) && sha1_file($real) !== $image['sha1']) {
            // The archive arrived damaged. Say so rather than storing a picture
            // that is not the one the deck was exported with.
            return $name;
        }

        slide_manager::store_slide_image(
            $context,
            $slideid,
            $real,
            'slide-' . $slideid . '.' . $types[$info[2]],
            (int)$info[0],
            (int)$info[1]
        );

        return null;
    }

    /**
     * Remove the pictures written for a set of slides.
     *
     * @param context_module $context
     * @param int[] $slideids
     * @return void
     */
    private static function discard_images(context_module $context, array $slideids): void {
        $fs = get_file_storage();

        foreach ($slideids as $slideid) {
            $fs->delete_area_files($context->id, 'mod_interactiveslide',
                slide_manager::FILEAREA_IMAGE, $slideid);
        }
    }
}
