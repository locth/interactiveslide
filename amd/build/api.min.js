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
 * Thin wrappers over the plugin's web services.
 *
 * @module     mod_interactiveslide/api
 * @copyright  2026 Interactive Slide contributors
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
define(['core/ajax'], function(Ajax) {

    /**
     * Call one external function and return its promise.
     *
     * @param {String} methodname
     * @param {Object} args
     * @return {Promise}
     */
    var call = function(methodname, args) {
        return Ajax.call([{methodname: methodname, args: args}], true, true, false)[0];
    };

    /**
     * Decode a state response into the state document.
     *
     * @param {Object} response
     * @return {Object|null} null when the server said nothing changed
     */
    var unwrap = function(response) {
        if (!response || !response.state) {
            return null;
        }
        return JSON.parse(response.state);
    };

    return {
        unwrap: unwrap,

        getState: function(cmid, knownRevision) {
            return call('mod_interactiveslide_get_state', {
                cmid: cmid,
                knownrevision: knownRevision === undefined ? -1 : knownRevision
            });
        },

        startSession: function(cmid, name) {
            return call('mod_interactiveslide_start_session', {cmid: cmid, name: name || ''});
        },

        endSession: function(cmid) {
            return call('mod_interactiveslide_end_session', {cmid: cmid});
        },

        setSlide: function(cmid, slideid, direction) {
            return call('mod_interactiveslide_set_slide', {
                cmid: cmid,
                slideid: slideid || 0,
                direction: direction || 0
            });
        },

        controlRound: function(cmid, action) {
            return call('mod_interactiveslide_control_round', {cmid: cmid, action: action});
        },

        awardStars: function(cmid, userid, stars, reason) {
            return call('mod_interactiveslide_award_stars', {
                cmid: cmid,
                userid: userid,
                stars: stars,
                reason: reason || ''
            });
        },

        submitResponse: function(cmid, roundid, answer) {
            return call('mod_interactiveslide_submit_response', {
                cmid: cmid,
                roundid: roundid,
                answer: JSON.stringify(answer)
            });
        },

        getDeck: function(cmid) {
            return call('mod_interactiveslide_get_deck', {cmid: cmid});
        },

        saveInteraction: function(cmid, slideid, payload) {
            return call('mod_interactiveslide_save_interaction', {
                cmid: cmid,
                slideid: slideid,
                payload: JSON.stringify(payload)
            });
        },

        resetInteraction: function(cmid, slideid) {
            return call('mod_interactiveslide_reset_interaction', {cmid: cmid, slideid: slideid});
        },

        deleteInteraction: function(cmid, slideid) {
            return call('mod_interactiveslide_delete_interaction', {cmid: cmid, slideid: slideid});
        },

        deleteSlide: function(cmid, slideid) {
            return call('mod_interactiveslide_delete_slide', {cmid: cmid, slideid: slideid});
        },

        updateSlide: function(cmid, slideid, title, order) {
            return call('mod_interactiveslide_update_slide', {
                cmid: cmid,
                slideid: slideid || 0,
                title: title || '',
                order: order || []
            });
        }
    };
});
