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

  Pages are encoded as JPEG by default. Every student downloads every slide they
  are shown, once, which makes this the largest single bandwidth cost of a big
  session: a templated lecture slide with a gradient background measures about
  **1.7MB as PNG against 140KB at JPEG 85** and 90KB at JPEG 70. PNG remains
  selectable for decks whose fine text has to stay pin sharp.

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
  - **Fill in the blanks** — several blanks on one slide, each with its own list
    of accepted answers and its own star value.
  - **Open ended** — a written answer, gathered into a wall of cards on the
    projector. No answer key, so everyone who writes something earns the
    participation stars; identical replies are badged with how often they came up.

**Scoring**
: Difficulty presets are configured site-wide and default to Easy = 1,
  Medium = 2, Hard = 3 stars. Fill in the blanks scores per blank, so a slide can
  be worth the sum of its parts. An optional speed bonus pays extra stars for
  answering correctly with time to spare.

**Timer**
: Per question, in seconds. The countdown is drawn in the browser but **enforced
  on the server**: pausing the page buys no extra time, and the round closes on
  its own even if nobody is watching.

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

**Gamification**
: Stars for joining a session, per-session and all-sessions leaderboards with
  medals for the top three, correct-answer streaks, and a `+1★` button so a
  teacher can reward a good spoken answer on the spot.

**Reporting**
: Three views from one picker: every Interactive Slide activity in the course
  with a column per deck and a semester total, this deck across every session on
  a fixed set of columns, or a single session with a column per question. The two
  summary sheets are laid out as an arithmetic — question stars, attendance stars
  and hand awarded stars, then the total they add up to — so any row can be
  checked by adding across it. Both sheets
  are laid out as an arithmetic — the per slide breakdown, then attendance stars
  and hand awarded stars, then the total they add up to — so any row can be
  checked by adding across it. Downloadable as CSV, Excel or ODS. Stars map to a
  gradebook grade by one of four rules when a session ends.

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
classes/external/       13 web services, all AJAX
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

## Licence

GPL v3 or later, matching Moodle.
