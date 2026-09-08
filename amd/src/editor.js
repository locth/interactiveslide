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
 * The slide and interaction editor.
 *
 * @module     mod_interactiveslide/editor
 * @copyright  2026 Interactive Slide contributors
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
define([
    'core/str',
    'core/notification',
    'mod_interactiveslide/api',
    'mod_interactiveslide/pdfimport',
    'mod_interactiveslide/util'
], function(Str, Notification, Api, PdfImport, Util) {

    var STRING_KEYS = [
        'saved', 'saving', 'savefailed', 'importing', 'importdone', 'importfailed',
        'pdfjsmissing', 'confirmdeleteslide', 'confirmdeleteslide_desc',
        'notanimage', 'uploading', 'slideadded', 'uploadfailed',
        'confirmdeleteinteraction', 'confirmdeleteinteraction_desc', 'confirmreplacedeck',
        'confirmreplacedeck_desc', 'deleteslide', 'removeinteraction', 'remove',
        'optionplaceholder', 'answersplaceholder', 'blanklabelplaceholder', 'correct',
        'acceptedanswers', 'points', 'difficulty', 'difficultyeasy', 'difficultymedium',
        'difficultyhard', 'blank', 'importprogress', 'notapdf', 'nointeractionyet',
        'unsavedinteraction', 'unsavedinteraction_desc', 'discardchanges', 'typewordcloud',
        'typemultichoice', 'typefillblank', 'clearresponses', 'confirmclearresponses',
        'confirmclearresponses_desc', 'responsescleared', 'typeopenended',
        'maxwordlength', 'maxanswerlength', 'novideo', 'video',
        'positions', 'blanks', 'addblank', 'options', 'addoption',
        'typedropdown', 'typevideo'
    ];

    /**
     * Boot the editor.
     *
     * @param {Object} config
     * @return {void}
     */
    var init = function(config) {
        var root = document.querySelector('[data-region="interactiveslide-editor"]');
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
     * Wire the editor up.
     *
     * @param {Element} root
     * @param {Object} config
     * @param {Object} strings
     * @return {void}
     */
    var start = function(root, config, strings) {
        var view = {
            root: root,
            config: config,
            strings: strings,
            slides: [],
            defaults: {easy: 1, medium: 2, hard: 3},
            selectedId: 0,
            draft: null,
            dirty: false
        };

        bindImport(view);
        bindAddSlide(view);
        bindSlideTools(view);
        bindInspector(view);

        reload(view);
    };

    /**
     * Fetch the deck and redraw everything.
     *
     * @param {Object} view
     * @param {Number} [selectId] slide to select afterwards
     * @return {Promise}
     */
    var reload = function(view, selectId) {
        return Api.getDeck(view.config.cmid).then(function(response) {
            var deck = JSON.parse(response.deck);
            view.slides = deck.slides || [];
            view.defaults = deck.defaultpoints || view.defaults;

            renderSlideList(view);
            renderDifficultyPoints(view);

            var target = selectId || view.selectedId;
            var exists = view.slides.some(function(slide) {
                return slide.id === target;
            });
            select(view, exists ? target : (view.slides.length ? view.slides[0].id : 0));

            return deck;
        }).catch(Notification.exception);
    };

    /**
     * Draw the slide thumbnails, with drag to reorder.
     *
     * @param {Object} view
     * @return {void}
     */
    var renderSlideList = function(view) {
        var list = Util.region(view.root, 'slidelist');
        if (!list) {
            return;
        }

        if (!view.slides.length) {
            list.innerHTML = '';
            return;
        }

        var html = '';
        view.slides.forEach(function(slide) {
            var badge = '';
            if (slide.interaction) {
                var qtype = slide.interaction.qtype;
                var letters = {
                    wordcloud: 'W',
                    multichoice: 'M',
                    dropdown: 'D',
                    fillblank: 'F',
                    openended: 'O',
                    video: 'V'
                };
                var names = {
                    wordcloud: view.strings.typewordcloud,
                    multichoice: view.strings.typemultichoice,
                    dropdown: view.strings.typedropdown,
                    fillblank: view.strings.typefillblank,
                    openended: view.strings.typeopenended,
                    video: view.strings.typevideo
                };
                badge = '<span class="islide-thumb-badge islide-thumb-' + Util.escape(qtype) + '"' +
                    ' title="' + Util.escape(names[qtype] || qtype) + '">' +
                    Util.escape(letters[qtype] || qtype.charAt(0).toUpperCase()) + '</span>';
            }
            html += '<div class="islide-thumb islide-thumb-draggable" draggable="true"' +
                ' data-slideid="' + Number(slide.id) + '" tabindex="0" role="button">' +
                '<span class="islide-thumb-index">' + (slide.index + 1) + '</span>' +
                '<img src="' + Util.escape(slide.imageurl) + '" alt="" loading="lazy">' +
                badge +
                '</div>';
        });
        list.innerHTML = html;

        if (!list.dataset.bound) {
            list.dataset.bound = '1';
            bindSlideList(view, list);
        }
    };

    /**
     * Selection and drag handling for the slide list.
     *
     * @param {Object} view
     * @param {Element} list
     * @return {void}
     */
    var bindSlideList = function(view, list) {
        var dragged = null;

        list.addEventListener('click', function(event) {
            var thumb = event.target.closest('.islide-thumb');
            if (thumb) {
                selectGuarded(view, parseInt(thumb.dataset.slideid, 10));
            }
        });

        list.addEventListener('keydown', function(event) {
            var thumb = event.target.closest('.islide-thumb');
            if (thumb && (event.key === 'Enter' || event.key === ' ')) {
                event.preventDefault();
                selectGuarded(view, parseInt(thumb.dataset.slideid, 10));
            }
        });

        list.addEventListener('dragstart', function(event) {
            dragged = event.target.closest('.islide-thumb');
            if (dragged) {
                dragged.classList.add('islide-dragging');
                event.dataTransfer.effectAllowed = 'move';
                // Firefox needs data on the transfer before it will start a drag.
                event.dataTransfer.setData('text/plain', dragged.dataset.slideid);
            }
        });

        list.addEventListener('dragover', function(event) {
            if (!dragged) {
                return;
            }
            event.preventDefault();

            var over = event.target.closest('.islide-thumb');
            if (!over || over === dragged) {
                return;
            }

            var box = over.getBoundingClientRect();
            var after = (event.clientY - box.top) > (box.height / 2);
            list.insertBefore(dragged, after ? over.nextSibling : over);
        });

        list.addEventListener('dragend', function() {
            if (!dragged) {
                return;
            }
            dragged.classList.remove('islide-dragging');
            dragged = null;

            var order = Array.prototype.map.call(
                list.querySelectorAll('.islide-thumb'),
                function(thumb) {
                    return parseInt(thumb.dataset.slideid, 10);
                }
            );

            Api.updateSlide(view.config.cmid, 0, '', order).then(function() {
                return reload(view);
            }).catch(Notification.exception);
        });
    };

    /**
     * Show the star value of each difficulty on the picker.
     *
     * @param {Object} view
     * @return {void}
     */
    var renderDifficultyPoints = function(view) {
        ['easy', 'medium', 'hard'].forEach(function(level) {
            var node = Util.region(view.root, 'points-' + level);
            if (node) {
                node.textContent = view.defaults[level] + ' ★';
            }
        });
    };

    /**
     * Move to another slide, asking first if the current one has unsaved edits.
     *
     * @param {Object} view
     * @param {Number} slideid
     * @return {void}
     */
    var selectGuarded = function(view, slideid) {
        if (slideid === view.selectedId || !view.dirty) {
            select(view, slideid);
            return;
        }

        Notification.saveCancelPromise(
            view.strings.unsavedinteraction,
            view.strings.unsavedinteraction_desc,
            view.strings.discardchanges
        ).then(function() {
            select(view, slideid);
            return null;
        }).catch(function() {
            return null;
        });
    };

    /**
     * Select a slide and fill the inspector from it.
     *
     * @param {Object} view
     * @param {Number} slideid
     * @return {void}
     */
    var select = function(view, slideid) {
        view.selectedId = slideid;
        view.dirty = false;

        var empty = Util.region(view.root, 'editor-empty');
        var canvas = Util.region(view.root, 'canvas');
        var inspector = Util.region(view.root, 'inspector');

        var slide = view.slides.filter(function(item) {
            return item.id === slideid;
        })[0];

        Util.toggle(empty, !slide);
        Util.toggle(canvas, !!slide);
        Util.toggle(inspector, !!slide);

        if (!slide) {
            return;
        }

        Array.prototype.forEach.call(view.root.querySelectorAll('.islide-thumb'), function(thumb) {
            thumb.classList.toggle('islide-thumb-active',
                parseInt(thumb.dataset.slideid, 10) === slideid);
        });

        var image = Util.region(view.root, 'slideimage');
        if (image) {
            image.setAttribute('src', slide.imageurl);
            var figure = image.closest('.islide-stage-image');
            if (figure && slide.width > 0 && slide.height > 0) {
                figure.style.setProperty('--islide-aspect', slide.width + ' / ' + slide.height);
            }
        }

        var title = Util.region(view.root, 'slidetitle');
        if (title) {
            title.value = slide.title || '';
        }

        fillInspector(view, slide);
    };

    /**
     * Load a slide's interaction into the inspector controls.
     *
     * @param {Object} view
     * @param {Object} slide
     * @return {void}
     */
    var fillInspector = function(view, slide) {
        var interaction = slide.interaction;
        var locked = !!slide.locked;

        Util.toggle(Util.region(view.root, 'lockednote'), locked);
        Util.actions(view.root, 'deleteinteraction').forEach(function(button) {
            button.hidden = !interaction;
        });

        var qtype = interaction ? interaction.qtype : 'none';
        setType(view, qtype, false);

        var form = Util.region(view.root, 'interactionform');
        Util.toggle(form, qtype !== 'none');

        // Unlock before the early return below. Leaving it until after meant a
        // slide with no interaction inherited the disabled controls of whatever
        // locked slide was open before it, so a new question could not be added
        // anywhere in a deck that had already been run.
        setLocked(view, locked);

        if (qtype === 'none') {
            return;
        }

        setField(view, 'questiontext', interaction.questiontext);
        setField(view, 'videourl', interaction.videourl || '');
        setField(view, 'maxentries', interaction.maxentries);
        setField(view, 'maxwordlength', interaction.maxwordlength);
        setField(view, 'points', interaction.points);
        setField(view, 'timerseconds', interaction.timerseconds);
        setCheck(view, 'hasanswer', interaction.hasanswer);
        setCheck(view, 'allowmultiple', interaction.allowmultiple);
        setCheck(view, 'showliveresult', interaction.showliveresult);
        setCheck(view, 'showleaderboard', interaction.showleaderboard);
        setCheck(view, 'allowretry', interaction.allowretry);

        setDifficulty(view, interaction.difficulty === 'custom' ? 'easy' : interaction.difficulty);

        renderOptions(view, interaction.options || []);
        renderBlanks(view, interaction.blanks || []);

        applyVisibility(view);
    };

    /**
     * Disable the whole form once the question has been answered by students.
     *
     * @param {Object} view
     * @param {Boolean} locked
     * @return {void}
     */
    var setLocked = function(view, locked) {
        var form = Util.region(view.root, 'interactionform');
        if (!form) {
            return;
        }
        Array.prototype.forEach.call(form.querySelectorAll('input, textarea, select, button'),
            function(node) {
                node.disabled = locked;
            });
        form.classList.toggle('islide-form-locked', locked);

        Array.prototype.forEach.call(view.root.querySelectorAll('.islide-type'), function(button) {
            button.disabled = locked;
        });
    };

    /**
     * Read one field element.
     *
     * @param {Object} view
     * @param {String} name
     * @return {Element|null}
     */
    var field = function(view, name) {
        return view.root.querySelector('[data-field="' + name + '"]');
    };

    /**
     * Write a value into a field.
     *
     * @param {Object} view
     * @param {String} name
     * @param {*} value
     * @return {void}
     */
    var setField = function(view, name, value) {
        var node = field(view, name);
        if (node) {
            node.value = value === null || value === undefined ? '' : value;
        }
    };

    /**
     * Write a checkbox state.
     *
     * @param {Object} view
     * @param {String} name
     * @param {*} value
     * @return {void}
     */
    var setCheck = function(view, name, value) {
        var node = field(view, name);
        if (node) {
            node.checked = !!Number(value);
        }
    };

    /**
     * Select a difficulty on the picker.
     *
     * @param {Object} view
     * @param {String} level
     * @return {void}
     */
    var setDifficulty = function(view, level) {
        Array.prototype.forEach.call(view.root.querySelectorAll('.islide-diff'), function(button) {
            var active = button.dataset.difficulty === level;
            button.classList.toggle('islide-diff-active', active);
            button.setAttribute('aria-checked', active ? 'true' : 'false');
        });
    };

    /**
     * The difficulty currently selected.
     *
     * @param {Object} view
     * @return {String}
     */
    var currentDifficulty = function(view) {
        var active = view.root.querySelector('.islide-diff-active');
        return active ? active.dataset.difficulty : 'easy';
    };

    /**
     * Select a question type.
     *
     * @param {Object} view
     * @param {String} qtype
     * @param {Boolean} resetFields clear the form when the type really changed
     * @return {void}
     */
    var setType = function(view, qtype, resetFields) {
        Array.prototype.forEach.call(view.root.querySelectorAll('.islide-type'), function(button) {
            var active = button.dataset.qtype === qtype;
            button.classList.toggle('islide-type-active', active);
            button.setAttribute('aria-checked', active ? 'true' : 'false');
        });

        view.root.dataset.qtype = qtype;

        var form = Util.region(view.root, 'interactionform');
        Util.toggle(form, qtype !== 'none');

        if (resetFields) {
            setField(view, 'questiontext', '');
            setField(view, 'timerseconds', 0);
            setCheck(view, 'hasanswer', qtype === 'multichoice' || qtype === 'fillblank' ? 1 : 0);
            setCheck(view, 'allowmultiple', 0);
            // A word cloud growing on the projector, or answers appearing on the
            // wall, is the whole point of those two. A choice tally or a list of
            // typed answers to a question that has a right answer just invites
            // copying, so those start off.
            setCheck(view, 'showliveresult',
                (qtype === 'wordcloud' || qtype === 'openended') ? 1 : 0);
            setCheck(view, 'showleaderboard', 1);
            setCheck(view, 'allowretry', 0);
            setField(view, 'maxentries', 3);
            // A word is thirty characters; a written answer needs room to breathe.
            setField(view, 'maxwordlength', qtype === 'openended' ? 300 : 30);
            setField(view, 'points', 1);
            setDifficulty(view, 'easy');

            renderOptions(view, qtype === 'multichoice'
                ? [{optiontext: '', iscorrect: 1}, {optiontext: '', iscorrect: 0}]
                : []);
            renderBlanks(view, qtype === 'fillblank'
                ? [{label: '', answers: [], points: view.defaults.easy, difficulty: 'easy'}]
                : []);
        }

        applyVisibility(view);
    };

    /**
     * Show only the controls the current type and answer mode need.
     *
     * @param {Object} view
     * @return {void}
     */
    var applyVisibility = function(view) {
        var qtype = view.root.dataset.qtype;
        var hasAnswer = !!(field(view, 'hasanswer') && field(view, 'hasanswer').checked);

        var participation = qtype === 'wordcloud' || qtype === 'openended';
        // Two controls, one question: a dropdown is a choice question answered
        // from a select, so it wants everything multiple choice wants except the
        // "more than one" switch a select cannot offer.
        var haschoices = qtype === 'multichoice' || qtype === 'dropdown';
        // A video is opened, watched and closed. Nothing is asked and nothing
        // is scored, so every field about answering is beside the point.
        var passive = qtype === 'video';

        Util.toggle(Util.region(view.root, 'videofield'), passive);
        Util.toggle(Util.region(view.root, 'participationfields'), participation);
        Util.toggle(Util.region(view.root, 'maxentriesfield'), qtype === 'wordcloud');
        Util.toggle(Util.region(view.root, 'answertoggle'), !participation && !passive && qtype !== 'none');

        var lengthlabel = Util.region(view.root, 'maxlengthlabel');
        if (lengthlabel) {
            lengthlabel.textContent = qtype === 'openended'
                ? view.strings.maxanswerlength
                : view.strings.maxwordlength;
        }
        Util.toggle(Util.region(view.root, 'allowmultiplewrap'), qtype === 'multichoice');
        // Fill in the blanks always keeps typed answers off the projector until
        // collecting stops, so offering the switch there would promise nothing.
        Util.toggle(Util.region(view.root, 'liveresultwrap'),
            qtype === 'wordcloud' || haschoices || qtype === 'openended');
        Util.toggle(Util.region(view.root, 'optionsblock'), qtype === 'multichoice');
        Util.toggle(Util.region(view.root, 'blanksblock'), qtype === 'fillblank' || qtype === 'dropdown');

        // The same block, two jobs: a list of blanks to type into, or a list of
        // places in the sentence where a select stands.
        var blankslabel = Util.region(view.root, 'blankslabel');
        if (blankslabel) {
            blankslabel.textContent = qtype === 'dropdown'
                ? view.strings.positions
                : view.strings.blanks;
        }
        // No Add position button: a position exists because the sentence has a
        // gap for it, so a button that made one with nowhere to stand would only
        // be a way to get the two out of step.
        Util.actions(view.root, 'addblank').forEach(function(button) {
            button.hidden = qtype === 'dropdown';
        });
        var gapshint = Util.region(view.root, 'gapshint');
        Util.toggle(gapshint, qtype === 'dropdown');

        // Fill in the blank scores each blank separately, so the question level
        // difficulty picker would only be misleading there.
        Util.toggle(Util.region(view.root, 'difficultyrow'), qtype === 'multichoice' && hasAnswer);

        Array.prototype.forEach.call(view.root.querySelectorAll('.islide-option-correct'),
            function(node) {
                node.hidden = !hasAnswer;
            });
        Array.prototype.forEach.call(view.root.querySelectorAll('.islide-blank-scoring'),
            function(node) {
                node.hidden = !hasAnswer;
            });
    };

    /**
     * Draw the multiple choice option rows.
     *
     * @param {Object} view
     * @param {Array} options
     * @return {void}
     */
    var renderOptions = function(view, options) {
        var container = Util.region(view.root, 'options');
        if (!container) {
            return;
        }

        var letters = 'ABCDEFGHIJ';
        var html = '';
        options.forEach(function(option, index) {
            html += '<div class="islide-option-row">' +
                '<span class="islide-option-letter">' + (letters.charAt(index) || (index + 1)) + '</span>' +
                '<input type="text" class="islide-option-text" maxlength="500"' +
                    ' value="' + Util.escape(option.optiontext || '') + '"' +
                    ' placeholder="' + Util.escape(view.strings.optionplaceholder) + '">' +
                '<label class="islide-option-correct" title="' + Util.escape(view.strings.correct) + '">' +
                    '<span class="islide-check-native">' +
                        '<input type="checkbox" class="islide-option-flag"' +
                        (Number(option.iscorrect) ? ' checked' : '') + '>' +
                    '</span>' +
                    '<span aria-hidden="true">&#10003;</span>' +
                    '<span class="islide-sr-only">' + Util.escape(view.strings.correct) + '</span>' +
                '</label>' +
                '<button type="button" class="islide-icon-btn islide-option-remove"' +
                    ' data-action="removeoption" title="' + Util.escape(view.strings.remove) + '">' +
                    '<span aria-hidden="true">&#10005;</span>' +
                '</button>' +
                '</div>';
        });
        container.innerHTML = html;
    };

    /**
     * Draw the fill in the blank rows.
     *
     * @param {Object} view
     * @param {Array} blanks
     * @return {void}
     */
    var renderBlanks = function(view, blanks) {
        var container = Util.region(view.root, 'blanks');
        if (!container) {
            return;
        }

        var isdropdown = view.root.dataset.qtype === 'dropdown';
        var html = '';
        blanks.forEach(function(blank, index) {
            var answers = (blank.answers || []).join('\n');
            html += '<div class="islide-blank-row">' +
                '<div class="islide-blank-head">' +
                    '<span class="islide-blank-index">' + (index + 1) + '</span>' +
                    '<input type="text" class="islide-blank-label" maxlength="255"' +
                        ' value="' + Util.escape(blank.label || '') + '"' +
                        ' placeholder="' + Util.escape(view.strings.blanklabelplaceholder) + '">' +
                    '<button type="button" class="islide-icon-btn" data-action="removeblank"' +
                        ' title="' + Util.escape(view.strings.remove) + '">' +
                        '<span aria-hidden="true">&#10005;</span>' +
                    '</button>' +
                '</div>' +
                '<div class="islide-blank-scoring">' +
                    '<label class="islide-field islide-field-half' +
                            (isdropdown ? ' islide-hidden-field' : '') + '">' +
                        '<span>' + Util.escape(view.strings.acceptedanswers) + '</span>' +
                        '<textarea class="islide-blank-answers" rows="2"' +
                            ' placeholder="' + Util.escape(view.strings.answersplaceholder) + '">' +
                            Util.escape(answers) +
                        '</textarea>' +
                    '</label>' +
                    '<label class="islide-field islide-field-half">' +
                        '<span>' + Util.escape(view.strings.difficulty) + '</span>' +
                        '<select class="islide-blank-difficulty">' +
                            difficultyOption(view, 'easy', blank.difficulty) +
                            difficultyOption(view, 'medium', blank.difficulty) +
                            difficultyOption(view, 'hard', blank.difficulty) +
                        '</select>' +
                    '</label>' +
                '</div>' +
                (isdropdown ? positionOptions(view, blank) : '') +
                '</div>';
        });
        container.innerHTML = html;
    };

    /**
     * The choice list of one dropdown position.
     *
     * The same row as a multiple choice option — text, a correct flag, a remove
     * button — so the two look and behave alike; only where they live differs.
     *
     * @param {Object} view
     * @param {Object} blank
     * @return {String}
     */
    var positionOptions = function(view, blank) {
        var letters = 'ABCDEFGHIJ';
        var options = blank.options && blank.options.length
            ? blank.options
            : [{optiontext: '', iscorrect: 0}, {optiontext: '', iscorrect: 0}];

        var html = '<div class="islide-position-options">' +
            '<span class="islide-field-label">' + Util.escape(view.strings.options) + '</span>' +
            '<div class="islide-options">';

        options.forEach(function(option, index) {
            html += '<div class="islide-option-row islide-position-option">' +
                '<span class="islide-option-letter">' + (letters.charAt(index) || (index + 1)) + '</span>' +
                '<input type="text" class="islide-option-text" maxlength="500"' +
                    ' value="' + Util.escape(option.optiontext || '') + '"' +
                    ' placeholder="' + Util.escape(view.strings.optionplaceholder) + '">' +
                '<label class="islide-option-correct" title="' + Util.escape(view.strings.correct) + '">' +
                    '<span class="islide-check-native">' +
                        '<input type="checkbox" class="islide-option-flag"' +
                        (Number(option.iscorrect) ? ' checked' : '') + '>' +
                    '</span>' +
                    '<span aria-hidden="true">&#10003;</span>' +
                    '<span class="islide-sr-only">' + Util.escape(view.strings.correct) + '</span>' +
                '</label>' +
                '<button type="button" class="islide-icon-btn islide-option-remove"' +
                    ' data-action="removeoption" title="' + Util.escape(view.strings.remove) + '">' +
                    '<span aria-hidden="true">&#10005;</span>' +
                '</button>' +
                '</div>';
        });

        html += '</div>' +
            '<button type="button" class="btn btn-sm btn-outline-primary" data-action="addpositionoption">' +
            Util.escape(view.strings.addoption) + '</button>' +
            '</div>';

        return html;
    };

    /**
     * One option of the per blank difficulty select.
     *
     * @param {Object} view
     * @param {String} level
     * @param {String} selected
     * @return {String}
     */
    var difficultyOption = function(view, level, selected) {
        var label = view.strings['difficulty' + level] || level;
        return '<option value="' + level + '"' + (selected === level ? ' selected' : '') + '>' +
            Util.escape(label) + ' (' + view.defaults[level] + ' ★)</option>';
    };

    /**
     * Slide rename and delete.
     *
     * @param {Object} view
     * @return {void}
     */
    var bindSlideTools = function(view) {
        var title = Util.region(view.root, 'slidetitle');
        if (title) {
            title.addEventListener('change', function() {
                if (!view.selectedId) {
                    return;
                }
                var value = title.value;
                Api.updateSlide(view.config.cmid, view.selectedId, value, []).then(function() {
                    // Update the local model only. Reloading the deck here used
                    // to redraw the inspector and throw away an interaction the
                    // teacher had filled in but not yet saved.
                    view.slides.forEach(function(slide) {
                        if (slide.id === view.selectedId) {
                            slide.title = value;
                        }
                    });
                    Util.toast(view.root, view.strings.saved, 'success');
                    return null;
                }).catch(Notification.exception);
            });
        }

        Util.actions(view.root, 'deleteslide').forEach(function(button) {
            button.addEventListener('click', function() {
                if (!view.selectedId) {
                    return;
                }
                Notification.saveCancelPromise(
                    view.strings.confirmdeleteslide,
                    view.strings.confirmdeleteslide_desc,
                    view.strings.deleteslide
                ).then(function() {
                    return Api.deleteSlide(view.config.cmid, view.selectedId);
                }).then(function() {
                    view.selectedId = 0;
                    return reload(view);
                }).catch(function() {
                    return null;
                });
            });
        });
    };

    /**
     * Everything inside the interaction inspector.
     *
     * @param {Object} view
     * @return {void}
     */
    var bindInspector = function(view) {
        var picker = Util.region(view.root, 'typepicker');
        if (picker) {
            picker.addEventListener('click', function(event) {
                var button = event.target.closest('.islide-type');
                if (!button || button.disabled) {
                    return;
                }
                var qtype = button.dataset.qtype;
                if (view.root.dataset.qtype === qtype) {
                    return;
                }

                // Choosing "no interaction" on a slide that already has one is a
                // request to delete it, so say so rather than silently hiding it.
                var slide = view.slides.filter(function(item) {
                    return item.id === view.selectedId;
                })[0];

                if (qtype === 'none' && slide && slide.interaction) {
                    Notification.saveCancelPromise(
                        view.strings.confirmdeleteinteraction,
                        view.strings.confirmdeleteinteraction_desc,
                        view.strings.removeinteraction
                    ).then(function() {
                        return Api.deleteInteraction(view.config.cmid, view.selectedId);
                    }).then(function() {
                        return reload(view, view.selectedId);
                    }).catch(function() {
                        return null;
                    });
                    return;
                }

                setType(view, qtype, true);
            });
        }

        var difficulty = Util.region(view.root, 'difficultyrow');
        if (difficulty) {
            difficulty.addEventListener('click', function(event) {
                var button = event.target.closest('.islide-diff');
                if (button && !button.disabled) {
                    setDifficulty(view, button.dataset.difficulty);
                }
            });
        }

        var form = Util.region(view.root, 'interactionform');
        if (form) {
            form.addEventListener('input', function(event) {
                view.dirty = true;
                clearStatus(view);
                if (event.target.matches('[data-field="questiontext"]')) {
                    syncPositions(view);
                }
            });

            form.addEventListener('change', function(event) {
                view.dirty = true;
                clearStatus(view);
                if (event.target.matches('[data-field="hasanswer"]')) {
                    applyVisibility(view);
                }
            });

            form.addEventListener('click', function(event) {
                var remove = event.target.closest('[data-action="removeoption"]');
                if (remove) {
                    var optionRow = remove.closest('.islide-option-row');
                    if (optionRow) {
                        optionRow.remove();
                        relabelOptions(view);
                    }
                    return;
                }

                var addToPosition = event.target.closest('[data-action="addpositionoption"]');
                if (addToPosition) {
                    var host = addToPosition.closest('.islide-blank-row');
                    var list = host && host.querySelector('.islide-options');
                    if (list && list.querySelectorAll('.islide-option-row').length < 10) {
                        renderBlanks(view, collectBlanksWithExtra(view, host));
                        applyVisibility(view);
                    }
                    return;
                }

                var removeBlank = event.target.closest('[data-action="removeblank"]');
                if (removeBlank) {
                    var blankRow = removeBlank.closest('.islide-blank-row');
                    if (blankRow) {
                        blankRow.remove();
                        relabelBlanks(view);
                    }
                }
            });
        }

        Util.actions(view.root, 'addoption').forEach(function(button) {
            button.addEventListener('click', function() {
                var options = collectOptions(view);
                if (options.length >= 10) {
                    return;
                }
                options.push({optiontext: '', iscorrect: 0});
                renderOptions(view, options);
                applyVisibility(view);
            });
        });

        Util.actions(view.root, 'addblank').forEach(function(button) {
            button.addEventListener('click', function() {
                var blanks = collectBlanks(view);
                if (blanks.length >= 10) {
                    return;
                }
                blanks.push({
                    label: '',
                    answers: [],
                    difficulty: 'easy',
                    options: [{optiontext: '', iscorrect: 0}, {optiontext: '', iscorrect: 0}]
                });
                renderBlanks(view, blanks);
                applyVisibility(view);
            });
        });

        Util.actions(view.root, 'saveinteraction').forEach(function(button) {
            button.addEventListener('click', function() {
                save(view);
            });
        });

        Util.actions(view.root, 'resetinteraction').forEach(function(button) {
            button.addEventListener('click', function() {
                Notification.saveCancelPromise(
                    view.strings.confirmclearresponses,
                    view.strings.confirmclearresponses_desc,
                    view.strings.clearresponses
                ).then(function() {
                    return Api.resetInteraction(view.config.cmid, view.selectedId);
                }).then(function(result) {
                    Util.toast(view.root,
                        view.strings.responsescleared.replace('{$a}', result.removed), 'success');
                    return reload(view, view.selectedId);
                }).catch(function(error) {
                    if (error && error.message) {
                        Util.toast(view.root, error.message, 'error');
                    }
                    return null;
                });
            });
        });

        Util.actions(view.root, 'deleteinteraction').forEach(function(button) {
            button.addEventListener('click', function() {
                Notification.saveCancelPromise(
                    view.strings.confirmdeleteinteraction,
                    view.strings.confirmdeleteinteraction_desc,
                    view.strings.removeinteraction
                ).then(function() {
                    return Api.deleteInteraction(view.config.cmid, view.selectedId);
                }).then(function() {
                    return reload(view, view.selectedId);
                }).catch(function() {
                    return null;
                });
            });
        });
    };

    /**
     * Renumber the option letters after a removal.
     *
     * @param {Object} view
     * @return {void}
     */
    var relabelOptions = function(view) {
        var letters = 'ABCDEFGHIJ';
        Array.prototype.forEach.call(view.root.querySelectorAll('.islide-option-row'),
            function(row, index) {
                var letter = row.querySelector('.islide-option-letter');
                if (letter) {
                    letter.textContent = letters.charAt(index) || (index + 1);
                }
            });
    };

    /**
     * Renumber the blanks after a removal.
     *
     * @param {Object} view
     * @return {void}
     */
    var relabelBlanks = function(view) {
        Array.prototype.forEach.call(view.root.querySelectorAll('.islide-blank-row'),
            function(row, index) {
                var badge = row.querySelector('.islide-blank-index');
                if (badge) {
                    badge.textContent = index + 1;
                }
            });
    };

    /**
     * Read the option rows back out of the DOM.
     *
     * @param {Object} view
     * @return {Array}
     */
    var collectOptions = function(view) {
        // Scoped to the question's own option block. A dropdown position draws
        // the same rows inside itself, and those belong to the position.
        var container = Util.region(view.root, 'options');
        if (!container) {
            return [];
        }

        return Array.prototype.map.call(container.querySelectorAll('.islide-option-row'),
            function(row) {
                return {
                    optiontext: row.querySelector('.islide-option-text').value,
                    iscorrect: row.querySelector('.islide-option-flag').checked ? 1 : 0
                };
            });
    };

    /**
     * Read the blank rows back out of the DOM.
     *
     * @param {Object} view
     * @return {Array}
     */
    /**
     * Every position as it stands, with one blank choice added to one of them.
     *
     * Read back and redrawn rather than appended in place, so what is on screen
     * is always what the next save would send.
     *
     * @param {Object} view
     * @param {Element} host the position row that asked for another choice
     * @return {Array}
     */
    var collectBlanksWithExtra = function(view, host) {
        var rows = Array.prototype.slice.call(view.root.querySelectorAll('.islide-blank-row'));
        var index = rows.indexOf(host);
        var blanks = collectBlanks(view);

        if (index >= 0 && blanks[index]) {
            blanks[index].options = (blanks[index].options || []).concat([{optiontext: '', iscorrect: 0}]);
        }

        return blanks;
    };

    var collectBlanks = function(view) {
        return Array.prototype.map.call(view.root.querySelectorAll('.islide-blank-row'),
            function(row) {
                var answers = row.querySelector('.islide-blank-answers').value
                    .split('\n')
                    .map(function(line) {
                        return line.trim();
                    })
                    .filter(function(line) {
                        return line !== '';
                    });

                var options = Array.prototype.map.call(
                    row.querySelectorAll('.islide-position-option'),
                    function(optionrow) {
                        return {
                            optiontext: optionrow.querySelector('.islide-option-text').value,
                            iscorrect: optionrow.querySelector('.islide-option-flag').checked ? 1 : 0
                        };
                    }
                );

                return {
                    label: row.querySelector('.islide-blank-label').value,
                    answers: answers,
                    difficulty: row.querySelector('.islide-blank-difficulty').value,
                    options: options
                };
            });
    };

    /**
     * Build the payload and save it.
     *
     * @param {Object} view
     * @return {void}
     */
    /**
     * Keep the dropdown positions in step with the gaps in the sentence.
     *
     * The teacher writes the sentence and puts ___ where a select belongs; the
     * position cards follow. Only the count is touched, and only from the end,
     * so choices already typed into position 1 survive a gap being added after
     * them.
     *
     * @param {Object} view
     * @return {void}
     */
    var syncPositions = function(view) {
        if (view.root.dataset.qtype !== 'dropdown') {
            return;
        }

        var text = field(view, 'questiontext') ? field(view, 'questiontext').value : '';
        var gaps = (String(text).match(/_{3,}/g) || []).length;
        if (gaps < 1 || gaps > 10) {
            return;
        }

        var blanks = collectBlanks(view);
        var before = blanks.length;

        while (blanks.length < gaps) {
            blanks.push({
                label: '',
                answers: [],
                difficulty: 'easy',
                options: [{optiontext: '', iscorrect: 0}, {optiontext: '', iscorrect: 0}]
            });
        }

        // Shrinking only removes positions nobody has typed into. Deleting a
        // character in the sentence must not be able to throw away a list of
        // choices the teacher wrote, and a stray underscore removed mid-edit
        // would otherwise do exactly that. A position with content stays until
        // it is removed with its own button.
        while (blanks.length > gaps && isEmptyPosition(blanks[blanks.length - 1])) {
            blanks.pop();
        }

        if (blanks.length === before) {
            return;
        }

        renderBlanks(view, blanks);
        applyVisibility(view);
    };

    /**
     * Whether a dropdown position is still untouched.
     *
     * @param {Object} blank
     * @return {Boolean}
     */
    var isEmptyPosition = function(blank) {
        if (String(blank.label || '').trim() !== '') {
            return false;
        }

        return (blank.options || []).every(function(option) {
            return String(option.optiontext || '').trim() === '';
        });
    };

    /**
     * Drop the line under the Save button.
     *
     * It carries the last refusal, and it used to stay there while the teacher
     * fixed exactly what it complained about: they would tick the option it
     * asked for and still be looking at "mark at least one option as correct".
     * Any edit now clears it, so the sentence on screen is never about a state
     * that has already been corrected.
     *
     * @param {Object} view
     * @return {void}
     */
    var clearStatus = function(view) {
        var status = Util.region(view.root, 'savestatus');
        if (status && status.textContent !== '') {
            status.textContent = '';
            status.dataset.tone = '';
        }
    };

    var save = function(view) {
        var qtype = view.root.dataset.qtype;
        if (!view.selectedId || qtype === 'none') {
            return;
        }

        var status = Util.region(view.root, 'savestatus');
        if (status) {
            status.textContent = view.strings.saving;
            status.dataset.tone = 'info';
        }

        var hasAnswer = !!(field(view, 'hasanswer') && field(view, 'hasanswer').checked);

        var payload = {
            qtype: qtype,
            questiontext: field(view, 'questiontext').value,
            hasanswer: hasAnswer ? 1 : 0,
            difficulty: currentDifficulty(view),
            points: parseInt(field(view, 'points').value, 10) || 1,
            timerseconds: parseInt(field(view, 'timerseconds').value, 10) || 0,
            autoclose: 1,
            showliveresult: field(view, 'showliveresult').checked ? 1 : 0,
            showleaderboard: field(view, 'showleaderboard').checked ? 1 : 0,
            allowmultiple: field(view, 'allowmultiple').checked ? 1 : 0,
            shuffleoptions: 0,
            maxentries: parseInt(field(view, 'maxentries').value, 10) || 3,
            maxwordlength: parseInt(field(view, 'maxwordlength').value, 10) || 30,
            casesensitive: 0,
            videourl: field(view, 'videourl') ? field(view, 'videourl').value : '',
            allowretry: field(view, 'allowretry').checked ? 1 : 0,
            options: collectOptions(view),
            blanks: collectBlanks(view)
        };

        Api.saveInteraction(view.config.cmid, view.selectedId, payload).then(function() {
            view.dirty = false;
            if (status) {
                status.textContent = view.strings.saved;
                status.dataset.tone = 'success';
            }
            Util.toast(view.root, view.strings.saved, 'success');
            return reload(view, view.selectedId);
        }).catch(function(error) {
            if (status) {
                status.textContent = error.message || view.strings.savefailed;
                status.dataset.tone = 'error';
            }
        });
    };

    /**
     * Add one slide from a picture the teacher picks.
     *
     * Separate from the PDF wizard on purpose: importing a PDF replaces the
     * deck, while this appends a single slide and leaves everything else alone,
     * which is what a teacher wants when one page arrived as a screenshot.
     *
     * @param {Object} view
     * @return {void}
     */
    var bindAddSlide = function(view) {
        var input = Util.region(view.root, 'slideinput');
        if (!input) {
            return;
        }

        input.addEventListener('change', function() {
            var file = input.files && input.files[0];
            input.value = '';

            if (!file) {
                return;
            }
            if (!/^image\//.test(file.type)) {
                Util.toast(view.root, view.strings.notanimage, 'error');
                return;
            }

            var form = new FormData();
            form.append('cmid', view.config.cmid);
            form.append('sesskey', view.config.sesskey);
            form.append('action', 'addslide');
            // Right after whatever the teacher is looking at; with nothing
            // selected the server puts it at the end.
            form.append('after', view.selectedId || 0);
            form.append('image', file, file.name);

            Util.toast(view.root, view.strings.uploading, 'info');

            fetch(view.config.uploadurl, {
                method: 'POST',
                body: form,
                credentials: 'same-origin'
            }).then(function(response) {
                if (!response.ok) {
                    throw new Error('HTTP ' + response.status);
                }
                return response.json();
            }).then(function(payload) {
                if (payload.status !== 'ok') {
                    throw new Error(payload.message || payload.error || 'Upload failed');
                }
                // Land on the slide that was just added, so the teacher can put
                // a question on it without hunting for it in the strip.
                view.selectedId = payload.slideid;
                return reload(view);
            }).then(function() {
                Util.toast(view.root, view.strings.slideadded, 'success');
                return null;
            }).catch(function(error) {
                Util.toast(view.root, error.message || view.strings.uploadfailed, 'error');
            });
        });
    };

    /**
     * The PDF import wizard.
     *
     * @param {Object} view
     * @return {void}
     */
    var bindImport = function(view) {
        var input = Util.region(view.root, 'pdfinput');
        if (!input) {
            return;
        }

        input.addEventListener('change', function() {
            var file = input.files && input.files[0];
            input.value = '';

            if (!file) {
                return;
            }
            if (file.type !== 'application/pdf' && !/\.pdf$/i.test(file.name)) {
                Util.toast(view.root, view.strings.notapdf, 'error');
                return;
            }

            var confirmation = view.slides.length
                ? Notification.saveCancelPromise(
                    view.strings.confirmreplacedeck,
                    view.strings.confirmreplacedeck_desc,
                    view.strings.confirmreplacedeck
                )
                : Promise.resolve();

            confirmation.then(function() {
                return runImport(view, file);
            }).catch(function() {
                return null;
            });
        });
    };

    /**
     * Render and upload every page, showing progress.
     *
     * @param {Object} view
     * @param {File} file
     * @return {Promise}
     */
    var runImport = function(view, file) {
        var panel = Util.region(view.root, 'import');
        var status = Util.region(view.root, 'import-status');
        var bar = Util.region(view.root, 'import-bar');

        Util.toggle(panel, true);
        if (bar) {
            bar.style.width = '0%';
        }
        if (status) {
            status.textContent = file.name;
        }

        return PdfImport.run(view.config, file, {
            onProgress: function(done, total) {
                if (bar) {
                    bar.style.width = Math.round(done * 100 / total) + '%';
                }
                if (status) {
                    status.textContent = view.strings.importprogress
                        .replace('{$a->done}', done)
                        .replace('{$a->total}', total);
                }
            }
        }).then(function(total) {
            Util.toggle(panel, false);
            Util.toast(view.root, view.strings.importdone.replace('{$a}', total), 'success');
            view.selectedId = 0;
            return reload(view);
        }).catch(function(error) {
            Util.toggle(panel, false);
            var message = (error && error.message === 'pdfjsmissing')
                ? view.strings.pdfjsmissing
                : (view.strings.importfailed + ' ' + (error && error.message ? error.message : ''));
            Util.toast(view.root, message, 'error');
        });
    };

    return {init: init};
});
