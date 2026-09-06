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

/**
 * Reads the site settings, with the defaults that apply before an administrator
 * has saved the form even once.
 *
 * Kept here rather than in the renderer because the state builder needs the same
 * answers, and domain code should not have to reach into the output layer to get
 * them.
 *
 * @package    mod_interactiveslide
 * @copyright  2026 Interactive Slide contributors
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class settings {

    /**
     * How imported pages should be encoded.
     *
     * PNG is lossless but compresses a gradient badly: a templated lecture slide
     * measures around 1.7MB as PNG against 140KB at JPEG 85, and every student
     * in the room downloads it once. JPEG is therefore the default, with PNG
     * kept for decks whose fine text has to stay pin sharp.
     *
     * @return array{mime: string, quality: float, extension: string}
     */
    public static function image_format(): array {
        $formats = [
            'jpeg85' => ['mime' => 'image/jpeg', 'quality' => 0.85, 'extension' => 'jpg'],
            'jpeg70' => ['mime' => 'image/jpeg', 'quality' => 0.70, 'extension' => 'jpg'],
            'png' => ['mime' => 'image/png', 'quality' => 1.0, 'extension' => 'png'],
        ];

        $chosen = (string)(get_config('mod_interactiveslide', 'imageformat') ?: 'jpeg85');

        return $formats[$chosen] ?? $formats['jpeg85'];
    }

    /**
     * Whether students are shown the slide picture at all.
     *
     * With this off the phone is an answer device and the room reads the
     * projector, which removes the largest single cost of a big session: every
     * student downloading every slide.
     *
     * @return bool
     */
    public static function student_slides_visible(): bool {
        $setting = get_config('mod_interactiveslide', 'studentslides');

        // Unset on a site that upgraded from before this option existed, and the
        // old behaviour was to show them.
        return $setting === false || (int)$setting === 1;
    }

    /**
     * How often the polling clients should ask for the live state.
     *
     * @return int milliseconds
     */
    public static function poll_interval(): int {
        return (int)(get_config('mod_interactiveslide', 'pollinterval') ?: 2000);
    }
}
