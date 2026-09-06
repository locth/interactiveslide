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

## If the import still fails

Open the browser console during an import. A message like

> Failed to load module script: expected a JavaScript MIME type but the server
> responded with "text/plain"

means your web server does not serve `.mjs` as JavaScript — Apache and nginx both
lacked that mapping until recently. **Rename both files to `.js`**:

```
pdf.mjs        ->  pdf.js
pdf.worker.mjs ->  pdf.worker.js
```

They are still ES modules; only the extension changes, and the plugin looks for
that pairing too. Fixing the server's MIME map (`AddType text/javascript .mjs`
for Apache, `types { text/javascript mjs; }` for nginx) works equally well.

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

pdf.js is distributed under the Apache License 2.0.
