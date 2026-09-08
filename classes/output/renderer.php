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

namespace mod_interactiveslide\output;

use cm_info;
use context_module;
use plugin_renderer_base;
use stdClass;

/**
 * Renders the three live screens: student player, presenter console and editor.
 *
 * Each one is a small Mustache shell that a JavaScript module fills from the
 * polled state document, so the markup here stays static.
 *
 * @package    mod_interactiveslide
 * @copyright  2026 Interactive Slide contributors
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class renderer extends plugin_renderer_base {

    /**
     * The student's live player.
     *
     * @param stdClass $instance
     * @param cm_info|stdClass $cm
     * @param context_module $context
     * @return string
     */
    public function render_student_player(stdClass $instance, $cm, context_module $context): string {
        global $USER;

        $this->page->requires->js_call_amd('mod_interactiveslide/student', 'init', [[
            'cmid' => (int)$cm->id,
            'pollinterval' => self::poll_interval(),
        ]]);

        // Counted here rather than polled: a student who has come to look up how
        // many stars they have should get the number whether or not a session is
        // running, and without a request every two seconds while they read it.
        $totals = \mod_interactiveslide\local\report_builder::own_totals(
            (int)$cm->course, (int)$instance->id, (int)$USER->id);

        $session = \mod_interactiveslide\local\session_manager::get_active_session((int)$instance->id);

        return $this->render_from_template('mod_interactiveslide/student_player', [
            'cmid' => (int)$cm->id,
            'name' => format_string($instance->name),
            'intro' => format_module_intro('interactiveslide', $instance, $cm->id),
            'hasintro' => trim(strip_tags($instance->intro ?? '')) !== '',
            'activitystars' => $totals['activity'],
            'coursestars' => $totals['course'],
            // Built here rather than with a parameterised {{#str}}: one place
            // where the number and the wording meet, and it is the place that
            // already knows the number.
            'sessionsjoinedtext' => get_string('sessionsjoined', 'mod_interactiveslide',
                $totals['sessions']),
            'acrossactivitiestext' => get_string('acrossactivities', 'mod_interactiveslide',
                $totals['decks']),
            'sessionlive' => (bool)$session,
            'reporturl' => (new \moodle_url('/mod/interactiveslide/report.php',
                ['id' => (int)$cm->id]))->out(false),
        ]);
    }

    /**
     * The presenter console.
     *
     * @param stdClass $instance
     * @param cm_info|stdClass $cm
     * @param context_module $context
     * @return string
     */
    public function render_presenter(stdClass $instance, $cm, context_module $context): string {
        $this->page->requires->js_call_amd('mod_interactiveslide/presenter', 'init', [[
            'cmid' => (int)$cm->id,
            'pollinterval' => self::poll_interval(),
            'canaward' => has_capability('mod/interactiveslide:awardstars', $context),
            'viewurl' => (new \moodle_url('/mod/interactiveslide/view.php', ['id' => $cm->id]))->out(false),
        ]]);

        return $this->render_from_template('mod_interactiveslide/presenter', [
            'cmid' => (int)$cm->id,
            'name' => format_string($instance->name),
            'viewurl' => (new \moodle_url('/mod/interactiveslide/view.php', ['id' => $cm->id]))->out(false),
        ]);
    }

    /**
     * The slide and interaction editor.
     *
     * @param stdClass $instance
     * @param cm_info|stdClass $cm
     * @param context_module $context
     * @return string
     */
    public function render_editor(stdClass $instance, $cm, context_module $context): string {
        $this->page->requires->js_call_amd('mod_interactiveslide/editor', 'init', [[
            'cmid' => (int)$cm->id,
            'sesskey' => sesskey(),
            'uploadurl' => (new \moodle_url('/mod/interactiveslide/upload.php'))->out(false),
            'renderscale' => (int)(get_config('mod_interactiveslide', 'renderscale') ?: 1600),
            'maxpages' => (int)(get_config('mod_interactiveslide', 'maxpages') ?: 200),
            'imageformat' => \mod_interactiveslide\local\settings::image_format(),
            'pdfjs' => self::pdfjs_candidates(),
        ]]);

        return $this->render_from_template('mod_interactiveslide/editor', [
            'cmid' => (int)$cm->id,
            'name' => format_string($instance->name),
            'viewurl' => (new \moodle_url('/mod/interactiveslide/view.php', ['id' => $cm->id]))->out(false),
            'presenturl' => (new \moodle_url('/mod/interactiveslide/present.php', ['id' => $cm->id]))->out(false),
            'pdffilename' => (string)($instance->pdffilename ?? ''),
            'haspdf' => !empty($instance->pdffilename),
        ]);
    }

    /**
     * The polling interval clients should use, in milliseconds.
     *
     * @return int
     */
    private static function poll_interval(): int {
        return \mod_interactiveslide\local\settings::poll_interval();
    }

    /**
     * The pdf.js builds present on this server, best first.
     *
     * pdf.js dropped its UMD build at version 4: current releases ship only ES
     * modules, and a site may have the files under either extension because some
     * web servers still do not send a JavaScript MIME type for `.mjs`. Every
     * candidate is checked on disk here so the browser only ever probes builds
     * that actually exist, and so the error message can say something useful.
     *
     * Each build is offered twice: first through pdfjs.php, which sets the
     * JavaScript MIME type itself, and then at its plain URL. A browser refuses
     * an ES module served as application/octet-stream, which is what many
     * servers still send for `.mjs`, so the plain URL fails on exactly the sites
     * where nobody can edit the server config. Serving it ourselves removes that
     * dependency; the plain URL stays as the second try because it costs no PHP
     * process on a site that is configured correctly.
     *
     * @return array[] each with src, worker, module, and optional font data URLs
     */
    private static function pdfjs_candidates(): array {
        global $CFG;

        $locations = [
            // A copy dropped into this plugin wins: an administrator put it there
            // on purpose, usually because the core one was missing or too old.
            ['plugin', '/mod/interactiveslide/thirdparty/pdfjs', 'pdf.mjs', 'pdf.worker.mjs', true],
            ['plugin', '/mod/interactiveslide/thirdparty/pdfjs', 'pdf.min.mjs', 'pdf.worker.min.mjs', true],
            // Same files renamed to .js, the hand workaround for a server that
            // will not serve .mjs as JavaScript. Still supported for anyone who
            // did it before this plugin started serving them itself.
            ['plugin', '/mod/interactiveslide/thirdparty/pdfjs', 'pdf.js', 'pdf.worker.js', true],
            ['plugin', '/mod/interactiveslide/thirdparty/pdfjs', 'pdf.min.js', 'pdf.worker.min.js', false],
            // Whatever this Moodle happens to ship.
            ['core', '/lib/pdfjs/build', 'pdf.mjs', 'pdf.worker.mjs', true],
            ['core', '/lib/pdfjs/build', 'pdf.js', 'pdf.worker.js', false],
        ];

        $candidates = [];
        foreach ($locations as [$area, $dir, $script, $worker, $ismodule]) {
            $scriptpath = $CFG->dirroot . $dir . '/' . $script;
            $workerpath = $CFG->dirroot . $dir . '/' . $worker;

            if (!file_exists($scriptpath) || !file_exists($workerpath)) {
                continue;
            }

            // The timestamp is what lets the served copy be cached for a year:
            // replacing pdf.js changes it, which changes the URL.
            $rev = max((int)filemtime($scriptpath), (int)filemtime($workerpath));

            $served = [
                'src' => self::pdfjs_served_url($area, $script, $rev),
                'worker' => self::pdfjs_served_url($area, $worker, $rev),
                'module' => $ismodule,
                'cmapurl' => '',
                'fonturl' => '',
            ];

            $candidate = [
                'src' => $CFG->wwwroot . $dir . '/' . $script,
                'worker' => $CFG->wwwroot . $dir . '/' . $worker,
                'module' => $ismodule,
                'cmapurl' => '',
                'fonturl' => '',
            ];

            // Optional data directories. A PDF that uses CJK text or a
            // non-embedded standard font needs them; most decks do not.
            $root = dirname($dir);
            if (is_dir($CFG->dirroot . $root . '/cmaps')) {
                $candidate['cmapurl'] = $CFG->wwwroot . $root . '/cmaps/';
            }
            if (is_dir($CFG->dirroot . $root . '/standard_fonts')) {
                $candidate['fonturl'] = $CFG->wwwroot . $root . '/standard_fonts/';
            }

            $served['cmapurl'] = $candidate['cmapurl'];
            $served['fonturl'] = $candidate['fonturl'];

            $candidates[] = $served;
            $candidates[] = $candidate;
        }

        return $candidates;
    }

    /**
     * The URL that serves one pdf.js file through the plugin's own shim.
     *
     * @param string $area 'plugin' or 'core', matching pdfjs.php
     * @param string $file the file name, with no path
     * @param int $rev the file's timestamp, so a replaced build misses the cache
     * @return string
     */
    private static function pdfjs_served_url(string $area, string $file, int $rev): string {
        return (new \moodle_url('/mod/interactiveslide/pdfjs.php', [
            'dir' => $area,
            'file' => $file,
            'rev' => $rev,
        ]))->out(false);
    }
}
