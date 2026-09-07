# PDF.js, vendored

`pdfjs-dist` **3.11.174**, Apache 2.0 (see `LICENSE`). Fetched with
`npm pack pdfjs-dist@3.11.174`, which verifies the registry's own checksum, and
copied out of `package/build/`.

    pdf.min.js         sha256-W1eZ5vjGgGYyB6xbQu4U7tKkBvp69I9QwVTwwLFWaUY=
    pdf.worker.min.js  sha256-/qvfMJdw7SS7oxpUZ4Ns3Iz2OccFryfVK1hbBBu4Uns=

Those two lines are the point of this file. A vendored dependency nobody can
check the provenance of is one nobody can safely update — this project has the
same problem with `vendor/`, and solves it there by rebuilding from a lockfile.
There is no lockfile for browser code here, so the hashes stand in for one.

## Why it is committed rather than loaded from a CDN

The same reason `vendor/` is committed. Deployment is `git pull` on shared
hosting with no build step and no npm, so anything the application needs at
runtime has to be in the repository. A CDN would also mean the reader stops
working whenever that CDN is blocked — which, for a church website read on a
guest wi-fi or a school network, is not hypothetical.

## Why 3.x rather than 6.x

3.11.174 is the last line that ships a **UMD** build, which a plain
`<script src>` can load. Everything after it is ESM-only and would need either
a module graph or a bundler. This project has no build step by design, and
adding one to get a newer renderer would be a much larger change than the
renderer is worth.

## Why not the full viewer

Only `pdf.min.js` and `pdf.worker.min.js` are here — not `web/viewer.js` and its
stylesheet, which is another ~1MB and brings a whole UI this application already
has its own version of. The reader in `themes/default/assets/book-reader.js`
drives the API directly.

## Updating

    npm pack pdfjs-dist@<version>
    tar -xzf pdfjs-dist-<version>.tgz
    cp package/build/pdf.min.js package/build/pdf.worker.min.js .
    cp package/LICENSE .

Then re-record the hashes above, and check the reader still pages, selects text
and extracts a text layer — those three are all this application uses.
