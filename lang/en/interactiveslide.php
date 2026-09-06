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
 * English strings for mod_interactiveslide.
 *
 * @package    mod_interactiveslide
 * @copyright  2026 Interactive Slide contributors
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$string['pluginname'] = 'Interactive Slide';
$string['modulename'] = 'Interactive Slide';
$string['modulenameplural'] = 'Interactive Slides';
$string['modulename_help'] = 'Import a PDF deck, attach a word cloud, a multiple choice question, a fill in the blank task or an open ended question to any slide, and run it live. Students follow the teacher\'s slide on their own device, answer while the round is open, and collect stars on a leaderboard.';
$string['pluginadministration'] = 'Interactive Slide administration';
$string['activityname'] = 'Activity name';
$string['noinstances'] = 'There are no Interactive Slide activities in this course.';

// Capabilities.
$string['interactiveslide:addinstance'] = 'Add a new Interactive Slide activity';
$string['interactiveslide:view'] = 'View an Interactive Slide activity';
$string['interactiveslide:manage'] = 'Import slides and edit interactions';
$string['interactiveslide:present'] = 'Run a live session';
$string['interactiveslide:submit'] = 'Answer interactions as a participant';
$string['interactiveslide:viewreports'] = 'View star reports';
$string['interactiveslide:awardstars'] = 'Award bonus stars by hand';

// Activity settings form.
$string['gamification'] = 'Stars and leaderboard';
$string['showleaderboard'] = 'Leaderboard visibility';
$string['showleaderboard_help'] = 'Who may see the ranking during a session. Teachers always see the full board.';
$string['leaderboardteacheronly'] = 'Teachers only';
$string['leaderboardeveryone'] = 'Everyone sees the full board';
$string['leaderboardownrank'] = 'Students see only their own position';
$string['leaderboardsize'] = 'Places shown to students';
$string['speedbonus'] = 'Speed bonus';
$string['speedbonus_help'] = 'Give extra stars for answering correctly with time to spare. This needs a timer on the question: an untimed question has nothing to measure against and never pays a bonus.';
$string['speedbonusmax'] = 'Maximum bonus stars';
$string['attendancestars'] = 'Stars for joining a session';
$string['attendancestars_help'] = 'Given once to every student who joins a session, before they answer anything. They accumulate into the student\'s total across sessions and appear in the reports and the gradebook. Set to 0 to reward answers only.';
$string['attendancestarsshort'] = 'Attendance';
$string['waitingforslides'] = 'No slide on screen';
$string['unsavedinteraction'] = 'Leave without saving?';
$string['unsavedinteraction_desc'] = 'This slide has interaction changes you have not saved yet. Moving to another slide will discard them.';
$string['discardchanges'] = 'Discard changes';
$string['anonymousresults'] = 'Anonymous leaderboard for students';
$string['anonymousresults_help'] = 'When enabled, students see the ranking without other people\'s names, but still see their own row. The presenter screen and the reports always show names. Aggregated results such as a word cloud never carry names either way.';
$string['allowlatejoin'] = 'Allow joining late';
$string['allowlatejoin_help'] = 'Students arriving after a session has started can still join and answer the rounds that are still open.';
$string['grademethod'] = 'Star to grade mapping';
$string['grademethod_help'] = 'How the stars a student collects become a gradebook grade. Grades are written when a session ends.';
$string['grademethodtotal'] = 'Total stars, against the sessions attended';
$string['grademethodbest'] = 'Best single session';
$string['grademethodlast'] = 'Most recent session';
$string['grademethodrelative'] = 'Total stars, relative to the top student';

// Site settings.
$string['settingsdifficulty'] = 'Default star values';
$string['settingsdifficulty_desc'] = 'What each difficulty level is worth when a teacher marks a question easy, medium or hard.';
$string['difficulty'] = 'Difficulty';
$string['difficultyeasy'] = 'Easy';
$string['difficultymedium'] = 'Medium';
$string['difficultyhard'] = 'Hard';
$string['points_easy_desc'] = 'Stars awarded for a correct answer to an easy question.';
$string['points_medium_desc'] = 'Stars awarded for a correct answer to a medium question.';
$string['points_hard_desc'] = 'Stars awarded for a correct answer to a hard question.';
$string['settingslive'] = 'Live sessions';
$string['settingslive_desc'] = 'How often the student and presenter screens ask the server what changed, and how large the imported slide images are.';
$string['pollinterval'] = 'Update interval';
$string['pollinterval_desc'] = 'A shorter interval feels more immediate but costs one request per participant per interval. Two seconds is comfortable for a class of up to about 150.';
$string['pollinterval1'] = '1 second';
$string['pollinterval2'] = '2 seconds (recommended)';
$string['pollinterval3'] = '3 seconds';
$string['pollinterval5'] = '5 seconds';
$string['renderscale'] = 'Slide image size';
$string['renderscale_desc'] = 'The longest edge, in pixels, of each page image produced when a PDF is imported. Larger images look sharper on a projector and take longer to upload.';
$string['imageformat'] = 'Slide image format';
$string['imageformat_desc'] = 'How each imported page is encoded. Every student downloads every slide they are shown, once, so this is the single largest bandwidth cost of a big session. A templated lecture slide with a gradient background measures roughly 1.7MB as PNG against 140KB at JPEG 85 and 90KB at JPEG 70. Keep PNG only for decks whose fine text must stay pin sharp.';
$string['imageformatjpeg85'] = 'JPEG, quality 85 (recommended)';
$string['imageformatjpeg70'] = 'JPEG, quality 70 (smallest)';
$string['imageformatpng'] = 'PNG, lossless (largest)';
$string['studentslides'] = 'Slides on student devices';
$string['studentslides_desc'] = 'With this off, students see the question and the answer form but not the slide picture, and read the slide from the projector instead. The image URL is never sent to their page, so nothing is downloaded. Choose it for large cohorts or weak lecture hall wifi, but not for decks whose questions rely on a diagram the student has to look at closely.';
$string['studentslidesshow'] = 'Show the slide on student devices';
$string['studentslideshide'] = 'Answers only, students read the projector';
$string['watchtheprojector'] = 'Look up at the screen';
$string['watchtheprojector_desc'] = 'Slides are shown on the projector for this activity. Your answer will appear here when your teacher opens a question.';
$string['maxpages'] = 'Maximum pages per import';
$string['maxpages_desc'] = 'Pages beyond this limit are ignored, which stops an accidental thousand page PDF from filling the file store.';

// Teacher hub.
$string['slides'] = 'Slides';
$string['interactions'] = 'Interactions';
$string['maxstarsperrun'] = 'Stars available per run';
$string['present'] = 'Present';
$string['resumepresenting'] = 'Back to the console';
$string['editslides'] = 'Edit slides';
$string['importpdf'] = 'Import PDF';
$string['reports'] = 'Reports';
$string['overallleaderboard'] = 'Leaderboard across all sessions';
$string['noslidesyet'] = 'No slides yet';
$string['noslidesyet_desc'] = 'Import a PDF and each page becomes a slide you can attach an interaction to.';
$string['sessionstatus'] = 'Session';
$string['sessionlive'] = 'Session running';
$string['sessionidle'] = 'Not running';
$string['joincode'] = 'Room code';
$string['participantsjoined'] = '{$a} joined';
$string['starsx'] = '{$a} stars';

// Editor.
$string['interaction'] = 'Interaction';
$string['nointeractionyet'] = 'This slide has no interaction yet.';
$string['removeinteraction'] = 'Remove interaction';
$string['clearresponses'] = 'Clear answers and unlock';
$string['confirmclearresponses'] = 'Clear the answers for this question?';
$string['confirmclearresponses_desc'] = 'Every answer students gave to this question is deleted and the stars it paid are taken back, which lets you edit the question again. The rest of the deck is untouched. This cannot be undone.';
$string['responsescleared'] = 'Cleared {$a} answers. The question can be edited again.';
$string['errorsessionrunning'] = 'End the running session before clearing answers.';
$string['interactionlockednote'] = 'Students have already answered this question, so it is locked. Clear those answers to edit it again; the rest of the deck is untouched.';
$string['questiontype'] = 'Question type';
$string['typenone'] = 'No interaction';
$string['typewordcloud'] = 'Word cloud';
$string['typemultichoice'] = 'Multiple choice';
$string['typefillblank'] = 'Fill in the blanks';
$string['typeopenended'] = 'Open ended';
$string['maxanswerlength'] = 'Characters per answer';
$string['openendedplaceholder'] = 'Write your answer';
$string['charactersleft'] = '{$a} characters left';
$string['reloadneeded'] = 'This question needs a newer version of this page. Reload to answer it.';
$string['reloadpage'] = 'Reload';
$string['questiontext'] = 'Question';
$string['questiontextplaceholder'] = 'What do you want to ask? Leave empty to use the text on the slide.';
$string['maxentries'] = 'Words per student';
$string['maxwordlength'] = 'Characters per word';
$string['participationstars'] = 'Stars for taking part';
$string['hasanswer'] = 'This question has a correct answer';
$string['allowmultiple'] = 'Allow more than one option to be selected';
$string['allowmultiple_hint'] = 'Use this for a poll where several choices are valid, including one with no correct answer at all. If the question does have an answer key, the student must select exactly the set you marked correct.';
$string['showliveresult'] = 'Show the tally on the projector while students answer';
$string['showleaderboardafter'] = 'Show the leaderboard after revealing';
$string['allowretry'] = 'Students may change their answer while the round is open';
$string['options'] = 'Options';
$string['addoption'] = 'Add option';
$string['optionplaceholder'] = 'Option text';
$string['correct'] = 'Correct';
$string['remove'] = 'Remove';
$string['blanks'] = 'Blanks';
$string['blank'] = 'Blank';
$string['addblank'] = 'Add blank';
$string['blanklabelplaceholder'] = 'What goes in this blank?';
$string['acceptedanswers'] = 'Accepted answers, one per line';
$string['answersplaceholder'] = 'One accepted answer per line';
$string['points'] = 'Stars';
$string['timerseconds'] = 'Timer (seconds)';
$string['timerseconds_help'] = '0 means no timer. The server closes the round when the time is up, so pausing the page does not buy extra time.';
$string['saveinteraction'] = 'Save interaction';
$string['slidetitle'] = 'Slide title';
$string['slidetitleplaceholder'] = 'Optional, used in reports';
$string['deleteslide'] = 'Delete slide';
$string['saving'] = 'Saving…';
$string['saved'] = 'Saved';
$string['savefailed'] = 'Could not save';
$string['importing'] = 'Importing';
$string['importprogress'] = 'Page {$a->done} of {$a->total}';
$string['importdone'] = 'Imported {$a} slides';
$string['importfailed'] = 'The import failed.';
$string['notapdf'] = 'Please choose a PDF file.';
$string['pdfjsmissing'] = 'The PDF reader could not be loaded. Download pdfjs-<version>-dist.zip from github.com/mozilla/pdf.js/releases and copy build/pdf.mjs and build/pdf.worker.mjs into mod/interactiveslide/thirdparty/pdfjs/. See the README in that folder if the files are there and it still fails.';
$string['confirmdeleteslide'] = 'Delete this slide?';
$string['confirmdeleteslide_desc'] = 'The slide, its interaction and every answer collected for it will be removed. This cannot be undone.';
$string['confirmdeleteinteraction'] = 'Remove this interaction?';
$string['confirmdeleteinteraction_desc'] = 'The question and every answer collected for it will be removed. This cannot be undone.';
$string['confirmreplacedeck'] = 'Replace the whole deck?';
$string['confirmreplacedeck_desc'] = 'Importing a PDF replaces every slide in this activity, together with the interactions and answers attached to them.';

// Presenter console.
$string['backtoactivity'] = 'Back to the activity';
$string['startsession'] = 'Start session';
$string['endsession'] = 'End session';
$string['sessionstarted'] = 'Session started';
$string['sessionended'] = 'Session ended';
$string['sessionended_desc'] = 'Your teacher has ended this session. Your stars have been saved.';
$string['confirmendsession'] = 'End this session?';
$string['confirmendsession_desc'] = 'Students will stop seeing the slides and the stars will be written to the gradebook.';
$string['confirmreset'] = 'Clear and run again';
$string['confirmreset_desc'] = 'Every answer collected for this question will be deleted and the stars given for it taken back, so the question can be run again from scratch.';
$string['startinteraction'] = 'Start';
$string['stopcollecting'] = 'Stop collecting';
$string['revealanswer'] = 'Reveal answer';
$string['hideanswer'] = 'Hide answer';
$string['pushresult'] = 'Send result to phones';
$string['hideresult'] = 'Take result off phones';
$string['resetround'] = 'Clear and run again';
$string['leaderboard'] = 'Leaderboard';
$string['awardstar'] = 'Give one star';
$string['awardreason'] = 'Awarded during the session';
$string['starawarded'] = 'Star given';
$string['fullscreen'] = 'Full screen';
$string['hideoverlay'] = 'Hide the overlay';
$string['previousslide'] = 'Previous slide';
$string['nextslide'] = 'Next slide';
$string['currentslide'] = 'Current slide';
$string['roundopened'] = 'Now collecting answers';
$string['collectinganswers'] = 'Collecting answers. The result stays off the screen until you show it.';
$string['roundclosedtoast'] = 'Answers closed';
$string['answerrevealed'] = 'Answer revealed';
$string['responsesreceived'] = 'Answers received out of participants joined';
$string['participantsonline'] = 'Online now';
$string['nosessionyet'] = 'No session running';
$string['nosessionyet_desc'] = 'Start a session and students will see whatever slide you are on.';
$string['slidenumber'] = 'Slide {$a}';

// Student player.
$string['connecting'] = 'Connecting';
$string['online'] = 'Live';
$string['offline'] = 'Reconnecting';
$string['waitingforteacher'] = 'Waiting for your teacher';
$string['waitingforteacher_desc'] = 'The slides will appear here as soon as the session starts. Keep this page open.';
$string['waitingfornextquestion'] = 'Waiting for the next question';
$string['submitanswer'] = 'Submit';
$string['changeanswer'] = 'Change my answer';
$string['answersubmitted'] = 'Answer sent';
$string['answerrecorded'] = 'Your answer was recorded.';
$string['roundclosed'] = 'This question is closed.';
$string['timesup'] = 'Time is up.';
$string['youwerecorrect'] = 'Correct!';
$string['youwereincorrect'] = 'Not this time.';
$string['starsearned'] = 'Stars earned';
$string['selectanswerfirst'] = 'Enter an answer first.';
$string['wordplaceholder'] = 'Word';
$string['blankplaceholder'] = 'Your answer';
$string['yourrank'] = 'Your position';
$string['liveresult'] = 'Live result';
$string['correctanswer'] = 'Correct answer';
$string['nowordsyet'] = 'No words yet.';
$string['noanswersyet'] = 'No answers yet.';
$string['noparticipantsyet'] = 'Nobody has joined yet.';
$string['anonymousparticipant'] = 'A participant';
$string['deleteduser'] = 'Deleted user';

// Reports.
$string['reportcourse'] = 'Every activity in this course (total stars)';
$string['reportoverview'] = 'All sessions';
$string['reportsession'] = 'Session: {$a}';
$string['chooseview'] = 'Show';
$string['participant'] = 'Participant';
$string['sessionsattended'] = 'Sessions';
$string['stars'] = 'Stars';
$string['correctanswers'] = 'Correct';
$string['answersgiven'] = 'Answered';
$string['questionstars'] = 'Question stars';
$string['totalstars'] = 'Total stars';
$string['manualstarsshort'] = 'Awarded';
$string['beststreak'] = 'Best streak';
$string['noresponsesyet'] = 'Nobody has answered anything yet.';
$string['exportresults'] = 'Download these results';
$string['resetsessions'] = 'Delete all sessions, answers and stars';

// Events.
$string['eventsessionstarted'] = 'Session started';
$string['eventsessionended'] = 'Session ended';
$string['eventroundopened'] = 'Interaction opened';
$string['eventroundclosed'] = 'Interaction closed';
$string['eventresponsesubmitted'] = 'Answer submitted';
$string['eventstarsawarded'] = 'Stars awarded';

// Errors.
$string['errorinvalidqtype'] = 'That is not a question type this activity supports.';
$string['errorinvalidaction'] = 'That action is not available.';
$string['errorinvalidpayload'] = 'The question definition could not be read.';
$string['errorneedtwooptions'] = 'A multiple choice question needs at least two options with text in them.';
$string['errorneedcorrectoption'] = 'Mark at least one option as correct, or turn off "This question has a correct answer".';
$string['errortoomanycorrect'] = 'Only one option can be correct unless you allow more than one answer.';
$string['errorneedoneblank'] = 'Add at least one blank.';
$string['errorblankneedsanswer'] = 'Blank {$a} has no accepted answer.';
$string['errorinteractionlocked'] = 'Students have already answered this question, so it can no longer be edited.';
$string['errorslidenotfound'] = 'That slide is not part of this activity.';
$string['errorsessionnotfound'] = 'That session is not part of this activity.';
$string['errornointeraction'] = 'This slide has no interaction to run.';
$string['errornosession'] = 'No session is running.';
$string['errornoround'] = 'Nothing is running on this slide.';
$string['errorsessionended'] = 'This session has ended.';
$string['errorroundclosed'] = 'This question is no longer accepting answers.';
$string['errorroundnotcurrent'] = 'The class has moved on to another slide.';
$string['erroralreadyanswered'] = 'You have already answered this question.';
$string['erroremptyanswer'] = 'Enter an answer first.';
$string['errorsinglechoiceonly'] = 'Choose one option only.';
$string['errornotparticipant'] = 'That user cannot take part in this activity.';
$string['errortoomanypages'] = 'This PDF has more than {$a} pages, which is the limit set for this site.';
$string['erroruploadfailed'] = 'The page image could not be uploaded.';
$string['errornotanimage'] = 'The uploaded page was not a valid PNG image.';

// Privacy.
$string['privacy:metadata:participant'] = 'A participant\'s running totals for one live session.';
$string['privacy:metadata:participant:userid'] = 'The participant.';
$string['privacy:metadata:participant:totalstars'] = 'Stars collected in the session.';
$string['privacy:metadata:participant:correctcount'] = 'Number of questions answered correctly.';
$string['privacy:metadata:participant:responsecount'] = 'Number of questions answered.';
$string['privacy:metadata:participant:timejoined'] = 'When the participant joined the session.';
$string['privacy:metadata:participant:lastseen'] = 'When the participant was last active.';
$string['privacy:metadata:response'] = 'One submission to one question.';
$string['privacy:metadata:response:userid'] = 'The participant who submitted.';
$string['privacy:metadata:response:iscorrect'] = 'Whether the submission was fully correct.';
$string['privacy:metadata:response:stars'] = 'Stars awarded for the submission.';
$string['privacy:metadata:response:timetaken'] = 'How long the participant took to answer.';
$string['privacy:metadata:response:timecreated'] = 'When the submission was made.';
$string['privacy:metadata:answer'] = 'The individual parts of a submission, such as one word or one blank.';
$string['privacy:metadata:answer:answertext'] = 'The text the participant entered.';
$string['privacy:metadata:answer:iscorrect'] = 'Whether that part was correct.';
$string['privacy:metadata:answer:stars'] = 'Stars awarded for that part.';
$string['privacy:metadata:award'] = 'Stars given by hand by a teacher.';
$string['privacy:metadata:award:userid'] = 'The participant who received the stars.';
$string['privacy:metadata:award:stars'] = 'How many stars were given.';
$string['privacy:metadata:award:reason'] = 'The reason recorded by the teacher.';
$string['privacy:metadata:award:awardedby'] = 'The teacher who gave the stars.';
$string['privacy:metadata:award:timecreated'] = 'When the stars were given.';
$string['privacy:sessionpath'] = 'Session: {$a}';
