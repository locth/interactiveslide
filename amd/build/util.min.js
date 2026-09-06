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
 * Small DOM and formatting helpers shared by the three screens.
 *
 * @module     mod_interactiveslide/util
 * @copyright  2026 Interactive Slide contributors
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
define([], function() {

    var TOAST_MS = 3200;
    var toastTimer = null;

    /**
     * Find one element by its data-region name.
     *
     * @param {Element} root
     * @param {String} name
     * @return {Element|null}
     */
    var region = function(root, name) {
        return root.querySelector('[data-region="' + name + '"]');
    };

    /**
     * Find every element carrying a data-action.
     *
     * @param {Element} root
     * @param {String} name
     * @return {Element[]}
     */
    var actions = function(root, name) {
        return Array.prototype.slice.call(root.querySelectorAll('[data-action="' + name + '"]'));
    };

    /**
     * Escape a string for insertion into HTML.
     *
     * Every value rendered by these modules comes from another user's keyboard,
     * so nothing reaches innerHTML without passing through here.
     *
     * @param {*} value
     * @return {String}
     */
    var escape = function(value) {
        return String(value === null || value === undefined ? '' : value)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    };

    /**
     * Show or hide an element.
     *
     * @param {Element} element
     * @param {Boolean} visible
     * @return {void}
     */
    var toggle = function(element, visible) {
        if (element) {
            element.hidden = !visible;
        }
    };

    /**
     * Build an element from a tag, class list and text.
     *
     * @param {String} tag
     * @param {String} className
     * @param {String} [text]
     * @return {Element}
     */
    var create = function(tag, className, text) {
        var element = document.createElement(tag);
        if (className) {
            element.className = className;
        }
        if (text !== undefined && text !== null) {
            element.textContent = String(text);
        }
        return element;
    };

    /**
     * Flash a short message in the screen's toast area.
     *
     * @param {Element} root
     * @param {String} message
     * @param {String} [tone] info, success or error
     * @return {void}
     */
    var toast = function(root, message, tone) {
        var node = region(root, 'toast');
        if (!node) {
            return;
        }

        node.textContent = message;
        node.dataset.tone = tone || 'info';
        node.hidden = false;

        if (toastTimer) {
            window.clearTimeout(toastTimer);
        }
        toastTimer = window.setTimeout(function() {
            node.hidden = true;
        }, TOAST_MS);
    };

    /**
     * Format a second count as m:ss.
     *
     * @param {Number} seconds
     * @return {String}
     */
    var clock = function(seconds) {
        var total = Math.max(0, Math.round(seconds));
        var minutes = Math.floor(total / 60);
        var rest = total % 60;
        if (minutes === 0) {
            return String(rest);
        }
        return minutes + ':' + (rest < 10 ? '0' : '') + rest;
    };

    /**
     * Draw the countdown ring and value of a timer widget.
     *
     * @param {Element} widget the .islide-timer element
     * @param {Number|null} secondsLeft
     * @param {Number} total the round's time limit
     * @return {void}
     */
    var drawTimer = function(widget, secondsLeft, total) {
        if (!widget) {
            return;
        }

        if (secondsLeft === null || secondsLeft === undefined || !total) {
            widget.hidden = true;
            return;
        }

        widget.hidden = false;

        var value = widget.querySelector('[data-region="timer-value"]');
        var arc = widget.querySelector('[data-region="timer-arc"]');
        var fraction = Math.max(0, Math.min(1, secondsLeft / total));

        if (value) {
            value.textContent = clock(secondsLeft);
        }
        if (arc) {
            var circumference = 2 * Math.PI * 19;
            arc.style.strokeDasharray = circumference.toFixed(2);
            arc.style.strokeDashoffset = (circumference * (1 - fraction)).toFixed(2);
        }

        widget.dataset.level = fraction <= 0 ? 'over' : (fraction < 0.2 ? 'low' : 'normal');
    };

    return {
        region: region,
        actions: actions,
        escape: escape,
        toggle: toggle,
        create: create,
        toast: toast,
        clock: clock,
        drawTimer: drawTimer
    };
});
