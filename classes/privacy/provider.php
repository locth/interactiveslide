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

namespace mod_interactiveslide\privacy;

use context;
use context_module;
use core_privacy\local\metadata\collection;
use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\contextlist;
use core_privacy\local\request\helper;
use core_privacy\local\request\transform;
use core_privacy\local\request\userlist;
use core_privacy\local\request\writer;

/**
 * Privacy API implementation for mod_interactiveslide.
 *
 * @package    mod_interactiveslide
 * @copyright  2026 Interactive Slide contributors
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class provider implements
    \core_privacy\local\metadata\provider,
    \core_privacy\local\request\plugin\provider,
    \core_privacy\local\request\core_userlist_provider {

    /**
     * Describe the personal data this plugin stores.
     *
     * @param collection $collection
     * @return collection
     */
    public static function get_metadata(collection $collection): collection {
        $collection->add_database_table('interactiveslide_participant', [
            'userid' => 'privacy:metadata:participant:userid',
            'totalstars' => 'privacy:metadata:participant:totalstars',
            'correctcount' => 'privacy:metadata:participant:correctcount',
            'responsecount' => 'privacy:metadata:participant:responsecount',
            'timejoined' => 'privacy:metadata:participant:timejoined',
            'lastseen' => 'privacy:metadata:participant:lastseen',
        ], 'privacy:metadata:participant');

        $collection->add_database_table('interactiveslide_response', [
            'userid' => 'privacy:metadata:response:userid',
            'iscorrect' => 'privacy:metadata:response:iscorrect',
            'stars' => 'privacy:metadata:response:stars',
            'timetaken' => 'privacy:metadata:response:timetaken',
            'timecreated' => 'privacy:metadata:response:timecreated',
        ], 'privacy:metadata:response');

        $collection->add_database_table('interactiveslide_answer', [
            'answertext' => 'privacy:metadata:answer:answertext',
            'iscorrect' => 'privacy:metadata:answer:iscorrect',
            'stars' => 'privacy:metadata:answer:stars',
        ], 'privacy:metadata:answer');

        $collection->add_database_table('interactiveslide_session', [
            'createdby' => 'privacy:metadata:session:createdby',
            'name' => 'privacy:metadata:session:name',
            'timecreated' => 'privacy:metadata:session:timecreated',
        ], 'privacy:metadata:session');

        $collection->add_database_table('interactiveslide_award', [
            'userid' => 'privacy:metadata:award:userid',
            'stars' => 'privacy:metadata:award:stars',
            'reason' => 'privacy:metadata:award:reason',
            'awardedby' => 'privacy:metadata:award:awardedby',
            'timecreated' => 'privacy:metadata:award:timecreated',
        ], 'privacy:metadata:award');

        return $collection;
    }

    /**
     * Contexts in which a user has data.
     *
     * @param int $userid
     * @return contextlist
     */
    public static function get_contexts_for_userid(int $userid): contextlist {
        $contextlist = new contextlist();

        // Taking part in a session.
        $sql = "SELECT ctx.id
                  FROM {course_modules} cm
                  JOIN {modules} m ON m.id = cm.module AND m.name = 'interactiveslide'
                  JOIN {interactiveslide} i ON i.id = cm.instance
                  JOIN {context} ctx ON ctx.instanceid = cm.id AND ctx.contextlevel = :contextlevel
                  JOIN {interactiveslide_participant} p ON p.interactiveslideid = i.id
                 WHERE p.userid = :userid";

        $contextlist->add_from_sql($sql, [
            'contextlevel' => CONTEXT_MODULE,
            'userid' => $userid,
        ]);

        // Running a session is recorded against the presenter, and handing out a
        // star is recorded against whoever gave it. Both are personal data about
        // members of staff, so they have to be findable here as well.
        $staffsql = "SELECT ctx.id
                       FROM {course_modules} cm
                       JOIN {modules} m ON m.id = cm.module AND m.name = 'interactiveslide'
                       JOIN {interactiveslide} i ON i.id = cm.instance
                       JOIN {context} ctx ON ctx.instanceid = cm.id AND ctx.contextlevel = :contextlevel
                       JOIN {interactiveslide_session} s ON s.interactiveslideid = i.id
                  LEFT JOIN {interactiveslide_award} a ON a.sessionid = s.id AND a.awardedby = :awardedby
                      WHERE s.createdby = :createdby OR a.id IS NOT NULL";

        $contextlist->add_from_sql($staffsql, [
            'contextlevel' => CONTEXT_MODULE,
            'createdby' => $userid,
            'awardedby' => $userid,
        ]);

        return $contextlist;
    }

    /**
     * Users who have data in a given context.
     *
     * @param userlist $userlist
     * @return void
     */
    public static function get_users_in_context(userlist $userlist) {
        $context = $userlist->get_context();
        if (!$context instanceof context_module) {
            return;
        }

        $sql = "SELECT p.userid
                  FROM {course_modules} cm
                  JOIN {modules} m ON m.id = cm.module AND m.name = 'interactiveslide'
                  JOIN {interactiveslide} i ON i.id = cm.instance
                  JOIN {interactiveslide_participant} p ON p.interactiveslideid = i.id
                 WHERE cm.id = :cmid";

        $userlist->add_from_sql('userid', $sql, ['cmid' => $context->instanceid]);

        $presentersql = "SELECT s.createdby
                           FROM {course_modules} cm
                           JOIN {modules} m ON m.id = cm.module AND m.name = 'interactiveslide'
                           JOIN {interactiveslide} i ON i.id = cm.instance
                           JOIN {interactiveslide_session} s ON s.interactiveslideid = i.id
                          WHERE cm.id = :cmid AND s.createdby > 0";

        $userlist->add_from_sql('createdby', $presentersql, ['cmid' => $context->instanceid]);

        $awardersql = "SELECT a.awardedby
                         FROM {course_modules} cm
                         JOIN {modules} m ON m.id = cm.module AND m.name = 'interactiveslide'
                         JOIN {interactiveslide} i ON i.id = cm.instance
                         JOIN {interactiveslide_session} s ON s.interactiveslideid = i.id
                         JOIN {interactiveslide_award} a ON a.sessionid = s.id
                        WHERE cm.id = :cmid AND a.awardedby > 0";

        $userlist->add_from_sql('awardedby', $awardersql, ['cmid' => $context->instanceid]);
    }

    /**
     * Export the data of the approved contexts for one user.
     *
     * @param approved_contextlist $contextlist
     * @return void
     */
    public static function export_user_data(approved_contextlist $contextlist) {
        global $DB;

        $user = $contextlist->get_user();

        foreach ($contextlist->get_contexts() as $context) {
            if (!$context instanceof context_module) {
                continue;
            }

            $cm = get_coursemodule_from_id('interactiveslide', $context->instanceid);
            if (!$cm) {
                continue;
            }

            $sessions = $DB->get_records_sql(
                'SELECT p.id, p.sessionid, p.totalstars, p.correctcount, p.responsecount,
                        p.timejoined, p.lastseen, s.name
                   FROM {interactiveslide_participant} p
                   JOIN {interactiveslide_session} s ON s.id = p.sessionid
                  WHERE p.interactiveslideid = :instanceid AND p.userid = :userid
               ORDER BY s.timecreated ASC',
                ['instanceid' => $cm->instance, 'userid' => $user->id]
            );

            $presented = $DB->get_records_sql(
                'SELECT s.id, s.name, s.timecreated,
                        (SELECT COUNT(a.id) FROM {interactiveslide_award} a
                          WHERE a.sessionid = s.id AND a.awardedby = :awardedby) AS awardsgiven
                   FROM {interactiveslide_session} s
                  WHERE s.interactiveslideid = :instanceid
                        AND (s.createdby = :createdby OR EXISTS (
                            SELECT 1 FROM {interactiveslide_award} a2
                             WHERE a2.sessionid = s.id AND a2.awardedby = :awardedby2))
               ORDER BY s.timecreated ASC',
                [
                    'instanceid' => $cm->instance,
                    'createdby' => $user->id,
                    'awardedby' => $user->id,
                    'awardedby2' => $user->id,
                ]
            );

            if (!$sessions && !$presented) {
                continue;
            }

            writer::with_context($context)->export_data([], helper::get_context_data($context, $user));
            helper::export_context_files($context, $user);

            if ($presented) {
                $ran = [];
                foreach ($presented as $session) {
                    $ran[] = (object)[
                        'session' => $session->name,
                        'started' => transform::datetime($session->timecreated),
                        'starsawarded' => (int)$session->awardsgiven,
                    ];
                }

                writer::with_context($context)->export_data(
                    [get_string('privacy:presenterpath', 'mod_interactiveslide')],
                    (object)['sessions' => $ran]
                );
            }

            foreach ($sessions as $participant) {
                $answers = $DB->get_records_sql(
                    'SELECT a.id, a.answertext, a.iscorrect, a.stars, r.timecreated, r.roundid
                       FROM {interactiveslide_answer} a
                       JOIN {interactiveslide_response} r ON r.id = a.responseid
                      WHERE r.sessionid = :sessionid AND r.userid = :userid
                   ORDER BY r.timecreated ASC, a.id ASC',
                    ['sessionid' => $participant->sessionid, 'userid' => $user->id]
                );

                $exportanswers = [];
                foreach ($answers as $answer) {
                    $exportanswers[] = (object)[
                        'answer' => $answer->answertext,
                        'iscorrect' => (int)$answer->iscorrect,
                        'stars' => (int)$answer->stars,
                        'submitted' => transform::datetime($answer->timecreated),
                    ];
                }

                writer::with_context($context)->export_data(
                    [get_string('privacy:sessionpath', 'mod_interactiveslide', $participant->name)],
                    (object)[
                        'stars' => (int)$participant->totalstars,
                        'correctanswers' => (int)$participant->correctcount,
                        'answersgiven' => (int)$participant->responsecount,
                        'joined' => transform::datetime($participant->timejoined),
                        'answers' => $exportanswers,
                    ]
                );
            }
        }
    }

    /**
     * Delete every user's data in a context.
     *
     * @param context $context
     * @return void
     */
    public static function delete_data_for_all_users_in_context(context $context) {
        if (!$context instanceof context_module) {
            return;
        }

        $cm = get_coursemodule_from_id('interactiveslide', $context->instanceid);
        if (!$cm) {
            return;
        }

        \mod_interactiveslide\local\session_manager::delete_all_sessions((int)$cm->instance);
    }

    /**
     * Delete one user's data across the approved contexts.
     *
     * @param approved_contextlist $contextlist
     * @return void
     */
    public static function delete_data_for_user(approved_contextlist $contextlist) {
        $userid = (int)$contextlist->get_user()->id;

        foreach ($contextlist->get_contexts() as $context) {
            if (!$context instanceof context_module) {
                continue;
            }
            $cm = get_coursemodule_from_id('interactiveslide', $context->instanceid);
            if ($cm) {
                self::delete_users_in_instance((int)$cm->instance, [$userid]);
            }
        }
    }

    /**
     * Delete the data of a set of users in one context.
     *
     * @param approved_userlist $userlist
     * @return void
     */
    public static function delete_data_for_users(approved_userlist $userlist) {
        $context = $userlist->get_context();
        if (!$context instanceof context_module) {
            return;
        }

        $cm = get_coursemodule_from_id('interactiveslide', $context->instanceid);
        if ($cm) {
            self::delete_users_in_instance((int)$cm->instance, $userlist->get_userids());
        }
    }

    /**
     * Remove every trace of the given users from one activity instance.
     *
     * @param int $instanceid
     * @param int[] $userids
     * @return void
     */
    private static function delete_users_in_instance(int $instanceid, array $userids): void {
        global $DB;

        if (!$userids) {
            return;
        }

        [$usersql, $userparams] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED, 'u');

        $sessionids = $DB->get_fieldset_select('interactiveslide_session', 'id',
            'interactiveslideid = ?', [$instanceid]);
        if (!$sessionids) {
            return;
        }

        [$sesssql, $sessparams] = $DB->get_in_or_equal($sessionids, SQL_PARAMS_NAMED, 's');

        $responseids = $DB->get_fieldset_select('interactiveslide_response', 'id',
            "sessionid $sesssql AND userid $usersql", array_merge($sessparams, $userparams));

        if ($responseids) {
            [$ressql, $resparams] = $DB->get_in_or_equal($responseids, SQL_PARAMS_NAMED, 'r');
            $DB->delete_records_select('interactiveslide_answer', "responseid $ressql", $resparams);
            $DB->delete_records_select('interactiveslide_response', "id $ressql", $resparams);
        }

        $DB->delete_records_select('interactiveslide_award',
            "sessionid $sesssql AND userid $usersql", array_merge($sessparams, $userparams));
        $DB->delete_records_select('interactiveslide_participant',
            "sessionid $sesssql AND userid $usersql", array_merge($sessparams, $userparams));

        // A star given to somebody else belongs to whoever received it, and the
        // record of a session belongs to the room. Neither may be deleted along
        // with the member of staff, so only the reference back to them goes.
        $DB->set_field_select('interactiveslide_award', 'awardedby', 0,
            "sessionid $sesssql AND awardedby $usersql", array_merge($sessparams, $userparams));
        $DB->set_field_select('interactiveslide_session', 'createdby', 0,
            "id $sesssql AND createdby $usersql", array_merge($sessparams, $userparams));
    }
}
