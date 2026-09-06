<?php
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

namespace mod_interactiveslide\external;

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_single_structure;
use core_external\external_value;
use mod_interactiveslide\local\interaction_manager;
use mod_interactiveslide\local\slide_manager;
use mod_interactiveslide\local\text_util;

/**
 * The full deck with every interaction, for the editor and the presenter filmstrip.
 *
 * @package    mod_interactiveslide
 * @copyright  2026 Interactive Slide contributors
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class get_deck extends external_api {

    /**
     * Parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'cmid' => new external_value(PARAM_INT, 'Course module id'),
        ]);
    }

    /**
     * Return every slide with its interaction.
     *
     * @param int $cmid
     * @return array
     */
    public static function execute(int $cmid): array {
        $params = self::validate_parameters(self::execute_parameters(), ['cmid' => $cmid]);

        $resolved = helper::resolve($params['cmid']);
        self::validate_context($resolved['context']);

        // Answer keys travel in this payload, so it is staff only. Presenters need
        // it to drive the filmstrip; editors need it to fill the editor.
        if (!has_capability('mod/interactiveslide:present', $resolved['context'])) {
            require_capability('mod/interactiveslide:manage', $resolved['context']);
        }

        $context = $resolved['context'];
        $slides = slide_manager::get_slides_with_interactions((int)$resolved['instance']->id);

        $out = [];
        $index = 0;
        foreach ($slides as $slide) {
            $entry = [
                'id' => (int)$slide->id,
                'index' => $index++,
                'title' => (string)$slide->title,
                'pageno' => (int)$slide->pageno,
                'imageurl' => slide_manager::get_image_url($context, $slide),
                'width' => (int)$slide->imagewidth,
                'height' => (int)$slide->imageheight,
                'interaction' => null,
                'locked' => 0,
            ];

            if ($slide->interaction) {
                $interaction = interaction_manager::attach_children($slide->interaction);
                $entry['interaction'] = self::export_interaction($interaction);
                $entry['locked'] = (int)interaction_manager::has_responses((int)$interaction->id);
            }

            $out[] = $entry;
        }

        return [
            'deck' => json_encode([
                'slides' => $out,
                'defaultpoints' => [
                    'easy' => interaction_manager::points_for_difficulty('easy'),
                    'medium' => interaction_manager::points_for_difficulty('medium'),
                    'hard' => interaction_manager::points_for_difficulty('hard'),
                ],
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ];
    }

    /**
     * Shape an interaction for the editor, answer keys included.
     *
     * @param \stdClass $interaction
     * @return array
     */
    private static function export_interaction(\stdClass $interaction): array {
        $options = [];
        foreach ($interaction->options ?? [] as $option) {
            $options[] = [
                'id' => (int)$option->id,
                'optiontext' => (string)$option->optiontext,
                'iscorrect' => (int)$option->iscorrect,
            ];
        }

        $blanks = [];
        foreach ($interaction->blanks ?? [] as $blank) {
            $blanks[] = [
                'id' => (int)$blank->id,
                'label' => (string)$blank->label,
                'answers' => $blank->answerlist ?? text_util::decode_answers($blank->answers),
                'points' => (int)$blank->points,
                'difficulty' => (string)$blank->difficulty,
                'casesensitive' => (int)$blank->casesensitive,
            ];
        }

        return [
            'id' => (int)$interaction->id,
            'qtype' => (string)$interaction->qtype,
            'questiontext' => (string)$interaction->questiontext,
            'hasanswer' => (int)$interaction->hasanswer,
            'difficulty' => (string)$interaction->difficulty,
            'points' => (int)$interaction->points,
            'timerseconds' => (int)$interaction->timerseconds,
            'autoclose' => (int)$interaction->autoclose,
            'showliveresult' => (int)$interaction->showliveresult,
            'showleaderboard' => (int)$interaction->showleaderboard,
            'allowmultiple' => (int)$interaction->allowmultiple,
            'shuffleoptions' => (int)$interaction->shuffleoptions,
            'maxentries' => (int)$interaction->maxentries,
            'maxwordlength' => (int)$interaction->maxwordlength,
            'casesensitive' => (int)$interaction->casesensitive,
            'allowretry' => (int)$interaction->allowretry,
            'maxstars' => interaction_manager::max_stars($interaction),
            'options' => $options,
            'blanks' => $blanks,
        ];
    }

    /**
     * Return description.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'deck' => new external_value(PARAM_RAW, 'The deck as JSON'),
        ]);
    }
}
