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
 * Drawing on top of the slide during a lecture.
 *
 * Built for a tablet and a stylus first. Strokes are kept in normalised
 * coordinates rather than pixels, so the same drawing survives a rotation, a
 * resize and entering full screen, all of which happen constantly when someone
 * is presenting from an iPad.
 *
 * Everything here lives in the browser. Annotations are what the room already
 * sees on the projector, so sending them anywhere would cost bandwidth and a
 * database table to show people something they are looking at already.
 *
 * @module     mod_interactiveslide/annotate
 * @copyright  2026 Interactive Slide contributors
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
define(['mod_interactiveslide/util'], function(Util) {

    /** @var {Number} How long a laser trail stays put after the pen lifts. */
    var LASER_HOLD = 800;

    /** @var {Number} How long the trail then takes to fade away.
     *
     * Close to the hold on purpose. An earlier build held for two seconds and
     * faded in well under one, and a fade that short after a wait that long is
     * not read as a fade at all: the trail appears to simply vanish. Roughly
     * half the trail's life is now spent visibly dimming.
     */
    var LASER_FADE = 700;

    /** @var {Number} Ceiling on trail points, so a long scribble stays cheap. */
    var LASER_MAX_POINTS = 4000;

    /** @var {Number} Stroke widths, as a fraction of the stage width. */
    var REFERENCE_WIDTH = 1000;

    /** @var {Number} How close a pointer must come to a stroke to rub it out. */
    var ERASER_RADIUS = 14;

    /** @var {Number} Ignore touches for this long after a stylus is used. */
    var PALM_GUARD = 1200;

    /**
     * Attach the annotation layer to a presenter stage.
     *
     * @param {Element} root the presenter console
     * @return {Object|null} the handle, or null when the markup is absent
     */
    var create = function(root) {
        var layer = Util.region(root, 'annotate-layer');
        var inkCanvas = Util.region(root, 'annotate-ink');
        var laserCanvas = Util.region(root, 'annotate-laser');
        var toolbar = Util.region(root, 'annotate');

        if (!layer || !inkCanvas || !laserCanvas || !toolbar) {
            return null;
        }

        var view = {
            root: root,
            layer: layer,
            ink: {canvas: inkCanvas, ctx: inkCanvas.getContext('2d')},
            laser: {canvas: laserCanvas, ctx: laserCanvas.getContext('2d')},
            toolbar: toolbar,

            tool: 'off',
            stylusonly: false,
            lasermode: 'dot',
            pencolour: '#2563eb',
            penwidth: 4,
            highlightcolour: '#fde047',

            // Strokes are kept per slide so flipping back and forth does not
            // lose what was drawn a moment ago.
            bySlide: {},
            slideid: 0,
            strokes: [],

            drawing: null,
            dot: null,
            // The laser head is drawn both while pointing and, on a mouse, while
            // merely hovering; this says which of the two is happening.
            laserdown: false,
            lastPen: 0,

            // The laser keeps whole strokes rather than one timestamped trail:
            // they stay put while the pen is in use and only fade once it has
            // been away for a while, the way a stylus laser behaves elsewhere.
            // Named apart from `laser`, which is the canvas it draws on.
            laserState: {
                strokes: [],
                current: null,
                points: 0,
                opacity: 1,
                holdTimer: null,
                frame: null,
                fadeStart: 0
            }
        };

        bindTools(view);
        bindPointer(view);
        observeSize(view);

        setTool(view, 'off');

        return {
            setSlide: function(slideid) {
                selectSlide(view, slideid);
            },
            resize: function() {
                resize(view);
            },
            isActive: function() {
                return view.tool !== 'off';
            }
        };
    };

    /**
     * Wire the toolbar buttons.
     *
     * @param {Object} view
     * @return {void}
     */
    var bindTools = function(view) {
        view.toolbar.addEventListener('click', function(event) {
            var button = event.target.closest('[data-annotate-tool], [data-annotate-set],'
                + ' [data-annotate-action]');
            if (!button) {
                return;
            }
            event.preventDefault();

            if (button.dataset.annotateTool) {
                setTool(view, button.dataset.annotateTool);
                return;
            }

            if (button.dataset.annotateAction === 'clear') {
                view.strokes.length = 0;
                redraw(view);
                return;
            }

            if (button.dataset.annotateAction === 'stylusonly') {
                view.stylusonly = !view.stylusonly;
                button.classList.toggle('islide-annotate-on', view.stylusonly);
                button.setAttribute('aria-pressed', view.stylusonly ? 'true' : 'false');
                return;
            }

            var set = button.dataset.annotateSet;
            if (set === 'lasermode') {
                view.lasermode = button.dataset.annotateValue;
            } else if (set === 'pencolour') {
                view.pencolour = button.dataset.annotateValue;
            } else if (set === 'penwidth') {
                view.penwidth = parseFloat(button.dataset.annotateValue);
            } else if (set === 'highlightcolour') {
                view.highlightcolour = button.dataset.annotateValue;
            }

            markSelected(view);
        });
    };

    /**
     * Switch tool and show the options that belong to it.
     *
     * @param {Object} view
     * @param {String} tool
     * @return {void}
     */
    var setTool = function(view, tool) {
        if (view.tool === 'laser' && tool !== 'laser') {
            laserCancel(view);
        }

        view.tool = tool;
        view.drawing = null;

        // With no tool in hand the layer must let clicks through, or the
        // presenter could not reach the transport controls underneath it.
        view.layer.style.pointerEvents = tool === 'off' ? 'none' : 'auto';
        view.layer.dataset.tool = tool;
        view.root.dataset.annotating = tool === 'off' ? '0' : '1';

        ['laser', 'pen', 'highlight'].forEach(function(name) {
            Util.toggle(Util.region(view.root, 'annotate-options-' + name), tool === name);
        });

        markSelected(view);
    };

    /**
     * Reflect the current tool and its settings on the toolbar.
     *
     * @param {Object} view
     * @return {void}
     */
    var markSelected = function(view) {
        var current = {
            lasermode: view.lasermode,
            pencolour: view.pencolour,
            penwidth: String(view.penwidth),
            highlightcolour: view.highlightcolour
        };

        Array.prototype.forEach.call(
            view.toolbar.querySelectorAll('[data-annotate-tool]'),
            function(button) {
                var on = button.dataset.annotateTool === view.tool;
                button.classList.toggle('islide-annotate-on', on);
                button.setAttribute('aria-pressed', on ? 'true' : 'false');
            }
        );

        Array.prototype.forEach.call(
            view.toolbar.querySelectorAll('[data-annotate-set]'),
            function(button) {
                var on = current[button.dataset.annotateSet] === button.dataset.annotateValue;
                button.classList.toggle('islide-annotate-on', on);
                button.setAttribute('aria-pressed', on ? 'true' : 'false');
            }
        );
    };

    /**
     * Move to another slide, parking the current drawing and restoring that
     * slide's own.
     *
     * @param {Object} view
     * @param {Number} slideid
     * @return {void}
     */
    var selectSlide = function(view, slideid) {
        slideid = parseInt(slideid, 10) || 0;
        if (slideid === view.slideid) {
            return;
        }

        if (view.slideid) {
            view.bySlide[view.slideid] = view.strokes;
        }

        view.slideid = slideid;
        view.strokes = view.bySlide[slideid] || [];
        view.drawing = null;
        laserCancel(view);

        redraw(view);
    };

    /**
     * Keep both canvases matched to the stage, in device pixels.
     *
     * @param {Object} view
     * @return {void}
     */
    var resize = function(view) {
        var rect = view.layer.getBoundingClientRect();
        if (!rect.width || !rect.height) {
            return;
        }

        var ratio = window.devicePixelRatio || 1;

        [view.ink, view.laser].forEach(function(surface) {
            surface.canvas.width = Math.round(rect.width * ratio);
            surface.canvas.height = Math.round(rect.height * ratio);
            surface.ctx.setTransform(ratio, 0, 0, ratio, 0, 0);
        });

        view.width = rect.width;
        view.height = rect.height;

        redraw(view);
        paintLaser(view);
    };

    /**
     * Redraw whenever the stage changes shape, which full screen and a tablet
     * rotation both do.
     *
     * @param {Object} view
     * @return {void}
     */
    var observeSize = function(view) {
        if (window.ResizeObserver) {
            new window.ResizeObserver(function() {
                resize(view);
            }).observe(view.layer);
        } else {
            window.addEventListener('resize', function() {
                resize(view);
            });
        }

        document.addEventListener('fullscreenchange', function() {
            window.setTimeout(function() {
                resize(view);
            }, 120);
        });

        // The stage has no size until the slide image has laid out.
        window.requestAnimationFrame(function() {
            resize(view);
        });
    };

    /**
     * Hold or release the pointer, without letting a refused capture cost a stroke.
     *
     * setPointerCapture throws when the id no longer belongs to an active
     * pointer, which a fast tap or a released stylus can produce between the
     * event being queued and this running. Unhandled, that exception abandoned
     * the rest of the handler and the stroke was never started.
     *
     * @param {Element} layer
     * @param {Number} pointerId
     * @param {Boolean} hold true to capture, false to release
     * @return {void}
     */
    var capture = function(layer, pointerId, hold) {
        try {
            if (hold) {
                layer.setPointerCapture(pointerId);
            } else if (layer.hasPointerCapture(pointerId)) {
                layer.releasePointerCapture(pointerId);
            }
        } catch (e) {
            // Capture is a convenience, not a requirement: the stroke carries on
            // either way, and pointerleave still ends it cleanly.
            return;
        }
    };

    /**
     * Where a pointer is, in normalised stage coordinates.
     *
     * @param {Object} view
     * @param {PointerEvent} event
     * @return {Object} x and y between 0 and 1
     */
    var pointOf = function(view, event) {
        var rect = view.layer.getBoundingClientRect();
        return {
            x: (event.clientX - rect.left) / rect.width,
            y: (event.clientY - rect.top) / rect.height,
            p: event.pointerType === 'pen' && event.pressure > 0 ? event.pressure : 0.5
        };
    };

    /**
     * Whether an event should be acted on.
     *
     * A hand resting on the screen while writing with a stylus arrives as a
     * touch, so touches are ignored for a moment after any stylus contact.
     *
     * @param {Object} view
     * @param {PointerEvent} event
     * @return {Boolean}
     */
    var accepts = function(view, event) {
        if (event.pointerType === 'pen') {
            view.lastPen = Date.now();
            return true;
        }

        // Stylus only: a finger is not a drawing tool, so it is left entirely to
        // the browser. Swiping and scrolling keep working without having to put
        // the pen down first.
        if (view.stylusonly) {
            return false;
        }

        if (event.pointerType === 'touch' && Date.now() - view.lastPen < PALM_GUARD) {
            return false;
        }

        return true;
    };

    /**
     * Stop the browser doing its own thing with a stroke.
     *
     * Safari on iPadOS builds pointer events on top of touch events, and
     * preventing the pointer event does not always reach far enough: the touch
     * sequence underneath still starts a text selection over the slide, and the
     * Copy callout lands on top of the drawing. These listeners are registered
     * as non passive precisely so preventDefault is allowed to work.
     *
     * @param {Object} view
     * @return {void}
     */
    var suppressBrowserGestures = function(view) {
        var layer = view.layer;

        var block = function(event) {
            if (view.tool === 'off') {
                return;
            }

            // In stylus only mode a finger must reach the browser untouched,
            // otherwise scrolling and swiping would be dead while a tool is out.
            // Safari reports stylus contact as a touch too, so the distinction is
            // made on the touch itself rather than on the mode alone.
            if (view.stylusonly && !hasStylusTouch(event)) {
                return;
            }

            event.preventDefault();
        };

        ['touchstart', 'touchmove', 'touchend', 'gesturestart', 'contextmenu']
            .forEach(function(name) {
                layer.addEventListener(name, block, {passive: false});
            });
    };

    /**
     * Whether a touch event carries stylus contact.
     *
     * Safari delivers Apple Pencil as a touch whose touchType is 'stylus', which
     * is the only way to tell pen from finger at the touch layer, before the
     * pointer events are synthesised.
     *
     * @param {TouchEvent} event
     * @return {Boolean}
     */
    var hasStylusTouch = function(event) {
        var touches = event.touches || event.changedTouches;
        if (!touches || !touches.length) {
            return false;
        }

        for (var i = 0; i < touches.length; i++) {
            if (touches[i].touchType === 'stylus') {
                return true;
            }
        }

        return false;
    };

    /**
     * Drop any selection the browser managed to start.
     *
     * Dismisses the callout if one is already on screen when the next stroke
     * begins, so a teacher is never a tap away from being able to draw.
     *
     * @return {void}
     */
    var clearSelection = function() {
        var selection = window.getSelection ? window.getSelection() : null;
        if (selection && selection.rangeCount) {
            selection.removeAllRanges();
        }
    };

    /**
     * Pointer handling for all four tools.
     *
     * @param {Object} view
     * @return {void}
     */
    var bindPointer = function(view) {
        var layer = view.layer;

        suppressBrowserGestures(view);

        layer.addEventListener('pointerdown', function(event) {
            if (view.tool === 'off' || !accepts(view, event)) {
                return;
            }
            event.preventDefault();
            clearSelection();
            capture(layer, event.pointerId, true);

            var point = pointOf(view, event);

            if (view.tool === 'laser') {
                laserBegin(view, point);
                return;
            }

            if (view.tool === 'eraser') {
                erase(view, point);
                view.drawing = {tool: 'eraser'};
                return;
            }

            view.drawing = {
                tool: view.tool,
                colour: view.tool === 'pen' ? view.pencolour : view.highlightcolour,
                width: (view.tool === 'pen' ? view.penwidth : 18) / REFERENCE_WIDTH,
                points: [point]
            };
            view.strokes.push(view.drawing);
        });

        layer.addEventListener('pointermove', function(event) {
            if (view.tool === 'off' || !accepts(view, event)) {
                return;
            }

            if (view.tool === 'laser') {
                if (view.laserdown) {
                    laserExtend(view, pointOf(view, event));
                } else if (event.pointerType === 'mouse') {
                    // Presenting from a laptop there is no pen to press, and the
                    // layer hides the mouse cursor so the room is not shown an
                    // arrow next to the dot. The dot follows the mouse instead,
                    // which is what a laser pointer does anyway.
                    laserHover(view, pointOf(view, event));
                }
                return;
            }

            if (!view.drawing) {
                return;
            }
            event.preventDefault();

            if (view.drawing.tool === 'eraser') {
                erase(view, pointOf(view, event));
                return;
            }

            // A stylus reports far more positions than the screen refreshes at;
            // taking them all is what makes a fast stroke look like a curve
            // rather than a run of straight segments.
            var events = event.getCoalescedEvents ? event.getCoalescedEvents() : [event];
            events.forEach(function(sample) {
                view.drawing.points.push(pointOf(view, sample));
            });

            redraw(view);
        });

        var finish = function(event) {
            capture(layer, event.pointerId, false);

            if (view.tool === 'laser') {
                if (view.laserdown) {
                    laserEnd(view);
                } else {
                    // Only the hover head was showing; take it off without
                    // restarting the fade of whatever is already on screen.
                    view.dot = null;
                    paintLaser(view);
                }
                return;
            }

            if (view.drawing && view.drawing.tool !== 'eraser' && view.drawing.points.length < 2) {
                // A tap with a pen should still leave a mark.
                view.drawing.points.push(view.drawing.points[0]);
            }

            view.drawing = null;
            redraw(view);
        };

        layer.addEventListener('pointerup', finish);
        layer.addEventListener('pointercancel', finish);
        layer.addEventListener('pointerleave', finish);
    };

    /**
     * Remove any stroke the eraser is touching.
     *
     * Whole strokes rather than pixels: the drawing is stored as strokes so it
     * can be redrawn at any size, and rubbing a hole in a stored stroke would
     * not survive the next resize.
     *
     * @param {Object} view
     * @param {Object} point
     * @return {void}
     */
    var erase = function(view, point) {
        var radius = ERASER_RADIUS / (view.width || REFERENCE_WIDTH);
        var before = view.strokes.length;

        view.strokes = view.strokes.filter(function(stroke) {
            return !stroke.points.some(function(candidate) {
                var dx = candidate.x - point.x;
                var dy = candidate.y - point.y;
                return Math.sqrt(dx * dx + dy * dy) < radius + stroke.width;
            });
        });

        if (view.strokes.length !== before) {
            redraw(view);
        }
    };

    /**
     * Repaint every stored stroke.
     *
     * @param {Object} view
     * @return {void}
     */
    var redraw = function(view) {
        var ctx = view.ink.ctx;
        if (!view.width) {
            return;
        }

        ctx.clearRect(0, 0, view.width, view.height);
        ctx.lineJoin = 'round';

        view.strokes.forEach(function(stroke) {
            drawStroke(view, ctx, stroke);
        });
    };

    /**
     * Paint one stroke as a smooth curve through its points.
     *
     * @param {Object} view
     * @param {CanvasRenderingContext2D} ctx
     * @param {Object} stroke
     * @return {void}
     */
    var drawStroke = function(view, ctx, stroke) {
        var points = stroke.points;
        if (!points.length) {
            return;
        }

        ctx.save();
        ctx.globalAlpha = stroke.tool === 'highlight' ? 0.4 : 1;
        ctx.strokeStyle = stroke.colour;
        ctx.lineCap = stroke.tool === 'highlight' ? 'butt' : 'round';
        ctx.lineWidth = Math.max(1, stroke.width * view.width * pressureOf(points));

        ctx.beginPath();
        ctx.moveTo(points[0].x * view.width, points[0].y * view.height);

        // Midpoint smoothing: each segment is a curve whose control point is the
        // sample itself, which removes the corners a polyline would show.
        for (var i = 1; i < points.length; i++) {
            var previous = points[i - 1];
            var current = points[i];
            var midx = ((previous.x + current.x) / 2) * view.width;
            var midy = ((previous.y + current.y) / 2) * view.height;
            ctx.quadraticCurveTo(previous.x * view.width, previous.y * view.height, midx, midy);
        }

        var last = points[points.length - 1];
        ctx.lineTo(last.x * view.width, last.y * view.height);
        ctx.stroke();
        ctx.restore();
    };

    /**
     * The average stylus pressure across a stroke, as a width multiplier.
     *
     * @param {Array} points
     * @return {Number}
     */
    var pressureOf = function(points) {
        var total = 0;
        points.forEach(function(point) {
            total += point.p || 0.5;
        });
        return 0.6 + (total / points.length);
    };

    /**
     * Start a laser stroke.
     *
     * Anything already on screen stays: putting the pen down again is a sign the
     * presenter is still making the same point, so the hold is restarted rather
     * than the previous strokes being thrown away.
     *
     * @param {Object} view
     * @param {Object} point
     * @return {void}
     */
    var laserBegin = function(view, point) {
        var laser = view.laserState;

        window.clearTimeout(laser.holdTimer);
        laser.holdTimer = null;

        if (laser.frame) {
            window.cancelAnimationFrame(laser.frame);
            laser.frame = null;
        }

        laser.opacity = 1;
        view.dot = point;
        view.laserdown = true;

        if (view.lasermode === 'line') {
            laser.current = [point];
            laser.strokes.push(laser.current);
            laser.points++;
        }

        paintLaser(view);
    };

    /**
     * Carry a laser stroke on to the next point.
     *
     * @param {Object} view
     * @param {Object} point
     * @return {void}
     */
    var laserExtend = function(view, point) {
        var laser = view.laserState;
        view.dot = point;

        if (view.lasermode === 'line' && laser.current) {
            laser.current.push(point);
            laser.points++;
            trimLaser(view);
        }

        paintLaser(view);
    };

    /**
     * Lift the pen: drop the head, and start counting down to the fade.
     *
     * @param {Object} view
     * @return {void}
     */
    var laserEnd = function(view) {
        var laser = view.laserState;
        view.dot = null;
        view.laserdown = false;
        laser.current = null;

        if (view.lasermode !== 'line' || !laser.strokes.length) {
            laserCancel(view);
            return;
        }

        paintLaser(view);

        // Nothing animates during the hold. The canvas already shows the trail,
        // so the only work left is a single timer, and the tablet can idle.
        window.clearTimeout(laser.holdTimer);
        laser.holdTimer = window.setTimeout(function() {
            laser.holdTimer = null;
            laser.fadeStart = Date.now();
            fadeLaser(view);
        }, LASER_HOLD);
    };

    /**
     * Wipe the laser immediately, cancelling any pending fade.
     *
     * @param {Object} view
     * @return {void}
     */
    var laserCancel = function(view) {
        var laser = view.laserState;

        window.clearTimeout(laser.holdTimer);
        laser.holdTimer = null;

        if (laser.frame) {
            window.cancelAnimationFrame(laser.frame);
            laser.frame = null;
        }

        laser.strokes = [];
        laser.current = null;
        laser.points = 0;
        laser.opacity = 1;
        view.dot = null;
        view.laserdown = false;

        if (view.width) {
            view.laser.ctx.clearRect(0, 0, view.width, view.height);
        }
    };

    /**
     * Move the laser head without drawing: the mouse pointing, not pressing.
     *
     * Deliberately touches neither the hold timer nor the opacity, so a trail
     * left by the last stroke keeps fading while the head moves over it.
     *
     * @param {Object} view
     * @param {Object} point
     * @return {void}
     */
    var laserHover = function(view, point) {
        view.dot = point;
        paintLaser(view);
    };

    /**
     * Keep the trail within its point budget by dropping the oldest.
     *
     * A stylus reports hundreds of points a second and every one of them is
     * redrawn on each frame of the fade, so an unbounded trail would make the
     * fade stutter after a long scribble.
     *
     * @param {Object} view
     * @return {void}
     */
    var trimLaser = function(view) {
        var laser = view.laserState;

        while (laser.points > LASER_MAX_POINTS && laser.strokes.length) {
            var oldest = laser.strokes[0];
            oldest.shift();
            laser.points--;

            if (oldest.length < 2) {
                laser.points -= oldest.length;
                laser.strokes.shift();
            }
        }
    };

    /**
     * The fade, once the pen has been away long enough.
     *
     * @param {Object} view
     * @return {void}
     */
    var fadeLaser = function(view) {
        var laser = view.laserState;

        var step = function() {
            var elapsed = Date.now() - laser.fadeStart;
            var progress = Math.min(1, elapsed / LASER_FADE);

            // Eased rather than linear: the trail holds most of its brightness
            // through the first half and then drops away, which reads as a fade
            // instead of a dimmer being turned down at a constant rate.
            laser.opacity = Math.max(0, 1 - (progress * progress));

            if (laser.opacity <= 0) {
                laser.frame = null;
                laserCancel(view);
                return;
            }

            paintLaser(view);
            laser.frame = window.requestAnimationFrame(step);
        };

        laser.frame = window.requestAnimationFrame(step);
    };

    /**
     * Repaint the laser layer: the trail so far, then the head.
     *
     * @param {Object} view
     * @return {void}
     */
    var paintLaser = function(view) {
        var ctx = view.laser.ctx;
        if (!view.width) {
            return;
        }

        ctx.clearRect(0, 0, view.width, view.height);
        ctx.save();
        ctx.globalAlpha = view.laserState.opacity;

        view.laserState.strokes.forEach(function(stroke) {
            drawTrail(view, ctx, stroke);
        });

        ctx.restore();

        // Outside the fade: the head is where the pointer is now, so it stays
        // bright even while an older trail underneath it is on its way out.
        if (view.dot) {
            drawDot(view, ctx, view.dot);
        }
    };

    /**
     * One laser stroke, evenly bright along its whole length.
     *
     * @param {Object} view
     * @param {CanvasRenderingContext2D} ctx
     * @param {Array} stroke
     * @return {void}
     */
    var drawTrail = function(view, ctx, stroke) {
        if (stroke.length < 2) {
            return;
        }

        ctx.save();
        ctx.lineCap = 'round';
        ctx.lineJoin = 'round';
        ctx.strokeStyle = '#ff2d2d';
        ctx.shadowColor = 'rgba(255, 45, 45, .9)';
        ctx.shadowBlur = 12;
        ctx.lineWidth = 5;

        ctx.beginPath();
        ctx.moveTo(stroke[0].x * view.width, stroke[0].y * view.height);

        for (var i = 1; i < stroke.length; i++) {
            var previous = stroke[i - 1];
            var current = stroke[i];
            var midx = ((previous.x + current.x) / 2) * view.width;
            var midy = ((previous.y + current.y) / 2) * view.height;
            ctx.quadraticCurveTo(previous.x * view.width, previous.y * view.height, midx, midy);
        }

        var last = stroke[stroke.length - 1];
        ctx.lineTo(last.x * view.width, last.y * view.height);
        ctx.stroke();
        ctx.restore();
    };

    /**
     * The glowing head of the laser.
     *
     * @param {Object} view
     * @param {CanvasRenderingContext2D} ctx
     * @param {Object} point
     * @return {void}
     */
    var drawDot = function(view, ctx, point) {
        var x = point.x * view.width;
        var y = point.y * view.height;

        ctx.save();
        var glow = ctx.createRadialGradient(x, y, 0, x, y, 16);
        glow.addColorStop(0, 'rgba(255, 255, 255, .95)');
        glow.addColorStop(0.35, 'rgba(255, 45, 45, .95)');
        glow.addColorStop(1, 'rgba(255, 45, 45, 0)');
        ctx.fillStyle = glow;
        ctx.beginPath();
        ctx.arc(x, y, 16, 0, Math.PI * 2);
        ctx.fill();
        ctx.restore();
    };

    return {create: create};
});
