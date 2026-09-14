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
 * The portable deck file: what it looks like, and what a valid one is.
 *
 * A deck travels as a zip holding `deck.json` plus an `images/` directory. This
 * class owns the shape of that manifest and every rule about reading one. It
 * deliberately keeps two halves apart:
 *
 * - the pure half — validating and normalising a manifest — touches neither the
 *   database nor the filesystem, so the rules can be exercised directly;
 * - the building half needs both, and is only used on the way out.
 *
 * Writing a deck back into an activity lives in {@see deck_importer}, because
 * that is orchestration rather than format.
 *
 * @package    mod_interactiveslide
 * @copyright  2026 Interactive Slide contributors
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class deck_archive {

    /**
     * What a file of ours says it is.
     *
     * Checked with a strict comparison before anything else is read. Without it,
     * any zip that happens to contain a `deck.json` would be taken seriously,
     * and the teacher who uploaded their holiday photos would get a stack trace
     * instead of a sentence.
     *
     * @var string
     */
    public const FORMAT = 'mod_interactiveslide.deck';

    /** @var int The version this plugin writes. */
    public const FORMATVERSION = 1;

    /**
     * Versions this plugin can read.
     *
     * The rule, which the README states so that a future maintainer keeps it:
     * adding a key never bumps this, and a reader ignores keys it does not know.
     * A bump means "a reader that does not understand this must refuse", and a
     * file from the future is then refused by name rather than half-read.
     *
     * @var int[]
     */
    public const SUPPORTED = [1];

    /** @var string The manifest's name inside the archive. */
    public const MANIFEST = 'deck.json';

    /** @var string The only directory an image may live in. */
    public const IMAGE_DIR = 'images/';

    /**
     * The most slides a manifest may claim, whatever the site allows.
     *
     * Separate from the `maxpages` site setting, which is policy about how big a
     * deck should be. This is self defence: it bounds the loop before any of the
     * work starts, so a manifest claiming a hundred thousand slides cannot make
     * us spin.
     *
     * @var int
     */
    public const MAX_SLIDES_HARD = 500;

    /** @var int Manifest size ceiling, checked before it is read into memory. */
    public const MAX_MANIFEST_BYTES = 4194304;

    /**
     * Image types a deck may carry, and the extension each is stored under.
     *
     * The same closed map upload.php uses. The bytes decide which entry applies;
     * the name in the archive never does.
     *
     * @return array<int, string>
     */
    public static function image_types(): array {
        return [
            IMAGETYPE_PNG => 'png',
            IMAGETYPE_JPEG => 'jpg',
            IMAGETYPE_GIF => 'gif',
            IMAGETYPE_WEBP => 'webp',
        ];
    }

    /**
     * The name an image takes inside the archive.
     *
     * Synthesised from the slide's position, never from what the file was called
     * on the site it came from: nothing another site named can decide a path on
     * this one.
     *
     * @param int $index zero based slide position
     * @param string $extension without the dot
     * @return string
     */
    public static function image_name(int $index, string $extension): string {
        return self::IMAGE_DIR . sprintf('%04d', $index + 1) . '.' . $extension;
    }

    /**
     * Whether an archive entry name may be read as a slide image.
     *
     * Three independent tests, in the manner of pdfjs.php: a closed pattern, a
     * check that the name is only a name, and a rejection of the byte that ends
     * a C string. The packer already refuses `..` and absolute entries; this
     * does not rely on that, so a bug in either is not enough on its own.
     *
     * @param string $name as it appears in the manifest
     * @return bool
     */
    public static function is_valid_image_name(string $name): bool {
        if ($name === '' || strpos($name, "\0") !== false) {
            return false;
        }

        if (!preg_match('~^images/\d{4}\.(png|jpg|jpeg|gif|webp)$~', $name)) {
            return false;
        }

        // Nothing may have survived the pattern that changes under basename(),
        // but stating it means the pattern is not the only thing standing there.
        return basename($name) === substr($name, strlen(self::IMAGE_DIR));
    }

    /**
     * Whether a deck of this size may be written into this activity.
     *
     * Pure arithmetic on purpose: the site's limit is the one rule here that a
     * teacher meets as a refusal, so it is worth being able to check it without
     * a database.
     *
     * @param int $existing slides already in the activity
     * @param int $incoming slides in the file
     * @param string $mode 'replace' or 'append'
     * @param int $maxpages the site limit
     * @return bool
     */
    public static function fits_in_deck(int $existing, int $incoming, string $mode, int $maxpages): bool {
        $total = ($mode === 'replace') ? $incoming : $existing + $incoming;

        return $total <= max(1, $maxpages);
    }

    /**
     * Shape one interaction for a file, or for the editor.
     *
     * This is the plugin's single serialiser for an interaction. The editor's
     * `get_deck` web service calls it too, with `$editorfields` on, so the two
     * cannot drift: there is only one of them.
     *
     * With `$editorfields` off the result is exactly the payload
     * {@see interaction_manager::save_from_payload()} accepts — which is the
     * whole point. An imported question is written by the same function the
     * Save button uses, so every rule about option counts, correct answers,
     * dropdown positions and video providers is enforced by the code that
     * already enforces them, rather than by a second copy that has to be kept
     * in step.
     *
     * @param stdClass $interaction with children attached
     * @param bool $editorfields add the ids and the star total the editor wants
     * @return array
     */
    public static function interaction_to_array(stdClass $interaction, bool $editorfields = false): array {
        $options = [];
        foreach ($interaction->options ?? [] as $option) {
            $row = [
                'optiontext' => (string)$option->optiontext,
                'iscorrect' => (int)$option->iscorrect,
            ];
            if ($editorfields) {
                $row = ['id' => (int)$option->id] + $row;
            }
            $options[] = $row;
        }

        $blanks = [];
        foreach ($interaction->blanks ?? [] as $blank) {
            $positionoptions = [];
            foreach ($blank->options ?? [] as $option) {
                $row = [
                    'optiontext' => (string)$option->optiontext,
                    'iscorrect' => (int)$option->iscorrect,
                ];
                if ($editorfields) {
                    $row = ['id' => (int)$option->id] + $row;
                }
                $positionoptions[] = $row;
            }

            $row = [
                'label' => (string)$blank->label,
                // Decoded, not the JSON the column holds: readable in the file,
                // and it keeps text_util::encode_answers the only writer of that
                // column, which is where the trimming and the caps live.
                'answers' => array_values($blank->answerlist ?? text_util::decode_answers($blank->answers)),
                'points' => (int)$blank->points,
                'difficulty' => (string)$blank->difficulty,
                'casesensitive' => (int)$blank->casesensitive,
                'options' => $positionoptions,
            ];
            if ($editorfields) {
                $row = ['id' => (int)$blank->id] + $row;
            }
            $blanks[] = $row;
        }

        $out = [
            'qtype' => (string)$interaction->qtype,
            'questiontext' => (string)$interaction->questiontext,
            'hasanswer' => (int)$interaction->hasanswer,
            'difficulty' => (string)$interaction->difficulty,
            'points' => (int)$interaction->points,
            'timerseconds' => (int)$interaction->timerseconds,
            'autoclose' => (int)$interaction->autoclose,
            'showliveresult' => (int)$interaction->showliveresult,
            'showleaderboard' => (int)$interaction->showleaderboard,
            'allowmultiple' => (int)$interaction->allowmultiple,
            'shuffleoptions' => (int)$interaction->shuffleoptions,
            'maxentries' => (int)$interaction->maxentries,
            'maxwordlength' => (int)$interaction->maxwordlength,
            'casesensitive' => (int)$interaction->casesensitive,
            // The raw URL, not the resolved embed: the editor shows the teacher
            // back what they typed, and a file has to carry the same.
            'videourl' => (string)($interaction->videourl ?? ''),
            'allowretry' => (int)$interaction->allowretry,
            'options' => $options,
            'blanks' => $blanks,
        ];

        if ($editorfields) {
            $out = ['id' => (int)$interaction->id] + $out;
            $out['maxstars'] = interaction_manager::max_stars($interaction);
        }

        return $out;
    }

    /**
     * Read one interaction out of a manifest into a save_from_payload payload.
     *
     * Only shape is settled here — that a list is a list, that a number is a
     * number. What a question is allowed to be is settled by save_from_payload,
     * which is the whitelist. Re-checking its rules here would guarantee the two
     * eventually disagree.
     *
     * @param mixed $raw
     * @return array|null null when the slide carries no interaction
     */
    public static function interaction_from_manifest($raw): ?array {
        if (!is_array($raw) || !isset($raw['qtype'])) {
            return null;
        }

        $payload = [
            'qtype' => (string)$raw['qtype'],
            'questiontext' => (string)($raw['questiontext'] ?? ''),
            'hasanswer' => (int)!empty($raw['hasanswer']),
            'difficulty' => (string)($raw['difficulty'] ?? 'easy'),
            'points' => (int)($raw['points'] ?? 1),
            'timerseconds' => (int)($raw['timerseconds'] ?? 0),
            'autoclose' => (int)!empty($raw['autoclose']),
            'showliveresult' => (int)!empty($raw['showliveresult']),
            'showleaderboard' => (int)!empty($raw['showleaderboard']),
            'allowmultiple' => (int)!empty($raw['allowmultiple']),
            'shuffleoptions' => (int)!empty($raw['shuffleoptions']),
            'maxentries' => (int)($raw['maxentries'] ?? 3),
            'maxwordlength' => (int)($raw['maxwordlength'] ?? 30),
            'casesensitive' => (int)!empty($raw['casesensitive']),
            'videourl' => (string)($raw['videourl'] ?? ''),
            'allowretry' => (int)!empty($raw['allowretry']),
            'options' => self::options_from_manifest($raw['options'] ?? []),
            'blanks' => [],
        ];

        foreach (self::as_list($raw['blanks'] ?? []) as $rawblank) {
            if (!is_array($rawblank)) {
                continue;
            }
            $payload['blanks'][] = [
                'label' => (string)($rawblank['label'] ?? ''),
                'answers' => array_values(array_filter(
                    array_map('strval', self::as_list($rawblank['answers'] ?? [])),
                    static fn($answer) => trim($answer) !== ''
                )),
                'points' => (int)($rawblank['points'] ?? 1),
                'difficulty' => (string)($rawblank['difficulty'] ?? 'easy'),
                'casesensitive' => (int)!empty($rawblank['casesensitive']),
                'options' => self::options_from_manifest($rawblank['options'] ?? []),
            ];
        }

        return $payload;
    }

    /**
     * Read a list of choices out of a manifest.
     *
     * @param mixed $raw
     * @return array[]
     */
    private static function options_from_manifest($raw): array {
        $options = [];
        foreach (self::as_list($raw) as $option) {
            if (!is_array($option)) {
                continue;
            }
            $options[] = [
                'optiontext' => (string)($option['optiontext'] ?? ''),
                'iscorrect' => (int)!empty($option['iscorrect']),
            ];
        }

        return $options;
    }

    /**
     * Treat a value as a list, whatever JSON decoding made of it.
     *
     * An empty PHP array encodes as `[]` but a sparse one encodes as an object,
     * so a file written by hand, or by another tool, can hand us either.
     *
     * @param mixed $value
     * @return array
     */
    private static function as_list($value): array {
        if (!is_array($value)) {
            return [];
        }

        return array_values($value);
    }

    /**
     * Check a decoded manifest and return the slides it describes.
     *
     * Everything structural is settled here, before a single row or file is
     * written. Nothing in this method reads the database or the disk.
     *
     * @param mixed $manifest the decoded deck.json
     * @return array[] normalised slides
     * @throws moodle_exception when the file is not a deck we can read
     */
    public static function validate_manifest($manifest): array {
        if (!is_array($manifest)
                || !isset($manifest['format'])
                || $manifest['format'] !== self::FORMAT) {
            throw new moodle_exception('errornotadeck', 'mod_interactiveslide');
        }

        $version = (int)($manifest['formatversion'] ?? 0);
        if (!in_array($version, self::SUPPORTED, true)) {
            throw new moodle_exception('errordeckversion', 'mod_interactiveslide', '', $version);
        }

        $rawslides = self::as_list($manifest['slides'] ?? []);
        if (!$rawslides) {
            throw new moodle_exception('errordeckempty', 'mod_interactiveslide');
        }

        if (count($rawslides) > self::MAX_SLIDES_HARD) {
            throw new moodle_exception('errordecktoolarge', 'mod_interactiveslide',
                '', self::MAX_SLIDES_HARD);
        }

        $slides = [];
        $seenimages = [];

        foreach ($rawslides as $index => $rawslide) {
            if (!is_array($rawslide)) {
                throw new moodle_exception('errordeckslidebroken', 'mod_interactiveslide',
                    '', $index + 1);
            }

            $image = null;
            $rawimage = $rawslide['image'] ?? null;
            if (is_array($rawimage) && isset($rawimage['file'])) {
                $file = (string)$rawimage['file'];
                if (!self::is_valid_image_name($file)) {
                    throw new moodle_exception('errordeckimage', 'mod_interactiveslide',
                        '', $index + 1);
                }
                // Two slides pointing at one file is ambiguous bookkeeping, and
                // ambiguous bookkeeping is where the next bug comes from.
                if (isset($seenimages[$file])) {
                    throw new moodle_exception('errordeckimage', 'mod_interactiveslide',
                        '', $index + 1);
                }
                $seenimages[$file] = true;

                $image = [
                    'file' => $file,
                    'sha1' => (string)($rawimage['sha1'] ?? ''),
                ];
                // Width and height are read but not kept. What the picture
                // actually measures is decided by getimagesize() when it is
                // written; a manifest that lied about it would break every
                // layout downstream, which sizes the stage from those numbers.
            }

            $slides[] = [
                'sortorder' => $index,
                'title' => \core_text::substr(clean_param((string)($rawslide['title'] ?? ''), PARAM_TEXT), 0, 255),
                'pageno' => max(0, min(self::MAX_SLIDES_HARD, (int)($rawslide['pageno'] ?? 0))),
                'image' => $image,
                'interaction' => self::interaction_from_manifest($rawslide['interaction'] ?? null),
            ];
        }

        return $slides;
    }

    /**
     * Build the manifest for a deck as it stands.
     *
     * @param context_module $context
     * @param stdClass $instance the deck record
     * @return array{manifest: array, files: array<string, \stored_file>}
     */
    public static function build_manifest(context_module $context, stdClass $instance): array {
        global $CFG;

        $fs = get_file_storage();
        $slides = slide_manager::get_slides_with_interactions((int)$instance->id);

        $entries = [];
        $files = [];
        $index = 0;

        foreach ($slides as $slide) {
            $image = null;

            if (!empty($slide->imagefilename)) {
                $stored = $fs->get_file($context->id, 'mod_interactiveslide',
                    slide_manager::FILEAREA_IMAGE, $slide->id, '/', $slide->imagefilename);

                if ($stored && !$stored->is_directory()) {
                    $extension = strtolower(pathinfo($slide->imagefilename, PATHINFO_EXTENSION));
                    if (!in_array($extension, self::image_types(), true)) {
                        $extension = 'png';
                    }
                    $name = self::image_name($index, $extension);
                    $files[$name] = $stored;
                    $image = [
                        'file' => $name,
                        'width' => (int)$slide->imagewidth,
                        'height' => (int)$slide->imageheight,
                        // Free: Moodle already keeps a sha1 of every stored file.
                        // It is here to catch the truncated download, which would
                        // otherwise surface as a broken picture during a lecture.
                        'sha1' => (string)$stored->get_contenthash(),
                    ];
                }
            }

            $interaction = null;
            if ($slide->interaction) {
                $interaction = self::interaction_to_array(
                    interaction_manager::attach_children($slide->interaction));
            }

            $entries[] = [
                'sortorder' => $index,
                'title' => (string)$slide->title,
                'pageno' => (int)$slide->pageno,
                'image' => $image,
                'interaction' => $interaction,
            ];

            $index++;
        }

        $manifest = [
            'format' => self::FORMAT,
            'formatversion' => self::FORMATVERSION,
            'generator' => [
                'plugin' => 'mod_interactiveslide',
                'release' => get_config('mod_interactiveslide', 'release') ?: '',
                'moodle' => $CFG->release ?? '',
                'exported' => time(),
                // Carried so an import can say out loud that it re-priced the
                // deck, rather than leaving the teacher to notice.
                'starvalues' => [
                    'easy' => interaction_manager::points_for_difficulty('easy'),
                    'medium' => interaction_manager::points_for_difficulty('medium'),
                    'hard' => interaction_manager::points_for_difficulty('hard'),
                ],
            ],
            'deck' => [
                'name' => (string)$instance->name,
                'pdffilename' => (string)($instance->pdffilename ?? ''),
                'slidecount' => count($entries),
            ],
            'slides' => $entries,
        ];

        return ['manifest' => $manifest, 'files' => $files];
    }
}
