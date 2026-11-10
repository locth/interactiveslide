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
    'mod_interactiveslide/fortune',
    'mod_interactiveslide/poller',
    'mod_interactiveslide/render',
    'mod_interactiveslide/util'
], function(Str, Notification, Api, Fortune, Poller, Render, Util) {

    var STRING_KEYS = [
        'waitingforteacher', 'waitingforteacher_desc', 'waitingfornextquestion', 'roundclosed',
        'submitanswer', 'changeanswer', 'answersubmitted', 'answerrecorded', 'timesup',
        'youwerecorrect', 'youwereincorrect', 'starsearned', 'nowordsyet', 'noanswersyet',
        'noparticipantsyet', 'leaderboard', 'yourrank', 'correctanswer', 'blank',
        'beststreak', 'wordplaceholder', 'blankplaceholder', 'online', 'offline', 'connecting',
        'sessionended', 'sessionended_desc', 'selectanswerfirst', 'liveresult', 'stars',
        'openendedplaceholder', 'charactersleft', 'reloadneeded', 'reloadpage',
        'watchtheprojector', 'watchtheprojector_desc', 'chooseanswer', 'position',
        'autosubmitting', 'guestbadge', 'errorguestname',
        'addanswerimage', 'changeanswerimage', 'removeanswerimage', 'answerimagealt',
        'answerimageuploading', 'notanimage', 'uploadfailed',
        'youwerecalled', 'youwerecalled_desc', 'youwerecalledwith', 'yourfortunesoon', 'yourfortune',
        'calledgotit', 'fortuneeffect'
    ];

    /** @var {String} Where a phone remembers which news its owner has already put away. */
    var CALLED_KEY = 'mod_interactiveslide_called';

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
            submitting: false,
            // What this page last sent, so the timer knows whether the form
            // holds anything the server has not been told about.
            submitted: {roundid: 0, payload: ''},
            joining: false,
            guestFocused: false,
            autoenter: !!config.autoenter,
            config: config,
            // The server's clock at the last state, and ours when it arrived:
            // being called is timed by the server, whatever this phone's clock says.
            clock: {server: 0, local: 0},
            callTimer: null,
            callShown: ''
        };

        view.imageViewer = Util.imageViewer(root);

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

        // A diagram on a phone is worth very little at answer-panel size, and
        // neither is a photograph of someone's working. Anything drawn with the
        // zoomable class opens full size, wherever on the screen it sits and
        // whenever it was drawn.
        root.addEventListener('click', function(event) {
            var picture = event.target.closest('img.islide-zoomable');
            if (picture && picture.getAttribute('src')) {
                view.imageViewer.open(picture.getAttribute('src'));
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

        root.addEventListener('click', function(event) {
            var ok = event.target.closest('[data-action="calledok"]');
            if (ok) {
                dismissCall(view, ok.getAttribute('data-call'));
            }
        });

        bindGuestJoin(view);
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

        // Someone who scanned the code came to take part, not to read a star
        // count, so they go straight in.
        if (view.autoenter) {
            Util.toggle(lobby, false);
            Util.toggle(live, true);
            view.poller.start();
        } else {
            Util.toggle(lobby, true);
            Util.toggle(live, false);
        }

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
     * The name form a guest fills in before joining.
     *
     * @param {Object} view
     * @return {void}
     */
    var bindGuestJoin = function(view) {
        var form = Util.region(view.root, 'guestjoinform');
        if (!form) {
            return;
        }

        form.addEventListener('submit', function(event) {
            event.preventDefault();

            var input = Util.region(view.root, 'guestname');
            var status = Util.region(view.root, 'guestjoinstatus');
            var button = Util.region(view.root, 'guestjoinbutton');
            var name = input ? input.value.trim() : '';

            if (name === '') {
                if (status) {
                    status.textContent = view.strings.errorguestname;
                    status.dataset.tone = 'error';
                }
                if (input) {
                    input.focus();
                }
                return;
            }

            // A slow phone and an impatient thumb must not make two guests.
            if (view.joining) {
                return;
            }
            view.joining = true;
            if (button) {
                button.disabled = true;
            }
            if (status) {
                status.textContent = '';
            }

            Api.joinAsGuest(view.cmid, name).then(function(response) {
                view.joining = false;
                if (button) {
                    button.disabled = false;
                }
                view.poller.adopt(Api.unwrap(response));
                return response;
            }).catch(function(error) {
                view.joining = false;
                if (button) {
                    button.disabled = false;
                }
                if (status) {
                    status.textContent = error.message || String(error);
                    status.dataset.tone = 'error';
                }
                view.poller.refresh();
            });
        });
    };

    /**
     * Put a guest's own screen up when that is what this browser should see.
     *
     * @param {Object} view
     * @param {Object} state
     * @return {Boolean} true when a guest screen has the whole player
     */
    var applyGuest = function(view, state) {
        var guest = state.guest || {};
        var needsname = !!guest.needsname;
        var removed = !!guest.removed;

        Util.toggle(Util.region(view.root, 'guestjoin'), needsname);
        Util.toggle(Util.region(view.root, 'guestremoved'), removed);

        if (!needsname && !removed) {
            view.guestFocused = false;
            return false;
        }

        ['waiting', 'stage', 'answerpanel', 'resultpanel', 'leaderboard', 'mystars', 'myrank'].forEach(function(name) {
            Util.toggle(Util.region(view.root, name), false);
        });
        stopTimer(view);

        // Once, so the keyboard comes up without stealing focus back on every poll.
        if (needsname && !view.guestFocused) {
            var input = Util.region(view.root, 'guestname');
            if (input) {
                input.focus();
            }
            view.guestFocused = true;
        }

        return true;
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

        // A guest who has not given a name, or whom the presenter removed, sees
        // that and nothing else.
        if (applyGuest(view, state)) {
            return;
        }

        var waiting = Util.region(view.root, 'waiting');
        var stage = Util.region(view.root, 'stage');
        var answer = Util.region(view.root, 'answerpanel');
        var result = Util.region(view.root, 'resultpanel');
        var board = Util.region(view.root, 'leaderboard');

        updateStars(view, state);
        view.clock = {server: state.servertime || 0, local: Date.now()};
        showCall(view);

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
     * Tell someone they were called to the board, and later what card they drew.
     *
     * Each piece of news waits until the projector has shown it to the room, so
     * nobody learns it from their pocket first. Once put away it stays away,
     * until there is news it has not shown yet.
     *
     * @param {Object} view
     * @return {void}
     */
    var showCall = function(view) {
        var panel = Util.region(view.root, 'called');
        if (!panel) {
            return;
        }
        window.clearTimeout(view.callTimer);

        var state = view.state;
        var call = state && state.hassession ? state.mycall : null;
        var now = view.clock.server + (Date.now() - view.clock.local) / 1000;
        var phase = 'none';
        var wait = 0;

        if (call) {
            if (now < call.revealat) {
                wait = call.revealat - now;
            } else if (call.fortune && now >= call.fortuneat) {
                phase = 'fortune';
            } else if (call.fortune) {
                // Drawn, but still turning over on the projector.
                phase = 'drawing';
                wait = call.fortuneat - now;
            } else {
                phase = 'called';
            }
        }
        if (wait > 0) {
            view.callTimer = window.setTimeout(function() {
                showCall(view);
            }, Math.ceil(wait * 1000) + 50);
        }

        var news = call ? call.id + ':' + (phase === 'fortune' ? call.fortune.key + ':' + call.fortune.prophecy.length : 'called')
            : '';
        if (phase === 'none' || news === readDismissed(view)) {
            panel.hidden = true;
            view.callShown = '';
            return;
        }

        var shown = news + ':' + phase;
        if (view.callShown === shown) {
            return;
        }
        view.callShown = shown;

        var s = view.strings;
        var ok = '<button type="button" class="btn islide-called-ok" data-action="calledok" data-call="' +
            Util.escape(news) + '">' + Util.escape(s.calledgotit) + '</button>';

        if (phase === 'fortune') {
            var card = call.fortune;
            panel.className = 'islide-called islide-called-fortune';
            panel.style.setProperty('--islide-acc', Fortune.accent(card.key));
            panel.innerHTML = '<div class="islide-called-body">' +
                '<div class="islide-called-label">' + Util.escape(s.yourfortune) + '</div>' +
                '<div class="islide-called-fcard islide-fcard">' + Fortune.face(card) + '</div>' +
                '<p class="islide-called-text">' + Util.escape(card.prophecy) + '</p>' +
                '<div class="islide-called-effect"><b>&#10022; ' + Util.escape(s.fortuneeffect) + ':</b> ' +
                    Util.escape(card.effect) + '</div>' +
                ok + '</div>';
        } else {
            panel.className = 'islide-called';
            panel.style.removeProperty('--islide-acc');
            panel.innerHTML = '<div class="islide-called-body"><div class="islide-called-card">' +
                '<div class="islide-called-icon" aria-hidden="true">' + Fortune.icon('star') + '</div>' +
                '<h2>' + Util.escape(s.youwerecalled) + '</h2>' +
                '<p>' + Util.escape(call.others > 0 ? Fortune.fill(s.youwerecalledwith, call.others)
                    : s.youwerecalled_desc) + '</p>' +
                (phase === 'drawing' ? '<p class="islide-called-soon">' + Util.escape(s.yourfortunesoon) + '</p>' : '') +
                '</div>' + ok + '</div>';
        }
        panel.hidden = false;

        if (navigator.vibrate) {
            navigator.vibrate([120, 60, 120]);
        }
    };

    /**
     * Put the news away for good.
     *
     * @param {Object} view
     * @param {String} news
     * @return {void}
     */
    var dismissCall = function(view, news) {
        // Kept on the page as well, for a browser that refuses storage.
        view.dismissedCall = news;
        try {
            window.sessionStorage.setItem(CALLED_KEY, news);
        } catch (e) {
            // Storage refused: the copy on the page does for as long as it is open.
        }
        view.callShown = '';
        showCall(view);
    };

    /**
     * The news last put away on this phone.
     *
     * @param {Object} view
     * @return {String}
     */
    var readDismissed = function(view) {
        try {
            return window.sessionStorage.getItem(CALLED_KEY) || view.dismissedCall || '';
        } catch (e) {
            return view.dismissedCall || '';
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
            // A dropdown carries the sentence inside the form, with the selects
            // in it. Repeating it above would show the same words twice.
            question.hidden = !state.interaction.questiontext
                || state.interaction.qtype === 'dropdown';
        }

        // The question's picture, if it has one. The video that may go with the
        // question never reaches this screen: the server leaves it out of the
        // state document a student receives, so there is nothing here to hide.
        showQuestionImage(view, state);

        var answered = !!(state.myresponse && state.myresponse.submitted);
        var canAnswer = state.round.status === 'open' && (!answered || state.myresponse.canchange);

        if (isNewRound || form.dataset.roundid !== String(state.round.id)) {
            form.dataset.roundid = String(state.round.id);
            buildForm(view, form, state);
        }

        setFormEnabled(form, canAnswer);

        // Nothing to fill in means nothing to submit — which is exactly the case
        // for a video, and exactly not the case for a dropdown, whose only
        // control is a select.
        var hasInputs = !!form.querySelector('input, textarea, select');

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
     * Show the picture that goes with the question, or put the box away.
     *
     * @param {Object} view
     * @param {Object} state
     * @return {void}
     */
    var showQuestionImage = function(view, state) {
        var box = Util.region(view.root, 'questionmedia');
        var image = Util.region(view.root, 'questionimage');
        if (!box || !image) {
            return;
        }

        var media = state.interaction.media || {};
        var picture = media.image || null;

        if (picture && picture.url) {
            if (image.getAttribute('src') !== picture.url) {
                image.setAttribute('src', picture.url);
            }
            // Held open at the picture's own shape while it loads, so the answer
            // buttons do not jump under a finger that is already reaching.
            if (picture.width > 0 && picture.height > 0) {
                box.style.setProperty('--islide-media-aspect', picture.width + ' / ' + picture.height);
            } else {
                box.style.removeProperty('--islide-media-aspect');
            }
            Util.toggle(box, true);
        } else {
            image.removeAttribute('src');
            Util.toggle(box, false);
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
     * Split a sentence on the gaps the teacher left.
     *
     * A run of three or more underscores marks a position, because that is what
     * a teacher writing "Toc do cua RAM ___ Toc do cua Cache" has already typed.
     * The result is one more piece than there are gaps, so a piece and a select
     * can be laid down in turn.
     *
     * @param {String} text
     * @return {String[]}
     */
    var splitOnGaps = function(text) {
        return String(text || '').split(/_{3,}/);
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

        } else if (interaction.qtype === 'dropdown') {
            // The selects sit where the teacher left the gaps, so the student
            // reads one sentence rather than a sentence and then a detached list
            // of questions about it.
            var chosenAt = {};
            if (mine) {
                mine.answers.forEach(function(part) {
                    chosenAt[part.blankid] = part.optionid;
                });
            }

            var pieces = splitOnGaps(interaction.questiontext);
            html += '<div class="islide-inlineblanks">';

            pieces.forEach(function(piece, index) {
                html += '<span class="islide-inlinetext">' + esc(piece) + '</span>';

                var blank = interaction.blanks[index];
                if (!blank) {
                    return;
                }

                html += '<span class="islide-inlineslot">' +
                    '<select class="islide-dropdownselect" data-blankid="' + Number(blank.id) + '"' +
                    ' aria-label="' + esc(view.strings.position + ' ' + (index + 1)) + '">' +
                    '<option value="">' + esc(view.strings.chooseanswer) + '</option>';

                (blank.options || []).forEach(function(option) {
                    html += '<option value="' + Number(option.id) + '"' +
                        (chosenAt[blank.id] === option.id ? ' selected' : '') + '>' +
                        esc(option.optiontext) + '</option>';
                });

                html += '</select></span>';
            });

            // A position the sentence has no gap for still needs its select, or
            // there would be no way to answer it at all.
            interaction.blanks.forEach(function(blank, index) {
                if (index < pieces.length - 1) {
                    return;
                }
                html += '<span class="islide-inlineslot">' +
                    '<select class="islide-dropdownselect" data-blankid="' + Number(blank.id) + '"' +
                    ' aria-label="' + esc(view.strings.position + ' ' + (index + 1)) + '">' +
                    '<option value="">' + esc(view.strings.chooseanswer) + '</option>';
                (blank.options || []).forEach(function(option) {
                    html += '<option value="' + Number(option.id) + '"' +
                        (chosenAt[blank.id] === option.id ? ' selected' : '') + '>' +
                        esc(option.optiontext) + '</option>';
                });
                html += '</select></span>';
            });

            html += '</div>';

        } else if (interaction.qtype === 'video') {
            // The video plays on the projector. Eighty phones starting the same
            // clip at slightly different moments is not a thing to do to a room.
            html += '<div class="islide-stale">' +
                '<p>' + esc(view.strings.watchtheprojector) + '</p>' +
                '<p class="islide-muted">' + esc(view.strings.watchtheprojector_desc) + '</p>' +
                '</div>';

        } else if (interaction.qtype === 'openended') {
            var written = (mine && mine.answers.length) ? mine.answers[0].text : '';
            html += '<div class="islide-openended">' +
                '<textarea class="islide-openendedinput" rows="4"' +
                    ' maxlength="' + Number(interaction.maxwordlength) + '"' +
                    ' placeholder="' + esc(view.strings.openendedplaceholder) + '">' +
                    esc(written) +
                '</textarea>' +
                '<span class="islide-charcount" data-region="charcount"></span>';

            if (interaction.allowimage) {
                // The thumbnail is deliberately small. A photograph of a page of
                // working is unreadable at any size a phone can spare next to
                // the keyboard, so it stands here as a token of what is
                // attached, and opens full screen when it is tapped.
                var attached = state.myimage || '';
                html += '<div class="islide-answerimage" data-region="answerimage">' +
                    '<label class="islide-answerimage-pick">' +
                        '<input type="file" accept="image/*" class="islide-file-input"' +
                            ' data-region="answerimageinput">' +
                        '<span data-region="answerimagelabel">' +
                            esc(attached ? view.strings.changeanswerimage : view.strings.addanswerimage) +
                        '</span>' +
                    '</label>' +
                    '<div class="islide-answerimage-preview" data-region="answerimagepreview"' +
                        (attached ? '' : ' hidden') + '>' +
                        '<img class="islide-answerimage-thumb islide-zoomable"' +
                            ' data-region="answerimagethumb" src="' + esc(attached) + '"' +
                            ' alt="' + esc(view.strings.answerimagealt) + '">' +
                        '<button type="button" class="islide-answerimage-remove"' +
                            ' data-action="removeanswerimage"' +
                            ' title="' + esc(view.strings.removeanswerimage) + '">' +
                            '<span aria-hidden="true">&#10005;</span>' +
                            '<span class="islide-sr-only">' + esc(view.strings.removeanswerimage) + '</span>' +
                        '</button>' +
                    '</div>' +
                    '<p class="islide-answer-status" data-region="answerimagestatus" role="status"></p>' +
                    '</div>';
            }

            html += '</div>';

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

        bindAnswerImage(view, form, state);
    };

    /**
     * The picture a student may attach to an open ended answer.
     *
     * It is sent the moment it is picked rather than held until Submit, so that
     * a phone on a slow hall network spends the time while the student is still
     * typing, and so that the picture survives a reload of the page. The server
     * never receives a file name from here: it finds what this person attached
     * by looking in the round's own area, so there is nothing to forge.
     *
     * @param {Object} view
     * @param {Element} form
     * @param {Object} state
     * @return {void}
     */
    var bindAnswerImage = function(view, form, state) {
        var box = form.querySelector('[data-region="answerimage"]');
        if (!box) {
            return;
        }

        var input = box.querySelector('[data-region="answerimageinput"]');
        var preview = box.querySelector('[data-region="answerimagepreview"]');
        var thumb = box.querySelector('[data-region="answerimagethumb"]');
        var label = box.querySelector('[data-region="answerimagelabel"]');
        var status = box.querySelector('[data-region="answerimagestatus"]');
        var roundid = state.round.id;

        var say = function(message, tone) {
            status.textContent = message || '';
            status.dataset.tone = tone || 'info';
        };

        var show = function(url) {
            if (url) {
                thumb.setAttribute('src', url);
            } else {
                thumb.removeAttribute('src');
            }
            Util.toggle(preview, !!url);
            label.textContent = url ? view.strings.changeanswerimage : view.strings.addanswerimage;
        };

        var send = function(body) {
            box.classList.add('islide-answerimage-busy');

            return window.fetch(view.config.answeruploadurl, {
                method: 'POST',
                body: body,
                credentials: 'same-origin'
            }).then(function(response) {
                if (!response.ok) {
                    throw new Error('HTTP ' + response.status);
                }
                return response.json();
            }).then(function(payload) {
                if (payload.status !== 'ok') {
                    throw new Error(payload.message || view.strings.uploadfailed);
                }
                show(payload.imageurl);
                say('');
                return payload;
            }).catch(function(error) {
                say(error.message || view.strings.uploadfailed, 'error');
                return null;
            }).then(function(result) {
                box.classList.remove('islide-answerimage-busy');
                return result;
            });
        };

        input.addEventListener('change', function() {
            var file = input.files && input.files[0];
            input.value = '';

            if (!file) {
                return;
            }
            if (!/^image\//.test(file.type)) {
                say(view.strings.notanimage, 'error');
                return;
            }

            var body = new FormData();
            body.append('cmid', view.cmid);
            body.append('sesskey', view.config.sesskey);
            body.append('action', 'image');
            body.append('roundid', roundid);
            body.append('image', file, file.name);

            say(view.strings.answerimageuploading, 'info');
            send(body);
        });

        Util.actions(box, 'removeanswerimage').forEach(function(button) {
            button.addEventListener('click', function() {
                var body = new FormData();
                body.append('cmid', view.cmid);
                body.append('sesskey', view.config.sesskey);
                body.append('action', 'remove');
                body.append('roundid', roundid);

                send(body);
            });
        });
    };

    /**
     * Enable or disable every input in the form.
     *
     * @param {Element} form
     * @param {Boolean} enabled
     * @return {void}
     */
    var setFormEnabled = function(form, enabled) {
        Array.prototype.forEach.call(form.querySelectorAll('input, textarea, select'), function(input) {
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
            // A photograph of a worked solution is an answer, so an empty box
            // with a picture attached is still something to send. The picture
            // itself is already on the server and is not named here.
            var preview = form.querySelector('[data-region="answerimagepreview"]');
            var hasimage = !!preview && !preview.hidden;

            return (written === '' && !hasimage) ? null : {text: written};
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

        if (qtype === 'dropdown') {
            var picks = Array.prototype.map.call(
                form.querySelectorAll('.islide-dropdownselect[data-blankid]'),
                function(select) {
                    return {
                        blankid: parseInt(select.dataset.blankid, 10),
                        optionid: parseInt(select.value, 10) || 0
                    };
                }
            );
            var anyPick = picks.some(function(pick) {
                return pick.optionid > 0;
            });
            return anyPick ? {blanks: picks} : null;
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
    var submit = function(view, auto) {
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

        var encoded = JSON.stringify(payload);

        if (auto && status) {
            status.textContent = view.strings.autosubmitting;
            status.dataset.tone = 'info';
        }

        view.submitting = true;
        var button = Util.actions(view.root, 'submit')[0];
        if (button) {
            button.disabled = true;
        }

        Api.submitResponse(view.cmid, state.round.id, payload).then(function(response) {
            view.submitting = false;
            view.submitted = {roundid: state.round.id, payload: encoded};
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
     * Send what is in the form when the clock runs out.
     *
     * A student who typed an answer and did not press the button still meant to
     * answer, so the device sends it for them. The server allows a few seconds
     * past the deadline for exactly these, and pays no speed bonus on them.
     *
     * An answer already with the server is left alone: resending it would
     * rewrite the time it was given, and on a question that allows changes it
     * would take back the speed bonus it earned. The exception is a form this
     * page has since changed, which is the student's newer answer.
     *
     * @param {Object} view
     * @return {Boolean} true when something was sent
     */
    var autoSubmit = function(view) {
        var state = view.state;
        if (!state || !state.round || !state.interaction || view.submitting) {
            return false;
        }
        if (state.round.status !== 'open') {
            return false;
        }

        var payload = collect(view, state);
        if (!payload) {
            return false;
        }

        var answered = !!(state.myresponse && state.myresponse.submitted);
        var changed = view.submitted.roundid === state.round.id
            && view.submitted.payload !== JSON.stringify(payload);

        if (answered && !changed) {
            return false;
        }

        submit(view, true);

        return true;
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

            var sent = autoSubmit(view);

            var status = Util.region(view.root, 'answerstatus');
            if (!sent && status && !(view.state.myresponse && view.state.myresponse.submitted)) {
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
