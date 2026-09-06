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
 * Turns a PDF into slides entirely in the teacher's browser.
 *
 * pdf.js rasterises each page to a canvas, and only the finished PNG is
 * uploaded. That is why this plugin needs no Ghostscript, no ImageMagick and no
 * exec() permission on the server.
 *
 * @module     mod_interactiveslide/pdfimport
 * @copyright  2026 Interactive Slide contributors
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
define([], function() {

    var cachedLib = null;
    var cachedCandidate = null;

    /**
     * Inject a classic script tag and resolve when it has run.
     *
     * @param {String} src
     * @return {Promise}
     */
    var loadScript = function(src) {
        return new Promise(function(resolve, reject) {
            var script = document.createElement('script');
            script.src = src;
            script.async = true;
            script.onload = function() {
                resolve();
            };
            script.onerror = function() {
                reject(new Error('Could not load ' + src));
            };
            document.head.appendChild(script);
        });
    };

    /**
     * Pick the pdf.js namespace out of whatever a build gave us.
     *
     * The ES module exports getDocument by name, older UMD builds hang a global
     * off window, and some bundles put everything on a default export. Rather
     * than trusting the file extension, look for the function we need.
     *
     * @param {Object} module the resolved module namespace, if there was one
     * @return {Object|null}
     */
    var resolveLib = function(module) {
        var shapes = [
            module,
            module && module.default,
            window.pdfjsLib,
            window['pdfjs-dist/build/pdf']
        ];

        for (var i = 0; i < shapes.length; i++) {
            if (shapes[i] && typeof shapes[i].getDocument === 'function' && shapes[i].GlobalWorkerOptions) {
                return shapes[i];
            }
        }

        return null;
    };

    /**
     * Try one pdf.js build.
     *
     * @param {Object} candidate src, worker, module flag and optional data URLs
     * @return {Promise<Object>} the pdf.js namespace
     */
    var tryCandidate = function(candidate) {
        // pdf.js dropped its UMD build at version 4, so a current release can
        // only be brought in with the browser's own dynamic import; requirejs
        // cannot load an ES module. Older builds still need a script tag.
        var loaded = candidate.module
            ? import(candidate.src)
            : loadScript(candidate.src).then(function() {
                return null;
            });

        return loaded.then(function(module) {
            var lib = resolveLib(module);
            if (!lib) {
                throw new Error('pdf.js did not load from ' + candidate.src);
            }
            lib.GlobalWorkerOptions.workerSrc = candidate.worker;
            return lib;
        });
    };

    /**
     * Load the first pdf.js build that works.
     *
     * @param {Array} candidates
     * @return {Promise<Object>}
     */
    var loadLibrary = function(candidates) {
        if (cachedLib) {
            return Promise.resolve(cachedLib);
        }

        if (!candidates || !candidates.length) {
            // The server found no build on disk, so there is nothing to probe.
            return Promise.reject(new Error('pdfjsmissing'));
        }

        var attempt = function(index) {
            if (index >= candidates.length) {
                return Promise.reject(new Error('pdfjsmissing'));
            }
            return tryCandidate(candidates[index]).then(function(lib) {
                cachedLib = lib;
                cachedCandidate = candidates[index];
                return lib;
            }).catch(function() {
                return attempt(index + 1);
            });
        };

        return attempt(0);
    };

    /**
     * Read a File into an ArrayBuffer.
     *
     * @param {File} file
     * @return {Promise<ArrayBuffer>}
     */
    var readFile = function(file) {
        return new Promise(function(resolve, reject) {
            var reader = new FileReader();
            reader.onload = function() {
                resolve(reader.result);
            };
            reader.onerror = function() {
                reject(new Error('Could not read the file'));
            };
            reader.readAsArrayBuffer(file);
        });
    };

    /**
     * Render one page to an image blob.
     *
     * @param {Object} page a pdf.js page proxy
     * @param {Number} maxEdge longest edge of the output image, in pixels
     * @param {Object} format mime, quality and extension chosen by the admin
     * @return {Promise<Object>} blob, width and height
     */
    var renderPage = function(page, maxEdge, format) {
        var base = page.getViewport({scale: 1});
        var scale = maxEdge / Math.max(base.width, base.height);
        var viewport = page.getViewport({scale: scale});

        var canvas = document.createElement('canvas');
        canvas.width = Math.round(viewport.width);
        canvas.height = Math.round(viewport.height);

        var context = canvas.getContext('2d');
        // Slides are usually white behind transparent vector art.
        context.fillStyle = '#ffffff';
        context.fillRect(0, 0, canvas.width, canvas.height);

        return page.render({canvasContext: context, viewport: viewport}).promise.then(function() {
            return new Promise(function(resolve, reject) {
                canvas.toBlob(function(blob) {
                    if (!blob) {
                        reject(new Error('Could not encode the page'));
                        return;
                    }
                    resolve({blob: blob, width: canvas.width, height: canvas.height});
                }, format.mime, format.quality);
            });
        });
    };

    /**
     * POST one form to the plugin's upload endpoint.
     *
     * @param {String} url
     * @param {FormData} form
     * @return {Promise<Object>} the decoded JSON reply
     */
    var post = function(url, form) {
        return fetch(url, {
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
                // Our own replies carry `message`; a Moodle exception carries `error`.
                throw new Error(payload.message || payload.error || 'Upload failed');
            }
            return payload;
        });
    };

    /**
     * Import a PDF as a deck of slides.
     *
     * @param {Object} config cmid, sesskey, uploadurl, renderscale, maxpages, pdfjs
     * @param {File} file the chosen PDF
     * @param {Object} hooks onProgress(done, total), onSlide(slide)
     * @return {Promise<Number>} how many pages were imported
     */
    var run = function(config, file, hooks) {
        hooks = hooks || {};

        // Falls back to PNG so an older site that has not saved the setting yet
        // still imports rather than failing on an undefined mime type.
        var format = config.imageformat
            || {mime: 'image/png', quality: 1, extension: 'png'};

        return loadLibrary(config.pdfjs).then(function(lib) {
            return readFile(file).then(function(buffer) {
                var options = {data: new Uint8Array(buffer)};

                // Only needed by decks with CJK text or a non-embedded standard
                // font, and only sent when the server saw the directories.
                if (cachedCandidate && cachedCandidate.cmapurl) {
                    options.cMapUrl = cachedCandidate.cmapurl;
                    options.cMapPacked = true;
                }
                if (cachedCandidate && cachedCandidate.fonturl) {
                    options.standardFontDataUrl = cachedCandidate.fonturl;
                }

                return lib.getDocument(options).promise;
            });
        }).then(function(pdf) {
            var total = Math.min(pdf.numPages, config.maxpages || 200);

            var begin = new FormData();
            begin.append('cmid', config.cmid);
            begin.append('sesskey', config.sesskey);
            begin.append('action', 'begin');
            begin.append('replace', '1');
            begin.append('pdfname', file.name);

            return post(config.uploadurl, begin).then(function() {
                // Pages go up one at a time on purpose: a 200 page deck should
                // not open 200 parallel uploads, and the progress bar stays honest.
                var step = function(pageNumber) {
                    if (pageNumber > total) {
                        return Promise.resolve();
                    }

                    return pdf.getPage(pageNumber).then(function(page) {
                        return renderPage(page, config.renderscale || 1600, format);
                    }).then(function(rendered) {
                        var form = new FormData();
                        form.append('cmid', config.cmid);
                        form.append('sesskey', config.sesskey);
                        form.append('action', 'page');
                        form.append('pageno', pageNumber);
                        form.append('width', rendered.width);
                        form.append('height', rendered.height);
                        form.append('image', rendered.blob,
                            'page-' + pageNumber + '.' + format.extension);

                        return post(config.uploadurl, form);
                    }).then(function(reply) {
                        if (hooks.onProgress) {
                            hooks.onProgress(pageNumber, total);
                        }
                        if (hooks.onSlide) {
                            hooks.onSlide(reply);
                        }
                        return step(pageNumber + 1);
                    });
                };

                return step(1);
            }).then(function() {
                var finish = new FormData();
                finish.append('cmid', config.cmid);
                finish.append('sesskey', config.sesskey);
                finish.append('action', 'finish');

                return post(config.uploadurl, finish);
            }).then(function() {
                return total;
            });
        });
    };

    return {run: run};
});
