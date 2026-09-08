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
 * Draws aggregated results and leaderboards.
 *
 * The presenter overlay and the student result panel show the same things, so
 * both screens call into here rather than growing two copies that drift.
 *
 * @module     mod_interactiveslide/render
 * @copyright  2026 Interactive Slide contributors
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
define([
    'mod_interactiveslide/util',
    'mod_interactiveslide/wordcloud'
], function(Util, WordCloud) {

    var esc = Util.escape;

    /**
     * Redraw a container only when what it is showing has actually changed.
     *
     * Both screens re-apply the whole state document on every poll. Rebuilding
     * identical markup two seconds apart made the word cloud and the bar chart
     * visibly flash, and it swapped the leaderboard's buttons out from under a
     * teacher's finger mid-click.
     *
     * @param {Element} container
     * @param {*} data whatever the container is about to render
     * @param {Function} draw called only when the data is new
     * @return {Boolean} true when a redraw happened
     */
    var redrawIfChanged = function(container, data, draw) {
        var signature = JSON.stringify(data);
        if (container.dataset.signature === signature) {
            return false;
        }
        container.dataset.signature = signature;
        draw();
        return true;
    };

    /**
     * Draw the aggregated result of a round.
     *
     * @param {Element} container
     * @param {Object} results the results branch of the state document
     * @param {Object} strings resolved language strings
     * @param {Object} [options] compact for the smaller student panel
     * @return {void}
     */
    var results = function(container, data, strings, options) {
        options = options || {};

        // The options are part of the signature: the same answers with star
        // buttons on are different markup from the same answers without them.
        redrawIfChanged(container, [data, options], function() {
            if (!data) {
                container.innerHTML = '';
                return;
            }

            switch (data.qtype) {
                case 'video':
                    // Nothing was collected; the video is the content.
                    container.innerHTML = videoEmbed(options.interaction || {}, strings);
                    break;
                case 'wordcloud':
                    renderWordcloud(container, data, strings, options);
                    break;
                case 'multichoice':
                    renderChoices(container, data, strings, options);
                    break;
                case 'dropdown':
                    // One tally per position in the sentence, which is the same
                    // shape a fill in the blank produces.
                    renderBlanks(container, data, strings, options);
                    break;
                case 'fillblank':
                    renderBlanks(container, data, strings, options);
                    break;
                case 'openended':
                    renderOpenEnded(container, data, strings, options);
                    break;
                default:
                    container.innerHTML = '';
            }
        });
    };

    /**
     * Draw a word cloud, falling back to a list in a narrow box.
     *
     * @param {Element} container
     * @param {Object} results
     * @param {Object} strings
     * @return {void}
     */
    var renderWordcloud = function(container, results, strings, options) {
        options = options || {};
        container.innerHTML = '';

        if (!results.words || !results.words.length) {
            container.appendChild(emptyNote(strings.nowordsyet));
            return;
        }

        var canvas = Util.create('div', 'islide-cloud-canvas');
        container.appendChild(canvas);

        // offsetWidth is only meaningful once the node is laid out.
        window.requestAnimationFrame(function() {
            if (canvas.clientWidth < 320 || canvas.clientHeight < 160) {
                WordCloud.renderList(canvas, results.words, esc);
                return;
            }
            // On a projector the cloud has to read from the back of the room.
            var placed = WordCloud.render(canvas, results.words,
                options.large ? {maxFont: 150} : {});
            if (!placed) {
                WordCloud.renderList(canvas, results.words, esc);
            }
        });
    };

    /**
     * Draw the vote bars of a multiple choice question.
     *
     * @param {Element} container
     * @param {Object} results
     * @param {Object} strings
     * @param {Object} options
     * @return {void}
     */
    var renderChoices = function(container, results, strings, options) {
        if (!results.choices || !results.choices.length) {
            container.innerHTML = '';
            container.appendChild(emptyNote(strings.noanswersyet));
            return;
        }

        var letters = 'ABCDEFGHIJ';
        var html = '<div class="islide-bars' + (options.compact ? ' islide-bars-compact' : '') + '">';

        results.choices.forEach(function(choice, index) {
            var classes = 'islide-bar';
            if (choice.iscorrect) {
                classes += ' islide-bar-correct';
            }
            html += '<div class="' + classes + '">' +
                '<span class="islide-bar-letter">' + esc(letters.charAt(index) || (index + 1)) + '</span>' +
                '<span class="islide-bar-text">' + esc(choice.text) + '</span>' +
                '<span class="islide-bar-track">' +
                    '<span class="islide-bar-fill" style="width:' + Number(choice.percent) + '%"></span>' +
                '</span>' +
                '<span class="islide-bar-count">' + esc(choice.count) +
                    ' <small>' + Number(choice.percent) + '%</small></span>' +
                '</div>';
        });

        html += '</div>';
        container.innerHTML = html;
    };

    /**
     * Draw the answers given for each blank.
     *
     * @param {Element} container
     * @param {Object} results
     * @param {Object} strings
     * @param {Object} options
     * @return {void}
     */
    var renderBlanks = function(container, results, strings, options) {
        if (!results.blanks || !results.blanks.length) {
            container.innerHTML = '';
            container.appendChild(emptyNote(strings.noanswersyet));
            return;
        }

        var html = '<div class="islide-blankresults' + (options.compact ? ' islide-compact' : '') + '">';

        results.blanks.forEach(function(blank, index) {
            html += '<div class="islide-blankresult">' +
                '<div class="islide-blankresult-head">' +
                    '<span class="islide-blankresult-label">' +
                        esc(blank.label || (strings.blank + ' ' + (index + 1))) +
                    '</span>';

            if (blank.answers && blank.answers.length) {
                html += '<span class="islide-blankresult-key">' +
                    esc(strings.correctanswer) + ': ' + esc(blank.answers.join(' / ')) +
                    '</span>';
            }

            html += '</div><ul class="islide-chiplist">';

            if (!blank.entries.length) {
                html += '<li class="islide-chip islide-chip-empty">' + esc(strings.noanswersyet) + '</li>';
            }

            blank.entries.forEach(function(entry) {
                var tone = entry.iscorrect ? ' islide-chip-correct' : '';
                html += '<li class="islide-chip' + tone + '">' +
                    '<span class="islide-chip-text">' + esc(entry.text) + '</span>' +
                    '<span class="islide-chip-count">' + esc(entry.count) + '</span>' +
                    '</li>';
            });

            html += '</ul></div>';
        });

        html += '</div>';
        container.innerHTML = html;
    };

    /**
     * Draw the wall of open ended answers.
     *
     * @param {Element} container
     * @param {Object} results
     * @param {Object} strings
     * @param {Object} [options] award to offer a star button on each card
     * @return {void}
     */
    var renderOpenEnded = function(container, results, strings, options) {
        options = options || {};

        if (!results.texts || !results.texts.length) {
            container.innerHTML = '';
            container.appendChild(emptyNote(strings.noanswersyet));
            return;
        }

        var html = '<div class="islide-wall">';

        results.texts.forEach(function(entry) {
            html += '<figure class="islide-wall-card">' +
                '<blockquote>' + esc(entry.text) + '</blockquote>';

            // The server sends names to the presenter only, so a card carries a
            // footer exactly when there is someone to credit.
            if (entry.fullname || (options.award && entry.userid)) {
                html += '<figcaption class="islide-wall-foot">' +
                    '<span class="islide-wall-author">' + esc(entry.fullname) + '</span>';

                if (options.award && entry.userid) {
                    html += '<button type="button" class="islide-award"' +
                        ' data-award-userid="' + Number(entry.userid) + '"' +
                        ' title="' + esc(strings.awardstar) + '">' +
                        '<span aria-hidden="true">+1&#9733;</span>' +
                        '<span class="islide-sr-only">' + esc(strings.awardstar) + '</span>' +
                        '</button>';
                }

                html += '</figcaption>';

            } else if (entry.count > 1) {
                html += '<figcaption class="islide-wall-count">&times;' + esc(entry.count) + '</figcaption>';
            }

            html += '</figure>';
        });

        html += '</div>';
        container.innerHTML = html;
    };

    /**
     * Draw the question itself, with no answers attached.
     *
     * Used on the presenter screen while a round is open but its tally is being
     * kept off the projector. The room still needs to read the options it is
     * choosing between, or the blanks it is filling in.
     *
     * @param {Element} container
     * @param {Object} interaction the question definition
     * @param {Object} strings
     * @return {void}
     */
    /**
     * The markup for an embedded video.
     *
     * The URL is not taken from the page: the server resolves what the teacher
     * pasted against a closed list of providers and sends the embeddable form,
     * or nothing. Nothing here builds a URL, so nothing here can be talked into
     * framing an arbitrary origin.
     *
     * @param {Object} interaction carrying a resolved `video` descriptor
     * @param {Object} strings
     * @return {String}
     */
    var videoEmbed = function(interaction, strings) {
        var video = interaction.video;

        if (!video || !video.url) {
            return '<div class="islide-empty-note">' + esc(strings.novideo) + '</div>';
        }

        if (video.kind === 'file') {
            return '<div class="islide-video">' +
                '<video controls playsinline src="' + esc(video.url) + '"></video>' +
                '</div>';
        }

        return '<div class="islide-video islide-video-frame">' +
            '<iframe src="' + esc(video.url) + '" title="' + esc(strings.video) + '"' +
            ' allow="accelerometer; autoplay; clipboard-write; encrypted-media; picture-in-picture"' +
            ' allowfullscreen referrerpolicy="strict-origin-when-cross-origin"></iframe>' +
            '</div>';
    };

    var prompt = function(container, interaction, strings) {
        redrawIfChanged(container, ['prompt', interaction], function() {
            if (!interaction) {
                container.innerHTML = '';
                return;
            }

            var letters = 'ABCDEFGHIJ';
            var html = '';

            if (interaction.qtype === 'video') {
                html = videoEmbed(interaction, strings);
                container.innerHTML = html;
                return;
            }

            if (interaction.qtype === 'dropdown' && interaction.blanks.length) {
                html = '<ol class="islide-promptlist islide-promptlist-blanks">';
                interaction.blanks.forEach(function(blank, index) {
                    html += '<li class="islide-promptlist-item islide-promptlist-choices">' +
                        '<span class="islide-promptlist-index">' + (index + 1) + '</span>' +
                        '<span class="islide-bar-text">';
                    (blank.options || []).forEach(function(option) {
                        html += '<span class="islide-chip">' + esc(option.optiontext) + '</span>';
                    });
                    html += '</span>' +
                        '<span class="islide-promptlist-stars">' + Number(blank.points) +
                            '<span class="islide-star" aria-hidden="true">&#9733;</span></span>' +
                        '</li>';
                });
                html += '</ol>';

            } else if (interaction.qtype === 'multichoice' && interaction.options.length) {
                html = '<ul class="islide-promptlist">';
                interaction.options.forEach(function(option, index) {
                    html += '<li class="islide-promptlist-item">' +
                        '<span class="islide-bar-letter">' +
                            esc(letters.charAt(index) || (index + 1)) +
                        '</span>' +
                        '<span class="islide-bar-text">' + esc(option.optiontext) + '</span>' +
                        '</li>';
                });
                html += '</ul>';

            } else if (interaction.qtype === 'fillblank' && interaction.blanks.length) {
                html = '<ol class="islide-promptlist islide-promptlist-blanks">';
                interaction.blanks.forEach(function(blank, index) {
                    var label = blank.label || (strings.blank + ' ' + (index + 1));
                    html += '<li class="islide-promptlist-item">' +
                        '<span class="islide-promptlist-index">' + (index + 1) + '</span>' +
                        '<span class="islide-bar-text">' + esc(label) + '</span>' +
                        '<span class="islide-promptlist-stars">' + Number(blank.points) +
                            '<span class="islide-star" aria-hidden="true">&#9733;</span></span>' +
                        '</li>';
                });
                html += '</ol>';
            }

            container.innerHTML = html;
            container.appendChild(emptyNote(strings.collectinganswers));
        });
    };

    /**
     * Draw a ranked leaderboard.
     *
     * @param {Element} container
     * @param {Array} board
     * @param {Object} strings
     * @param {Object} [options] highlightUserid, compact, award
     * @return {void}
     */
    var leaderboard = function(container, board, strings, options) {
        options = options || {};

        // The signature covers the options too: the same board with award
        // buttons on is different markup from the same board without them.
        redrawIfChanged(container, [board, options], function() {
            drawLeaderboard(container, board, strings, options);
        });
    };

    /**
     * Build the leaderboard markup.
     *
     * @param {Element} container
     * @param {Array} board
     * @param {Object} strings
     * @param {Object} options
     * @return {void}
     */
    var drawLeaderboard = function(container, board, strings, options) {
        if (!board || !board.length) {
            container.innerHTML = '';
            container.appendChild(emptyNote(strings.noparticipantsyet));
            return;
        }

        var medals = {1: '\uD83E\uDD47', 2: '\uD83E\uDD48', 3: '\uD83E\uDD49'};
        var html = '<ol class="islide-board' + (options.compact ? ' islide-board-compact' : '') + '">';

        board.forEach(function(entry) {
            var classes = 'islide-board-row islide-rank-' + entry.rank;
            if (options.highlightUserid && entry.userid === options.highlightUserid) {
                classes += ' islide-board-me';
            }

            var rankLabel = medals[entry.rank]
                ? '<span class="islide-board-medal" aria-hidden="true">' + medals[entry.rank] + '</span>'
                    + '<span class="islide-sr-only">' + esc(entry.rank) + '</span>'
                : esc(entry.rank);

            html += '<li class="' + classes + '">' +
                '<span class="islide-board-rank">' + rankLabel + '</span>' +
                '<span class="islide-board-avatar">' + (entry.pictureurl || '') + '</span>' +
                '<span class="islide-board-name">' + esc(entry.fullname) + '</span>';

            if (entry.beststreak > 1) {
                html += '<span class="islide-board-streak" title="' + esc(strings.beststreak) + '">' +
                    '&#128293; ' + esc(entry.beststreak) + '</span>';
            }

            html += '<span class="islide-board-stars">' + esc(entry.stars) +
                '<span class="islide-star" aria-hidden="true">&#9733;</span></span>';

            if (options.award && entry.userid) {
                html += '<button type="button" class="islide-award" data-award-userid="' +
                    Number(entry.userid) + '" title="' + esc(strings.awardstar) + '">' +
                    '<span aria-hidden="true">+1&#9733;</span>' +
                    '<span class="islide-sr-only">' + esc(strings.awardstar) + '</span>' +
                    '</button>';
            }

            html += '</li>';
        });

        html += '</ol>';
        container.innerHTML = html;
    };

    /**
     * A muted placeholder line.
     *
     * @param {String} text
     * @return {Element}
     */
    var emptyNote = function(text) {
        return Util.create('p', 'islide-empty-note', text);
    };

    return {
        results: results,
        prompt: prompt,
        leaderboard: leaderboard,
        emptyNote: emptyNote
    };
});
