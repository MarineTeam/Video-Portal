# Tesseract, vendored

`tesseract.js` **7.0.0** and `tesseract.js-core` **7.0.0**, both Apache 2.0.
English language data is `eng.traineddata` from the `tessdata_fast` set,
Apache 2.0, fetched from `tessdata.projectnaptha.com/4.0.0_fast`.

Fetched with `npm pack`, which verifies the registry's checksum.

## What is here, and what deliberately is not

    tesseract.min.js                          63 KB   the API
    worker.min.js                            111 KB   the worker it spawns
    tesseract-core-lstm.wasm.js             3.9 MB   no SIMD
    tesseract-core-simd-lstm.wasm.js        3.9 MB   SIMD
    tesseract-core-relaxedsimd-lstm.wasm.js 3.9 MB   relaxed SIMD
    lang/eng.traineddata.gz                 2.0 MB   English

**~14 MB.** That is the honest cost of OCR working with no external request,
and it is by far the largest thing in this repository. It is stated here
because somebody cloning this and wondering where the size went deserves a
straight answer.

Three core builds because tesseract.js picks one at runtime by feature
detection — relaxed SIMD, then SIMD, then neither — and shipping only the fast
one means OCR silently fails on whatever browser lacks it. They are the
**LSTM-only** builds, which are the ones `tessdata_fast` needs and about 0.6 MB
smaller each than the full builds.

The `.wasm` binaries from the same package are **not** here. Each `.wasm.js`
embeds its own binary as base64 and never fetches a sibling — shipping both
would have been another 8 MB of files nothing loads.

## Why it is self-hosted rather than fetched from a CDN

tesseract.js defaults to jsdelivr for the core AND the language data. Left
alone that means OCR stops working whenever that CDN is blocked — a guest
wi-fi, a school network, a country that blocks it — and it tells a browser
somewhere else which books this site is indexing. `book-index.js` therefore
sets `workerPath`, `corePath` and `langPath` explicitly at these files.

## Only an administrator ever loads this

OCR runs on the admin book screen, and only when the box is ticked. No visitor
fetches any of it: the reader loads PDF.js, which is 1.4 MB and a different
directory.

## Another language

Drop `<code>.traineddata.gz` into `lang/` from
<https://github.com/tesseract-ocr/tessdata_fast> and pass its code where
`book-index.js` says `'eng'`. Nothing else needs changing.
