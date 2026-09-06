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

/**
 * Module API for mod_interactiveslide.
 *
 * @package    mod_interactiveslide
 * @copyright  2026 Interactive Slide contributors
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

/**
 * Declare which optional Moodle features this module supports.
 *
 * @param string $feature FEATURE_xx constant
 * @return mixed true/false for boolean features, a string for MOD_ARCHETYPE etc, null when unknown
 */
function interactiveslide_supports($feature) {
    switch ($feature) {
        case FEATURE_MOD_INTRO:
        case FEATURE_SHOW_DESCRIPTION:
        case FEATURE_BACKUP_MOODLE2:
        case FEATURE_GRADE_HAS_GRADE:
        case FEATURE_COMPLETION_TRACKS_VIEWS:
        case FEATURE_GROUPS:
        case FEATURE_GROUPINGS:
            return true;
        case FEATURE_GRADE_OUTCOMES:
        case FEATURE_RATE:
            return false;
        case FEATURE_MOD_PURPOSE:
            return MOD_PURPOSE_COLLABORATION;
        default:
            return null;
    }
}

/**
 * Create a new activity instance.
 *
 * @param stdClass $data form data from mod_form
 * @param mod_interactiveslide_mod_form|null $mform
 * @return int the new instance id
 */
function interactiveslide_add_instance($data, $mform = null) {
    global $DB;

    $data->timecreated = time();
    $data->timemodified = $data->timecreated;
    $data->id = $DB->insert_record('interactiveslide', $data);

    interactiveslide_grade_item_update($data);

    return $data->id;
}

/**
 * Update an existing activity instance.
 *
 * @param stdClass $data form data from mod_form
 * @param mod_interactiveslide_mod_form|null $mform
 * @return bool
 */
function interactiveslide_update_instance($data, $mform = null) {
    global $DB;

    $data->id = $data->instance;
    $data->timemodified = time();
    $DB->update_record('interactiveslide', $data);

    interactiveslide_grade_item_update($data);
    interactiveslide_update_grades($data);

    return true;
}

/**
 * Delete an activity instance and everything that hangs off it.
 *
 * @param int $id instance id
 * @return bool
 */
function interactiveslide_delete_instance($id) {
    global $DB;

    $instance = $DB->get_record('interactiveslide', ['id' => $id]);
    if (!$instance) {
        return false;
    }

    $slideids = $DB->get_fieldset_select('interactiveslide_slide', 'id', 'interactiveslideid = ?', [$id]);
    if ($slideids) {
        [$slidesql, $slideparams] = $DB->get_in_or_equal($slideids);
        $interactionids = $DB->get_fieldset_select('interactiveslide_interaction', 'id', "slideid $slidesql", $slideparams);
        if ($interactionids) {
            [$intsql, $intparams] = $DB->get_in_or_equal($interactionids);
            $DB->delete_records_select('interactiveslide_option', "interactionid $intsql", $intparams);
            $DB->delete_records_select('interactiveslide_blank', "interactionid $intsql", $intparams);
            $DB->delete_records_select('interactiveslide_interaction', "id $intsql", $intparams);
        }
        $DB->delete_records_select('interactiveslide_slide', "id $slidesql", $slideparams);
    }

    $sessionids = $DB->get_fieldset_select('interactiveslide_session', 'id', 'interactiveslideid = ?', [$id]);
    if ($sessionids) {
        [$sesssql, $sessparams] = $DB->get_in_or_equal($sessionids);
        $roundids = $DB->get_fieldset_select('interactiveslide_round', 'id', "sessionid $sesssql", $sessparams);
        if ($roundids) {
            [$roundsql, $roundparams] = $DB->get_in_or_equal($roundids);
            $DB->delete_records_select('interactiveslide_answer', "roundid $roundsql", $roundparams);
            $DB->delete_records_select('interactiveslide_response', "roundid $roundsql", $roundparams);
            $DB->delete_records_select('interactiveslide_round', "id $roundsql", $roundparams);
        }
        $DB->delete_records_select('interactiveslide_award', "sessionid $sesssql", $sessparams);
        $DB->delete_records_select('interactiveslide_participant', "sessionid $sesssql", $sessparams);
        $DB->delete_records_select('interactiveslide_session', "id $sesssql", $sessparams);
    }

    $DB->delete_records('interactiveslide', ['id' => $id]);

    $cm = get_coursemodule_from_instance('interactiveslide', $id);
    if ($cm) {
        $fs = get_file_storage();
        $fs->delete_area_files(context_module::instance($cm->id)->id, 'mod_interactiveslide');
    }

    interactiveslide_grade_item_delete($instance);

    return true;
}

/**
 * Serve files from the plugin file areas.
 *
 * @param stdClass $course
 * @param stdClass $cm
 * @param context $context
 * @param string $filearea
 * @param array $args
 * @param bool $forcedownload
 * @param array $options
 * @return bool false when the file cannot be served
 */
function interactiveslide_pluginfile($course, $cm, $context, $filearea, $args, $forcedownload, array $options = []) {
    if ($context->contextlevel != CONTEXT_MODULE) {
        return false;
    }

    require_login($course, true, $cm);

    if (!has_capability('mod/interactiveslide:view', $context)) {
        return false;
    }

    $allowedareas = ['slideimage', 'sourcepdf', 'intro'];
    if (!in_array($filearea, $allowedareas, true)) {
        return false;
    }

    if ($filearea === 'sourcepdf' && !has_capability('mod/interactiveslide:manage', $context)) {
        return false;
    }

    // When the site has chosen to keep the deck off the students' devices, the
    // state document already withholds the URL. Withhold the bytes here too:
    // a URL is guessable from a slide id, so leaving this open would make the
    // setting a suggestion rather than a rule.
    if ($filearea === 'slideimage'
            && !\mod_interactiveslide\local\settings::student_slides_visible()
            && !has_capability('mod/interactiveslide:present', $context)
            && !has_capability('mod/interactiveslide:manage', $context)) {
        return false;
    }

    $itemid = (int)array_shift($args);
    $filename = array_pop($args);
    $filepath = $args ? '/' . implode('/', $args) . '/' : '/';

    $fs = get_file_storage();
    $file = $fs->get_file($context->id, 'mod_interactiveslide', $filearea, $itemid, $filepath, $filename);
    if (!$file || $file->is_directory()) {
        return false;
    }

    // Slide images never change once written, so they are safe to cache hard.
    $lifetime = ($filearea === 'slideimage') ? DAYSECS : 0;
    send_stored_file($file, $lifetime, 0, $forcedownload, $options);
}

/**
 * Create or update the gradebook item for an instance.
 *
 * @param stdClass $instance
 * @param mixed $grades single grade, array of grades, 'reset', or null
 * @return int GRADE_UPDATE_xx constant
 */
function interactiveslide_grade_item_update($instance, $grades = null) {
    global $CFG;
    require_once($CFG->libdir . '/gradelib.php');

    $params = ['itemname' => $instance->name];

    if (empty($instance->grade)) {
        $params['gradetype'] = GRADE_TYPE_NONE;
    } else {
        $params['gradetype'] = GRADE_TYPE_VALUE;
        $params['grademax'] = $instance->grade;
        $params['grademin'] = 0;
    }

    if ($grades === 'reset') {
        $params['reset'] = true;
        $grades = null;
    }

    return grade_update('mod/interactiveslide', $instance->course, 'mod', 'interactiveslide',
        $instance->id, 0, $grades, $params);
}

/**
 * Remove the gradebook item for an instance.
 *
 * @param stdClass $instance
 * @return int GRADE_UPDATE_xx constant
 */
function interactiveslide_grade_item_delete($instance) {
    global $CFG;
    require_once($CFG->libdir . '/gradelib.php');

    return grade_update('mod/interactiveslide', $instance->course, 'mod', 'interactiveslide',
        $instance->id, 0, null, ['deleted' => 1]);
}

/**
 * Push grades for one or all users into the gradebook.
 *
 * @param stdClass $instance
 * @param int $userid 0 for every participant
 * @param bool $nullifnone
 * @return void
 */
function interactiveslide_update_grades($instance, $userid = 0, $nullifnone = true) {
    global $CFG;
    require_once($CFG->libdir . '/gradelib.php');

    if (empty($instance->grade)) {
        interactiveslide_grade_item_update($instance);
        return;
    }

    $grades = \mod_interactiveslide\local\grading::calculate_grades($instance, $userid);

    if (empty($grades) && $userid && $nullifnone) {
        $grades = [$userid => (object)['userid' => $userid, 'rawgrade' => null]];
    }

    interactiveslide_grade_item_update($instance, $grades ?: null);
}

/**
 * Return the user's outline for the course participation report.
 *
 * @param stdClass $course
 * @param stdClass $user
 * @param stdClass $mod
 * @param stdClass $instance
 * @return stdClass|null
 */
function interactiveslide_user_outline($course, $user, $mod, $instance) {
    global $DB;

    $stars = $DB->get_field_sql(
        'SELECT SUM(totalstars + bonusstars)
           FROM {interactiveslide_participant}
          WHERE interactiveslideid = :id AND userid = :userid',
        ['id' => $instance->id, 'userid' => $user->id]
    );

    if ($stars === null || $stars === false) {
        return null;
    }

    $result = new stdClass();
    $result->info = get_string('starsx', 'mod_interactiveslide', (int)$stars);
    $result->time = (int)$DB->get_field_sql(
        'SELECT MAX(lastseen) FROM {interactiveslide_participant} WHERE interactiveslideid = ? AND userid = ?',
        [$instance->id, $user->id]
    );

    return $result;
}

/**
 * Add plugin nodes to the activity settings navigation.
 *
 * @param settings_navigation $settings
 * @param navigation_node $node
 * @return void
 */
function interactiveslide_extend_settings_navigation(settings_navigation $settings, navigation_node $node) {
    $cm = $settings->get_page()->cm;
    if (!$cm) {
        return;
    }
    $context = $cm->context;

    if (has_capability('mod/interactiveslide:manage', $context)) {
        $node->add(
            get_string('editslides', 'mod_interactiveslide'),
            new moodle_url('/mod/interactiveslide/edit.php', ['id' => $cm->id]),
            navigation_node::TYPE_SETTING,
            null,
            'interactiveslideedit',
            new pix_icon('t/edit', '')
        );
    }

    if (has_capability('mod/interactiveslide:viewreports', $context)) {
        $node->add(
            get_string('reports', 'mod_interactiveslide'),
            new moodle_url('/mod/interactiveslide/report.php', ['id' => $cm->id]),
            navigation_node::TYPE_SETTING,
            null,
            'interactiveslidereport',
            new pix_icon('i/report', '')
        );
    }
}

/**
 * Reset all session data when a course is reset.
 *
 * @param stdClass $data
 * @return array
 */
function interactiveslide_reset_userdata($data) {
    global $DB;

    $status = [];
    $componentstr = get_string('modulenameplural', 'mod_interactiveslide');

    if (!empty($data->reset_interactiveslide_sessions)) {
        foreach ($DB->get_records('interactiveslide', ['course' => $data->courseid]) as $instance) {
            \mod_interactiveslide\local\session_manager::delete_all_sessions((int)$instance->id);
        }

        // The stars are gone, so the grades computed from them have to go too.
        // Without this the gradebook keeps marks for work that no longer exists.
        // When the reset form is also clearing grades, core does it for us.
        if (empty($data->reset_gradebook_grades)) {
            interactiveslide_reset_gradebook($data->courseid);
        }

        $status[] = [
            'component' => $componentstr,
            'item' => get_string('resetsessions', 'mod_interactiveslide'),
            'error' => false,
        ];
    }

    return $status;
}

/**
 * Wipe the gradebook items of every deck in a course.
 *
 * Called by the course reset, and by core when the reset is clearing grades.
 *
 * @param int $courseid
 * @param string $type unused, kept for the core callback signature
 * @return void
 */
function interactiveslide_reset_gradebook($courseid, $type = '') {
    global $DB;

    foreach ($DB->get_records('interactiveslide', ['course' => $courseid]) as $instance) {
        interactiveslide_grade_item_update($instance, 'reset');
    }
}

/**
 * Add the reset options to the course reset form.
 *
 * @param MoodleQuickForm $mform
 * @return void
 */
function interactiveslide_reset_course_form_definition($mform) {
    $mform->addElement('header', 'interactiveslideheader', get_string('modulenameplural', 'mod_interactiveslide'));
    $mform->addElement('advcheckbox', 'reset_interactiveslide_sessions',
        get_string('resetsessions', 'mod_interactiveslide'));
}
