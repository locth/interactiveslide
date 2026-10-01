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
                votersCount(choice, strings, options,
                    esc(choice.count) + ' <small>' + Number(choice.percent) + '%</small>',
                    'islide-bar-count') +
                '</div>';
        });

        html += '</div>';
        container.innerHTML = html;
    };

    /**
     * Whether an answer can be opened to see who gave it.
     *
     * Only on the presenter's screen, which is the only one the server sends
     * names to, and only for an answer somebody actually gave.
     *
     * @param {Object} entry a choice or a blank entry
     * @param {Object} options
     * @return {Boolean}
     */
    var canListVoters = function(entry, options) {
        return !!(options.voters && entry.key && entry.count > 0);
    };

    /**
     * The count at the end of a vote bar, as a button when it opens a list.
     *
     * @param {Object} choice
     * @param {Object} strings
     * @param {Object} options
     * @param {String} inner markup, already escaped
     * @param {String} className
     * @return {String}
     */
    var votersCount = function(choice, strings, options, inner, className) {
        if (!canListVoters(choice, options)) {
            return '<span class="' + className + '">' + inner + '</span>';
        }
        return '<button type="button" class="' + className + ' islide-voters-btn islide-bar-count-voters"' +
            ' data-voters-key="' + esc(choice.key) + '" title="' + esc(strings.showvoters) + '">' +
            '<span class="islide-voters-icon" aria-hidden="true">&#128101;</span>' + inner + '</button>';
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
                var inner = '<span class="islide-chip-text">' + esc(entry.text) + '</span>' +
                    '<span class="islide-chip-count">' + esc(entry.count) + '</span>';

                if (canListVoters(entry, options)) {
                    // The whole chip opens the list: on a projector driven from
                    // a tablet the count alone is too small a target.
                    html += '<li class="islide-chip islide-chip-voters' + tone + '">' +
                        '<button type="button" class="islide-voters-btn" data-voters-key="' +
                            esc(entry.key) + '" title="' + esc(strings.showvoters) + '">' +
                        inner + '</button></li>';
                } else {
                    html += '<li class="islide-chip' + tone + '">' + inner + '</li>';
                }
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
     * Draw the picture and the video that go with a question.
     *
     * Pass null to empty the box: a video that is merely hidden goes on playing,
     * and a lecture hall does not need a soundtrack from a panel nobody can see.
     *
     * The video only ever arrives on the presenter's screen — the server does
     * not put its URL in a student's state document — so there is no test for
     * which screen this is. There is nothing here to hide.
     *
     * @param {Element} container
     * @param {Object|null} data the media branch of the interaction
     * @param {Object} strings
     * @param {Object} [options] compact for a smaller box
     * @return {Boolean} whether there is anything to show
     */
    var media = function(container, data, strings, options) {
        options = options || {};

        redrawIfChanged(container, [data, options], function() {
            var html = '';

            if (data && data.image && data.image.url) {
                var ratio = (data.image.width > 0 && data.image.height > 0)
                    ? ' style="aspect-ratio:' + Number(data.image.width) + ' / ' + Number(data.image.height) + '"'
                    : '';
                html += '<figure class="islide-media-figure">' +
                    '<img class="islide-zoomable" src="' + esc(data.image.url) + '"' + ratio +
                    ' title="' + esc(strings.enlargeimage) + '"' +
                    ' alt="' + esc(strings.questionimagealt) + '">' +
                    '</figure>';
            }

            if (data && data.video) {
                html += '<div class="islide-media-figure islide-media-clip">' +
                    videoEmbed({video: data.video}, strings) + '</div>';
            }

            container.innerHTML = html;
        });

        return !!(data && (data.image || data.video));
    };

    /**
     * Draw a ranked leaderboard.
     *
     * @param {Element} container
     * @param {Array} board
     * @param {Object} strings
     * @param {Object} [options] highlightUserid, compact, award, and emptyText to say
     *     something other than "nobody has joined" when the board is empty
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
            container.appendChild(emptyNote(options.emptyText || strings.noparticipantsyet));
            return;
        }

        var medals = {1: '\uD83E\uDD47', 2: '\uD83E\uDD48', 3: '\uD83E\uDD49'};
        var html = '<ol class="islide-board' + (options.compact ? ' islide-board-compact' : '') + '">';

        board.forEach(function(entry) {
            var classes = 'islide-board-row islide-rank-' + entry.rank;
            if (options.highlightUserid && entry.userid === options.highlightUserid) {
                classes += ' islide-board-me';
            }
            if (entry.isguest) {
                classes += ' islide-board-guest';
            }

            var rankLabel = medals[entry.rank]
                ? '<span class="islide-board-medal" aria-hidden="true">' + medals[entry.rank] + '</span>'
                    + '<span class="islide-sr-only">' + esc(entry.rank) + '</span>'
                : esc(entry.rank);

            // A guest is on the same board as everyone else, marked, so the room
            // reads one ranking and the teacher still sees who has no account.
            var name = entry.isguest
                ? '<span class="islide-board-name islide-board-name-guest">' +
                    '<span class="islide-board-nametext">' + esc(entry.fullname) + '</span>' +
                    '<span class="islide-guest-badge">' + esc(strings.guestbadge) + '</span>' +
                    '</span>'
                : '<span class="islide-board-name">' + esc(entry.fullname) + '</span>';

            html += '<li class="' + classes + '">' +
                '<span class="islide-board-rank">' + rankLabel + '</span>' +
                '<span class="islide-board-avatar">' + (entry.pictureurl || '') + '</span>' +
                name;

            if (entry.beststreak > 1) {
                html += '<span class="islide-board-streak" title="' + esc(strings.beststreak) + '">' +
                    '&#128293; ' + esc(entry.beststreak) + '</span>';
            }

            html += '<span class="islide-board-stars">' + esc(entry.stars) +
                '<span class="islide-star" aria-hidden="true">&#9733;</span></span>';

            var canAward = options.award && entry.userid;
            // Only a guest can be taken off the board, and only from the console.
            var canRemove = options.removeGuests && entry.isguest && entry.userid < 0;

            // One cell for the buttons, so a guest row keeps the same columns as
            // every other row instead of spilling a button onto a line of its own.
            if (canAward || canRemove) {
                html += '<span class="islide-board-actions">';

                if (canAward) {
                    html += '<button type="button" class="islide-award" data-award-userid="' +
                        Number(entry.userid) + '" title="' + esc(strings.awardstar) + '">' +
                        '<span aria-hidden="true">+1&#9733;</span>' +
                        '<span class="islide-sr-only">' + esc(strings.awardstar) + '</span>' +
                        '</button>';
                }

                if (canRemove) {
                    html += '<button type="button" class="islide-remove-guest" data-remove-guest="' +
                        Number(entry.userid) + '" title="' + esc(strings.removeguest) + '">' +
                        '<span aria-hidden="true">&#10005;</span>' +
                        '<span class="islide-sr-only">' + esc(strings.removeguest) + '</span>' +
                        '</button>';
                }

                html += '</span>';
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
        media: media,
        leaderboard: leaderboard,
        emptyNote: emptyNote
    };
});
