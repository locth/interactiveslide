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
 * Text normalisation shared by wordcloud grouping and answer matching.
 *
 * Diacritics are deliberately preserved: in Vietnamese "ma", "má" and "mà" are
 * different words, so folding accents would merge unrelated wordcloud entries
 * and accept wrong answers.
 *
 * @package    mod_interactiveslide
 * @copyright  2026 Interactive Slide contributors
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class text_util {

    /** @var string Characters trimmed from both ends of a submitted entry. */
    private const TRIM_CHARS = " \t\n\r\0\x0B.,;:!?\"'()[]{}<>/\\|`~@#$%^&*_+=-";

    /**
     * Normalise a submitted entry so equivalent answers group together.
     *
     * @param string $text raw user input
     * @param bool $casesensitive keep the original casing
     * @return string normalised text, truncated to fit the normtext column
     */
    public static function normalise(string $text, bool $casesensitive = false): string {
        $text = self::strip_invisible($text);
        // Collapse every run of whitespace (including Unicode spaces) to one space.
        $text = preg_replace('/[\p{Z}\s]+/u', ' ', $text);
        $text = trim((string)$text);
        $text = trim($text, self::TRIM_CHARS);

        if (!$casesensitive) {
            $text = \core_text::strtolower($text);
        }

        return \core_text::substr($text, 0, 255);
    }

    /**
     * Remove zero width and control characters that would let two visually
     * identical answers be stored as different strings.
     *
     * @param string $text
     * @return string
     */
    public static function strip_invisible(string $text): string {
        $text = preg_replace('/[\x{200B}-\x{200D}\x{FEFF}\x{00AD}]/u', '', $text);
        $text = preg_replace('/[\x{0000}-\x{0008}\x{000B}\x{000C}\x{000E}-\x{001F}]/u', '', (string)$text);
        return (string)$text;
    }

    /**
     * Decide whether a submitted string matches any accepted answer.
     *
     * @param string $submitted raw user input
     * @param string[] $accepted accepted answers as authored by the teacher
     * @param bool $casesensitive
     * @return bool
     */
    public static function matches_any(string $submitted, array $accepted, bool $casesensitive = false): bool {
        $needle = self::normalise($submitted, $casesensitive);
        if ($needle === '') {
            return false;
        }

        foreach ($accepted as $candidate) {
            if (!is_string($candidate)) {
                continue;
            }
            if (self::normalise($candidate, $casesensitive) === $needle) {
                return true;
            }
        }

        return false;
    }

    /**
     * Split a raw wordcloud submission into individual entries.
     *
     * Students routinely type "a, b, c" into one box, so commas, semicolons and
     * newlines all act as separators.
     *
     * @param string $raw
     * @param int $maxentries hard cap on how many entries are kept
     * @param int $maxlength maximum characters per entry
     * @return string[] cleaned entries, duplicates removed, original casing kept
     */
    public static function split_entries(string $raw, int $maxentries, int $maxlength): array {
        $raw = self::strip_invisible($raw);
        $parts = preg_split('/[\r\n,;]+/u', $raw) ?: [];

        $entries = [];
        $seen = [];
        foreach ($parts as $part) {
            $part = trim(preg_replace('/[\p{Z}\s]+/u', ' ', $part) ?? '');
            $part = trim($part, self::TRIM_CHARS);
            if ($part === '') {
                continue;
            }
            $part = \core_text::substr($part, 0, max(1, $maxlength));

            $key = \core_text::strtolower($part);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $entries[] = $part;

            if (count($entries) >= $maxentries) {
                break;
            }
        }

        return $entries;
    }

    /**
     * Encode an accepted answer list for storage.
     *
     * @param string[] $answers
     * @return string JSON
     */
    public static function encode_answers(array $answers): string {
        $clean = [];
        foreach ($answers as $answer) {
            if (!is_string($answer)) {
                continue;
            }
            $answer = trim(self::strip_invisible($answer));
            if ($answer !== '') {
                $clean[] = \core_text::substr($answer, 0, 255);
            }
        }
        return json_encode(array_values(array_unique($clean)), JSON_UNESCAPED_UNICODE);
    }

    /**
     * Decode a stored accepted answer list.
     *
     * @param string|null $json
     * @return string[]
     */
    public static function decode_answers(?string $json): array {
        if ($json === null || $json === '') {
            return [];
        }
        $decoded = json_decode($json, true);
        return is_array($decoded) ? array_values(array_filter($decoded, 'is_string')) : [];
    }
}
