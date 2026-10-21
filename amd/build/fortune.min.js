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
 * How the cards of a random call look, shared by the projector and the phone.
 *
 * The server decides which card is drawn and says it in words; this module only
 * knows how each card is drawn. Icons are SVG rather than symbol characters,
 * because a projector's fonts decide what a character looks like: the wheel that
 * is a neat outline on one laptop is a coloured emoji, or an empty box, on the next.
 *
 * @module     mod_interactiveslide/fortune
 * @copyright  2026 Interactive Slide contributors
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
define(['mod_interactiveslide/util'], function(Util) {

    /** @var {String} Opening of every card icon: a 64 unit square drawn in the card's own colour. */
    var SVG = '<svg viewBox="0 0 64 64" aria-hidden="true" focusable="false" fill="none" stroke="currentColor" ' +
        'stroke-width="3.5" stroke-linecap="round" stroke-linejoin="round">';

    /** @var {Object} One icon per card, keyed as the server keys the deck. */
    var ICONS = {
        moon: '<path fill="currentColor" stroke="none" d="M40 9a23 23 0 1 0 0 46a18 18 0 1 1 0-46z"/>' +
            '<path fill="currentColor" stroke="none" d="M49 20l1.4 4.6 4.6 1.4-4.6 1.4-1.4 4.6-1.4-4.6-4.6-1.4 4.6-1.4z"/>',
        gemini: '<path d="M14 14c11 6 25 6 36 0M14 50c11-6 25-6 36 0M25 18v28M39 18v28"/>',
        emperor: '<path fill="currentColor" stroke="none" d="M11 44l3-25 11 10 7-15 7 15 11-10 3 25z"/>' +
            '<rect fill="currentColor" stroke="none" x="11" y="48" width="42" height="6" rx="2"/>' +
            '<circle fill="currentColor" stroke="none" cx="32" cy="11" r="3"/>',
        sun: '<circle fill="currentColor" stroke="none" cx="32" cy="32" r="11"/>' +
            '<path d="M32 6v8M32 50v8M6 32h8M50 32h8M13.6 13.6l5.7 5.7M44.7 44.7l5.7 5.7M13.6 50.4l5.7-5.7M44.7 19.3l5.7-5.7"/>',
        mercury: '<path d="M21 8a11 11 0 0 0 22 0"/><circle cx="32" cy="27" r="10"/><path d="M32 37v20M24 49h16"/>',
        hermit: '<path d="M27 8h10M32 8v5"/><path d="M24 17h16l4 7v19l-4 7H24l-4-7V24z"/>' +
            '<path fill="currentColor" stroke="none" d="M32 26c4 5 5 9 0 14c-5-5-4-9 0-14z"/>',
        star: '<path fill="currentColor" stroke="none" d="M32 5l5.5 21.5L59 32l-21.5 5.5L32 59l-5.5-21.5L5 32l21.5-5.5z"/>' +
            '<path fill="currentColor" stroke="none" d="M51 7l1 3 3 1-3 1-1 3-1-3-3-1 3-1z"/>',
        wheel: '<circle cx="32" cy="32" r="23"/><circle cx="32" cy="32" r="6"/>' +
            '<path d="M32 9v17M32 38v17M9 32h17M38 32h17M15.7 15.7l12 12M36.3 36.3l12 12M15.7 48.3l12-12M36.3 27.7l12-12"/>',
        magician: '<path d="M32 32C25 21 9 21 9 32s16 11 23 0c7-11 23-11 23 0s-16 11-23 0z"/>',
        jupiter: '<path d="M12 20c5-9 22-10 22 2c0 10-11 17-20 20h38M42 8v50"/>'
    };

    /** @var {Object} Each card's own colour; the frame, glow and rays all take it. */
    var ACCENTS = {
        moon: '#a5b8ff',
        gemini: '#7fd4ff',
        emperor: '#ff9f6e',
        sun: '#ffcf5a',
        mercury: '#9ff0b8',
        hermit: '#8fd3c8',
        star: '#ffc4e1',
        wheel: '#e59bff',
        magician: '#ff86c0',
        jupiter: '#ffd36b'
    };

    /**
     * Fill a language string's placeholders.
     *
     * @param {String} template
     * @param {*} values a single value for {$a}, or an object for {$a->name}
     * @return {String}
     */
    var fill = function(template, values) {
        var text = String(template);
        if (values !== null && typeof values === 'object') {
            Object.keys(values).forEach(function(key) {
                text = text.split('{$a->' + key + '}').join(values[key]);
            });
            return text;
        }
        return text.split('{$a}').join(values);
    };

    /**
     * Two letters for someone with no picture.
     *
     * A Vietnamese name is said by its last word, so that word and the one
     * before it make the initials: Nguyễn Minh Anh is MA, not NA.
     *
     * @param {String} name
     * @return {String}
     */
    var initials = function(name) {
        var words = String(name || '').trim().split(/\s+/).filter(Boolean);
        if (!words.length) {
            return '?';
        }
        var last = words[words.length - 1];
        var before = words.length > 1 ? words[words.length - 2] : '';
        return (before.charAt(0) + last.charAt(0)).toUpperCase();
    };

    /**
     * The colour of a card.
     *
     * @param {String} key
     * @return {String}
     */
    var accent = function(key) {
        return ACCENTS[key] || '#ffd36b';
    };

    /**
     * The icon of a card.
     *
     * @param {String} key
     * @return {String} SVG markup
     */
    var icon = function(key) {
        return SVG + (ICONS[key] || ICONS.star) + '</svg>';
    };

    /**
     * The face of a fortune card.
     *
     * @param {Object} card the fortune as the server exports it
     * @return {String} markup for the inside of an .islide-fcard element
     */
    var face = function(card) {
        return '<div class="islide-fcard-cap">' + Util.escape(card.arcana) + '</div>' +
            '<div class="islide-fcard-glyph">' + icon(card.key) + '</div>' +
            '<div><div class="islide-fcard-title">' + Util.escape(card.name) + '</div>' +
            '<div class="islide-fcard-short">' + Util.escape(card.short) + '</div></div>';
    };

    /**
     * The back of a fortune card: a moon inside a sun, in gold on violet.
     *
     * @param {String} label
     * @return {String}
     */
    var tarotBack = function(label) {
        return '<svg viewBox="0 0 100 150" aria-hidden="true" focusable="false">' +
            '<g fill="none" stroke="#e8c26a" stroke-width="1.2">' +
            '<rect x="6" y="6" width="88" height="138" rx="5" opacity=".45"/>' +
            '<circle cx="50" cy="75" r="30" opacity=".55"/>' +
            '<circle cx="50" cy="75" r="37" opacity=".3" stroke-dasharray="1.5 3.5"/>' +
            '<path d="M50 20v14M50 116v-6M14 75h8M78 75h8" opacity=".6"/></g>' +
            '<path fill="#ffd36b" d="M57 57a20 20 0 1 0 0 36a16 16 0 1 1 0-36z"/>' +
            '<path fill="#fff" d="M62 66l1.6 6.4L70 74l-6.4 1.6L62 82l-1.6-6.4L54 74l6.4-1.6z"/>' +
            '<g fill="#ffd36b"><circle cx="50" cy="14" r="2.2"/><circle cx="22" cy="30" r="1.2"/>' +
            '<circle cx="78" cy="30" r="1.2"/><circle cx="22" cy="120" r="1.2"/><circle cx="78" cy="120" r="1.2"/></g>' +
            '<text x="50" y="132" text-anchor="middle" font-family="Georgia, serif" font-size="6" ' +
            'letter-spacing="2.5" fill="#c9a24a">' + Util.escape(String(label).toUpperCase()) + '</text></svg>';
    };

    /**
     * The back of a name card: an eight pointed star.
     *
     * @return {String}
     */
    var nameBack = function() {
        return '<svg viewBox="0 0 100 100" aria-hidden="true" focusable="false">' +
            '<g fill="none" stroke="#e8c26a" stroke-width="2"><circle cx="50" cy="50" r="44" opacity=".5"/>' +
            '<circle cx="50" cy="50" r="34" opacity=".35"/></g>' +
            '<path fill="#ffd36b" d="M50 8l7 35 35 7-35 7-7 35-7-35-35-7 35-7z"/>' +
            '<path fill="#fff" opacity=".85" d="M50 30l3 17 17 3-17 3-3 17-3-17-17-3 17-3z"/></svg>';
    };

    return {
        accent: accent,
        face: face,
        fill: fill,
        icon: icon,
        initials: initials,
        nameBack: nameBack,
        tarotBack: tarotBack
    };
});
