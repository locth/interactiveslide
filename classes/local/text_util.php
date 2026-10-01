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

    /**
     * Characters trimmed from both ends of a submitted entry.
     *
     * Sentence punctuation only. It used to be every punctuation mark on the
     * keyboard, which forgave "Paris." but also quietly turned "-5" into "5",
     * "<" into nothing, and — in a digital logic class — B'D' into B'D, so a
     * student who left the last complement off was marked correct. What a
     * teacher types in an answer key is the answer; only the full stop at the
     * end of a sentence is noise.
     *
     * @var string
     */
    private const TRIM_CHARS = " \t\n\r\0\x0B.,;:!?";

    /** @var string[] Quote pairs stripped when they wrap the whole entry. */
    private const WRAPPING_QUOTES = ["'", '"'];

    /**
     * Typographic characters a keyboard substitutes, and the plain ones they
     * stand for.
     *
     * A phone turns ' into ’ as you type, and a laptop does not. The two look
     * the same on a projector and are different bytes, so an answer key typed
     * on the teacher's laptop as B'D' was not matched by the same answer typed
     * on a student's phone as B’D’ — which is a student losing a star for
     * owning a phone. Primes are in the list because that is what a phone
     * offers for a derivative or a complement, and dashes because the same
     * substitution happens to them.
     *
     * Diacritics are still never folded: in Vietnamese "ma", "má" and "mà" are
     * different words. This is only about punctuation nobody chose.
     *
     * @var array<string, string>
     */
    private const PUNCTUATION_FOLD = [
        // Single quotes, apostrophes and primes.
        "\u{2018}" => "'", "\u{2019}" => "'", "\u{201A}" => "'", "\u{201B}" => "'",
        "\u{2032}" => "'", "\u{00B4}" => "'", "\u{02B9}" => "'", "\u{02BC}" => "'",
        "\u{02C8}" => "'", "\u{FF07}" => "'",
        // Double quotes.
        "\u{201C}" => '"', "\u{201D}" => '"', "\u{201E}" => '"', "\u{201F}" => '"',
        "\u{2033}" => '"', "\u{02BA}" => '"', "\u{FF02}" => '"',
        // Dashes and the minus sign.
        "\u{2010}" => '-', "\u{2011}" => '-', "\u{2012}" => '-', "\u{2013}" => '-',
        "\u{2014}" => '-', "\u{2015}" => '-', "\u{2212}" => '-', "\u{FF0D}" => '-',
    ];

    /**
     * Replace typographic punctuation with the plain equivalent.
     *
     * @param string $text
     * @return string
     */
    public static function fold_punctuation(string $text): string {
        return strtr($text, self::PUNCTUATION_FOLD);
    }

    /**
     * Normalise a submitted entry so equivalent answers group together.
     *
     * @param string $text raw user input
     * @param bool $casesensitive keep the original casing
     * @return string normalised text, truncated to fit the normtext column
     */
    public static function normalise(string $text, bool $casesensitive = false): string {
        $text = self::strip_invisible($text);
        // Before the trim below, so that a curly apostrophe at the end of an
        // answer is trimmed exactly as a straight one is.
        $text = self::fold_punctuation($text);
        // Collapse every run of whitespace (including Unicode spaces) to one space.
        $text = preg_replace('/[\p{Z}\s]+/u', ' ', $text);
        $text = trim((string)$text);
        $text = trim($text, self::TRIM_CHARS);
        $text = self::strip_wrapping_quotes($text);

        if (!$casesensitive) {
            $text = \core_text::strtolower($text);
        }

        return \core_text::substr($text, 0, 255);
    }

    /**
     * Remove one pair of quotes wrapping a whole entry.
     *
     * "Paris" is Paris written with quotes round it. B'D' is not: the mark at
     * the end is part of the answer and there is no mark at the start to pair
     * it with, which is what tells the two apart.
     *
     * @param string $text already trimmed
     * @return string
     */
    private static function strip_wrapping_quotes(string $text): string {
        foreach (self::WRAPPING_QUOTES as $quote) {
            if (strlen($text) >= 2 && $text[0] === $quote && substr($text, -1) === $quote) {
                return trim(substr($text, 1, -1), self::TRIM_CHARS);
            }
        }

        return $text;
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
     * Clean a piece of text a teacher typed, without deciding it contains markup.
     *
     * PARAM_TEXT, which this used to be, ends in strip_tags(). That is right for
     * a name that will be printed through format_string(), and wrong for the
     * content of a question: strip_tags() reads "<" as the start of a tag, so
     * "0<x<1" is stored as "0", a dropdown choice of "<" is stored as nothing at
     * all, and the teacher is never told. A mathematics lecturer types those.
     *
     * So this keeps the text as it was typed and removes only what could not
     * have been meant: invalid UTF-8, and the control characters that let two
     * identical looking answers be stored as different strings. It is the same
     * treatment student answers have always had ({@see self::encode_answers()}),
     * and it is safe for the same reason: every renderer of these fields escapes
     * them - Util.escape() or textContent in the AMD modules, {{ }} in the
     * templates. Nothing writes them into a page as markup.
     *
     * @param string $text as typed
     * @return string
     */
    public static function clean_plain(string $text): string {
        // PARAM_RAW is not "no cleaning": it repairs broken UTF-8, which is the
        // one thing that must not reach the database.
        return self::strip_invisible((string)clean_param($text, PARAM_RAW));
    }

    /**
     * Fold a name for searching: lower case, no accents, single spaces.
     *
     * A lecturer typing a name to hand out a star types "nguyen", not
     * "Nguyễn", and should still find the student. Every Vietnamese diacritic
     * decomposes into a base letter plus combining marks in U+0300..U+036F, so
     * dropping that range is the whole job, except for "đ", which is a letter
     * of its own rather than a "d" with a mark on it.
     *
     * The presenter's JavaScript folds with exactly the same rules, so a name
     * the board finds locally is the same name the server finds.
     *
     * @param string $text
     * @return string
     */
    public static function search_fold(string $text): string {
        $text = self::strip_invisible($text);
        if (class_exists('\Normalizer')) {
            $text = (string)\Normalizer::normalize($text, \Normalizer::FORM_D);
        }
        $text = preg_replace('/[\x{0300}-\x{036F}]/u', '', $text);
        $text = str_replace(['đ', 'Đ'], 'd', (string)$text);
        $text = \core_text::strtolower($text);
        $text = preg_replace('/[\p{Z}\s]+/u', ' ', $text);

        return trim((string)$text);
    }

    /**
     * Whether every word of a search starts some word of a name.
     *
     * Word by word and in any order, because a Vietnamese name is written
     * family name first while a lecturer calls a student by the given name:
     * "an nguyen" has to find "Nguyễn Văn An". The start of a word rather than
     * anywhere in it, because once accents are gone "an" is inside "Trần",
     * "Khánh" and "Hoàng", and a search for An would list half the class.
     *
     * @param string $name
     * @param string $query as typed
     * @return bool false for an empty query
     */
    public static function name_matches(string $name, string $query): bool {
        $query = self::search_fold($query);
        if ($query === '') {
            return false;
        }

        $namewords = explode(' ', self::search_fold($name));
        foreach (explode(' ', $query) as $word) {
            $found = false;
            foreach ($namewords as $nameword) {
                if (strncmp($nameword, $word, strlen($word)) === 0) {
                    $found = true;
                    break;
                }
            }
            if (!$found) {
                return false;
            }
        }

        return true;
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
