# epub.js, vendored

`epubjs` **0.3.93** (BSD 2-Clause, see `LICENSE`) and its one runtime
dependency `jszip` **3.10.1** (MIT, see `LICENSE-jszip.md`). Both fetched with
`npm pack`, which verifies the registry's checksum, and copied out of
`package/dist/`.

    epub.min.js   sha256-BurhV0UQe0qlCMlVOCdSUfab+58RdWIfxFjZ9C7QgtQ=
    jszip.min.js  sha256-rMfkFFWoB2W1/Zx+4bgHim0WC7vKRVrq6FTeZclH1Z4=

## Why JSZip is here too

An EPUB is a zip file. epub.js does not bundle an unzipper and expects
`JSZip` on the window; without it a book fails to open with an error that
names neither library. Vendoring one and not the other is a working directory
listing and a broken feature.

## What this changes about an EPUB, and what it does not

epub.js renders into an **iframe it owns**, which is why an EPUB still cannot
be highlighted by this application — there is no selection this code can see,
and a mark anchored to something inside that iframe could never be drawn
again. That is refused in `ReaderController::addMark()` rather than only hidden
in the theme.

What it does buy: the book renders in the page rather than being handed to the
browser as a download, its own chapters become the contents, and the reading
position is an EPUB CFI — an opaque string only this renderer understands,
which is exactly why `{reading_positions}` stores it beside the page rather
than instead of it.

## Updating

    npm pack epubjs@<version> jszip@<version>
    tar -xzf epubjs-<version>.tgz && tar -xzf jszip-<version>.tgz
    cp package/dist/epub.min.js .

Re-record the hashes, and check an EPUB still opens, pages, and remembers
where it was.
