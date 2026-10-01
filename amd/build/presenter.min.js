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
    'mod_interactiveslide/qrcode',
    'mod_interactiveslide/render',
    'mod_interactiveslide/util'
], function(Str, Notification, Annotate, Api, Poller, QR, Render, Util) {

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
        'showresultscreen', 'hideresultscreen', 'novideo', 'video',
        'questionimagealt', 'enlargeimage',
        'awardclasstitle', 'awardclass_desc', 'awardgroup', 'groupawarded', 'groupawardreason',
        'showvoters', 'voterstitle', 'voterscount', 'boardnomatch', 'boardsearching',
        'guestbadge', 'removeguest', 'confirmremoveguest', 'confirmremoveguest_desc', 'guestremovedtoast',
        'guestlinkcopied', 'guestlinkcopyfailed'
    ];

    /** @var {Number} The most stars the group panel offers; the server allows up to 100. */
    var GROUP_STARS_MAX = 99;

    /** @var {Number} How long typing pauses before the server is asked to search. */
    var SEARCH_DELAY = 250;

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
            busy: false,
            // What the teacher has typed into the board's search box.
            boardQuery: '',
            // A server search, used only when the class is bigger than the board.
            search: {inflight: false, result: null, timer: null},
            // The panel that gives several people stars at once: the whole class,
            // or everyone behind one answer. Null while it is closed.
            group: null,
            groupStars: 1,
            // The link of the running session, and what stands between guests and
            // the course, worked out once when the console opened.
            guestLink: '',
            guestWarning: config.guestwarning || ''
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
        view.imageViewer = Util.imageViewer(root);
        offerStandaloneLaunch();

        loadDeck(view);
        bindControls(view);
        bindBoardSearch(view);
        bindGroupAward(view);
        bindImageViewer(view);
        bindKeyboard(view);
        bindInvite(view);

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
            hideOverlayNow(view);
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
                var remove = event.target.closest('[data-remove-guest]');
                if (remove) {
                    var guestid = parseInt(remove.dataset.removeGuest, 10);
                    Notification.saveCancelPromise(
                        view.strings.confirmremoveguest,
                        view.strings.confirmremoveguest_desc,
                        view.strings.removeguest
                    ).then(function() {
                        return act(view, function() {
                            return Api.removeGuest(view.cmid, guestid);
                        }, view.strings.guestremovedtoast);
                    }).catch(function() {
                        return null;
                    });
                    return;
                }

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
     * The invite dropdown and the QR code it opens.
     *
     * @param {Object} view
     * @return {void}
     */
    var bindInvite = function(view) {
        var toggle = Util.actions(view.root, 'toggleinvite')[0];
        var menu = Util.region(view.root, 'invitemenu');
        if (!toggle || !menu) {
            return;
        }

        toggle.addEventListener('click', function() {
            if (menu.hidden) {
                setInviteOpen(view, true);
            } else {
                setInviteOpen(view, false);
            }
        });

        // A click anywhere else puts the menu away, as a menu does.
        document.addEventListener('click', function(event) {
            if (!menu.hidden && !menu.contains(event.target) && !toggle.contains(event.target)) {
                setInviteOpen(view, false);
            }
        });

        // Turning a tablet round moves the button the menu hangs from.
        window.addEventListener('resize', function() {
            if (!menu.hidden) {
                placeInviteMenu(menu);
            }
        });

        var field = Util.region(view.root, 'invitelink');
        if (field) {
            field.addEventListener('focus', function() {
                field.select();
            });
        }

        Util.actions(view.root, 'copyguestlink').forEach(function(button) {
            button.addEventListener('click', function() {
                copyGuestLink(view);
            });
        });

        Util.actions(view.root, 'showguestqr').forEach(function(button) {
            button.addEventListener('click', function() {
                setInviteOpen(view, false);
                setGuestQrOpen(view, true);
            });
        });

        Util.actions(view.root, 'closeguestqr').forEach(function(button) {
            button.addEventListener('click', function() {
                setGuestQrOpen(view, false);
            });
        });

        var dialog = Util.region(view.root, 'guestqr');
        if (dialog) {
            // The dimmed backdrop closes it; the card itself does not.
            dialog.addEventListener('click', function(event) {
                if (event.target === dialog) {
                    setGuestQrOpen(view, false);
                }
            });
        }
    };

    /**
     * Show the invite button while guests can use it, and keep its link current.
     *
     * @param {Object} view
     * @param {Object} state
     * @return {void}
     */
    var updateInvite = function(view, state) {
        var invite = Util.region(view.root, 'invite');
        if (!invite) {
            return;
        }

        var link = state.hassession ? (state.guestlink || '') : '';
        Util.toggle(invite, link !== '');

        if (link === '') {
            view.guestLink = '';
            setInviteOpen(view, false);
            setGuestQrOpen(view, false);
            return;
        }

        var warning = Util.region(view.root, 'invitewarning');
        if (warning) {
            warning.textContent = view.guestWarning;
            Util.toggle(warning, view.guestWarning !== '');
        }

        if (link === view.guestLink) {
            return;
        }
        view.guestLink = link;

        var field = Util.region(view.root, 'invitelink');
        if (field) {
            field.value = link;
        }

        // A new session is a new link, and a code already on the projector has
        // to follow it or the room scans a dead one.
        var dialog = Util.region(view.root, 'guestqr');
        if (dialog && !dialog.hidden) {
            drawGuestQr(view);
        }
    };

    /**
     * Open or close the invite menu.
     *
     * @param {Object} view
     * @param {Boolean} open
     * @return {void}
     */
    var setInviteOpen = function(view, open) {
        var toggle = Util.actions(view.root, 'toggleinvite')[0];
        var menu = Util.region(view.root, 'invitemenu');
        if (!toggle || !menu) {
            return;
        }
        menu.hidden = !open;
        toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
        if (open) {
            placeInviteMenu(menu);
        }
    };

    /**
     * Keep the invite menu inside the screen.
     *
     * It hangs from the right edge of its button, which on a narrow screen puts
     * the start of the link off the left edge. The start of the link is the part
     * a teacher reads, so when the menu cannot fit either side it is the left
     * edge that is kept.
     *
     * @param {Element} menu
     * @return {void}
     */
    var placeInviteMenu = function(menu) {
        var margin = 8;
        menu.style.right = '';

        var viewport = document.documentElement.clientWidth;
        var box = menu.getBoundingClientRect();
        var shift = 0;

        if (box.right > viewport - margin) {
            shift = box.right - (viewport - margin);
        }
        if (box.left - shift < margin) {
            shift = box.left - margin;
        }
        if (shift !== 0) {
            // A positive right offset moves the menu left, a negative one right.
            menu.style.right = shift + 'px';
        }
    };

    /**
     * Open or close the QR code dialog.
     *
     * @param {Object} view
     * @param {Boolean} open
     * @return {void}
     */
    var setGuestQrOpen = function(view, open) {
        var dialog = Util.region(view.root, 'guestqr');
        if (!dialog) {
            return;
        }
        if (open) {
            if (!view.guestLink) {
                return;
            }
            drawGuestQr(view);
        }
        var wasOpen = !dialog.hidden;
        dialog.hidden = !open;

        if (open) {
            var close = dialog.querySelector('[data-action="closeguestqr"]');
            if (close) {
                close.focus();
            }
        } else if (wasOpen) {
            var toggle = Util.actions(view.root, 'toggleinvite')[0];
            if (toggle && !toggle.closest('[hidden]')) {
                toggle.focus();
            }
        }
    };

    /**
     * Draw the running session's link as a QR code.
     *
     * @param {Object} view
     * @return {void}
     */
    var drawGuestQr = function(view) {
        var code = Util.region(view.root, 'guestqrcode');
        if (code) {
            // Built by the encoder from numbers alone, so it can go straight in.
            code.innerHTML = QR.svg(view.guestLink, {level: 'M'});
        }
        var text = Util.region(view.root, 'guestqrlink');
        if (text) {
            text.textContent = view.guestLink;
        }
    };

    /**
     * Copy the guest link, falling back to the selected field where the
     * clipboard API is not available, which is any page not served over HTTPS.
     *
     * @param {Object} view
     * @return {void}
     */
    var copyGuestLink = function(view) {
        if (!view.guestLink) {
            return;
        }

        var fallback = function() {
            var field = Util.region(view.root, 'invitelink');
            var copied = false;
            if (field) {
                field.focus();
                field.select();
                try {
                    copied = document.execCommand('copy');
                } catch (e) {
                    copied = false;
                }
            }
            Util.toast(view.root,
                copied ? view.strings.guestlinkcopied : view.strings.guestlinkcopyfailed,
                copied ? 'success' : 'error');
        };

        if (navigator.clipboard && window.isSecureContext) {
            navigator.clipboard.writeText(view.guestLink).then(function() {
                Util.toast(view.root, view.strings.guestlinkcopied, 'success');
                return null;
            }).catch(fallback);
        } else {
            fallback();
        }
    };

    /**
     * Close whichever guest layer is open.
     *
     * @param {Object} view
     * @return {Boolean} true when something was closed
     */
    var closeGuestLayers = function(view) {
        var dialog = Util.region(view.root, 'guestqr');
        if (dialog && !dialog.hidden) {
            setGuestQrOpen(view, false);
            return true;
        }
        var menu = Util.region(view.root, 'invitemenu');
        if (menu && !menu.hidden) {
            setInviteOpen(view, false);
            return true;
        }
        return false;
    };

    /**
     * Keyboard transport, so the presenter can drive from a clicker.
     *
     * @param {Object} view
     * @return {void}
     */
    var bindKeyboard = function(view) {
        document.addEventListener('keydown', function(event) {
            // The invitation is on top of everything, so Escape puts it away first.
            if (event.key === 'Escape' && closeGuestLayers(view)) {
                event.preventDefault();
                return;
            }

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
                // The group panel sits on top of everything else in the overlay.
                if (view.group) {
                    event.preventDefault();
                    closeGroup(view);
                    return;
                }

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
                    hideOverlayNow(view);
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
        updateInvite(view, state);

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

        if (!state.hassession) {
            setBoardQuery(view, '');
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
        var mediaBox = Util.region(view.root, 'overlay-media');

        var hasRound = !!(state.round && state.interaction);
        // Dismissal comes first, always. The leaderboard decides whether there is
        // anything worth showing when no question is up; it must never override
        // the teacher having put the overlay away, or the close button and the
        // show/hide button both stop working while the board is raised.
        var wantOverlay = state.hassession
            && !view.overlayDismissed
            && (hasRound || view.boardVisible);

        if (!wantOverlay) {
            hideOverlayNow(view);
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

        // The picture the question is about, and the clip the room watches. Both
        // sit above the tally rather than inside it, so they stay put when the
        // answers start arriving.
        var hasMedia = Render.media(mediaBox, hasRound ? state.interaction.media : null, view.strings);
        Util.toggle(mediaBox, hasMedia);
        if (mediaBox) {
            // Room for the tally once there is one to read.
            mediaBox.classList.toggle('islide-overlay-media-compact', !!state.results);
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
                // Every tallied answer opens the list of who gave it.
                voters: true,
                // A video round has no tally to draw; the renderer needs the
                // interaction itself to know what to put on the projector.
                interaction: hasRound ? state.interaction : null
            });
        }

        if (view.boardVisible) {
            // The whole board. It used to be cut to eight rows because it sat
            // under the question and any more pushed the question off the top;
            // in its own column it scrolls instead, so a class of eighty is all
            // there and the teacher can reach anyone to hand them a star.
            drawBoard(view, state);
            Util.toggle(boardPanel, true);
        } else {
            Util.toggle(boardPanel, false);
        }

        // The class panel is opened from the board, so it goes when the board does.
        if (!view.boardVisible && view.group && view.group.kind === 'class') {
            closeGroup(view);
        }
        refreshGroup(view, state);

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
     * Draw the board, narrowed to the search when there is one.
     *
     * While the whole class fits on the board the board filters itself, which
     * is instant and follows every poll for free. The board is capped, though,
     * and a class can be bigger than the cap: then the server searches the
     * whole session, so a student ranked below the cap can still be found and
     * handed a star.
     *
     * @param {Object} view
     * @param {Object} state
     * @return {void}
     */
    var drawBoard = function(view, state) {
        var list = Util.region(view.root, 'overlay-board-list');
        var query = view.boardQuery;

        Util.actions(view.root, 'awardclass').forEach(function(button) {
            button.hidden = !(view.canAward && state.hassession && state.participantcount > 0);
        });

        var options = {award: view.canAward, removeGuests: true};
        var board = state.leaderboard;

        if (Util.fold(query) !== '') {
            options.emptyText = view.strings.boardnomatch;

            if (state.participantcount <= state.leaderboard.length) {
                board = state.leaderboard.filter(function(entry) {
                    return Util.nameMatches(entry.fullname, query);
                });
            } else {
                var result = view.search.result;
                var current = result && result.query === query;

                if (!current || result.revision !== state.revision) {
                    scheduleSearch(view, 0);
                }

                if (current) {
                    // The same search on an older board: show it while the new
                    // one is on its way, rather than flashing an empty list.
                    board = result.board;
                } else {
                    board = [];
                    options.emptyText = view.strings.boardsearching;
                }
            }
        }

        if (list) {
            Render.leaderboard(list, board, view.strings, options);
        }
    };

    /**
     * Ask the server to search the session, once at a time.
     *
     * A search is never sent while another is out. When it returns the board is
     * redrawn, and if the query or the board changed meanwhile that redraw asks
     * again, for whatever is current by then.
     *
     * @param {Object} view
     * @param {Number} delay milliseconds to wait for typing to settle
     * @return {void}
     */
    var scheduleSearch = function(view, delay) {
        var search = view.search;

        if (search.inflight) {
            return;
        }

        if (search.timer) {
            if (!delay) {
                return;
            }
            window.clearTimeout(search.timer);
        }

        search.timer = window.setTimeout(function() {
            search.timer = null;
            var query = view.boardQuery;
            var revision = view.state ? view.state.revision : 0;

            if (Util.fold(query) === '') {
                return;
            }

            search.inflight = true;

            var done = function(board) {
                search.inflight = false;
                search.result = {query: query, revision: revision, board: board};
                if (view.state && view.boardVisible) {
                    drawBoard(view, view.state);
                }
            };

            Api.searchLeaderboard(view.cmid, query).then(function(response) {
                done(JSON.parse(response.board) || []);
                return response;
            }).catch(function(error) {
                // Stored as an answer, so the same search is not retried on
                // every poll; the next keystroke or star tries again.
                done([]);
                Util.toast(view.root, error.message || String(error), 'error');
            });
        }, delay);
    };

    /**
     * Set the search, keeping the box and its clear button in step.
     *
     * @param {Object} view
     * @param {String} query
     * @return {void}
     */
    var setBoardQuery = function(view, query) {
        view.boardQuery = query;

        var input = Util.region(view.root, 'boardsearch');
        if (input && input.value !== query) {
            input.value = query;
        }
        Util.actions(view.root, 'clearboardsearch').forEach(function(button) {
            button.hidden = query === '';
        });
    };

    /**
     * Wire up the board's search box.
     *
     * @param {Object} view
     * @return {void}
     */
    var bindBoardSearch = function(view) {
        var input = Util.region(view.root, 'boardsearch');
        if (!input) {
            return;
        }

        var redraw = function() {
            if (view.state && view.boardVisible) {
                drawBoard(view, view.state);
            }
        };

        input.addEventListener('input', function() {
            setBoardQuery(view, input.value);
            var state = view.state;
            if (state && Util.fold(input.value) !== '' && state.participantcount > state.leaderboard.length) {
                // Typing a name is several requests' worth of keystrokes.
                scheduleSearch(view, SEARCH_DELAY);
            }
            redraw();
        });

        input.addEventListener('keydown', function(event) {
            if (event.key === 'Escape' && input.value !== '') {
                // First Escape empties the box; the next one is the console's.
                event.preventDefault();
                event.stopPropagation();
                setBoardQuery(view, '');
                redraw();
            }
        });

        Util.actions(view.root, 'clearboardsearch').forEach(function(button) {
            button.addEventListener('click', function() {
                setBoardQuery(view, '');
                redraw();
                input.focus();
            });
        });
    };

    /**
     * Wire up the panel that gives several people stars at once.
     *
     * @param {Object} view
     * @return {void}
     */
    var bindGroupAward = function(view) {
        var body = Util.region(view.root, 'overlay-body');
        if (body) {
            body.addEventListener('click', function(event) {
                var button = event.target.closest('[data-voters-key]');
                if (button) {
                    openGroup(view, {kind: 'voters', key: button.dataset.votersKey});
                }
            });
        }

        var on = function(action, handler) {
            Util.actions(view.root, action).forEach(function(button) {
                button.addEventListener('click', handler);
            });
        };

        on('awardclass', function() {
            openGroup(view, {kind: 'class'});
        });

        on('closegroupaward', function() {
            closeGroup(view);
        });

        var step = function(delta) {
            view.groupStars = Math.max(1, Math.min(GROUP_STARS_MAX, view.groupStars + delta));
            if (view.state) {
                refreshGroup(view, view.state);
            }
        };
        on('groupstarsless', function() {
            step(-1);
        });
        on('groupstarsmore', function() {
            step(1);
        });

        on('confirmgroupaward', function() {
            var target = view.group && view.group.target;
            if (!target || !target.count || !view.canAward) {
                return;
            }
            var stars = view.groupStars;

            act(view, function() {
                return Api.awardStarsGroup(view.cmid, target.everyone, target.userids, stars,
                    view.strings.groupawardreason);
            }).then(function(response) {
                if (response) {
                    closeGroup(view);
                    Util.toast(view.root, view.strings.groupawarded
                        .replace('{$a->stars}', stars)
                        .replace('{$a->count}', response.awarded), 'success');
                }
                return response;
            }).catch(Notification.exception);
        });
    };

    /**
     * Tapping the question's picture puts it up whole.
     *
     * @param {Object} view
     * @return {void}
     */
    var bindImageViewer = function(view) {
        var box = Util.region(view.root, 'overlay-media');
        if (!box) {
            return;
        }

        box.addEventListener('click', function(event) {
            var picture = event.target.closest('img');
            if (picture) {
                view.imageViewer.open(picture.getAttribute('src'));
            }
        });
    };

    /**
     * Open the group panel.
     *
     * @param {Object} view
     * @param {Object} group kind "class", or kind "voters" with the answer's key
     * @return {void}
     */
    var openGroup = function(view, group) {
        view.group = {
            kind: group.kind,
            key: group.key || '',
            roundid: view.state && view.state.round ? view.state.round.id : 0,
            signature: '',
            target: null
        };
        view.groupStars = 1;

        if (view.state) {
            refreshGroup(view, view.state);
        }

        var panel = Util.region(view.root, 'groupaward');
        if (view.group && panel) {
            var close = panel.querySelector('[data-action="closegroupaward"]');
            if (close) {
                close.focus();
            }
        }
    };

    /**
     * Close the group panel.
     *
     * @param {Object} view
     * @return {void}
     */
    var closeGroup = function(view) {
        view.group = null;
        Util.toggle(Util.region(view.root, 'groupaward'), false);
    };

    /**
     * Find an answer in the results by the key the server gave it.
     *
     * @param {Object} results
     * @param {String} key
     * @param {Object} strings
     * @return {Object|null} with the answer's label, count, userids and iscorrect
     */
    var findAnswer = function(results, key, strings) {
        if (!results) {
            return null;
        }

        var letters = 'ABCDEFGHIJ';
        var found = null;

        (results.choices || []).forEach(function(choice, index) {
            if (choice.key === key) {
                found = {
                    label: (letters.charAt(index) || (index + 1)) + '. ' + choice.text,
                    entry: choice
                };
            }
        });

        var blanks = results.blanks || [];
        blanks.forEach(function(blank, index) {
            (blank.entries || []).forEach(function(entry) {
                if (entry.key === key) {
                    // Which blank matters once there is more than one of them.
                    var prefix = blanks.length > 1
                        ? (blank.label || (strings.blank + ' ' + (index + 1))) + ': '
                        : '';
                    found = {label: prefix + entry.text, entry: entry};
                }
            });
        });

        return found;
    };

    /**
     * Bring the group panel up to date with the latest state.
     *
     * Called on every state, so the list behind an answer grows as answers
     * arrive, and the panel closes itself when what it pointed at is gone: the
     * session ended, the round was reset, or another question came up.
     *
     * @param {Object} view
     * @param {Object} state
     * @return {void}
     */
    var refreshGroup = function(view, state) {
        var panel = Util.region(view.root, 'groupaward');
        var group = view.group;
        if (!panel || !group) {
            return;
        }

        var strings = view.strings;
        var title = '';
        var meta = '';
        var names = [];
        var target = null;

        if (!state.hassession) {
            closeGroup(view);
            return;
        }

        if (group.kind === 'class') {
            title = strings.awardclasstitle;
            meta = strings.awardclass_desc.replace('{$a}', state.participantcount);
            target = {everyone: true, userids: [], count: state.participantcount};
        } else {
            var roundid = state.round ? state.round.id : 0;
            var found = roundid === group.roundid ? findAnswer(state.results, group.key, strings) : null;
            var userids = found && found.entry.userids ? found.entry.userids : [];
            if (!userids.length) {
                closeGroup(view);
                return;
            }

            var people = {};
            (state.results.people || []).forEach(function(person) {
                people[person.userid] = person.fullname;
            });
            names = userids.map(function(userid) {
                return people[userid] || '';
            }).sort(function(a, b) {
                return a.localeCompare(b, 'vi', {sensitivity: 'base'});
            });

            title = strings.voterstitle.replace('{$a}', found.label);
            meta = strings.voterscount.replace('{$a}', userids.length) +
                (found.entry.iscorrect ? ' · ' + strings.correctanswer : '');
            target = {everyone: false, userids: userids, count: userids.length};
        }

        group.target = target;

        var signature = JSON.stringify([title, meta, names]);
        if (signature !== group.signature) {
            group.signature = signature;
            Util.region(view.root, 'groupaward-title').textContent = title;
            Util.region(view.root, 'groupaward-meta').textContent = meta;

            var list = Util.region(view.root, 'groupaward-list');
            list.innerHTML = names.map(function(name) {
                return '<li class="islide-groupaward-name">' + Util.escape(name) + '</li>';
            }).join('');
            list.hidden = names.length === 0;
        }

        Util.region(view.root, 'groupstars').textContent = view.groupStars;
        Util.toggle(Util.region(view.root, 'groupaward-foot'), view.canAward);
        Util.actions(view.root, 'groupstarsless').forEach(function(button) {
            button.disabled = view.groupStars <= 1;
        });
        Util.actions(view.root, 'groupstarsmore').forEach(function(button) {
            button.disabled = view.groupStars >= GROUP_STARS_MAX;
        });
        Util.actions(view.root, 'confirmgroupaward').forEach(function(button) {
            button.textContent = strings.awardgroup
                .replace('{$a->stars}', view.groupStars)
                .replace('{$a->count}', target.count);
            button.disabled = !target.count;
        });

        Util.toggle(panel, true);
    };

    /**
     * Put the overlay away, with everything that was living inside it.
     *
     * The media box is emptied rather than hidden: a video in a hidden box goes
     * on playing, and a lecture hall does not need a soundtrack from a panel
     * nobody can see. Both the buttons and the state loop come through here, so
     * there is one way down rather than three.
     *
     * @param {Object} view
     * @return {void}
     */
    var hideOverlayNow = function(view) {
        Util.toggle(Util.region(view.root, 'overlay'), false);
        closeGroup(view);
        view.imageViewer.close();

        var mediaBox = Util.region(view.root, 'overlay-media');
        Render.media(mediaBox, null, view.strings);
        Util.toggle(mediaBox, false);
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
