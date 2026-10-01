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

/**
 * The picture a student attaches to an open ended answer.
 *
 * One picture per person per round, stored under the round so that clearing a
 * round clears the pictures with it. The browser never gets to name a file: it
 * uploads, and the name is built here from the author and a random tail. That
 * is what stops one student overwriting another's picture, and what stops a
 * classmate reading an answer off a guessed URL before the teacher shows it.
 *
 * @package    mod_interactiveslide
 * @copyright  2026 Interactive Slide contributors
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class answer_image {

    /** @var string The file area pictures attached to answers live in. */
    public const FILEAREA = 'answerimage';

    /** @var int Characters of randomness in a stored name. */
    private const TAIL_LENGTH = 16;

    /** @var array<int, string> Image types accepted, by the bytes rather than the name. */
    public const TYPES = [
        IMAGETYPE_PNG => 'png',
        IMAGETYPE_JPEG => 'jpg',
        IMAGETYPE_GIF => 'gif',
        IMAGETYPE_WEBP => 'webp',
    ];

    /**
     * The prefix every picture of one person in one round carries.
     *
     * A guest's id is negative, which is why the sign is spelled out rather
     * than left to string concatenation: "a-4-" and "a4-" must not be able to
     * collide, or one guest could claim a student's picture.
     *
     * @param int $userid
     * @return string
     */
    public static function prefix(int $userid): string {
        return $userid < 0 ? ('g' . abs($userid) . '-') : ('u' . $userid . '-');
    }

    /**
     * Whether a stored name belongs to this person.
     *
     * @param string $filename
     * @param int $userid
     * @return bool
     */
    public static function belongs_to(string $filename, int $userid): bool {
        return $filename !== '' && strpos($filename, self::prefix($userid)) === 0;
    }

    /**
     * A fresh name for a picture this person is uploading.
     *
     * @param int $userid
     * @param string $extension without the dot
     * @return string
     */
    public static function new_filename(int $userid, string $extension): string {
        return self::prefix($userid) . random_string(self::TAIL_LENGTH) . '.' . $extension;
    }

    /**
     * Store an uploaded picture, replacing whatever this person had before.
     *
     * @param context_module $context
     * @param int $roundid
     * @param int $userid
     * @param string $sourcepath the uploaded temporary file
     * @param string $filename from {@see self::new_filename()}
     * @return void
     */
    public static function store(context_module $context, int $roundid, int $userid,
            string $sourcepath, string $filename): void {
        self::remove($context, $roundid, $userid);

        get_file_storage()->create_file_from_pathname([
            'contextid' => $context->id,
            'component' => 'mod_interactiveslide',
            'filearea' => self::FILEAREA,
            'itemid' => $roundid,
            'filepath' => '/',
            'filename' => $filename,
        ], $sourcepath);
    }

    /**
     * The name of the picture this person has waiting in this round, if any.
     *
     * Found by looking, not by being told: the submission carries no file name,
     * so there is nothing there for a browser to tamper with.
     *
     * @param context_module $context
     * @param int $roundid
     * @param int $userid
     * @return string empty when there is none
     */
    public static function current(context_module $context, int $roundid, int $userid): string {
        foreach (self::files($context, $roundid) as $file) {
            if (self::belongs_to($file->get_filename(), $userid)) {
                return $file->get_filename();
            }
        }

        return '';
    }

    /**
     * Take away whatever picture this person had in this round.
     *
     * @param context_module $context
     * @param int $roundid
     * @param int $userid
     * @return bool whether anything was there
     */
    public static function remove(context_module $context, int $roundid, int $userid): bool {
        $removed = false;

        foreach (self::files($context, $roundid) as $file) {
            if (self::belongs_to($file->get_filename(), $userid)) {
                $file->delete();
                $removed = true;
            }
        }

        return $removed;
    }

    /**
     * Every picture stored against one round.
     *
     * @param context_module $context
     * @param int $roundid
     * @return \stored_file[]
     */
    private static function files(context_module $context, int $roundid): array {
        return get_file_storage()->get_area_files($context->id, 'mod_interactiveslide',
            self::FILEAREA, $roundid, 'filename', false);
    }

    /**
     * Where a stored picture can be fetched from.
     *
     * @param context_module $context
     * @param int $roundid
     * @param string $filename
     * @return string empty when there is no picture
     */
    public static function url(context_module $context, int $roundid, string $filename): string {
        if ($filename === '') {
            return '';
        }

        return \moodle_url::make_pluginfile_url(
            $context->id,
            'mod_interactiveslide',
            self::FILEAREA,
            $roundid,
            '/',
            $filename
        )->out(false);
    }

    /**
     * Drop every picture attached to the given rounds.
     *
     * @param context_module $context
     * @param int[] $roundids
     * @return void
     */
    public static function delete_for_rounds(context_module $context, array $roundids): void {
        $fs = get_file_storage();

        foreach ($roundids as $roundid) {
            $fs->delete_area_files($context->id, 'mod_interactiveslide', self::FILEAREA, (int)$roundid);
        }
    }

    /**
     * Drop every picture one person left behind in the given rounds.
     *
     * Used when a guest is taken off the board: their answers go, and the
     * pictures that went with them have no reason to stay.
     *
     * @param context_module $context
     * @param int[] $roundids
     * @param int $userid
     * @return void
     */
    public static function delete_for_user(context_module $context, array $roundids, int $userid): void {
        foreach ($roundids as $roundid) {
            self::remove($context, (int)$roundid, $userid);
        }
    }
}
