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
 * Calling people to the board at random, on the projector.
 *
 * The server has already chosen who, and later which fortune card; everything
 * here is theatre. Each card gets about five seconds: silver shooting stars
 * streak past, then a gold one strikes the card and turns it over. A fortune
 * card is chosen from a fan of tarot backs by a light that runs along them and
 * slows to a stop.
 *
 * The phones of the people called hold their news back by the same timings
 * (picker::REVEAL_PER_CARD and FORTUNE_REVEAL on the server), so if the
 * scenes below get longer or shorter, those numbers move with them.
 *
 * @module     mod_interactiveslide/randompick
 * @copyright  2026 Interactive Slide contributors
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
define([
    'mod_interactiveslide/api',
    'mod_interactiveslide/fortune',
    'mod_interactiveslide/util'
], function(Api, Fortune, Util) {

    /** @var {String} Where the presenter's choices in the panel are remembered. */
    var STORE_KEY = 'mod_interactiveslide_randomcall';

    /** @var {String} The gold of the name cards and of the shooting star that opens them. */
    var GOLD = '#ffd36b';

    /** @var {String[]} The silver of the shooting stars that only pass by. */
    var SILVER = ['#cfe0ff', '#a9c8ff'];

    /** @var {Number} How many tarot backs the fan spreads out. */
    var FAN_SIZE = 9;

    /** @var {Number[]} The sizes the panel offers; the server allows 1 to 5. */
    var COUNTS = [1, 2, 3, 5];

    /**
     * Read the panel's remembered choices.
     *
     * Storage can be switched off or full; the defaults then simply apply.
     *
     * @return {Object}
     */
    var loadPrefs = function() {
        var prefs = {pool: 'online', count: 1, norepeat: true, fortune: true, sound: false};
        try {
            var saved = JSON.parse(window.localStorage.getItem(STORE_KEY) || '{}');
            Object.keys(prefs).forEach(function(key) {
                if (saved[key] !== undefined && typeof saved[key] === typeof prefs[key]) {
                    prefs[key] = saved[key];
                }
            });
        } catch (e) {
            return prefs;
        }
        if (COUNTS.indexOf(prefs.count) === -1) {
            prefs.count = 1;
        }
        return prefs;
    };

    /**
     * Remember the panel's choices for next time.
     *
     * @param {Object} prefs
     * @return {void}
     */
    var savePrefs = function(prefs) {
        try {
            window.localStorage.setItem(STORE_KEY, JSON.stringify(prefs));
        } catch (e) {
            return;
        }
    };

    /**
     * Whether the viewer asked their system for less motion.
     *
     * @return {Boolean}
     */
    var reduced = function() {
        return !!(window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches);
    };

    /**
     * Set up the random call for one presenter console.
     *
     * @param {Object} options root, cmid, strings, canaward, adopt(state), toast(message, tone)
     * @return {Object} update(state), handleKey(event), close()
     */
    var create = function(options) {
        var self = {
            root: options.root,
            cmid: options.cmid,
            strings: options.strings,
            canAward: !!options.canaward,
            adopt: options.adopt,
            toast: options.toast,
            stage: Util.region(options.root, 'stage'),
            panel: Util.region(options.root, 'pickpanel'),
            prefs: loadPrefs(),
            state: null,
            run: null,
            scene: null,
            busy: false,
            audio: null
        };

        bindPanel(self);
        window.addEventListener('resize', function() {
            if (self.run) {
                self.run.sky.resize();
            }
        });

        return {
            update: function(state) {
                update(self, state);
            },
            handleKey: function(event) {
                return handleKey(self, event);
            },
            close: function() {
                closeOverlay(self);
                setPanelOpen(self, false);
            }
        };
    };

    // ---------------------------------------------------------------------
    // The panel under the button.
    // ---------------------------------------------------------------------

    /**
     * Wire up the panel that sets up a call.
     *
     * @param {Object} self
     * @return {void}
     */
    var bindPanel = function(self) {
        var panel = self.panel;
        if (!panel) {
            return;
        }

        Util.actions(self.root, 'togglepick').forEach(function(button) {
            button.addEventListener('click', function(event) {
                event.stopPropagation();
                setPanelOpen(self, panel.hidden);
            });
        });

        panel.addEventListener('click', function(event) {
            event.stopPropagation();
            var choice = event.target.closest('[data-pick-pool], [data-pick-count]');
            if (choice) {
                if (choice.hasAttribute('data-pick-pool')) {
                    self.prefs.pool = choice.getAttribute('data-pick-pool');
                } else {
                    self.prefs.count = parseInt(choice.getAttribute('data-pick-count'), 10) || 1;
                }
                savePrefs(self.prefs);
                drawPanel(self);
            }
        });

        Array.prototype.forEach.call(panel.querySelectorAll('[data-pick-pref]'), function(box) {
            box.addEventListener('change', function() {
                self.prefs[box.getAttribute('data-pick-pref')] = box.checked;
                savePrefs(self.prefs);
            });
        });

        Util.actions(panel, 'resetpicks').forEach(function(button) {
            button.addEventListener('click', function() {
                Api.resetPicks(self.cmid).then(function(response) {
                    self.adopt(Api.unwrap(response));
                    self.toast(self.strings.randomcallresetdone, 'success');
                    return response;
                }).catch(function(error) {
                    self.toast(error.message || String(error), 'error');
                });
            });
        });

        Util.actions(panel, 'pickgo').forEach(function(button) {
            button.addEventListener('click', function() {
                setPanelOpen(self, false);
                call(self);
            });
        });

        document.addEventListener('click', function() {
            if (!panel.hidden) {
                setPanelOpen(self, false);
            }
        });

        drawPanel(self);
    };

    /**
     * Show or hide the panel.
     *
     * @param {Object} self
     * @param {Boolean} open
     * @return {void}
     */
    var setPanelOpen = function(self, open) {
        if (!self.panel) {
            return;
        }
        self.panel.hidden = !open;
        Util.actions(self.root, 'togglepick').forEach(function(button) {
            button.setAttribute('aria-expanded', open ? 'true' : 'false');
        });
        if (open) {
            drawPanel(self);
        }
    };

    /**
     * Reflect the remembered choices and the room's numbers in the panel.
     *
     * @param {Object} self
     * @return {void}
     */
    var drawPanel = function(self) {
        var panel = self.panel;
        if (!panel) {
            return;
        }
        var prefs = self.prefs;
        Array.prototype.forEach.call(panel.querySelectorAll('[data-pick-pool]'), function(button) {
            button.setAttribute('aria-pressed', button.getAttribute('data-pick-pool') === prefs.pool ? 'true' : 'false');
        });
        Array.prototype.forEach.call(panel.querySelectorAll('[data-pick-count]'), function(button) {
            button.setAttribute('aria-pressed',
                parseInt(button.getAttribute('data-pick-count'), 10) === prefs.count ? 'true' : 'false');
        });
        Array.prototype.forEach.call(panel.querySelectorAll('[data-pick-pref]'), function(box) {
            box.checked = !!prefs[box.getAttribute('data-pick-pref')];
        });

        var state = self.state || {};
        var people = function(region, count) {
            var node = Util.region(panel, region);
            if (node) {
                node.textContent = Fortune.fill(self.strings.randomcallpeople, count || 0);
            }
        };
        people('pickonline', state.onlinecount);
        people('pickall', state.participantcount);
        var called = Util.region(panel, 'pickcalled');
        if (called) {
            called.textContent = Fortune.fill(self.strings.randomcallcalled, state.calledcount || 0);
        }
    };

    /**
     * Follow the session.
     *
     * @param {Object} self
     * @param {Object} state
     * @return {void}
     */
    var update = function(self, state) {
        self.state = state;
        Util.actions(self.root, 'togglepick').forEach(function(button) {
            button.hidden = !state.hassession;
        });
        if (!state.hassession) {
            closeOverlay(self);
            setPanelOpen(self, false);
            return;
        }
        if (self.panel && !self.panel.hidden) {
            drawPanel(self);
        }
    };

    /**
     * Keys while a call is on screen.
     *
     * @param {Object} self
     * @param {KeyboardEvent} event
     * @return {Boolean} true when the key was dealt with here
     */
    var handleKey = function(self, event) {
        if (self.panel && !self.panel.hidden && event.key === 'Escape') {
            event.preventDefault();
            setPanelOpen(self, false);
            return true;
        }
        if (!self.run) {
            return false;
        }
        if (event.key === 'Escape') {
            event.preventDefault();
            closeOverlay(self);
            return true;
        }
        if ((event.key === ' ' || event.key === 'Enter') && self.scene && !self.scene.done) {
            event.preventDefault();
            skip(self);
            return true;
        }
        // Nothing underneath should move while the room is watching a card: no
        // slide change, and no Space opening the question behind it. The key
        // still reaches a focused button on the overlay, which the browser
        // presses as usual.
        return event.key !== 'Tab';
    };

    // ---------------------------------------------------------------------
    // Scenes: timed steps that a press of Skip jumps to the end of.
    // ---------------------------------------------------------------------

    /**
     * Start a scene, ending whichever was playing.
     *
     * @param {Object} self
     * @param {Function} finish brings the screen to the scene's end state
     * @return {Object}
     */
    var newScene = function(self, finish) {
        endScene(self);
        self.scene = {timers: [], skipped: false, done: false, finish: finish};
        setSkip(self, true);
        return self.scene;
    };

    /**
     * Stop the scene's timers without finishing it.
     *
     * @param {Object} self
     * @return {void}
     */
    var endScene = function(self) {
        if (self.scene) {
            self.scene.timers.forEach(window.clearTimeout);
            self.scene.timers = [];
            self.scene.done = true;
        }
    };

    /**
     * Run steps one after another; each returns how long to wait before the next.
     *
     * @param {Object} self
     * @param {Object} scene
     * @param {Function[]} steps
     * @return {void}
     */
    var play = function(self, scene, steps) {
        var index = 0;
        var next = function() {
            if (self.scene !== scene || scene.done || !self.run) {
                return;
            }
            if (index >= steps.length) {
                complete(self, scene);
                return;
            }
            var delay = steps[index++]() || 0;
            scene.timers.push(window.setTimeout(next, delay));
        };
        next();
    };

    /**
     * End a scene at its final state.
     *
     * @param {Object} self
     * @param {Object} scene
     * @return {void}
     */
    var complete = function(self, scene) {
        if (scene.done) {
            return;
        }
        endScene(self);
        setSkip(self, false);
        // A star removes itself when its flight ends, but a page in a background
        // tab draws no frames and so ends no flights. Nothing may outlive its scene.
        Array.prototype.forEach.call(self.run.el.querySelectorAll('.islide-pick-meteor'), function(meteor) {
            meteor.remove();
        });
        scene.finish();
    };

    /**
     * Jump to the end of what is playing.
     *
     * @param {Object} self
     * @return {void}
     */
    var skip = function(self) {
        var scene = self.scene;
        if (!self.run || !scene || scene.done) {
            return;
        }
        scene.skipped = true;
        var el = self.run.el;
        el.classList.add('islide-pick-instant');
        if (el.getAnimations) {
            el.getAnimations({subtree: true}).forEach(function(animation) {
                // The floating and the glow are meant to run for as long as the
                // card is up; only the one-off movements are brought to an end.
                if (window.CSSAnimation && animation instanceof window.CSSAnimation) {
                    return;
                }
                try {
                    animation.finish();
                } catch (e) {
                    animation.cancel();
                }
            });
        }
        complete(self, scene);
        window.requestAnimationFrame(function() {
            window.requestAnimationFrame(function() {
                el.classList.remove('islide-pick-instant');
            });
        });
    };

    /**
     * Show or hide the Skip button.
     *
     * @param {Object} self
     * @param {Boolean} visible
     * @return {void}
     */
    var setSkip = function(self, visible) {
        if (self.run) {
            self.run.el.querySelector('.islide-pick-skip').hidden = !visible;
        }
    };

    // ---------------------------------------------------------------------
    // The overlay.
    // ---------------------------------------------------------------------

    /**
     * Ask the server to call people, then play it.
     *
     * @param {Object} self
     * @return {void}
     */
    var call = function(self) {
        if (self.busy) {
            return;
        }
        self.busy = true;
        // Sound has to be unlocked inside the click that asked for it.
        if (self.prefs.sound) {
            audio(self);
        }
        var prefs = self.prefs;
        Api.pickRandom(self.cmid, prefs.count, prefs.pool === 'online', prefs.norepeat).then(function(response) {
            self.busy = false;
            self.adopt(Api.unwrap(response));
            var picks = JSON.parse(response.picks || '[]');
            if (picks.length) {
                openOverlay(self, picks);
                if (picks.length < prefs.count) {
                    say(self, Fortune.fill(self.strings.randomcallshortfall, picks.length));
                }
            }
            return response;
        }).catch(function(error) {
            self.busy = false;
            self.toast(error.message || String(error), 'error');
        });
    };

    /**
     * Raise the overlay over the stage and start the name cards.
     *
     * @param {Object} self
     * @param {Object[]} picks as the server exported them
     * @return {void}
     */
    var openOverlay = function(self, picks) {
        closeOverlay(self);
        var s = self.strings;

        var el = document.createElement('div');
        el.className = 'islide-pick';
        el.setAttribute('role', 'dialog');
        el.setAttribute('aria-modal', 'true');
        el.setAttribute('aria-label', s.randomcall);
        el.innerHTML = '<canvas class="islide-pick-sky" aria-hidden="true"></canvas>' +
            '<div class="islide-pick-head">' +
                '<span class="islide-pick-title"><span aria-hidden="true">&#10022;</span> ' +
                Util.escape(s.randomcall) + '</span>' +
                '<button type="button" class="islide-pick-close" data-pick-act="close" title="' +
                Util.escape(s.randomcalldone) + '"><span aria-hidden="true">&#10005;</span>' +
                '<span class="islide-sr-only">' + Util.escape(s.randomcalldone) + '</span></button>' +
            '</div>' +
            '<div class="islide-pick-cards' + (picks.length === 1 ? ' islide-pick-single' : '') + '" ' +
                'style="--islide-pick-n: ' + picks.length + '"></div>' +
            '<div class="islide-pick-flash" aria-hidden="true"></div>' +
            '<button type="button" class="islide-pick-skip" data-pick-act="skip" hidden>' +
                Util.escape(s.randomcallskip) + ' <span aria-hidden="true">&#8250;&#8250;</span></button>' +
            '<div class="islide-pick-foot" hidden></div>' +
            '<div class="islide-pick-toast" role="status" aria-live="polite" hidden></div>';
        self.stage.appendChild(el);

        self.run = {el: el, picks: picks, sky: makeSky(el.querySelector('canvas'))};
        el.addEventListener('click', function(event) {
            onClick(self, event);
        });

        namesScene(self);
    };

    /**
     * Take the overlay down.
     *
     * @param {Object} self
     * @return {void}
     */
    var closeOverlay = function(self) {
        endScene(self);
        self.scene = null;
        if (self.run) {
            self.run.sky.stop();
            self.run.el.remove();
            self.run = null;
        }
    };

    /**
     * Buttons on the overlay.
     *
     * @param {Object} self
     * @param {Event} event
     * @return {void}
     */
    var onClick = function(self, event) {
        var button = event.target.closest('[data-pick-act]');
        if (!button || !self.run || button.disabled) {
            return;
        }
        var index = parseInt(button.getAttribute('data-pick-index'), 10) || 0;
        switch (button.getAttribute('data-pick-act')) {
            case 'close':
                closeOverlay(self);
                break;
            case 'skip':
                skip(self);
                break;
            case 'again':
                call(self);
                break;
            case 'award':
                award(self, [index]);
                break;
            case 'awardall':
                award(self, self.run.picks.map(function(pick, i) {
                    return i;
                }));
                break;
            case 'fortune':
                drawFortune(self, index, false);
                break;
            case 'redraw':
                drawFortune(self, index, true);
                break;
            case 'viewfortune':
                fortuneScene(self, index, true);
                break;
            case 'apply':
                applyFortune(self, index, button);
                break;
            case 'back':
                backToNames(self);
                break;
        }
    };

    /**
     * A short message on the overlay itself, which covers the console's own.
     *
     * @param {Object} self
     * @param {String} message
     * @return {void}
     */
    var say = function(self, message) {
        if (!self.run) {
            self.toast(message, 'info');
            return;
        }
        var node = self.run.el.querySelector('.islide-pick-toast');
        node.textContent = message;
        node.hidden = false;
        window.clearTimeout(self.sayTimer);
        self.sayTimer = window.setTimeout(function() {
            node.hidden = true;
        }, 2600);
    };

    /**
     * Fill the overlay's footer.
     *
     * @param {Object} self
     * @param {String[]} buttons markup
     * @return {void}
     */
    var setFoot = function(self, buttons) {
        var foot = self.run.el.querySelector('.islide-pick-foot');
        foot.innerHTML = buttons.join('');
        foot.hidden = !buttons.length;
    };

    /**
     * Markup for one footer button.
     *
     * @param {String} act
     * @param {String} label
     * @param {String} [tone] primary or magic
     * @param {Number} [index]
     * @return {String}
     */
    var footButton = function(act, label, tone, index) {
        return '<button type="button" class="islide-pick-btn' + (tone ? ' islide-pick-btn-' + tone : '') + '" ' +
            'data-pick-act="' + act + '"' + (index !== undefined ? ' data-pick-index="' + index + '"' : '') + '>' +
            Util.escape(label) + '</button>';
    };

    // ---------------------------------------------------------------------
    // Scene 1: the name cards.
    // ---------------------------------------------------------------------

    /**
     * Turn the name cards over one by one.
     *
     * @param {Object} self
     * @return {void}
     */
    var namesScene = function(self) {
        var run = self.run;
        var cards = run.el.querySelector('.islide-pick-cards');
        run.picks.forEach(function(pick, index) {
            cards.appendChild(nameCard(self, pick, index));
        });
        var slots = Array.prototype.slice.call(cards.children);
        slots.forEach(function(slot) {
            slot.style.visibility = 'hidden';
        });

        var scene = newScene(self, function() {
            slots.forEach(function(slot) {
                slot.style.visibility = '';
                slot.classList.remove('islide-pick-charging');
                slot.classList.add('islide-pick-flipped');
            });
            namesFoot(self);
        });

        var calm = reduced();
        var steps = [];

        // The face down cards come out of the night sky.
        steps.push(function() {
            return calm ? 0 : 300;
        });
        steps.push(function() {
            slots.forEach(function(slot, index) {
                slot.style.visibility = '';
                if (!calm && slot.animate) {
                    slot.animate([
                        {transform: 'scale(.6) translateY(12%)', opacity: 0, filter: 'blur(8px) brightness(2)'},
                        {transform: 'none', opacity: 1, filter: 'none'}
                    ], {duration: 800, delay: index * 120, easing: 'cubic-bezier(.2,1.1,.4,1)', fill: 'backwards'});
                }
            });
            return calm ? 0 : 800 + slots.length * 120;
        });

        slots.forEach(function(slot) {
            if (calm) {
                steps.push(function() {
                    slot.classList.add('islide-pick-flipped');
                    return 400;
                });
                return;
            }
            // Three to five silver stars pass by without touching anything...
            var passes = 3 + Math.floor(Math.random() * 3);
            var gap = 2200 / passes;
            for (var i = 0; i < passes; i++) {
                steps.push(function() {
                    silverMeteor(self);
                    whoosh(self, 0.08, 0.7);
                    return gap * (0.75 + Math.random() * 0.5);
                });
            }
            // ...then the card brightens, and the gold one strikes it.
            steps.push(function() {
                slot.classList.add('islide-pick-charging');
                return 450;
            });
            steps.push(function() {
                var at = centre(self, slot);
                var box = run.el.getBoundingClientRect();
                whoosh(self, 0.3, 1.1);
                meteor(self, {
                    from: {x: at.x + box.width * 0.55, y: -box.height * 0.3},
                    to: {x: at.x, y: at.y},
                    color: GOLD,
                    duration: 1100,
                    big: true
                });
                return 1080;
            });
            steps.push(function() {
                var at = centre(self, slot);
                flash(run.el);
                run.sky.burst(at.x, at.y, GOLD, 110);
                slot.classList.remove('islide-pick-charging');
                slot.classList.add('islide-pick-flipped');
                chime(self, [659, 784, 988, 1319, 1568], 0.07, 'triangle', 0.12, 0.9);
                return 1100;
            });
        });
        steps.push(function() {
            return 300;
        });

        play(self, scene, steps);
    };

    /**
     * One name card, face down.
     *
     * @param {Object} self
     * @param {Object} pick
     * @param {Number} index
     * @return {Element}
     */
    var nameCard = function(self, pick, index) {
        var s = self.strings;
        var several = self.run.picks.length > 1;
        var avatar = pick.pictureurl
            ? '<img src="' + Util.escape(pick.pictureurl) + '" alt="">'
            : Util.escape(Fortune.initials(pick.fullname));

        var slot = document.createElement('div');
        slot.className = 'islide-pick-slot';
        slot.innerHTML = '<div class="islide-pick-rays" aria-hidden="true"></div>' +
            '<div class="islide-pick-card" style="--islide-pick-float: ' + (index * 0.37).toFixed(2) + 's">' +
            '<div class="islide-pick-inner">' +
                '<div class="islide-pick-face islide-pick-back">' + Fortune.nameBack() +
                    '<div class="islide-pick-backlabel">' + Util.escape(s.pluginname) + '</div></div>' +
                '<div class="islide-pick-face islide-pick-front islide-pick-namefront">' +
                    '<div class="islide-pick-ornament" aria-hidden="true">&#10022; &#10022; &#10022;</div>' +
                    '<div class="islide-pick-avatar">' + avatar + '</div>' +
                    '<div class="islide-pick-name">' + Util.escape(pick.fullname) + '</div>' +
                    '<div class="islide-pick-meta"></div>' +
                    '<div class="islide-pick-badge"></div>' +
                    (several && self.canAward
                        ? footButton('award', s.randomcallawardone, 'card', index)
                        : '') +
                '</div>' +
            '</div></div>';
        setMeta(self, slot, pick);
        return slot;
    };

    /**
     * Write a card's stars and rank.
     *
     * @param {Object} self
     * @param {Element} slot
     * @param {Object} pick
     * @return {void}
     */
    var setMeta = function(self, slot, pick) {
        var meta = slot.querySelector('.islide-pick-meta');
        if (meta) {
            meta.textContent = Fortune.fill(self.strings.randomcallmeta,
                {stars: pick.stars, rank: pick.rank, total: pick.total});
        }
    };

    /**
     * The footer under the name cards.
     *
     * @param {Object} self
     * @return {void}
     */
    var namesFoot = function(self) {
        var s = self.strings;
        var picks = self.run.picks;
        var next = nextUndrawn(self);
        var buttons = [];

        if (self.prefs.fortune && next >= 0) {
            var label = picks.length > 1
                ? Fortune.fill(s.drawfortunefor, {
                    name: shortName(picks[next].fullname),
                    index: next + 1,
                    count: picks.length
                })
                : s.drawfortune;
            buttons.push(footButton('fortune', '✦ ' + label, 'magic', next));
        }
        if (self.canAward) {
            buttons.push(picks.length > 1
                ? footButton('awardall', Fortune.fill(s.randomcallawardall, picks.length), next < 0 ? 'primary' : '')
                : footButton('award', s.randomcallaward, next < 0 || !self.prefs.fortune ? 'primary' : '', 0));
        }
        buttons.push(footButton('again', '↻ ' + s.randomcallagain));
        buttons.push(footButton('close', s.randomcalldone));
        setFoot(self, buttons);
    };

    /**
     * The first person called who has not drawn a fortune yet.
     *
     * @param {Object} self
     * @return {Number} -1 when everyone has
     */
    var nextUndrawn = function(self) {
        for (var i = 0; i < self.run.picks.length; i++) {
            if (!self.run.picks[i].fortune) {
                return i;
            }
        }
        return -1;
    };

    /**
     * The name a person is called by: the last word of a Vietnamese name.
     *
     * @param {String} name
     * @return {String}
     */
    var shortName = function(name) {
        var words = String(name || '').trim().split(/\s+/);
        return words[words.length - 1] || name;
    };

    /**
     * Give stars from the overlay.
     *
     * @param {Object} self
     * @param {Number[]} indexes
     * @return {void}
     */
    var award = function(self, indexes) {
        var run = self.run;
        var picks = indexes.map(function(index) {
            return run.picks[index];
        });
        var request = picks.length === 1
            ? Api.awardStars(self.cmid, picks[0].userid, 1, self.strings.randomcallreason)
            : Api.awardStarsGroup(self.cmid, false, picks.map(function(pick) {
                return pick.userid;
            }), 1, self.strings.randomcallreason);

        request.then(function(response) {
            self.adopt(Api.unwrap(response));
            if (self.run !== run) {
                return response;
            }
            indexes.forEach(function(index) {
                run.picks[index].stars += 1;
                bump(self, index);
            });
            say(self, Fortune.fill(self.strings.randomcallstarsgiven, {
                stars: 1,
                name: picks.length === 1 ? picks[0].fullname : picks.length
            }));
            return response;
        }).catch(function(error) {
            say(self, error.message || String(error));
        });
    };

    /**
     * Refresh one name card's stars, with a little pop and a shower of sparks.
     *
     * @param {Object} self
     * @param {Number} index
     * @return {void}
     */
    var bump = function(self, index) {
        var slot = self.run.el.querySelectorAll('.islide-pick-cards .islide-pick-slot')[index];
        if (!slot) {
            return;
        }
        setMeta(self, slot, self.run.picks[index]);
        var meta = slot.querySelector('.islide-pick-meta');
        meta.classList.remove('islide-pick-bump');
        void meta.offsetWidth;
        meta.classList.add('islide-pick-bump');
        var anchor = self.run.el.querySelector('.islide-pick-fslot') || meta;
        var at = centre(self, anchor);
        self.run.sky.burst(at.x, at.y, GOLD, 50);
    };

    // ---------------------------------------------------------------------
    // Scene 2: the fortune card.
    // ---------------------------------------------------------------------

    /**
     * Ask the server for someone's card, then play it.
     *
     * @param {Object} self
     * @param {Number} index
     * @param {Boolean} redraw
     * @return {void}
     */
    var drawFortune = function(self, index, redraw) {
        var run = self.run;
        if (self.busy) {
            return;
        }
        self.busy = true;
        Array.prototype.forEach.call(run.el.querySelectorAll('.islide-pick-foot button'), function(button) {
            button.disabled = true;
        });
        Api.drawFortune(self.cmid, run.picks[index].id, redraw).then(function(response) {
            self.busy = false;
            self.adopt(Api.unwrap(response));
            if (self.run === run) {
                run.picks[index] = JSON.parse(response.pick);
                fortuneScene(self, index, false);
            }
            return response;
        }).catch(function(error) {
            self.busy = false;
            Array.prototype.forEach.call(run.el.querySelectorAll('.islide-pick-foot button'), function(button) {
                button.disabled = false;
            });
            say(self, error.message || String(error));
        });
    };

    /**
     * Pay the stars a card promised.
     *
     * @param {Object} self
     * @param {Number} index
     * @param {Element} button
     * @return {void}
     */
    var applyFortune = function(self, index, button) {
        var run = self.run;
        button.disabled = true;
        Api.applyFortune(self.cmid, run.picks[index].id).then(function(response) {
            self.adopt(Api.unwrap(response));
            if (self.run !== run) {
                return response;
            }
            var before = run.picks[index].stars;
            run.picks[index] = JSON.parse(response.pick);
            bump(self, index);
            button.remove();
            say(self, Fortune.fill(self.strings.randomcallstarsgiven, {
                stars: run.picks[index].stars - before,
                name: run.picks[index].fullname
            }));
            return response;
        }).catch(function(error) {
            button.disabled = false;
            say(self, error.message || String(error));
        });
    };

    /**
     * Spread the fan, let the light choose, and turn the card over.
     *
     * @param {Object} self
     * @param {Number} index
     * @param {Boolean} replay show a card already drawn, straight away
     * @return {void}
     */
    var fortuneScene = function(self, index, replay) {
        var run = self.run;
        var s = self.strings;
        var pick = run.picks[index];
        var card = pick.fortune;
        if (!card) {
            return;
        }

        run.el.querySelector('.islide-pick-cards').classList.add('islide-pick-away');
        var old = run.el.querySelector('.islide-pick-fortune');
        if (old) {
            old.remove();
        }
        setFoot(self, []);

        // Sentence by sentence, so the prophecy is read out rather than dumped.
        var sentences = String(card.prophecy).match(/[^.!?…]+[.!?…]+["”»]?\s*|[^.!?…]+$/g) || [card.prophecy];

        var box = document.createElement('div');
        box.className = 'islide-pick-fortune';
        box.style.setProperty('--islide-acc', Fortune.accent(card.key));
        box.innerHTML = '<div class="islide-pick-fhead"><span class="islide-pick-fav">' +
                Util.escape(Fortune.initials(pick.fullname)) + '</span> ' +
                Fortune.fill(Util.escape(s.fortuneof), '<b>' + Util.escape(pick.fullname) + '</b>') + '</div>' +
            '<div class="islide-pick-fan" aria-hidden="true"></div>' +
            '<div class="islide-pick-fstage" hidden>' +
                '<div class="islide-pick-slot islide-pick-fslot">' +
                    '<div class="islide-pick-rays" aria-hidden="true"></div>' +
                    '<div class="islide-pick-card"><div class="islide-pick-inner">' +
                        '<div class="islide-pick-face islide-tarot-back">' + Fortune.tarotBack(s.fortunecardback) + '</div>' +
                        '<div class="islide-pick-face islide-pick-front islide-fcard">' + Fortune.face(card) + '</div>' +
                    '</div></div>' +
                '</div>' +
                '<div class="islide-pick-ftext">' +
                    '<span class="islide-pick-fcap islide-pick-line">' + Util.escape(card.arcana + ' — ' + card.name) +
                    '</span>' +
                    '<p class="islide-pick-prophecy">' + sentences.map(function(sentence) {
                        return '<span class="islide-pick-line">' + Util.escape(sentence) + '</span>';
                    }).join('') + '</p>' +
                    '<div class="islide-pick-effect"><b>&#10022; ' + Util.escape(s.fortuneeffect) + '</b>' +
                        Util.escape(card.effect) + '</div>' +
                '</div>' +
            '</div>';
        run.el.appendChild(box);

        var fan = box.querySelector('.islide-pick-fan');
        var stage = box.querySelector('.islide-pick-fstage');
        var slot = box.querySelector('.islide-pick-fslot');
        var lines = Array.prototype.slice.call(box.querySelectorAll('.islide-pick-line, .islide-pick-effect'));

        var scene = newScene(self, function() {
            fan.remove();
            stage.hidden = false;
            slot.classList.add('islide-pick-flipped');
            lines.forEach(function(line) {
                line.classList.add('islide-pick-on');
            });
            fortuneFoot(self, index, !replay);
        });

        if (replay || reduced()) {
            skip(self);
            return;
        }

        var cards = [];
        var target = Math.floor(Math.random() * FAN_SIZE);
        var steps = [];

        // A fan of nine backs opens out from the bottom of the screen.
        steps.push(function() {
            for (var k = 0; k < FAN_SIZE; k++) {
                var angle = -32 + 64 * k / (FAN_SIZE - 1);
                var back = document.createElement('div');
                back.className = 'islide-pick-tarot islide-tarot-back';
                back.innerHTML = Fortune.tarotBack(s.fortunecardback);
                back.style.setProperty('--islide-pick-angle', angle + 'deg');
                back.setAttribute('data-angle', angle);
                fan.appendChild(back);
                cards.push(back);
                if (back.animate) {
                    back.animate([
                        {transform: 'rotate(0deg) translateY(50%)', opacity: 0},
                        {transform: 'rotate(' + angle + 'deg)', opacity: 1}
                    ], {duration: 700, delay: k * 45, easing: 'cubic-bezier(.2,1.2,.4,1)', fill: 'backwards'});
                }
            }
            whoosh(self, 0.25, 1.1);
            return 700 + FAN_SIZE * 45 + 200;
        });

        // A light runs along the fan and back, then slows to a stop on one card.
        var path = [];
        var k;
        for (k = 0; k < FAN_SIZE; k++) {
            path.push(k);
        }
        for (k = FAN_SIZE - 2; k >= 0; k--) {
            path.push(k);
        }
        for (k = 1; k <= target; k++) {
            path.push(k);
        }
        var delay = 55;
        path.forEach(function(position, step) {
            var wait = delay;
            if (step > path.length - 9) {
                delay *= 1.3;
            }
            steps.push(function() {
                cards.forEach(function(back) {
                    back.classList.remove('islide-pick-hot');
                });
                cards[position].classList.add('islide-pick-hot');
                chime(self, [1800], 0, 'square', 0.03, 0.04);
                return wait;
            });
        });
        steps.push(function() {
            return 450;
        });

        // The others fall away; the chosen card flies to its place.
        steps.push(function() {
            cards.forEach(function(back, position) {
                if (position !== target && back.animate) {
                    back.animate([
                        {opacity: 1},
                        {transform: 'rotate(' + back.getAttribute('data-angle') + 'deg) translateY(70%)', opacity: 0}
                    ], {duration: 450, delay: Math.abs(position - target) * 30, easing: 'ease-in', fill: 'forwards'});
                }
            });
            var chosen = cards[target];
            var from = centre(self, chosen);
            stage.hidden = false;
            var to = centre(self, slot);
            var scale = chosen.offsetHeight / slot.offsetHeight;
            chosen.style.visibility = 'hidden';
            if (slot.animate) {
                slot.animate([
                    {transform: 'translate(' + (from.x - to.x) + 'px,' + (from.y - to.y) + 'px) rotate(' +
                        chosen.getAttribute('data-angle') + 'deg) scale(' + scale + ')'},
                    {transform: 'none'}
                ], {duration: 800, easing: 'cubic-bezier(.3,1.1,.4,1)'});
            }
            return 900;
        });

        // Turn it over.
        steps.push(function() {
            fan.remove();
            slot.classList.add('islide-pick-flipped');
            chime(self, [440, 554, 659, 880, 1109], 0.11, 'sine', 0.14, 1.4);
            scene.timers.push(window.setTimeout(function() {
                if (self.run === run) {
                    var at = centre(self, slot);
                    run.sky.burst(at.x, at.y, Fortune.accent(card.key), card.auto ? 220 : 110);
                }
            }, 300));
            return 1000;
        });

        // And read the prophecy out, a sentence at a time.
        lines.forEach(function(line) {
            steps.push(function() {
                line.classList.add('islide-pick-on');
                return line.classList.contains('islide-pick-fcap') ? 300 : 650;
            });
        });

        play(self, scene, steps);
    };

    /**
     * The footer under a fortune card, and the card's mark on its name card.
     *
     * @param {Object} self
     * @param {Number} index
     * @param {Boolean} fresh the card was drawn just now, not looked at again
     * @return {void}
     */
    var fortuneFoot = function(self, index, fresh) {
        var s = self.strings;
        var run = self.run;
        var pick = run.picks[index];
        var card = pick.fortune;

        var slot = run.el.querySelectorAll('.islide-pick-cards .islide-pick-slot')[index];
        if (slot) {
            var badge = slot.querySelector('.islide-pick-badge');
            badge.innerHTML = '<button type="button" class="islide-pick-badgebtn" data-pick-act="viewfortune" ' +
                'data-pick-index="' + index + '" style="--islide-acc: ' + Fortune.accent(card.key) + '">' +
                Fortune.icon(card.key) + ' ' + Util.escape(card.name) + '</button>';
            setMeta(self, slot, pick);
        }

        // A card that pays on the spot has been paid by the server already.
        if (fresh && card.auto && card.applied) {
            say(self, Fortune.fill(s.randomcallstarsgiven, {stars: card.auto, name: pick.fullname}));
            bump(self, index);
        }

        var buttons = [];
        if (card.redraw) {
            buttons.push(footButton('redraw', '☸ ' + s.fortuneredraw, 'magic', index));
        } else if (card.stars && !card.applied && self.canAward) {
            buttons.push(footButton('apply', card.action, 'primary', index));
        } else if (!card.stars && !card.auto && self.canAward) {
            buttons.push(footButton('award', s.randomcallaward, 'primary', index));
        }
        var next = nextUndrawn(self);
        if (next >= 0 && !card.redraw) {
            buttons.push(footButton('fortune', Fortune.fill(s.fortunenext, shortName(run.picks[next].fullname)) +
                ' ›', 'magic', next));
        }
        buttons.push(footButton('back', '← ' + s.fortuneback));
        buttons.push(footButton('close', s.randomcalldone));
        setFoot(self, buttons);
    };

    /**
     * Put the name cards back up.
     *
     * @param {Object} self
     * @return {void}
     */
    var backToNames = function(self) {
        endScene(self);
        setSkip(self, false);
        var box = self.run.el.querySelector('.islide-pick-fortune');
        if (box) {
            box.remove();
        }
        self.run.el.querySelector('.islide-pick-cards').classList.remove('islide-pick-away');
        namesFoot(self);
    };

    // ---------------------------------------------------------------------
    // Shooting stars, sparks and sound.
    // ---------------------------------------------------------------------

    /**
     * The middle of an element, in the overlay's coordinates.
     *
     * @param {Object} self
     * @param {Element} node
     * @return {Object} x and y
     */
    var centre = function(self, node) {
        var box = node.getBoundingClientRect();
        var outer = self.run.el.getBoundingClientRect();
        return {x: box.left - outer.left + box.width / 2, y: box.top - outer.top + box.height / 2};
    };

    /**
     * A shooting star from one point to another.
     *
     * @param {Object} self
     * @param {Object} options from, to, color, duration, big
     * @return {void}
     */
    var meteor = function(self, options) {
        var run = self.run;
        if (!run.el.animate) {
            return;
        }
        var star = document.createElement('div');
        star.className = 'islide-pick-meteor' + (options.big ? ' islide-pick-meteor-big' : '');
        star.style.setProperty('--islide-pick-meteor', options.color);
        star.innerHTML = '<div class="islide-pick-meteor-tail"></div><div class="islide-pick-meteor-head"></div>';
        run.el.appendChild(star);

        var angle = Math.atan2(options.to.y - options.from.y, options.to.x - options.from.x) + 'rad';
        var animation = star.animate([
            {transform: 'translate(' + options.from.x + 'px,' + options.from.y + 'px) rotate(' + angle + ')', opacity: 0},
            {opacity: 1, offset: 0.12},
            {transform: 'translate(' + options.to.x + 'px,' + options.to.y + 'px) rotate(' + angle + ')',
                opacity: options.big ? 1 : 0}
        ], {
            duration: options.duration,
            easing: options.big ? 'cubic-bezier(.5,0,.8,.6)' : 'cubic-bezier(.35,0,.65,1)',
            fill: 'forwards'
        });

        // The gold star leaves a trail of dust where it actually is.
        if (options.big && window.DOMMatrix) {
            var follow = function() {
                if (!star.isConnected || self.run !== run) {
                    return;
                }
                var matrix = new window.DOMMatrix(window.getComputedStyle(star).transform);
                run.sky.sparkle(matrix.e, matrix.f, options.color);
                window.requestAnimationFrame(follow);
            };
            window.requestAnimationFrame(follow);
        }

        var done = function() {
            star.remove();
        };
        animation.finished.then(done, done);
    };

    /**
     * A silver star that crosses the sky and touches nothing.
     *
     * @param {Object} self
     * @return {void}
     */
    var silverMeteor = function(self) {
        var box = self.run.el.getBoundingClientRect();
        var from = {x: box.width * (0.25 + Math.random() * 0.95), y: -box.height * (0.05 + Math.random() * 0.15)};
        var angle = (118 + Math.random() * 40) * Math.PI / 180;
        var length = Math.max(box.width, box.height) * 1.5;
        meteor(self, {
            from: from,
            to: {x: from.x + Math.cos(angle) * length, y: from.y + Math.sin(angle) * length},
            color: SILVER[Math.floor(Math.random() * SILVER.length)],
            duration: 900 + Math.random() * 400
        });
    };

    /**
     * A white flash across the whole overlay.
     *
     * @param {Element} el
     * @return {void}
     */
    var flash = function(el) {
        var layer = el.querySelector('.islide-pick-flash');
        if (layer.animate) {
            layer.animate([{opacity: 0}, {opacity: 0.55, offset: 0.2}, {opacity: 0}], {duration: 600, easing: 'ease-out'});
        }
    };

    /**
     * The night sky: twinkling stars and the sparks of every burst.
     *
     * @param {HTMLCanvasElement} canvas
     * @return {Object} burst, sparkle, resize, stop
     */
    var makeSky = function(canvas) {
        var context = canvas.getContext('2d');
        var ratio = window.devicePixelRatio || 1;
        var width = 0;
        var height = 0;
        var stars = [];
        var sparks = [];
        var frame = 0;
        var last = 0;

        var resize = function() {
            width = canvas.clientWidth;
            height = canvas.clientHeight;
            canvas.width = width * ratio;
            canvas.height = height * ratio;
            context.setTransform(ratio, 0, 0, ratio, 0, 0);
            stars = [];
            for (var i = Math.round(width * height / 3500); i > 0; i--) {
                stars.push({
                    x: Math.random() * width,
                    y: Math.random() * height,
                    r: Math.random() * 1.3 + 0.3,
                    phase: Math.random() * 6.28,
                    speed: Math.random() * 2 + 0.5
                });
            }
        };

        var draw = function(time) {
            var step = last ? Math.min(50, time - last) / 16.7 : 1;
            last = time;
            context.clearRect(0, 0, width, height);
            context.fillStyle = '#ffffff';
            stars.forEach(function(star) {
                context.globalAlpha = 0.35 + 0.35 * Math.sin(time / 1000 * star.speed + star.phase);
                context.beginPath();
                context.arc(star.x, star.y, star.r, 0, 6.28);
                context.fill();
            });
            context.globalCompositeOperation = 'lighter';
            sparks = sparks.filter(function(spark) {
                spark.life -= 0.012 * step;
                return spark.life > 0;
            });
            sparks.forEach(function(spark) {
                spark.vx *= 0.985;
                spark.vy = spark.vy * 0.985 + 0.03 * step;
                spark.x += spark.vx * step;
                spark.y += spark.vy * step;
                context.globalAlpha = spark.life;
                context.fillStyle = spark.color;
                context.beginPath();
                context.arc(spark.x, spark.y, spark.r * (0.5 + spark.life), 0, 6.28);
                context.fill();
            });
            context.globalCompositeOperation = 'source-over';
            context.globalAlpha = 1;
            frame = window.requestAnimationFrame(draw);
        };

        resize();
        frame = window.requestAnimationFrame(draw);

        return {
            burst: function(x, y, color, count) {
                for (var i = 0; i < count; i++) {
                    var angle = Math.random() * 6.28;
                    var speed = Math.random() * 6 + 1.5;
                    sparks.push({
                        x: x, y: y,
                        vx: Math.cos(angle) * speed,
                        vy: Math.sin(angle) * speed - 1.5,
                        r: Math.random() * 2.2 + 0.8,
                        life: Math.random() * 0.5 + 0.6,
                        color: Math.random() < 0.35 ? '#ffffff' : color
                    });
                }
            },
            sparkle: function(x, y, color) {
                for (var i = 0; i < 3; i++) {
                    sparks.push({
                        x: x + (Math.random() - 0.5) * 8,
                        y: y + (Math.random() - 0.5) * 8,
                        vx: (Math.random() - 0.5) * 1.2,
                        vy: (Math.random() - 0.5) * 1.2,
                        r: Math.random() * 1.6 + 0.6,
                        life: Math.random() * 0.35 + 0.35,
                        color: Math.random() < 0.4 ? '#ffffff' : color
                    });
                }
            },
            resize: resize,
            stop: function() {
                window.cancelAnimationFrame(frame);
            }
        };
    };

    /**
     * The sound engine, made on first use.
     *
     * @param {Object} self
     * @return {AudioContext|null} null when sound is off or unavailable
     */
    var audio = function(self) {
        if (!self.prefs.sound) {
            return null;
        }
        if (!self.audio) {
            var Engine = window.AudioContext || window.webkitAudioContext;
            if (!Engine) {
                return null;
            }
            self.audio = new Engine();
        }
        return self.audio;
    };

    /**
     * A few notes, one after another.
     *
     * @param {Object} self
     * @param {Number[]} notes frequencies
     * @param {Number} step seconds between notes
     * @param {String} type oscillator shape
     * @param {Number} volume
     * @param {Number} length seconds each note rings
     * @return {void}
     */
    var chime = function(self, notes, step, type, volume, length) {
        var engine = audio(self);
        if (!engine) {
            return;
        }
        notes.forEach(function(frequency, index) {
            var oscillator = engine.createOscillator();
            var gain = engine.createGain();
            var start = engine.currentTime + index * step;
            oscillator.type = type;
            oscillator.frequency.value = frequency;
            gain.gain.setValueAtTime(0, start);
            gain.gain.linearRampToValueAtTime(volume, start + 0.02);
            gain.gain.exponentialRampToValueAtTime(0.0001, start + length);
            oscillator.connect(gain).connect(engine.destination);
            oscillator.start(start);
            oscillator.stop(start + length + 0.05);
        });
    };

    /**
     * The rush of a shooting star: filtered noise rising in pitch.
     *
     * @param {Object} self
     * @param {Number} volume
     * @param {Number} seconds
     * @return {void}
     */
    var whoosh = function(self, volume, seconds) {
        var engine = audio(self);
        if (!engine) {
            return;
        }
        var length = Math.round(engine.sampleRate * seconds);
        var buffer = engine.createBuffer(1, length, engine.sampleRate);
        var data = buffer.getChannelData(0);
        for (var i = 0; i < length; i++) {
            data[i] = (Math.random() * 2 - 1) * Math.pow(i / length, 1.5);
        }
        var source = engine.createBufferSource();
        var filter = engine.createBiquadFilter();
        var gain = engine.createGain();
        source.buffer = buffer;
        filter.type = 'bandpass';
        filter.frequency.setValueAtTime(400, engine.currentTime);
        filter.frequency.exponentialRampToValueAtTime(3000, engine.currentTime + seconds);
        gain.gain.value = volume;
        source.connect(filter).connect(gain).connect(engine.destination);
        source.start();
    };

    return {create: create};
});
