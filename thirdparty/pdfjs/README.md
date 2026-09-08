# pdf.js drop-in location

The import wizard rasterises each PDF page in the teacher's browser, so this
plugin needs a copy of [pdf.js](https://mozilla.github.io/pdf.js/). Nothing is
shipped here: drop the files in yourself, or rely on the copy your Moodle ships.

## What to download

Go to <https://github.com/mozilla/pdf.js/releases> and download
**`pdfjs-<version>-dist.zip`** (the plain `dist`, not `legacy`, unless you must
support very old browsers).

Since **pdf.js v4 the UMD build is gone**: releases contain only ES modules, so
the files you want are

```
build/pdf.mjs
build/pdf.worker.mjs
```

Copy **both** into this directory, keeping the names:

```
mod/interactiveslide/thirdparty/pdfjs/pdf.mjs
mod/interactiveslide/thirdparty/pdfjs/pdf.worker.mjs
```

That is all that is required.

## Optional: wider font coverage

Decks with CJK text, or with a standard font such as Helvetica that the PDF does
not embed, also need the data directories. Copy them from the same zip:

```
mod/interactiveslide/thirdparty/pdfjs/cmaps/
mod/interactiveslide/thirdparty/pdfjs/standard_fonts/
```

The plugin detects them on disk and only points pdf.js at them when they exist.
Most decks with embedded fonts, including Vietnamese ones, work without this.

## The `.mjs` MIME type

Browsers refuse an ES module unless the response carries a JavaScript MIME type,
and a good many servers still send `.mjs` as `application/octet-stream` because
it is missing from their type map. That used to break the import wizard with:

> Failed to load module script: Expected a JavaScript-or-Wasm module script but
> the server responded with a MIME type of "application/octet-stream"

**Nothing needs doing about this any more.** The plugin serves both files through
`mod/interactiveslide/pdfjs.php`, which sets the header itself, and only falls
back to the plain URL if that build loads there too. The fix is in the plugin
because on a shared university install the person who needs the import wizard is
rarely the person who can edit the server config.

If you would rather the browser fetch the files directly — one less PHP process
per import — add the mapping to your server (`AddType text/javascript .mjs` for
Apache, `types { text/javascript mjs; }` for nginx). Renaming both files to `.js`
also still works; the plugin looks for that pairing.

## Where the plugin looks

In this order, and it only probes files that exist on disk:

1. `thirdparty/pdfjs/pdf.mjs` + `pdf.worker.mjs`
2. `thirdparty/pdfjs/pdf.min.mjs` + `pdf.worker.min.mjs`
3. `thirdparty/pdfjs/pdf.js` + `pdf.worker.js` — the renamed ES modules above
4. `thirdparty/pdfjs/pdf.min.js` + `pdf.worker.min.js` — a pdf.js v3 UMD build
5. `lib/pdfjs/build/pdf.mjs` — whatever your Moodle ships
6. `lib/pdfjs/build/pdf.js` — older Moodle

## Notes

No PDF ever leaves the teacher's browser during rendering; only the finished PNG
of each page is uploaded. Nothing is installed on the server.

pdf.js is distributed under the Apache License 2.0. The full text is in
`LICENSE` beside this file, as that licence requires.
