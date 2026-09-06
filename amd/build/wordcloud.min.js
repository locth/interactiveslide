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
 * Lays a word cloud out with a spiral packing pass.
 *
 * The words are measured after they are in the document, so this works with
 * whatever font the site theme provides, including Vietnamese diacritics that
 * make glyphs taller than a Latin-only estimate would predict.
 *
 * @module     mod_interactiveslide/wordcloud
 * @copyright  2026 Interactive Slide contributors
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
define([], function() {

    var MIN_FONT = 15;
    var MAX_FONT = 68;
    var PADDING = 6;
    var SPIRAL_STEP = 0.25;
    var MAX_SPIRAL_TURNS = 260;

    /**
     * Whether two rectangles overlap.
     *
     * @param {Object} a
     * @param {Object} b
     * @return {Boolean}
     */
    var overlaps = function(a, b) {
        return !(a.right < b.left || a.left > b.right || a.bottom < b.top || a.top > b.bottom);
    };

    /**
     * Render a word cloud into a container.
     *
     * @param {Element} container the element to fill; it is emptied first
     * @param {Array} words entries with text, count and weight
     * @param {Object} [options] maxFont to cap the largest word
     * @return {Number} how many words were placed
     */
    var render = function(container, words, options) {
        options = options || {};
        container.textContent = '';
        container.classList.add('islide-cloud');

        if (!words || !words.length) {
            return 0;
        }

        var width = container.clientWidth || 800;
        var height = container.clientHeight || 400;
        var centreX = width / 2;
        var centreY = height / 2;

        var maxFont = Math.min(options.maxFont || MAX_FONT, Math.max(MIN_FONT + 4, height / 4));
        var placed = [];

        // Biggest first: the crowd's answer should land in the middle.
        var sorted = words.slice().sort(function(a, b) {
            return b.count - a.count;
        });

        sorted.forEach(function(word, index) {
            var span = document.createElement('span');
            span.className = 'islide-cloud-word islide-cloud-tone-' + (index % 6);
            span.textContent = word.text;
            span.style.fontSize = Math.round(
                MIN_FONT + ((maxFont - MIN_FONT) * (Math.max(1, word.weight) / 10))
            ) + 'px';
            span.title = word.text + ' × ' + word.count;
            span.setAttribute('data-count', word.count);
            container.appendChild(span);

            var boxWidth = span.offsetWidth + PADDING;
            var boxHeight = span.offsetHeight + PADDING;

            var angle = 0;
            var found = false;

            while (angle < MAX_SPIRAL_TURNS) {
                // Archimedean spiral, squashed horizontally so the cloud reads wide.
                var radius = 4 * angle;
                var x = centreX + (radius * Math.cos(angle)) * 1.6 - boxWidth / 2;
                var y = centreY + (radius * Math.sin(angle)) - boxHeight / 2;

                var rect = {
                    left: x,
                    top: y,
                    right: x + boxWidth,
                    bottom: y + boxHeight
                };

                var insideCanvas = rect.left >= 0 && rect.top >= 0
                    && rect.right <= width && rect.bottom <= height;

                if (insideCanvas && !placed.some(function(other) {
                    return overlaps(rect, other);
                })) {
                    span.style.left = Math.round(x) + 'px';
                    span.style.top = Math.round(y) + 'px';
                    placed.push(rect);
                    found = true;
                    break;
                }

                angle += SPIRAL_STEP;
            }

            if (!found) {
                // No room left: dropping the word beats overlapping the cloud.
                container.removeChild(span);
            }
        });

        return placed.length;
    };

    /**
     * Render a simple ranked list, used when the cloud has no room to breathe.
     *
     * @param {Element} container
     * @param {Array} words
     * @param {Function} escape
     * @return {void}
     */
    var renderList = function(container, words, escape) {
        var html = '<ol class="islide-cloud-list">';
        words.forEach(function(word) {
            html += '<li><span class="islide-cloud-list-text">' + escape(word.text) + '</span>' +
                '<span class="islide-cloud-list-count">' + escape(word.count) + '</span></li>';
        });
        html += '</ol>';
        container.innerHTML = html;
    };

    return {
        render: render,
        renderList: renderList
    };
});
