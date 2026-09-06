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

use context_module;
use core_external\external_single_structure;
use core_external\external_value;
use mod_interactiveslide\local\state;
use moodle_exception;
use stdClass;

/**
 * Shared plumbing for the plugin's external functions.
 *
 * Every call starts from a course module id, and almost every call answers with
 * the same live state document, so both are built once here.
 *
 * @package    mod_interactiveslide
 * @copyright  2026 Interactive Slide contributors
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class helper {

    /**
     * Resolve a course module id into everything the callers need.
     *
     * @param int $cmid
     * @return array{cm: \cm_info|stdClass, context: context_module, instance: stdClass, course: stdClass}
     */
    public static function resolve(int $cmid): array {
        global $DB;

        [$course, $cm] = get_course_and_cm_from_cmid($cmid, 'interactiveslide');
        $context = context_module::instance($cm->id);

        require_login($course, false, $cm);
        require_capability('mod/interactiveslide:view', $context);

        $instance = $DB->get_record('interactiveslide', ['id' => $cm->instance], '*', MUST_EXIST);

        return ['cm' => $cm, 'context' => $context, 'instance' => $instance, 'course' => $course];
    }

    /**
     * Resolve a course module id and require the presenting capability.
     *
     * @param int $cmid
     * @return array{cm: \cm_info|stdClass, context: context_module, instance: stdClass, course: stdClass}
     */
    public static function resolve_for_presenter(int $cmid): array {
        $resolved = self::resolve($cmid);
        require_capability('mod/interactiveslide:present', $resolved['context']);

        return $resolved;
    }

    /**
     * Resolve a course module id and require the editing capability.
     *
     * @param int $cmid
     * @return array{cm: \cm_info|stdClass, context: context_module, instance: stdClass, course: stdClass}
     */
    public static function resolve_for_editor(int $cmid): array {
        $resolved = self::resolve($cmid);
        require_capability('mod/interactiveslide:manage', $resolved['context']);

        return $resolved;
    }

    /**
     * The active session of a deck, or an error when there is none.
     *
     * @param stdClass $instance
     * @return stdClass
     * @throws moodle_exception
     */
    public static function require_active_session(stdClass $instance): stdClass {
        $session = \mod_interactiveslide\local\session_manager::get_active_session((int)$instance->id);
        if (!$session) {
            throw new moodle_exception('errornosession', 'mod_interactiveslide');
        }

        return $session;
    }

    /**
     * Build the state document for the calling user and encode it.
     *
     * @param stdClass $instance
     * @param context_module $context
     * @return array the external function return value
     */
    public static function state_response(stdClass $instance, context_module $context): array {
        global $USER;

        $ispresenter = has_capability('mod/interactiveslide:present', $context);
        $payload = state::build($instance, $context, (int)$USER->id, $ispresenter);

        return [
            'revision' => (int)$payload['revision'],
            'state' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ];
    }

    /**
     * The return structure shared by every state returning function.
     *
     * The state document is deeply nested and its shape depends on the question
     * type in play, so it travels as JSON rather than as a Moodle structure that
     * would have to declare every branch as optional.
     *
     * @return external_single_structure
     */
    public static function state_returns(): external_single_structure {
        return new external_single_structure([
            'revision' => new external_value(PARAM_INT, 'Session revision counter; unchanged means nothing happened'),
            'state' => new external_value(PARAM_RAW, 'The live state document, JSON encoded'),
        ]);
    }
}
