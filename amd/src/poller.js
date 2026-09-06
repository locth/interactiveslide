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
 * The polling loop that keeps a screen in step with the session.
 *
 * Each poll sends the revision the client already holds, so an idle classroom
 * costs one tiny request per client per interval instead of a full state
 * document. The interval stretches while the tab is hidden and after errors so
 * a lecture hall of laptops does not hammer the server for nothing.
 *
 * @module     mod_interactiveslide/poller
 * @copyright  2026 Interactive Slide contributors
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
define(['mod_interactiveslide/api'], function(Api) {

    var HIDDEN_MULTIPLIER = 4;
    var MAX_BACKOFF = 30000;

    /**
     * Create a poller for one activity.
     *
     * @param {Object} config cmid, interval, onState, onError, onConnection
     * @return {Object} the poller handle
     */
    var create = function(config) {
        var state = {
            revision: -1,
            timer: null,
            stopped: false,
            failures: 0,
            inflight: false
        };

        /**
         * The delay before the next poll, given tab visibility and failures.
         *
         * @return {Number} milliseconds
         */
        var nextDelay = function() {
            var base = config.interval || 2000;
            if (document.hidden) {
                base *= HIDDEN_MULTIPLIER;
            }
            if (state.failures > 0) {
                base = Math.min(MAX_BACKOFF, base * Math.pow(2, Math.min(state.failures, 5)));
            }
            return base;
        };

        /**
         * Queue the next poll.
         *
         * @return {void}
         */
        var schedule = function() {
            if (state.stopped) {
                return;
            }
            window.clearTimeout(state.timer);
            state.timer = window.setTimeout(tick, nextDelay());
        };

        /**
         * Run one poll.
         *
         * @return {void}
         */
        var tick = function() {
            if (state.stopped || state.inflight) {
                return;
            }
            state.inflight = true;

            Api.getState(config.cmid, state.revision).then(function(response) {
                state.inflight = false;
                state.failures = 0;

                if (config.onConnection) {
                    config.onConnection('online');
                }

                if (response.changed) {
                    state.revision = response.revision;
                    var document_ = Api.unwrap(response);
                    if (document_ && config.onState) {
                        config.onState(document_);
                    }
                }

                schedule();
                return response;
            }).catch(function(error) {
                state.inflight = false;
                state.failures++;

                if (config.onConnection) {
                    config.onConnection('offline');
                }
                if (config.onError) {
                    config.onError(error);
                }

                schedule();
            });
        };

        /**
         * Fetch the state immediately, ignoring the known revision.
         *
         * Used after an action so the screen updates without waiting a tick.
         *
         * @return {void}
         */
        var refresh = function() {
            state.revision = -1;
            window.clearTimeout(state.timer);
            tick();
        };

        /**
         * Adopt a state document that arrived from an action's response.
         *
         * @param {Object} document_
         * @return {void}
         */
        var adopt = function(document_) {
            if (!document_) {
                return;
            }
            state.revision = document_.revision;
            if (config.onState) {
                config.onState(document_);
            }
            schedule();
        };

        document.addEventListener('visibilitychange', function() {
            if (!document.hidden && !state.stopped) {
                // Coming back to the tab should feel instant, not one interval late.
                refresh();
            }
        });

        return {
            start: function() {
                state.stopped = false;
                tick();
            },
            stop: function() {
                state.stopped = true;
                window.clearTimeout(state.timer);
            },
            refresh: refresh,
            adopt: adopt
        };
    };

    return {create: create};
});
