# Interactive Slide (mod_interactiveslide)

A Moodle activity module for running a lecture the way Mentimeter or ClassPoint
does, without leaving Moodle. Import a PDF deck, attach an interaction to any
slide, and drive the room live: students see the slide the teacher is on, answer
while the round is open, and collect **stars** on a leaderboard.

Built for **Moodle 5.0+**.

---

## What it does

**Import**
: A PDF is rasterised page by page **in the teacher's browser** by pdf.js and
  each page is uploaded as one slide. The server needs no Ghostscript, no
  ImageMagick and no `exec()` permission, so this works on shared hosting.

  A single slide can also be added from a picture — a screenshot, a photo of a
  whiteboard, a page that arrived on its own. It lands immediately after the
  slide being looked at, which is where a missing page is noticed, and nothing
  else in the deck moves. Importing a PDF replaces the
  deck; adding a picture does not.

  Pages are encoded as JPEG by default. Every student downloads every slide they
  are shown, once, which makes this the largest single bandwidth cost of a big
  session: a templated lecture slide with a gradient background measures about
  **1.7MB as PNG against 140KB at JPEG 85** and 90KB at JPEG 70. PNG remains
  selectable for decks whose fine text has to stay pin sharp.

**Moving a deck**
: A deck exports to one `.zip` — every slide, every picture, every interaction —
  and imports into any other Interactive Slide activity, on this site or another
  one. It is how you hand a colleague a lecture, or carry one between the test
  site and the live one.

  The file holds `deck.json` and an `images/` directory, and nothing else. It
  carries **content only**: no sessions, no stars, no student answers. Those
  belong to a particular class, they mean nothing once user ids no longer match,
  and Moodle's own course backup already carries them. An import never renames
  the target activity or touches its grade and leaderboard settings either —
  only slides and questions cross over.

  Importing into an activity that already has slides asks which you meant:
  replace the deck, or add to the end. On an empty activity it does not ask,
  because the two are the same thing.

  Two things are worth knowing before moving a deck between sites. **Star values
  follow the destination.** A question marked Hard is worth what Hard is worth
  where it lands, because difficulty is what the teacher authored and the star
  value is the site's own policy; a question given an exact number instead
  (difficulty "custom") keeps it. The editor says so when the two sites differ.
  And **a deck of fifty slides is around 7 MB**, so a big one can meet the
  server's `post_max_size` — the import says which limit it hit rather than
  failing obscurely.

  The format identifies itself and carries a version. A file written by a newer
  version of the plugin is refused by name, with a message saying to update,
  rather than half-read. Adding a key never bumps that version; a reader ignores
  keys it does not know.

**Slides on student devices**
: Can be switched off site-wide, leaving the phone as an answer device while the
  room reads the projector. The image URL is then never put in the student's
  state document, so nothing is fetched at all — hiding the picture with CSS
  would still have cost every student the download.

**Interactions** (one per slide)
: - **Word cloud** — free text; several words per student; duplicates merge and
    grow. No right answer, so everyone who takes part earns the participation
    stars.
  - **Multiple choice** — optional answer key, single or multiple correct
    options, optional live tally while students answer.
  - **Dropdown** — selects standing in the sentence itself, as many as the
    sentence needs. The teacher writes `___` wherever one belongs and a position
    appears for it, each with its own list of choices and its own star value;
    the student reads one sentence with the gaps filled in rather than a
    sentence followed by a detached list of questions about it. Scored per
    position, like fill in the blanks, and tallied per position on the
    projector so the room sees which choice each gap drew.

    Positions follow the gaps as they are typed, and only that way — there is no
    button to add one, because a position with nowhere to stand is only a way for
    the two to get out of step. They grow with the gaps but shrink carefully: a
    gap deleted mid-edit removes a position only while nothing has been typed
    into it, so a stray keystroke cannot throw away a list of choices.
  - **Fill in the blanks** — several blanks on one slide, each with its own list
    of accepted answers and its own star value. Matching ignores case (unless
    the blank asks for it), collapses runs of spaces, and forgives a full stop
    at the end or quotes wrapped round the whole answer. It also folds the
    punctuation a keyboard substitutes on its own: a phone types `’` where a
    laptop types `'`, and `B’D’` from a phone matches an answer key of `B'D'`
    typed on a laptop. Curly double quotes, primes and long dashes are folded
    the same way. **Nothing else is removed** — `-5` keeps its sign, `<` is an
    answer in its own right, and `B'D` does not match `B'D'`, because in a
    digital logic class that last mark is the answer.
  - **Open ended** — a written answer, gathered into a wall of cards on the
    projector. No answer key, so everyone who writes something earns the
    participation stars; identical replies are badged with how often they came up.
  - **Video** — opened with the same Start button as any other interaction, but
    what fills the overlay is an embedded video rather than a question. Nothing
    is collected and no stars are paid, so it never enters the total the
    gradebook divides by. The phones say to look up rather than playing eighty
    copies of the same clip a moment apart.

    Links are resolved against a closed list — YouTube, Vimeo, or a direct link
    to an `.mp4`, `.webm` or `.ogv` file — and a link that matches none of them
    is refused while the teacher is still looking at the field, not during the
    lecture. YouTube is embedded through `youtube-nocookie.com`: the room did not
    choose to be tracked by being in it.

**A picture, and a clip for the room**
: Any question can carry a picture and a video, whatever it asks.

  The **picture is part of the question**, so it goes to both screens: the
  projector and every student's phone. It is sent even when the site keeps slide
  images off student devices, because that setting is a bandwidth rule about
  scanned lecture pages, not about the thing being asked. The box is held open
  at the picture's own shape while it loads, so the answer buttons do not jump
  under a finger already reaching for them.

  The **video plays on the presenter's screen only**. That is not a CSS
  decision: the resolved URL is never put in the state document a student
  receives, so there is nothing on the phone to reveal. A clip is something a
  room watches together once; thirty phones playing it out of step, on the
  hall's wifi, is a different and worse thing. Links go through the same closed
  provider list the video question type uses, and are refused while the teacher
  is still looking at the field.

  Putting the overlay away empties the box rather than hiding it: a video in a
  hidden panel goes on playing, and the room does not need a soundtrack from
  something nobody can see. Pictures travel in deck exports beside the slide
  images, under names made from the slide's position, and are re-checked byte by
  byte on the way in.

  The picture is capped on both screens so it cannot push the answers off the
  bottom, which on a dense diagram means the room can see it but not read it.
  **Tapping it puts it up whole**, over everything else; the button, the
  backdrop, the picture itself and `Esc` all put it away again. On the
  presenter that `Esc` stops there rather than also dismissing the overlay
  underneath.

**Scoring**
: Difficulty presets are configured site-wide and default to Easy = 1,
  Medium = 2, Hard = 3 stars. Fill in the blanks scores per blank, so a slide can
  be worth the sum of its parts. An optional speed bonus pays extra stars for
  answering correctly with time to spare.

  **Stars are paid when the question closes, not when the answer is sent.** An
  answer that scored on arrival told the student it had scored: with answer
  changing switched on, sending each option in turn and watching the header was
  enough to find the right one. So a round contributes nothing to anybody's
  total until it stops taking answers — by the timer, by **Stop collecting**, by
  **Reveal answer**, or by the session ending — and closing it credits everyone
  who answered in one pass. Running the same question again takes those stars
  back off the board until it closes a second time. The answers themselves are
  stored and marked as they arrive, as they always were; what changed is when
  they are counted.

**Timer**
: Per question, in seconds. The countdown is drawn in the browser but **enforced
  on the server**: pausing the page buys no extra time, and the round closes on
  its own even if nobody is watching.

  When the clock reaches zero, each phone sends whatever is in its form: a
  student who typed an answer and did not press the button still meant to
  answer. An answer already with the server is left alone, so a resend cannot
  rewrite the time it was given or take back its speed bonus — unless the form
  has been changed since, which is the student's newer answer. The whole room
  sends at the same moment and some of those requests arrive after the round has
  been closed, so the server accepts them for five seconds past the deadline.
  Only a round that ran out of time has that window: one the teacher stopped by
  hand is stopped.

**Sessions**
: Students see nothing until a teacher starts a session, and only ever see the
  slide the teacher is on. A round accepts answers only while it is open and only
  from students who are on that slide. With late joining switched off, the door
  closes when the first question opens rather than when the session starts, so
  the people still walking in are not shut out but a latecomer cannot collect
  stars for a session they missed.

**Presenting**
: A presentation mode that fills the window, with a filmstrip, keyboard transport
  (`←` `→` change slide, `Space` opens and closes the round, `Esc` puts the pen
  down, then leaves presentation mode, `F` toggles it) and a live overlay carrying
  the word cloud, bar chart, timer, answer key and leaderboard. The stage takes
  each page's own ratio, so nothing is letterboxed, and presentation mode drops
  the filmstrip so the deck owns the projector.

  Opening a question raises that overlay; arriving on a slide never does, so a
  question run earlier in the lecture does not bury its own slide when the
  teacher comes back to it. A **Show result** button on the transport bar brings
  it back, and puts it away again. The one exception is a question still
  collecting answers: that is what the room is watching, so it keeps the screen.

  The leaderboard sits beside the result rather than under it, taking half the
  width, and only when its own button is pressed. A class of eighty makes a board
  taller than the screen; underneath the question it pushed the question off the
  top, so it now takes a column of its own and scrolls inside it. Every name is
  there — the teacher can scroll to anyone to hand them a star — and the question
  does not move. Below about 900px, or on a tablet held upright, the two stack
  again with the board capped, because there is no room for two columns.

  The board is the one part of the overlay that is not projector typography. It
  is what the teacher reads from their own screen while looking for a name, so
  its rows are smaller: nine or ten people on screen at once rather than four,
  each carrying a place, a picture, a name, a star count and the star button, and
  nothing else. Sized like the rest of the overlay it fitted four, and the name —
  the only flexible column — collapsed to nothing between the picture and the
  count, leaving a row of medals with nobody on it.

  A search box sits at the top of the board and stays there while the names
  scroll. It ignores accents and letter case and takes the words in any order,
  matching the start of each word: `an nguyen` finds *Nguyễn Văn An*, `duc`
  finds *Đức*, and `an` does not drag in *Trần* or *Khánh*. While the whole
  class fits on the board (it carries the top 100) the board filters itself;
  in a bigger session the server searches every participant instead, so a
  student ranked 150th is still found and is still shown as 150th.

  **Stars for the class** next to the search gives everyone who has joined the
  session — online or not — the same number of stars in one step. A small
  panel asks how many before anything is written.

  **Who gave an answer.** Once a multiple choice, dropdown or fill in the blank
  result is on screen, the count at the end of each bar, and each typed-answer
  chip, opens a panel listing everyone behind it, sorted by name, and it keeps
  up as more answers come in. From there the same panel gives all of them
  stars at once. It is for the question whose own answer key turned out to be
  wrong: open the answer the class was right to give and credit everyone who
  gave it. A group award adds stars, it never takes them away, is capped at
  100 each, and reaches only people who are in the session and still allowed
  to take part, whatever list the browser sends. It is logged per student
  like any other award and shows up as one award row each in the reports.

  Presentation mode is drawn by the plugin itself rather than asked of the
  browser, because iPadOS does not give web pages the Fullscreen API at all — the
  button used to fail there with nothing to show for it. Now the console pins
  itself over the whole window in CSS, which works on every browser including
  iPad, and the real Fullscreen API is still requested on top where it exists
  (desktop Chrome, Firefox, Safari on macOS) so a laptop plugged into a projector
  loses its browser chrome as before.

**Annotating**
: A laser pointer (a dot, or a trail that stays while you are using it and starts
  fading only after you have stopped for a moment, so a second stroke does not
  erase the first). Presenting from a laptop there is no pen to press, so the dot
  follows the mouse instead: the layer hides the system cursor, because the room
  should be shown a laser dot rather than an arrow, and the dot takes its place.
  A pen in three colours and
  three thicknesses, a highlighter in three colours, and an eraser that lifts
  whole strokes. Built for a stylus on a tablet: strokes follow pen pressure,
  a hand resting on the screen is ignored while the stylus is in use, and the
  high sample rate of a stylus is used in full so a fast stroke draws as a curve.
  Each slide keeps its own drawing, and strokes are stored in normalised
  coordinates so they survive a resize, a rotation and full screen. iPadOS runs
  Live Text over the slide picture, which turned a stroke into a text selection
  and put the Copy callout on top of the drawing, so selection, the callout,
  image dragging and the touch gestures underneath the stylus are all turned off
  while a tool is in hand — and turned back on the moment it is put down. Escape puts
  the pen down, and a stylus only mode hands every finger gesture back to the
  browser, so the page can still be scrolled and swiped without putting the pen
  away. Nothing is sent anywhere: the room is already looking at it.

  On an iPad the browser's own toolbar is the last thing left, and no web page
  can hide it — so the console declares itself a standalone web app: added to the
  Home Screen and launched from that icon, it runs with no browser chrome at all,
  and there is no toolbar for a swipe to bring back.

  The presenter console **is** the projector, so it follows the same rules as the
  room: the answer key appears only after the reveal, and a question can keep its
  running tally off the screen until collecting stops. With the tally hidden the
  room still sees the question's own options or blanks, just with nothing filled
  in. Pushing the result to the students' own phones is a separate, deliberate
  action.

**For the student**
: Opening the activity shows what they have collected before it shows anything
  live: their stars in this activity and their stars across every Interactive
  Slide in the course, with a button to start taking part. Until they press it
  nothing is polled and no attendance is recorded — someone who came only to look
  up a number is not counted as present, and costs the server nothing while they
  read it. The live screen is the second step, with a way back to the first.

  Students can also open the report, restricted to their own line: one session,
  every session of this activity, or their total across the course. The
  restriction is applied in the queries, so nobody else's rows are loaded at all,
  and the session picker offers only the sessions they were actually in.

**Gamification**
: Stars for joining a session, per-session and all-sessions leaderboards with
  medals for the top three, correct-answer streaks, and a `+1★` button so a
  teacher can reward a good spoken answer on the spot.

**Reporting**
: Three views from one picker: every Interactive Slide activity in the course
  with a column per deck and a semester total, this deck across every session on
  a fixed set of columns, or a single session with a column per question. Each
  sheet opens with the student's ID number and their name, so it lines up with
  the class list a lecturer already marks from. The summary sheets are laid out
  as an arithmetic — question stars, attendance stars and hand awarded stars,
  then the total they add up to — so any row can be checked by adding across it.
  Downloadable as CSV, Excel or ODS. Stars map to a gradebook grade by one of
  four rules when a session ends.

  A session can be deleted from its own report, which takes the stars it awarded
  with it. Every star a student holds is a row belonging to a session, so there
  is nothing left over to recalculate: the totals for the activity and for the
  course drop by exactly what that session paid out, and the gradebook is
  rebuilt from what remains. It needs the editing capability rather than the
  reading one, it will not touch a session that is still running, and it is
  logged — it is the one action here that destroys results a student earned.

---

## Installing

1. Copy this directory to `mod/interactiveslide` inside your Moodle root:

   ```
   <moodleroot>/mod/interactiveslide/
   ```

2. Visit **Site administration → Notifications** and complete the upgrade.

3. Optional: set the star values and update interval under
   **Site administration → Plugins → Activity modules → Interactive Slide**.

### pdf.js

The plugin checks the server for a pdf.js build and hands the browser only the
ones that exist, preferring a copy in `thirdparty/pdfjs/` over whatever Moodle
ships in `lib/pdfjs/`.

If your Moodle has no usable copy, download `pdfjs-<version>-dist.zip` from
[the pdf.js releases page](https://github.com/mozilla/pdf.js/releases) and copy
`build/pdf.mjs` and `build/pdf.worker.mjs` into
`mod/interactiveslide/thirdparty/pdfjs/`. Since pdf.js v4 there is no UMD
`pdf.min.js` any more — ES modules are all that ship.

The plugin serves those two files through `pdfjs.php` so that a server which
does not know the `.mjs` extension — many send it as `application/octet-stream`,
which browsers refuse for an ES module — needs no configuration change.

Full instructions, including the fix for a web server that will not serve `.mjs`
as JavaScript, are in [`thirdparty/pdfjs/README.md`](thirdparty/pdfjs/README.md).

---

## Using it

**Teacher**

1. Add an *Interactive Slide* activity to the course.
2. **Edit slides → Import PDF**. Each page becomes a slide; drag the thumbnails
   to reorder, and delete any you do not need.
3. Pick a slide, choose a question type, fill the question in and **Save**.
   A question that students have already answered locks itself: rewriting it
   would silently change what the stored answers mean.
4. **Present** → **Start session**. Move through the deck; on a slide that has an
   interaction, press **Start** (or `Space`).
5. **Stop collecting** → **Reveal answer** → the leaderboard comes up on its own.
6. **End session** writes the stars to the gradebook.

**Student**

Open the activity. Until the teacher starts a session there is a waiting screen;
after that the slide, the question and the answer form appear on their own. Stars
and rank sit in the header.

---

## How it is put together

```
db/install.xml          10 tables: deck, slide, interaction, option, blank,
                        session, round, response, answer, participant, award
classes/local/          the domain: session state machine, marking, aggregation,
                        leaderboard, grading, reporting
classes/external/       15 web services, all AJAX
amd/src/                poller, renderers, the three screens, the PDF importer
templates/              static shells the JavaScript fills
```

**Live updates use long polling.** Every screen holds a revision number and sends
it with each poll; when nothing has moved, the server answers with two queries and
an empty body instead of assembling the whole state document. A running timer is
interpolated in the browser, so a quiet classroom is genuinely quiet on the wire.
The presenter is one person and needs live participant counts, so they always get
the full document.

This is comfortable up to roughly 150 concurrent students per activity at the
default two second interval. Beyond that, raise the interval first; a WebSocket
transport would be the next step, and the poller is the only module that would
need to change.

**Nothing about scoring is decided in the browser.** The client chooses what to
show; the server decides what a submission is worth, whether the round is still
open, and whether a student is even on the right slide.

**Correct answers never leave the server** until the presenter reveals them. The
student-facing export of a question strips the answer key, and the aggregated
result omits which option was right until the reveal.

---

## Privacy

Implements the Moodle Privacy API: metadata, export, and deletion for a single
user, a set of users, and a whole context. Stars, submissions, individual answer
parts and manual awards are all covered.

---

## Known limits

- The wordcloud packs words with a spiral pass at render time. Very long entries
  on a narrow screen fall back to a ranked list rather than overlapping.
- The AMD modules are written in `define()` form and `amd/build/*.min.js` are
  plain copies of `amd/src/*.js`, so the plugin runs without a Grunt step. Run
  `grunt amd` in your Moodle root if you want them genuinely minified.
- Editing a question after students have answered it is refused rather than
  silently invalidating the collected responses.
- Question text and choices are stored exactly as typed, so `<` and `>` survive
  and every renderer escapes them. Answers are compared with only sentence
  punctuation trimmed, so `<`, `-5` and `B'D'` are answers in their own right.
  Answers stored before 2.0.5 were normalised with every punctuation mark
  trimmed; that only affects how old rounds group identical answers in a
  report, never what is scored now.
- The answer panel of a fill in the blank lists the people behind each chip on
  the board, and the board shows the twelve most common answers per blank. A
  rare answer below those twelve has no chip, so it cannot be opened.
- The send-at-time-up is done by the student's own device, so a phone that is
  asleep, offline or on a page that was closed sends nothing. The five second
  window after the deadline covers a slow network, not a closed browser.
- Because stars are paid at the end of a question, the leaderboard does not move
  while one is running. It jumps when the question closes.

## Licence

GPL v3 or later, matching Moodle.
