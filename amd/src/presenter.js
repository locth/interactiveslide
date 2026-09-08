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
 * The presenter console.
 *
 * Every control here is a request to the server, and the screen only changes
 * once the server has confirmed it. That keeps the projector and the room's
 * phones showing the same thing even on a flaky lecture hall network.
 *
 * @module     mod_interactiveslide/presenter
 * @copyright  2026 Interactive Slide contributors
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
define([
    'core/str',
    'core/notification',
    'mod_interactiveslide/annotate',
    'mod_interactiveslide/api',
    'mod_interactiveslide/poller',
    'mod_interactiveslide/render',
    'mod_interactiveslide/util'
], function(Str, Notification, Annotate, Api, Poller, Render, Util) {

    /** @var {String} Body class that lifts the console over the whole viewport. */
    var IMMERSIVE_CLASS = 'mod-interactiveslide-immersive';

    var STRING_KEYS = [
        'startsession', 'endsession', 'confirmendsession', 'confirmendsession_desc',
        'confirmreset', 'confirmreset_desc', 'responsesreceived', 'participantsonline',
        'nosessionyet', 'nosessionyet_desc', 'noslidesyet', 'online', 'offline', 'connecting',
        'nowordsyet', 'noanswersyet', 'noparticipantsyet', 'correctanswer', 'blank',
        'beststreak', 'leaderboard', 'stars', 'sessionstarted', 'sessionended',
        'roundopened', 'roundclosedtoast', 'answerrevealed', 'awardstar', 'awardreason', 'starawarded',
        'nosessionyet', 'nosessionyet_desc', 'waitingforslides', 'collectinganswers',
        'showresultscreen', 'hideresultscreen', 'novideo', 'video'
    ];

    /**
     * Boot the presenter console.
     *
     * @param {Object} config cmid, pollinterval, canaward, viewurl
     * @return {void}
     */
    var init = function(config) {
        var root = document.querySelector('[data-region="interactiveslide-present"]');
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
     * Wire the console up.
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
            deck: [],
            state: null,
            timerHandle: null,
            deadline: null,
            overlayDismissed: false,
            boardVisible: false,
            // The slide the console is currently parked on, and the round whose
            // opening has already pushed the overlay up. Together they keep the
            // overlay from reappearing over a slide the teacher came back to.
            slideShown: 0,
            openedRound: 0,
            expiredRound: 0,
            canAward: !!config.canaward,
            busy: false
        };

        view.poller = Poller.create({
            cmid: config.cmid,
            interval: config.pollinterval || 2000,
            onState: function(state) {
                apply(view, state);
            },
            onConnection: function(status) {
                root.dataset.connection = status;
            }
        });

        view.annotate = Annotate.create(root);
        offerStandaloneLaunch();

        loadDeck(view);
        bindControls(view);
        bindKeyboard(view);

        view.poller.start();
    };

    /**
     * Declare the console as a standalone web app.
     *
     * iPadOS reads these when someone taps Add to Home Screen. Launched from that
     * icon the page runs with no browser chrome at all, which is the only full
     * screen on a tablet that a swipe cannot collapse: there is no toolbar left
     * for the gesture to bring back. The Fullscreen API is still offered for
     * everything else, and this costs nothing where it is not understood.
     *
     * @return {void}
     */
    var offerStandaloneLaunch = function() {
        [
            ['apple-mobile-web-app-capable', 'yes'],
            ['apple-mobile-web-app-status-bar-style', 'black-translucent'],
            ['mobile-web-app-capable', 'yes']
        ].forEach(function(pair) {
            if (document.querySelector('meta[name="' + pair[0] + '"]')) {
                return;
            }
            var meta = document.createElement('meta');
            meta.setAttribute('name', pair[0]);
            meta.setAttribute('content', pair[1]);
            document.head.appendChild(meta);
        });
    };

    /**
     * Fetch the deck and build the filmstrip.
     *
     * @param {Object} view
     * @return {void}
     */
    var loadDeck = function(view) {
        Api.getDeck(view.cmid).then(function(response) {
            var deck = JSON.parse(response.deck);
            view.deck = deck.slides || [];
            renderFilmstrip(view);
            return deck;
        }).catch(Notification.exception);
    };

    /**
     * Draw the slide thumbnails.
     *
     * @param {Object} view
     * @return {void}
     */
    var renderFilmstrip = function(view) {
        var strip = Util.region(view.root, 'filmstrip');
        if (!strip) {
            return;
        }

        if (!view.deck.length) {
            strip.innerHTML = '';
            strip.appendChild(Render.emptyNote(view.strings.noslidesyet));
            return;
        }

        var html = '';
        view.deck.forEach(function(slide) {
            html += '<button type="button" class="islide-thumb" data-slideid="' + Number(slide.id) + '">' +
                '<span class="islide-thumb-index">' + (slide.index + 1) + '</span>' +
                '<img src="' + Util.escape(slide.imageurl) + '" alt="" loading="lazy">' +
                (slide.interaction
                    ? '<span class="islide-thumb-badge islide-thumb-' + Util.escape(slide.interaction.qtype) +
                        '" aria-hidden="true"></span>'
                    : '') +
                '</button>';
        });
        strip.innerHTML = html;

        strip.addEventListener('click', function(event) {
            var thumb = event.target.closest('.islide-thumb');
            if (thumb) {
                act(view, function() {
                    return Api.setSlide(view.cmid, parseInt(thumb.dataset.slideid, 10), 0);
                });
            }
        });
    };

    /**
     * Hook up every button in the console.
     *
     * @param {Object} view
     * @return {void}
     */
    var bindControls = function(view) {
        var on = function(action, handler) {
            Util.actions(view.root, action).forEach(function(button) {
                button.addEventListener('click', handler);
            });
        };

        on('startsession', function() {
            act(view, function() {
                return Api.startSession(view.cmid, '');
            }, view.strings.sessionstarted);
        });

        on('endsession', function() {
            Notification.saveCancelPromise(
                view.strings.confirmendsession,
                view.strings.confirmendsession_desc,
                view.strings.endsession
            ).then(function() {
                return act(view, function() {
                    return Api.endSession(view.cmid);
                }, view.strings.sessionended);
            }).catch(function() {
                return null;
            });
        });

        on('prev', function() {
            act(view, function() {
                return Api.setSlide(view.cmid, 0, -1);
            });
        });

        on('next', function() {
            act(view, function() {
                return Api.setSlide(view.cmid, 0, 1);
            });
        });

        on('openround', function() {
            view.overlayDismissed = false;
            act(view, function() {
                return Api.controlRound(view.cmid, 'open');
            }, view.strings.roundopened);
        });

        on('closeround', function() {
            act(view, function() {
                return Api.controlRound(view.cmid, 'close');
            }, view.strings.roundclosedtoast);
        });

        on('reveal', function() {
            act(view, function() {
                return Api.controlRound(view.cmid, 'reveal');
            }, view.strings.answerrevealed);
        });

        on('hide', function() {
            act(view, function() {
                return Api.controlRound(view.cmid, 'hide');
            });
        });

        on('showresult', function() {
            act(view, function() {
                return Api.controlRound(view.cmid, 'showresult');
            });
        });

        on('hideresult', function() {
            act(view, function() {
                return Api.controlRound(view.cmid, 'hideresult');
            });
        });

        on('reset', function() {
            Notification.saveCancelPromise(
                view.strings.confirmreset,
                view.strings.confirmreset_desc,
                view.strings.confirmreset
            ).then(function() {
                return act(view, function() {
                    return Api.controlRound(view.cmid, 'reset');
                });
            }).catch(function() {
                return null;
            });
        });

        on('closeoverlay', function() {
            view.overlayDismissed = true;
            Util.toggle(Util.region(view.root, 'overlay'), false);
        });

        on('toggleoverlay', function() {
            view.overlayDismissed = !view.overlayDismissed;
            if (view.state) {
                apply(view, view.state);
            }
        });

        on('toggleboard', function() {
            // One explicit flag, so turning the board off keeps it off. It used
            // to be recomputed from "has the answer been revealed", which put it
            // straight back on the next poll.
            view.boardVisible = !view.boardVisible;
            if (view.boardVisible) {
                view.overlayDismissed = false;
            }
            if (view.state) {
                apply(view, view.state);
            }
        });

        // Star buttons appear on the leaderboard and on each open ended answer,
        // so one delegated handler covers the whole overlay.
        var awardFrom = function(region) {
            var node = Util.region(view.root, region);
            if (!node) {
                return;
            }
            node.addEventListener('click', function(event) {
                var button = event.target.closest('[data-award-userid]');
                if (!button) {
                    return;
                }
                var userid = parseInt(button.dataset.awardUserid, 10);
                act(view, function() {
                    return Api.awardStars(view.cmid, userid, 1, view.strings.awardreason);
                }, view.strings.starawarded);
            });
        };

        awardFrom('overlay-board');
        awardFrom('overlay-body');

        on('fullscreen', function() {
            togglePresentationMode(view);
        });

        // Leaving real fullscreen by the system's own escape should also drop the
        // CSS side, or the console would stay locked over the page.
        document.addEventListener('fullscreenchange', function() {
            if (!document.fullscreenElement && document.body.classList.contains(IMMERSIVE_CLASS)) {
                setPresentationMode(view, false);
            }
        });
    };

    /**
     * Keyboard transport, so the presenter can drive from a clicker.
     *
     * @param {Object} view
     * @return {void}
     */
    var bindKeyboard = function(view) {
        document.addEventListener('keydown', function(event) {
            // A key event can be aimed at something that is not an element, and
            // matches() only exists on elements. Typing into a field is what this
            // is guarding against, so anything else is fair game for a shortcut.
            var target = event.target;
            if (target && target.matches && target.matches('input, textarea, select')) {
                return;
            }

            // Escape puts the pen down before it touches anything else, then on a
            // second press leaves presentation mode. One key, one step at a time.
            if (event.key === 'Escape') {
                if (view.annotate && view.annotate.isActive()) {
                    event.preventDefault();
                    var off = view.root.querySelector('[data-annotate-tool="off"]');
                    if (off) {
                        off.click();
                    }
                    return;
                }

                if (document.body.classList.contains(IMMERSIVE_CLASS)) {
                    event.preventDefault();
                    setPresentationMode(view, false);
                    return;
                }
            }

            switch (event.key) {
                case 'ArrowRight':
                case 'PageDown':
                    event.preventDefault();
                    act(view, function() {
                        return Api.setSlide(view.cmid, 0, 1);
                    });
                    break;

                case 'ArrowLeft':
                case 'PageUp':
                    event.preventDefault();
                    act(view, function() {
                        return Api.setSlide(view.cmid, 0, -1);
                    });
                    break;

                case ' ':
                    if (view.state && view.state.slide && view.state.slide.hasinteraction) {
                        event.preventDefault();
                        var action = (view.state.round && view.state.round.status === 'open')
                            ? 'close' : 'open';
                        view.overlayDismissed = false;
                        act(view, function() {
                            return Api.controlRound(view.cmid, action);
                        });
                    }
                    break;

                case 'Escape':
                    view.overlayDismissed = true;
                    Util.toggle(Util.region(view.root, 'overlay'), false);
                    break;

                case 'f':
                case 'F':
                    togglePresentationMode(view);
                    break;
            }
        });
    };

    /**
     * Run one control action, adopting the state it returns.
     *
     * @param {Object} view
     * @param {Function} runner returns the API promise
     * @param {String} [message] toast to show on success
     * @return {Promise}
     */
    var act = function(view, runner, message) {
        if (view.busy) {
            return Promise.resolve();
        }
        view.busy = true;

        return runner().then(function(response) {
            view.busy = false;
            view.poller.adopt(Api.unwrap(response));
            if (message) {
                Util.toast(view.root, message, 'success');
            }
            return response;
        }).catch(function(error) {
            view.busy = false;
            Util.toast(view.root, error.message || String(error), 'error');
            view.poller.refresh();
        });
    };

    /**
     * Apply a state document to the console.
     *
     * @param {Object} view
     * @param {Object} state
     * @return {void}
     */
    var apply = function(view, state) {
        var previousRound = view.state && view.state.round ? view.state.round.id : 0;
        view.state = state;

        var startButton = Util.actions(view.root, 'startsession')[0];
        var endButton = Util.actions(view.root, 'endsession')[0];
        var sessionInfo = Util.region(view.root, 'sessioninfo');

        if (startButton) {
            startButton.hidden = state.hassession;
        }
        if (endButton) {
            endButton.hidden = !state.hassession;
        }
        Util.toggle(sessionInfo, state.hassession);

        if (state.hassession) {
            var code = Util.region(view.root, 'joincode');
            if (code) {
                code.textContent = state.joincode;
            }
            var online = Util.region(view.root, 'onlinecount');
            if (online) {
                online.textContent = state.onlinecount + ' / ' + state.participantcount;
            }
        }

        updateStage(view, state);

        var position = Util.region(view.root, 'slideposition');
        if (position) {
            position.textContent = state.slide
                ? ((state.slide.index + 1) + ' / ' + state.slidecount)
                : ('0 / ' + state.slidecount);
        }

        highlightThumb(view, state.slide ? state.slide.id : 0);

        // Each slide keeps its own drawing, so moving through the deck parks one
        // and brings back whatever was on the next.
        if (view.annotate) {
            view.annotate.setSlide(state.slide ? state.slide.id : 0);
        }

        // Arriving on a slide shows the slide. Coming back to a question that
        // has already been run used to bury its own slide under the result, and
        // the way back was not obvious; the result is now one button away.
        var slideid = state.slide ? state.slide.id : 0;
        var roundopen = !!(state.round && state.round.status === 'open');
        var arrived = slideid !== view.slideShown;

        if (arrived) {
            view.slideShown = slideid;
            view.boardVisible = false;
            // A round still collecting is the exception: that is the thing the
            // room is watching, so it keeps the screen.
            view.overlayDismissed = !roundopen;
        }

        if (state.round && state.round.id !== previousRound) {
            view.boardVisible = false;
            view.expiredRound = 0;
        }

        // Opening a round raises the overlay, whoever opened it and from
        // whichever device. Closing re-arms that, so running the same question
        // a second time raises it again.
        if (roundopen) {
            if (view.openedRound !== state.round.id) {
                view.openedRound = state.round.id;
                view.overlayDismissed = false;
            }
        } else {
            view.openedRound = 0;
        }

        // The board goes up when its button is pressed and at no other time.
        // Revealing an answer used to raise it by itself, which put a list of
        // names over the answer the room was reading.

        updateRoundButtons(view, state);
        updateOverlay(view, state);
    };

    /**
     * Show the slide, or an explanation of why there is no slide.
     *
     * An <img> with no src is drawn by the browser as a broken image icon, which
     * is what a teacher used to be greeted with before starting a session.
     *
     * @param {Object} view
     * @param {Object} state
     * @return {void}
     */
    var updateStage = function(view, state) {
        var stage = Util.region(view.root, 'stage');
        var figure = stage ? stage.querySelector('.islide-stage-image') : null;
        var image = Util.region(view.root, 'slideimage');
        var placeholder = Util.region(view.root, 'stageplaceholder');
        var hasSlide = !!(state.slide && state.slide.imageurl);

        if (hasSlide) {
            if (image.getAttribute('src') !== state.slide.imageurl) {
                image.setAttribute('src', state.slide.imageurl);
            }
            // Take the ratio from the imported page so nothing is letterboxed.
            if (figure && state.slide.width > 0 && state.slide.height > 0) {
                figure.style.setProperty('--islide-aspect',
                    state.slide.width + ' / ' + state.slide.height);
            }
        } else {
            image.removeAttribute('src');
        }

        Util.toggle(figure, hasSlide);
        Util.toggle(placeholder, !hasSlide);

        if (!hasSlide && placeholder) {
            var title = placeholder.querySelector('[data-region="placeholder-title"]');
            var text = placeholder.querySelector('[data-region="placeholder-text"]');
            var noSession = !state.hassession;
            if (title) {
                title.textContent = noSession ? view.strings.nosessionyet : view.strings.waitingforslides;
            }
            if (text) {
                text.textContent = noSession ? view.strings.nosessionyet_desc : '';
            }
        }
    };

    /**
     * Mark the running slide in the filmstrip and scroll it into view.
     *
     * @param {Object} view
     * @param {Number} slideid
     * @return {void}
     */
    var highlightThumb = function(view, slideid) {
        var strip = Util.region(view.root, 'filmstrip');
        if (!strip) {
            return;
        }

        Array.prototype.forEach.call(strip.querySelectorAll('.islide-thumb'), function(thumb) {
            var active = parseInt(thumb.dataset.slideid, 10) === slideid;
            thumb.classList.toggle('islide-thumb-active', active);
            if (active) {
                thumb.scrollIntoView({block: 'nearest', behavior: 'smooth'});
            }
        });
    };

    /**
     * Show only the transport buttons that make sense right now.
     *
     * @param {Object} view
     * @param {Object} state
     * @return {void}
     */
    var updateRoundButtons = function(view, state) {
        var show = function(action, visible) {
            Util.actions(view.root, action).forEach(function(button) {
                button.hidden = !visible;
            });
        };

        var hasInteraction = !!(state.slide && state.slide.hasinteraction);
        var round = state.round;
        var open = !!(round && round.status === 'open');
        var closed = !!(round && round.status === 'closed');
        var hasAnswer = !!(state.interaction && state.interaction.hasanswer);
        var live = state.hassession;

        show('openround', live && hasInteraction && !open);
        show('closeround', live && open);
        show('reveal', live && round && hasAnswer && !round.revealed);
        show('hide', live && round && hasAnswer && round.revealed);
        show('showresult', live && round && !round.showresult);
        show('hideresult', live && round && round.showresult);
        show('reset', live && round && (closed || round.responsecount > 0));

        // The way back to a result the teacher has put away, and the way to put
        // it away in the first place.
        show('toggleoverlay', live && (!!round || view.boardVisible));
        var overlaylabel = Util.region(view.root, 'toggleoverlay-label');
        if (overlaylabel) {
            overlaylabel.textContent = view.overlayDismissed
                ? view.strings.showresultscreen
                : view.strings.hideresultscreen;
        }

        var badge = view.root.querySelector('.islide-start-badge');
        if (badge) {
            // The badge is the big obvious way in; hide it once the round is up.
            badge.hidden = !(live && hasInteraction && !round);
        }
    };

    /**
     * Draw the live overlay.
     *
     * @param {Object} view
     * @param {Object} state
     * @return {void}
     */
    var updateOverlay = function(view, state) {
        var overlay = Util.region(view.root, 'overlay');
        var body = Util.region(view.root, 'overlay-body');
        var boardPanel = Util.region(view.root, 'overlay-board');

        var hasRound = !!(state.round && state.interaction);
        // Dismissal comes first, always. The leaderboard decides whether there is
        // anything worth showing when no question is up; it must never override
        // the teacher having put the overlay away, or the close button and the
        // show/hide button both stop working while the board is raised.
        var wantOverlay = state.hassession
            && !view.overlayDismissed
            && (hasRound || view.boardVisible);

        if (!wantOverlay) {
            Util.toggle(overlay, false);
            stopTimer(view);
            return;
        }

        Util.toggle(overlay, true);

        var question = Util.region(view.root, 'overlay-question');
        if (question) {
            var text = hasRound ? (state.interaction.questiontext || '') : view.strings.leaderboard;
            question.textContent = text;
            // An empty heading would just eat projector space.
            question.hidden = text === '';
        }

        var count = Util.region(view.root, 'overlay-count');
        if (count) {
            count.hidden = !hasRound;
            if (hasRound) {
                count.textContent = state.round.responsecount + ' / ' + state.participantcount;
                count.title = view.strings.responsesreceived;
            }
        }

        if (hasRound && !state.results) {
            // No tally yet, on purpose. The room still has to be able to read
            // what it is choosing between, so show the question's own options or
            // blanks with nothing filled in.
            Render.prompt(body, state.interaction, view.strings);
        } else {
            Render.results(body, hasRound ? state.results : null, view.strings, {
                large: true,
                award: view.canAward,
                // A video round has no tally to draw; the renderer needs the
                // interaction itself to know what to put on the projector.
                interaction: hasRound ? state.interaction : null
            });
        }

        if (view.boardVisible && state.leaderboard.length) {
            // The whole board. It used to be cut to eight rows because it sat
            // under the question and any more pushed the question off the top;
            // in its own column it scrolls instead, so a class of eighty is all
            // there and the teacher can reach anyone to hand them a star.
            Render.leaderboard(boardPanel, state.leaderboard, view.strings, {
                award: view.canAward
            });
            Util.toggle(boardPanel, true);
        } else {
            Util.toggle(boardPanel, false);
        }

        var timer = Util.region(view.root, 'timer');
        if (hasRound && state.round.timelimit > 0) {
            if (state.round.status === 'open') {
                startTimer(view, timer, state);
            } else {
                // A closed round keeps the ring on screen at zero. Running the
                // countdown here made it expire immediately, refresh, restart and
                // expire again, which is what made the results flash.
                stopTimer(view);
                Util.drawTimer(timer, 0, state.round.timelimit);
            }
        } else {
            stopTimer(view);
            Util.toggle(timer, false);
        }
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

            // The server closes the round on expiry; ask it what happened, but
            // only once per round or the answer never stops arriving.
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

    /**
     * Make the console take over the screen, and give it back.
     *
     * Two mechanisms, because one of them is not available where it is needed
     * most. The Fullscreen API is used when the browser has it, but iPadOS does
     * not offer it to web pages at all, and a tablet is exactly where a lecturer
     * wants the deck to fill the glass. So the console also lifts itself out of
     * the page and covers the viewport with plain CSS, which needs no permission
     * and no API, and the two are applied together: the class alone is enough on
     * a tablet, and on a laptop the real thing goes over the top of it.
     *
     * @param {Object} view
     * @return {void}
     */
    var togglePresentationMode = function(view) {
        setPresentationMode(view, !document.body.classList.contains(IMMERSIVE_CLASS));
    };

    /**
     * Apply or drop presentation mode.
     *
     * @param {Object} view
     * @param {Boolean} on
     * @return {void}
     */
    var setPresentationMode = function(view, on) {
        document.body.classList.toggle(IMMERSIVE_CLASS, on);

        Util.actions(view.root, 'fullscreen').forEach(function(button) {
            button.setAttribute('aria-pressed', on ? 'true' : 'false');
            button.classList.toggle('islide-icon-btn-on', on);
        });

        if (on) {
            requestNativeFullscreen(view);
        } else if (document.fullscreenElement && document.exitFullscreen) {
            document.exitFullscreen();
        }

        // The stage has just changed size, so the annotation canvases have to be
        // remeasured or the drawing would land in the wrong place.
        if (view.annotate) {
            window.setTimeout(function() {
                view.annotate.resize();
            }, 120);
        }
    };

    /**
     * Ask for real fullscreen, quietly accepting a refusal.
     *
     * The CSS side has already taken effect by this point, so a browser that
     * says no costs the presenter nothing.
     *
     * @param {Object} view
     * @return {void}
     */
    var requestNativeFullscreen = function(view) {
        var target = view.root;
        var request = target.requestFullscreen || target.webkitRequestFullscreen;

        if (!request) {
            return;
        }

        try {
            var result = request.call(target);
            if (result && result.catch) {
                result.catch(function() {
                    return null;
                });
            }
        } catch (e) {
            return;
        }
    };

    return {init: init};
});
