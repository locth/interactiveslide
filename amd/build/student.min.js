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
 * The student's live screen.
 *
 * The student never decides anything: the server says which slide is showing,
 * whether the round is open and what the answer was. This module only reflects
 * that state and posts the one thing the student owns, their answer.
 *
 * @module     mod_interactiveslide/student
 * @copyright  2026 Interactive Slide contributors
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
define([
    'core/str',
    'core/notification',
    'mod_interactiveslide/api',
    'mod_interactiveslide/poller',
    'mod_interactiveslide/render',
    'mod_interactiveslide/util'
], function(Str, Notification, Api, Poller, Render, Util) {

    var STRING_KEYS = [
        'waitingforteacher', 'waitingforteacher_desc', 'waitingfornextquestion', 'roundclosed',
        'submitanswer', 'changeanswer', 'answersubmitted', 'answerrecorded', 'timesup',
        'youwerecorrect', 'youwereincorrect', 'starsearned', 'nowordsyet', 'noanswersyet',
        'noparticipantsyet', 'leaderboard', 'yourrank', 'correctanswer', 'blank',
        'beststreak', 'wordplaceholder', 'blankplaceholder', 'online', 'offline', 'connecting',
        'sessionended', 'sessionended_desc', 'selectanswerfirst', 'liveresult', 'stars',
        'openendedplaceholder', 'charactersleft', 'reloadneeded', 'reloadpage',
        'watchtheprojector', 'watchtheprojector_desc'
    ];

    /**
     * Boot the student screen.
     *
     * @param {Object} config cmid and pollinterval
     * @return {void}
     */
    var init = function(config) {
        var root = document.querySelector('[data-region="interactiveslide-player"]');
        if (!root) {
            return;
        }

        Str.get_strings(STRING_KEYS.map(function(key) {
            return {key: key, component: 'mod_interactiveslide'};
        })).then(function(resolved) {
            var strings = {};
            STRING_KEYS.forEach(function(key, index) {
                strings[key] = resolved[index];
            });
            start(root, config, strings);
            return strings;
        }).catch(Notification.exception);
    };

    /**
     * Wire the screen up once the language strings are in hand.
     *
     * @param {Element} root
     * @param {Object} config
     * @param {Object} strings
     * @return {void}
     */
    var start = function(root, config, strings) {
        var view = {
            root: root,
            cmid: config.cmid,
            strings: strings,
            state: null,
            timerHandle: null,
            deadline: null,
            currentRoundId: 0,
            currentSlideId: 0,
            expiredRound: 0,
            submitting: false
        };

        view.poller = Poller.create({
            cmid: config.cmid,
            interval: config.pollinterval || 2000,
            onState: function(state) {
                apply(view, state);
            },
            onConnection: function(status) {
                setConnection(view, status);
            }
        });

        var submitButton = Util.actions(root, 'submit')[0];
        if (submitButton) {
            submitButton.addEventListener('click', function() {
                submit(view);
            });
        }

        // Enter submits a single line answer, which is what a phone keyboard offers.
        root.addEventListener('keydown', function(event) {
            // Deliberately not textareas: an open ended answer wants its returns.
            if (event.key === 'Enter' && event.target.matches('.islide-answer input[type="text"]')) {
                event.preventDefault();
                submit(view);
            }
        });

        bindLobby(view);
    };

    /**
     * The screen shown before joining, and the way through it.
     *
     * Polling does not start here. A student who has only come to look up how
     * many stars they have should not be counted as present, should not be paid
     * the stars for attending, and should not cost the server a request every
     * two seconds while they read a number that is already on the page.
     *
     * @param {Object} view
     * @return {void}
     */
    var bindLobby = function(view) {
        var lobby = Util.region(view.root, 'lobby');
        var live = Util.region(view.root, 'live');

        if (!lobby || !live) {
            // No lobby in the markup: behave as the player always did.
            view.poller.start();
            return;
        }

        Util.toggle(lobby, true);
        Util.toggle(live, false);

        Util.actions(view.root, 'enter').forEach(function(button) {
            button.addEventListener('click', function() {
                Util.toggle(lobby, false);
                Util.toggle(live, true);
                view.poller.start();
            });
        });

        Util.actions(view.root, 'leave').forEach(function(button) {
            button.addEventListener('click', function() {
                // Reload rather than swap the panels back: the totals on the
                // first screen were counted when the page was built, and after a
                // session they are exactly the numbers that have just changed.
                view.poller.stop();
                window.location.reload();
            });
        });
    };

    /**
     * Reflect the connection status in the header.
     *
     * @param {Object} view
     * @param {String} status
     * @return {void}
     */
    var setConnection = function(view, status) {
        var node = Util.region(view.root, 'connection');
        if (!node) {
            return;
        }
        node.dataset.state = status;
        var label = node.querySelector('.islide-connection-label');
        if (label) {
            label.textContent = view.strings[status] || status;
        }
    };

    /**
     * Apply a state document to the screen.
     *
     * @param {Object} view
     * @param {Object} state
     * @return {void}
     */
    var apply = function(view, state) {
        view.state = state;

        var waiting = Util.region(view.root, 'waiting');
        var stage = Util.region(view.root, 'stage');
        var answer = Util.region(view.root, 'answerpanel');
        var result = Util.region(view.root, 'resultpanel');
        var board = Util.region(view.root, 'leaderboard');

        updateStars(view, state);

        if (!state.hassession) {
            Util.toggle(waiting, true);
            Util.toggle(stage, false);
            Util.toggle(answer, false);
            Util.toggle(result, false);
            Util.toggle(board, false);
            stopTimer(view);
            setWaiting(view, view.strings.waitingforteacher, view.strings.waitingforteacher_desc);
            return;
        }

        var hasSlide = !!state.slide;
        // The server blanks the URL when the site keeps slides off student
        // devices, so there is nothing here to decide and nothing to download.
        var showImage = hasSlide && !!state.slide.imageurl;
        var hasRound = !!(state.round && state.interaction);

        // The slide itself.
        if (showImage) {
            var image = Util.region(view.root, 'slideimage');
            if (image && image.getAttribute('src') !== state.slide.imageurl) {
                image.setAttribute('src', state.slide.imageurl);
            }
            // Match the imported page's own ratio so nothing is letterboxed.
            var figure = Util.region(view.root, 'stagefigure');
            if (figure && state.slide.width > 0 && state.slide.height > 0) {
                figure.style.setProperty('--islide-aspect',
                    state.slide.width + ' / ' + state.slide.height);
            }
        }
        Util.toggle(stage, showImage);

        // Which slide the room is on is still worth knowing without the picture.
        var position = Util.region(view.root, 'slideposition');
        if (position) {
            if (hasSlide) {
                position.textContent = (state.slide.index + 1) + ' / ' + state.slidecount;
            }
            position.hidden = !hasSlide;
        }

        // The question.
        if (hasRound) {
            renderRound(view, state);
        } else {
            Util.toggle(answer, false);
            Util.toggle(result, false);
            stopTimer(view);
        }

        // The waiting panel is whatever is left when there is neither a picture
        // nor a question to show. With slides kept off phones that is the normal
        // resting state between questions, so it says to look up rather than
        // implying the session has not started.
        if (!hasSlide) {
            Util.toggle(waiting, true);
            setWaiting(view, view.strings.waitingforteacher, view.strings.waitingforteacher_desc);
        } else if (!showImage && !hasRound) {
            Util.toggle(waiting, true);
            setWaiting(view, view.strings.watchtheprojector,
                state.slidehidden
                    ? view.strings.watchtheprojector_desc
                    : view.strings.waitingfornextquestion);
        } else {
            Util.toggle(waiting, false);
        }

        // The leaderboard.
        if (state.leaderboardvisible && state.leaderboard.length) {
            Render.leaderboard(board, state.leaderboard, view.strings, {
                compact: true,
                highlightUserid: state.myuserid
            });
            Util.toggle(board, true);
        } else {
            Util.toggle(board, false);
        }
    };

    /**
     * Update the star counter and rank chip.
     *
     * @param {Object} view
     * @param {Object} state
     * @return {void}
     */
    var updateStars = function(view, state) {
        var stars = Util.region(view.root, 'mystars');
        var value = Util.region(view.root, 'mystars-value');
        var rank = Util.region(view.root, 'myrank');

        if (!state.myrank) {
            Util.toggle(stars, false);
            Util.toggle(rank, false);
            return;
        }

        if (value) {
            var previous = parseInt(value.textContent, 10) || 0;
            value.textContent = state.myrank.stars;
            if (state.myrank.stars > previous) {
                // A tiny pop is the whole point of calling them stars.
                stars.classList.remove('islide-pop');
                void stars.offsetWidth;
                stars.classList.add('islide-pop');
            }
        }
        Util.toggle(stars, true);

        if (rank) {
            if (state.myrank.rank > 0) {
                rank.textContent = '#' + state.myrank.rank + ' / ' + state.myrank.total;
                Util.toggle(rank, true);
            } else {
                Util.toggle(rank, false);
            }
        }
    };

    /**
     * Set the waiting screen's headline and body.
     *
     * @param {Object} view
     * @param {String} title
     * @param {String} text
     * @return {void}
     */
    var setWaiting = function(view, title, text) {
        var titleNode = Util.region(view.root, 'waiting-title');
        var textNode = Util.region(view.root, 'waiting-text');
        if (titleNode) {
            titleNode.textContent = title;
        }
        if (textNode) {
            textNode.textContent = text;
        }
    };

    /**
     * Draw the question, the answer form and any pushed result.
     *
     * @param {Object} view
     * @param {Object} state
     * @return {void}
     */
    var renderRound = function(view, state) {
        var answer = Util.region(view.root, 'answerpanel');
        var result = Util.region(view.root, 'resultpanel');
        var form = Util.region(view.root, 'answerform');
        var question = Util.region(view.root, 'questiontext');
        var status = Util.region(view.root, 'answerstatus');
        var submitButton = Util.actions(view.root, 'submit')[0];

        var isNewRound = view.currentRoundId !== state.round.id;
        view.currentRoundId = state.round.id;

        Util.toggle(answer, true);
        Util.toggle(Util.region(view.root, 'waiting'), false);

        if (question) {
            question.textContent = state.interaction.questiontext || '';
            question.hidden = !state.interaction.questiontext;
        }

        var answered = !!(state.myresponse && state.myresponse.submitted);
        var canAnswer = state.round.status === 'open' && (!answered || state.myresponse.canchange);

        if (isNewRound || form.dataset.roundid !== String(state.round.id)) {
            form.dataset.roundid = String(state.round.id);
            buildForm(view, form, state);
        }

        setFormEnabled(form, canAnswer);

        // Nothing to fill in means nothing to submit.
        var hasInputs = !!form.querySelector('input, textarea');

        if (submitButton) {
            submitButton.hidden = !canAnswer || !hasInputs;
            submitButton.textContent = answered ? view.strings.changeanswer : view.strings.submitanswer;
        }

        if (status) {
            status.textContent = statusText(view, state, answered);
            status.dataset.tone = answerTone(state, answered);
        }

        // Timer. Only run the countdown while answers are still being taken:
        // running it on a closed round expires instantly, triggers a refresh and
        // restarts itself, which made the result panel flash.
        var timer = Util.region(view.root, 'timer');
        if (state.round.timelimit > 0) {
            if (state.round.status === 'open') {
                startTimer(view, timer, state);
            } else {
                stopTimer(view);
                Util.drawTimer(timer, 0, state.round.timelimit);
            }
        } else {
            stopTimer(view);
            Util.toggle(timer, false);
        }

        // Results, only when the teacher has pushed them to the phones.
        if (state.results) {
            Render.results(result, state.results, view.strings, {compact: true});
            Util.toggle(result, true);
        } else {
            Util.toggle(result, false);
        }
    };

    /**
     * The sentence under the answer form.
     *
     * @param {Object} view
     * @param {Object} state
     * @param {Boolean} answered
     * @return {String}
     */
    var statusText = function(view, state, answered) {
        if (state.round.status !== 'open') {
            if (answered && state.round.revealed) {
                return state.myresponse.iscorrect
                    ? view.strings.youwerecorrect + ' +' + state.myresponse.stars + ' ★'
                    : view.strings.youwereincorrect;
            }
            return answered ? view.strings.answerrecorded : view.strings.roundclosed;
        }
        return answered ? view.strings.answersubmitted : '';
    };

    /**
     * A tone name for the status line.
     *
     * @param {Object} state
     * @param {Boolean} answered
     * @return {String}
     */
    var answerTone = function(state, answered) {
        if (!answered) {
            return 'info';
        }
        if (state.round.revealed && state.myresponse) {
            return state.myresponse.iscorrect ? 'success' : 'error';
        }
        return 'success';
    };

    /**
     * Build the inputs for the current question type.
     *
     * @param {Object} view
     * @param {Element} form
     * @param {Object} state
     * @return {void}
     */
    var buildForm = function(view, form, state) {
        var interaction = state.interaction;
        var mine = state.myresponse;
        var esc = Util.escape;
        var html = '';

        if (interaction.qtype === 'wordcloud') {
            var existing = {};
            if (mine) {
                mine.answers.forEach(function(part, index) {
                    existing[index] = part.text;
                });
            }
            html += '<div class="islide-wordinputs">';
            for (var i = 0; i < interaction.maxentries; i++) {
                html += '<input type="text" class="islide-wordinput" data-entry="' + i + '"' +
                    ' maxlength="' + Number(interaction.maxwordlength) + '"' +
                    ' value="' + esc(existing[i] || '') + '"' +
                    ' placeholder="' + esc(view.strings.wordplaceholder) + ' ' + (i + 1) + '">';
            }
            html += '</div>';

        } else if (interaction.qtype === 'multichoice') {
            var chosen = {};
            if (mine) {
                mine.answers.forEach(function(part) {
                    chosen[part.optionid] = true;
                });
            }
            var inputType = interaction.allowmultiple ? 'checkbox' : 'radio';
            var letters = 'ABCDEFGHIJ';

            html += '<div class="islide-choices">';
            interaction.options.forEach(function(option, index) {
                var id = 'islide-opt-' + option.id;
                var tone = '';
                if (state.round.revealed) {
                    tone = option.iscorrect ? ' islide-choice-correct' : ' islide-choice-wrong';
                }
                html += '<label class="islide-choice' + tone + '" for="' + id + '">' +
                    '<span class="islide-check-native">' +
                        '<input type="' + inputType + '" name="islide-choice" id="' + id + '"' +
                        ' value="' + Number(option.id) + '"' + (chosen[option.id] ? ' checked' : '') + '>' +
                    '</span>' +
                    '<span class="islide-choice-letter">' + esc(letters.charAt(index) || (index + 1)) + '</span>' +
                    '<span class="islide-choice-text">' + esc(option.optiontext) + '</span>' +
                    '</label>';
            });
            html += '</div>';

        } else if (interaction.qtype === 'openended') {
            var written = (mine && mine.answers.length) ? mine.answers[0].text : '';
            html += '<div class="islide-openended">' +
                '<textarea class="islide-openendedinput" rows="4"' +
                    ' maxlength="' + Number(interaction.maxwordlength) + '"' +
                    ' placeholder="' + esc(view.strings.openendedplaceholder) + '">' +
                    esc(written) +
                '</textarea>' +
                '<span class="islide-charcount" data-region="charcount"></span>' +
                '</div>';

        } else if (interaction.qtype === 'fillblank') {
            var byBlank = {};
            if (mine) {
                mine.answers.forEach(function(part) {
                    byBlank[part.blankid] = part.text;
                });
            }
            html += '<div class="islide-blankinputs">';
            interaction.blanks.forEach(function(blank, index) {
                var label = blank.label || (view.strings.blank + ' ' + (index + 1));
                html += '<label class="islide-blankinput">' +
                    '<span class="islide-blankinput-label">' + esc(label) +
                        '<span class="islide-blankinput-points">' + Number(blank.points) + ' ★</span>' +
                    '</span>' +
                    '<input type="text" data-blankid="' + Number(blank.id) + '" maxlength="200"' +
                        ' value="' + esc(byBlank[blank.id] || '') + '"' +
                        ' placeholder="' + esc(view.strings.blankplaceholder) + '">' +
                    '</label>';
            });
            html += '</div>';
        }

        if (html === '') {
            // The server sent a question type this copy of the page cannot draw,
            // which means the page was open before the plugin was updated. An
            // empty form with a Submit button that always complains is a dead
            // end, so say what happened and offer the way out.
            html = '<div class="islide-stale">' +
                '<p>' + esc(view.strings.reloadneeded) + '</p>' +
                '<button type="button" class="btn btn-primary" data-action="reload">' +
                    esc(view.strings.reloadpage) +
                '</button>' +
                '</div>';
        }

        form.innerHTML = html;

        var reload = form.querySelector('[data-action="reload"]');
        if (reload) {
            reload.addEventListener('click', function() {
                window.location.reload();
            });
        }

        var textarea = form.querySelector('.islide-openendedinput');
        if (textarea) {
            var counter = form.querySelector('[data-region="charcount"]');
            var update = function() {
                var left = interaction.maxwordlength - textarea.value.length;
                counter.textContent = view.strings.charactersleft.replace('{$a}', left);
                counter.dataset.tone = left <= 20 ? 'warn' : '';
            };
            textarea.addEventListener('input', update);
            update();
        }
    };

    /**
     * Enable or disable every input in the form.
     *
     * @param {Element} form
     * @param {Boolean} enabled
     * @return {void}
     */
    var setFormEnabled = function(form, enabled) {
        Array.prototype.forEach.call(form.querySelectorAll('input, textarea'), function(input) {
            input.disabled = !enabled;
        });
        form.classList.toggle('islide-form-locked', !enabled);
    };

    /**
     * Collect the answer payload from the form.
     *
     * @param {Object} view
     * @param {Object} state
     * @return {Object|null} null when nothing was entered
     */
    var collect = function(view, state) {
        var form = Util.region(view.root, 'answerform');
        var qtype = state.interaction.qtype;

        if (qtype === 'wordcloud') {
            var entries = Array.prototype.map.call(
                form.querySelectorAll('.islide-wordinput'),
                function(input) {
                    return input.value.trim();
                }
            ).filter(function(value) {
                return value !== '';
            });
            return entries.length ? {entries: entries} : null;
        }

        if (qtype === 'openended') {
            var textarea = form.querySelector('.islide-openendedinput');
            var written = textarea ? textarea.value.trim() : '';
            return written === '' ? null : {text: written};
        }

        if (qtype === 'multichoice') {
            var optionids = Array.prototype.map.call(
                form.querySelectorAll('input[name="islide-choice"]:checked'),
                function(input) {
                    return parseInt(input.value, 10);
                }
            );
            return optionids.length ? {optionids: optionids} : null;
        }

        if (qtype === 'fillblank') {
            var blanks = Array.prototype.map.call(
                form.querySelectorAll('input[data-blankid]'),
                function(input) {
                    return {blankid: parseInt(input.dataset.blankid, 10), text: input.value};
                }
            );
            var anyText = blanks.some(function(blank) {
                return blank.text.trim() !== '';
            });
            return anyText ? {blanks: blanks} : null;
        }

        return null;
    };

    /**
     * Send the answer.
     *
     * @param {Object} view
     * @return {void}
     */
    var submit = function(view) {
        var state = view.state;
        if (!state || !state.round || !state.interaction || view.submitting) {
            return;
        }

        var status = Util.region(view.root, 'answerstatus');
        var payload = collect(view, state);

        if (!payload) {
            if (status) {
                status.textContent = view.strings.selectanswerfirst;
                status.dataset.tone = 'error';
            }
            return;
        }

        view.submitting = true;
        var button = Util.actions(view.root, 'submit')[0];
        if (button) {
            button.disabled = true;
        }

        Api.submitResponse(view.cmid, state.round.id, payload).then(function(response) {
            view.submitting = false;
            if (button) {
                button.disabled = false;
            }
            view.poller.adopt(Api.unwrap(response));
            return response;
        }).catch(function(error) {
            view.submitting = false;
            if (button) {
                button.disabled = false;
            }
            if (status) {
                status.textContent = error.message || String(error);
                status.dataset.tone = 'error';
            }
            // The round may have closed under us; a refresh tells us which.
            view.poller.refresh();
        });
    };

    /**
     * Run the countdown locally between polls.
     *
     * @param {Object} view
     * @param {Element} widget
     * @param {Object} state
     * @return {void}
     */
    var startTimer = function(view, widget, state) {
        stopTimer(view);

        var total = state.round.timelimit;
        view.deadline = Date.now() + (state.round.secondsleft * 1000);

        var roundid = state.round.id;

        var tick = function() {
            var left = Math.max(0, (view.deadline - Date.now()) / 1000);
            Util.drawTimer(widget, left, total);

            if (left > 0) {
                return;
            }

            stopTimer(view);

            var status = Util.region(view.root, 'answerstatus');
            if (status && !(view.state.myresponse && view.state.myresponse.submitted)) {
                status.textContent = view.strings.timesup;
                status.dataset.tone = 'error';
            }
            setFormEnabled(Util.region(view.root, 'answerform'), false);
            var button = Util.actions(view.root, 'submit')[0];
            if (button) {
                button.hidden = true;
            }

            // Ask the server what happened, but only once per round.
            if (view.expiredRound !== roundid) {
                view.expiredRound = roundid;
                view.poller.refresh();
            }
        };

        tick();
        view.timerHandle = window.setInterval(tick, 250);
    };

    /**
     * Stop the local countdown.
     *
     * @param {Object} view
     * @return {void}
     */
    var stopTimer = function(view) {
        if (view.timerHandle) {
            window.clearInterval(view.timerHandle);
            view.timerHandle = null;
        }
    };

    return {init: init};
});
