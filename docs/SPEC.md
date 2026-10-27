# Tegaru — specification

This document is the specification the code follows. Sections A, B and C each
name the files they own, and a change to one of them touches only those files.
`index.php`, `config/`, `src/Options.php`, `src/I18n.php`, this file and the CI
are shared by all three.

Public interface, option names, code, comments and docs are English.
Messages ship in English and German (English first, same keys; see
`translations/`). Examples in docs and tests are fictional.

## What Tegaru does

A phone photo (12–24 megapixels, 2–4 MB, EXIF orientation 6, GPS position
inside) is picked in the Kirby 5 Panel. Before the upload starts, the Panel
script decodes it, applies the EXIF rotation, scales it to a longest edge of
2400 px and re-encodes it in its own type (JPEG and WebP at quality 0.85, PNG as PNG).
The canvas output has no EXIF block, so no GPS, and weighs about 1 MB. A camera
capture named `image.jpg` or `image.jpeg` is renamed to `foto-<local time>.jpg`
(section A, item 5, step 5b). HEIC is not touched in the browser. The upload dialog
shows the new size; a notification sums it up. On the server a hook
rejects what is still too big, too many pixels, or HEIC, on create and on replace.

Measured on a current iPhone, Safari (the numbers are the acceptance
criteria of section A):

| Input | Result at 2560 / 0.85 | Time incl. decoding |
|---|---|---|
| 12.2 MP JPEG, 2.2–2.4 MB, orientation 6 | 983KB – 1.1MB, 1920 × 2560, upright | < 0.2 s |
| 24.5 MP JPEG, 3.5–3.7 MB, orientation 6 | 961KB – 1.1MB, 1920 × 2560, upright | < 0.2 s |
| 5 photos, 15.5 MB together | ≈ 5.3 MB | — |

Kirby itself (`panel.upload`) does not touch the bytes: a file goes to the
server byte-identical, only split into chunks when it exceeds
`upload_max_filesize`. Kirby cannot resize, thumbnail or even open HEIC.

## What Tegaru does NOT do

- No server-side resizing. That is the blueprint's `create:` option
  (`create: { width: 2400, height: 2400, format: jpg }`), which also covers
  uploads the script never sees (the API, a server-side import) and photos it
  leaves unchanged (a browser without canvas, files under `minBytes`). A pick
  or a drop in the Panel, Files app and desktop included, goes through
  `select` and the script. The README recommends it.
- No HEIC conversion and no HEIC removal in the browser, in any browser. A HEIC
  goes up unchanged and the server refuses it. The blueprint's `accept` list
  (README, Blueprints) keeps HEIC out of the picker. Verified on an iPhone with
  Safari (Safari hands over a JPEG for a library photo); Android not measured. libheif-wasm is out of scope unless real use shows the case.
- No change to Kirby's dialogs, and no component override except the one
  extension of `k-files-field` in section D; no DOM surgery in
  `k-upload-dialog`. Tegaru wraps three functions of the `panel.upload` module
  (`select`, `submit`, and `reset` only to learn that the dialog was closed;
  `pick` is left alone, it calls `select`) and otherwise leaves the Panel
  alone.
- No build step, no npm, no bundler. `index.js` is one plain script,
  ES2017, loaded by Kirby from the plugin root. No modules, no `import`.
- Nothing stored in the browser, no address of any host, no `eval`. CI
  greps for all three.

## Options (`site/config/config.php`, under `sayamaapps.tegaru.`; the 1.x name `tegaru.` still works as fallback)

| Key | Default | Meaning |
|---|---|---|
| `active` | `false` | Off by default: hook lets everything through, script changes nothing. |
| `maxEdge` | `2400` | Longest edge of the browser-side result, px. 2400 matches the `create:` size the blueprint recommends; the measurements above were taken at 2560. |
| `quality` | `0.85` | JPEG and WebP quality 0.1–1.0 of the browser-side result. PNG has none. |
| `minBytes` | `1048576` | Files below this size are left alone in the browser (a 400-kB JPEG does not get worse). `0` = always process. |
| `maxUploadBytes` | `15728640` | Server cap per file (15 MB). |
| `maxPixels` | `40000000` | Server cap per raster image, width × height (40 MP). |
| `language` | site language | `en` or `de` for the server messages; the Panel messages follow the Panel language. See `Options::language()`. |

`Options::forPanel()` is what `GET /api/tegaru/options` returns (Panel
login required, Kirby's default API auth):

```json
{"format":1,"active":true,"maxEdge":2400,"quality":0.85,"minBytes":1048576,"maxUploadBytes":15728640,"maxPixels":40000000,"language":"de"}
```

Invalid values fall back to the default (non-int, ≤ 0, quality outside
0.1–1.0); see `src/Options.php`.

## Extension point (read before touching index.js)

Kirby 5 bundles uploading in the reactive module `window.panel.upload`:
`pick(options)` opens the file chooser, `select(files, options)` turns
`File` objects into items `{src: File, name, extension, filename, size,
niceSize, type, url: ObjectURL, progress, completed, error, model}` in
`upload.files`, `open()` shows `k-upload-dialog`, `submit()` uploads all
items (up to 20 in parallel) via `upload(item, attributes)` →
`uploadAsChunks(item.src, …)`. There is no "transform before upload" hook.

Plugins register with `panel.plugin("sayamaapps/tegaru", { created(app)
{…} })`; Kirby calls every `created` with the root Vue instance **before any
dialog exists**, so `app.$panel.upload` is reachable there. The module
reads the bytes from `item.src` only inside `upload()`, the dialog reads
only the item fields. Replacing `item.src` (and the fields that describe it)
after `select` and before `submit` is therefore enough — the preview, the
rename field and the size display keep working because they use the item
fields, not the `File`.

This API is not documented as public but has been stable since 5.0.
Tegaru therefore uses feature detection: if `upload.select` or
`upload.submit` is not a function, the script logs one `console.info` and
does nothing. `tests/PanelSignatureTest.php` (section B) fails the build
when the installed Kirby's Panel bundle no longer contains these methods.

## Section A — Panel script (`index.js`, `tests/tegaru-js.test.mjs`)

Files: `index.js` (replace the stub), `tests/tegaru-js.test.mjs`.

### Behaviour

1. **Register** `panel.plugin('sayamaapps/tegaru', { created(app) })`. Feature
   detection as above. Keep the originals: `select`, `submit`, `pick`. If
   `window.panel.dialog` is missing, log one `console.info` and go on: a
   dialog closed by Escape is then not detected.
2. **Wrap `select(files, options)`**: call the original synchronously (the
   dialog opens at once), then for every item in `this.files` without
   `item.tegaru`: if its file equals the original of an item already prepared
   (name, type, size, lastModified, kept as `item.tegaruOriginal` by
   `keepOriginal`, before a rename or replacement), remove it silently; the prepared item stays (Kirby's own filter,
   which can no longer see the match, would keep the later pick). Otherwise
   set `item.tegaru = 'pending'`, `item.tegaruStamp` (the local time, item 5b)
   and `item.tegaruName` (the name Kirby gave the item, `item.name`), and add its
   job (`prepare(item)` raced with the time limit, see item 5) to a `Map` of
   pending promises to their items (remove on settle). Never throw
   out of `select`.
3. **Wrap `submit()`**: at the call remember the click number (a counter of
   submit calls), the reset count and the open Panel dialog
   (`window.panel.dialog.isOpen` and `.id`, or none). Wait until no item that
   `upload.files` still holds has a pending promise; repeat while there is
   one, so items added to the open dialog meanwhile are prepared and go up
   with the same click. Items the dialog dropped are not waited for: the wait
   looks at the dialog again on every reset and every 100 ms (`POLL`), and
   ends as soon as the click is void (see below). Then
   call the original with the same `this` and arguments and return its
   result, unless one of these changed meanwhile, in which case resolve with
   `undefined` and do not call the original: a later click came (only the
   latest click uploads, so two immediate picks upload once), the reset count
   changed (cancel), or the Panel dialog of the click is no longer the open
   one (closed by Escape or otherwise; Kirby gives every opened dialog an id,
   the key, and the upload dialog a fresh props object, a second key; both are
   compared, and a dialog restored from Kirby's history counts as the same).
   A click made without a dialog (Kirby's immediate pick) is bound to none. A
   photo picked after that waits for its own click. An item cut by Kirby's
   `max` or its duplicate filter is no cancel. A failed `prepare` must not
   block submit (it resolves; the item is uploaded as is). A `prepare` still
   running when its time limit (item 5) is over counts as settled too.
   **Wrap `reset()`** when present: call the original, count the reset, wake
   waiting clicks. Nothing else changes; without `reset` a cancel is noticed
   by the dialog check or the poll instead.
4. **Options**: fetched once per Panel session with `app.$api.get('tegaru/options')`
   (cached promise), by the first `select` or the first files field that
   appears (section D). A failed request is asked again by the next `select`
   or field. An
   answer that never comes is not asked for again: the cached promise stays
   open, every photo then goes up unchanged after the time limit. If `active` is false, `prepare` returns without
   touching the item; the first 64 KiB of the file have been read locally by
   then (item 5), nothing was decoded and no photo data was sent.
5. **`prepare(item)`**. Each item has 20 s for the whole run, options included
   (`TIME_LIMIT`). The limit runs from the
   selection, the wait in the queue included. Past it the item is `'kept'`
   (uploaded as it is) and its job leaves `pending`. A late result changes
   nothing: after every `await` (head, options, slot) `prepare` returns unless
   `item.tegaru` is still `'pending'`, and `replace`, `removeItem` and the
   messages run only then. The timer is cleared when `prepare` settles first.
   The steps, in this order:
   - read the first 64 KiB of `item.src`, sniff the type by magic bytes
     (`jpeg`, `png`, `gif`, `webp`, `avif`, `other`; HEIC is `other`). This is
     local and comes before the settings are requested.
   - the settings (item 4); with `active` off: return.
   - camera name (step 5b, before the size check; applies to any size).
   - `gif`, `avif`, `other` (HEIC, SVG, PDF, …) and files with
     `size < minBytes`: return. Only JPEG, PNG and WebP above `minBytes` go on.
   - decode: `createImageBitmap(file, { imageOrientation: 'from-image' })`;
     if that is missing or rejects, fall back to `new Image()` with an object
     URL (browsers apply EXIF rotation to `<img>` by default). Decoding
     failure → `panel.notification.info(t('tegaru.panel.unreadable', {name}))`,
     return (uploaded unchanged).
   - target size: `scale = min(1, maxEdge / max(w, h))`, `w' = round(w·scale)`,
     `h' = round(h·scale)`. `decide()` says whether it is worth it: JPEG, PNG and
     WebP above `minBytes` (re-encoding drops the metadata, at any size).
   - encode: the decoded picture is drawn on a canvas of `w' × h'` and
     `toBlob`'d as its own type: JPEG → `image/jpeg` and WebP → `image/webp`,
     each with `quality`; PNG → `image/png` without a quality argument.
     `OffscreenCanvas` may be used when present, otherwise a
     detached `<canvas>`. Decode and encode together hold one of two slots: at
     most two pictures at a time, the others wait in one queue; the first ready
     picture takes the next free slot. Close the bitmap afterwards. Known
     limit: a decode that never returns holds its slot until the Panel page is
     left; the items behind it go up as they are after the time limit. The time limit runs from the selection, the wait in
     the queue included; a decode that finishes after the limit frees its slot
     only then, and its result is dropped. An item that left the dialog while
     it waited is not decoded at all, and one that left it during the decode
     is not encoded; a decode already running holds its slot until it ends. A
     cancelled selection therefore delays the next one by at most the decodes
     in flight.
   - if the browser hands back another type than requested (no WebP encoder
     gives PNG), no blob at all, or the encoder throws, keep the original,
     return.
   - if the result is **not smaller** than the original: keep the original, return.
   - if the item is no longer in `upload.files` (the dialog was cancelled or
     the item removed): return. No object URL is created, nothing is removed
     and no message is shown, for an undecodable result as well.
   - replace: `item.src = new File([blob], original.name, { type, lastModified: original.lastModified })`.
     The name is the one `original` has (after step 5b, if it was renamed).
     `keepOriginal(item)` records `item.tegaruOriginal` before `item.src` changes.
     Then `item.size`, `item.niceSize` (Kirby's format: `983KB`, `1.1MB`),
     `item.type`; revoke the old `item.url` and set a new object URL. Set
     `item.tegaru = { from: <bytes>, to: <bytes> }`.
   - 5b. Camera name (`renameCapture`, run in every case with the settings in
     hand, before the size check, so a small capture is renamed too): a `jpeg`
     whose name is `image.jpg` or `image.jpeg` (any case) and `active` on gets
     `cameraName(item.tegaruStamp, taken)`, i.e. `foto-<stamp>.jpg`, where
     `stamp` is the device's local time `YYYY-MM-DD-hh-mm-ss` taken in the
     `select` wrapper (item 2), and `taken` is the other items' names. While a
     name is taken (case-blind), `-2`, `-3` … goes before the extension. The
     item gets a new `File` with the same bytes and `lastModified`; update
     `item.name` (without extension), `item.extension` (`jpg`) and
     `item.filename`; `item.url` is not changed. A name that is not a camera
     name, or content that is not JPEG (a PNG named `image.jpg`), keeps its name.
     A name the user typed in the open dialog after the selection (`item.name`
     differs from `item.tegaruName`, set in item 2) is kept as well: the
     answer of the options may come later, and the rename is then skipped.
     Kirby's duplicate filter (item 2) still sees a re-picked original through
     `item.tegaruOriginal`.
6. **Notification** once per `select` call after all its items settled, only
   if at least one item was replaced and is still in `upload.files` (a dialog
   closed meanwhile gets no message): `panel.notification.success` with
   `tegaru.panel.resizedOne` (one item) or `tegaru.panel.resized`
   (`{count}`, `{from}`, `{to}` as formatted sizes, summed). Use
   `app.$t(key, values)` / `window.panel.$t` for the texts; never hard-code
   a sentence in the script.
7. **`removeItem(item)`**: `upload.remove(item.id)` if it exists (Kirby filters by
   `id`), otherwise splice it out of `upload.files` and revoke its URL.
8. **Pure helpers**, reached by the tests through the harness (`tests/tegaru-js.test.mjs`):
   `sniff(ArrayBuffer|Uint8Array) → string`, `targetSize(w, h, maxEdge) →
   {width, height, scale}`, `isCameraName(name) → bool`, `stamp(Date) →
   'YYYY-MM-DD-hh-mm-ss'`, `cameraName(stamp, taken) → 'foto-<stamp>.jpg'`
   (`-2`, `-3` … before the extension while taken, case-blind),
   `formatBytes(n) → string`, `decide({type, size, width, height},
   options) → 'skip' | 'resize'` (JPEG, PNG and WebP are 'resize' at or above
   `minBytes`, at any size; everything else, HEIC included, is 'skip'). No DOM,
   no side effects.

### Tests (`node --test tests/*.test.mjs`, no dependencies)

Load `index.js` with `node:vm` into a context that provides a fake
`window.panel` (`plugin` capturing the extension, `$t` returning the key
plus JSON of the values, `notification.{success,info,error}` recording
calls) and a fake `app` with `$panel.upload` (`files`, `select`, `submit`,
`remove`) and `$api.get` returning the options. Then:

- `sniff`: JPEG, PNG, GIF, WebP, AVIF, unknown; HEIC brands (`heic`, `mif1`) are `other`.
- `targetSize`: 4032×3024 → 2560×1920 scale 0.635; 1000×800 → unchanged scale 1; 3024×4032 → 1920×2560.
- `isCameraName`, `stamp`, `cameraName`: `image.jpg`, `IMAGE.JPEG` and `image.jpeg` are camera names, `myimage.jpg` is not; the stamp has leading zeros; `cameraName('2026-10-20-14-32-05', [])` → `foto-2026-10-20-14-32-05.jpg`; with a taken `FOTO-…JPG` → `-2` (case-blind).
- `decide`: small JPEG under `minBytes` → skip; big JPEG → resize; PNG above maxEdge → resize; PNG below maxEdge → resize; PNG under minBytes → skip; WebP big → resize; HEIC any size → skip; GIF → skip; `active: false` → skip.
- camera captures: `image.jpg` is renamed to `foto-<clock>.jpg` (the clock is fixed in the tests) and resized as before; under `minBytes` it is renamed too; two in one second get `-2`, `-3`; `active: false` and unknown settings keep the name; a PNG named `image.jpg` keeps its name; a name typed while the options are still awaited is kept when the answer arrives.
- wrapping: after `select` with two fake items, `submit` waits for the
  pending promises (order observable through a log); a rejecting `prepare`
  does not stop `submit`; `select` is still synchronous (dialog state
  changes before any `await`); items get `item.tegaru` exactly once.
- semaphore: five large JPEGs with a decode gate; the log shows two decodes
  started and the others waiting; each next decode starts when a slot frees,
  in the order they became ready; never more than two at once.
- late item: an item selected while `submit` waits is waited for too.
- time limit: `TIME_LIMIT` set to 10 ms (harness `setTimeLimit`) and a decode that never
  settles: `submit` still runs and the item is the original. A result that
  comes after the limit changes nothing. A picture past the limit goes up as
  it is, without a message; a HEIC is never decoded and goes up unchanged.
- PNG under maxEdge: re-encoded at its own size; kept when not smaller.
- WebP: re-encoded as WebP with the quality setting; a browser that hands back
  PNG for WebP leaves the original.
- dialog closed before the pictures are ready: no notification.
- no feature: `upload` without `select` → `console.info` once, nothing wrapped.
- the `canvas`/`createImageBitmap` path is not run under Node; test the
  decisions and the wrapping, not the pixels. The pixel path is accepted on
  the device against the table above (manual test, see Acceptance).

CI additionally runs `node --check index.js` and greps for storage, foreign
hosts and `eval` — keep all three out.

## Section B — Server safety net (`src/Guard.php`, `tests/`)

Files: `src/Guard.php` (replace the stub, keep the
signatures), `tests/GuardTest.php`, `tests/OptionsTest.php`,
`tests/I18nTest.php`, `tests/PanelSignatureTest.php`, `tests/KirbyTest.php`.
Pattern for tests: PHPUnit test classes with the `expect()`/`holds()` helpers
of `tests/helpers.php`. KirbyTest boots a throwaway Kirby with the plugin.

The hook (`Hooks::guard()`, registered in `config/hooks.php`) runs on `file.create:before` and on
`file.replace:before`. Both pass `(File, Upload)`; the upload is the file in PHP's
tmp directory (for a replacement: the source of `replace()`).

### `Guard::inspect(string $path, int $maxBytes, int $maxPixels): ?array`

Kirby-free. Returns `null` (allowed) or `['key' => …, 'values' => […]]`:

1. `filesize($path) > $maxBytes` → `error.size` with `size` and `max` in MB,
   one decimal (`'3.7'`, `'15.0'`), as strings, point as decimal separator
   (the translation does not localise numbers yet).
2. first 32 bytes: `Guard::sniff($head)`; `heic` → `error.heic`, no values.
3. `jpeg|png|gif|webp` → `getimagesize($path)`; if it fails → `null`
   (Kirby's own checks take over); if `width × height > $maxPixels` →
   `error.pixels` with `megapixels` and `max` in MP, one decimal.
4. anything else → `null`.

`Guard::sniff(string $head): string` — magic bytes as in the Panel script:
`FF D8 FF` jpeg; `89 50 4E 47 0D 0A 1A 0A` png; `GIF8` gif; `RIFF….WEBP`
webp; `ftyp` at offset 4 with brand in `Guard::HEIC_BRANDS` → heic, brand
`avif`/`avis` → avif; everything else `other`. Never reads more than 32 bytes.

### Tests

- `GuardTest.php`: generate fixtures at runtime with GD (`imagecreatetruecolor`
  + `imagejpeg`/`imagepng`/`imagewebp`), a GIF and a fake HEIC (12-byte
  header `00 00 00 18 66 74 79 70 68 65 69 63` + zeros), a text file.
  Check every branch, the order (a huge HEIC reports `size` first), the
  number formatting, and that a file that `getimagesize` cannot read passes.
  Skip the WebP case with a note if the GD build lacks `imagewebp`.
- `OptionsTest.php`: a throwaway `Kirby\Cms\App` with options; defaults,
  flat and nested spelling, invalid values (string, negative, quality 1.5)
  fall back, `forPanel()` shape and `format`, `language()` from the option,
  from the site language, from `locale`, fallback `en`.
- `I18nTest.php`: same keys and the same `{placeholders}` in every language,
  unknown language → English, `translate()` replaces values, missing key → key,
  `t()` gives the same text and is reported as a deprecation in debug mode.
- `PanelSignatureTest.php`: finds the installed Kirby under `vendor/getkirby/cms`,
  asserts `panel/dist/js/plugins.js` exists and mentions `created`, and that
  `panel/dist/js/index.min.js` contains the method names `pick(`, `select(`,
  `submit(`, `upload(` and the string `plugins.created` (or the equivalent
  loop that calls them). The test prints the Kirby version it checked.
- `KirbyTest.php`: throwaway Kirby instance with the plugin loaded.
  `require index.php` **before** `new App(...)` — `App::plugin()` fills a
  static registry that the App reads while it is built; registered later,
  the routes and translations are missing (verified on Kirby 5.6.1). Pass
  `roots` to a temp dir and `options`: (a) `sayamaapps.tegaru.active` off → `Hooks::guard()` returns for a 20-MB
  temp file; (b) on → throws `InvalidArgumentException` whose message is the
  German text when the site's default language is `de`, English otherwise;
  (c) the plugin translations contain `tegaru.error.size` in `en` and `de`;
  (d) `Options::forPanel()` through the real app reflects the options;
  (e) the API route `tegaru/options` is registered (inspect
  `$kirby->extensions('api')['routes']` or call the closure) and returns the
  array; (f) the hook `file.create:before` is registered; (g) `file.replace:before`
  is registered and a 20-MB replacement is refused with the size message; (h)
  the options route throws `AuthException` without a login and answers with
  one. The temp dir is removed in a shutdown function, because Kirby commits its
  sessions in one.

## Section C — Documentation (`README.md`, `CHANGELOG.md`)

Files: the two above (README replaces the scaffold
text). Style and structure follow the Kirby plugin conventions: short
intro with the Japanese word, Requirements, Installation (`composer require
sayamaapps/kirby-tegaru`, the `repositories` entry while the package is not
on Packagist), Configuration (the options table above), How it works (the
extension point in three sentences, what leaves the device, what the
server rejects), Blueprints (recommend `accept: mime: image/jpeg, image/png,
image/webp` instead of `type: image` — a restricted `accept` list is the HEIC
strategy: iOS Safari hands over JPEG for library photos, verified on an iPhone
(Android not measured) — and `create: { width: 2400,
height: 2400, format: jpg }` as the server-side rule for uploads the script
never sees), Browser support (Safari iOS/macOS, Chrome, Firefox: canvas
path; HEIC is never converted), Limits (no server resizing,
no HEIC conversion anywhere, GIF/SVG/PDF untouched), Development
(`composer install`, `composer test`, `node --test tests/`).

No privacy text: Tegaru processes no visitor data. The README's Privacy section says so in three sentences.

The metadata behaviour of Kirby's two image drivers: GD writes no EXIF block; ImageMagick is called with `-strip`
for PNG only (`Darkroom/ImageMagick.php`, `strip()`), so JPEG keeps EXIF and GPS.
Every case in which a photo goes up unchanged is listed here; the README's Limits section names some of them:

- every photo while `tegaru.active` is off,
- files below `minBytes` (1 MB by default; `0` processes every file),
- files the browser cannot read as an image, HEIC photos among them,
- HEIC photos, and other formats such as GIF, AVIF, SVG and PDF,
- JPEG, PNG or WebP photos whose re-encoded version would not be smaller than the original, or whose encoding fails in the browser,
- WebP photos when the browser cannot encode WebP,
- every photo when the settings cannot be fetched or the browser lacks what the script needs,
- photos that are still not ready 20 seconds after they were picked.

A camera capture renamed to `foto-<time>.jpg` keeps its bytes; its name shows
only the time of selection.

The server checks apply in every case.

`CHANGELOG.md`: keep the rules block; `[1.0.0]` describes the package in the
Added/Changed structure of the sibling packages.

No customer names, no site names, no "measured at <site>" — the
measurements are quoted as device + browser only.

## Section D — Empty files field opens the picker (`index.js`, `tests/tegaru-js.test.mjs`, `tests/PanelSignatureTest.php`, `README.md`, `CHANGELOG.md`)

Kirby's files field (`k-files-field`, extending `k-models-field`) calls
`open()` both from its empty state (`k-collection` event `empty`) and from its
Select button; `open()` lists the files already there (`k-files-dialog`).
Only the small Upload button calls `$panel.upload.pick(uploadOptions)`, which
on a phone offers the photo library and the camera. People tap the large
empty area. Rule: every empty photo field opens the picker.

1. **Register** `components: { 'k-files-field': { extends: 'k-files-field', … } }`
   in the same `panel.plugin` call. Kirby replaces the `extends` string with
   its registered component before Vue sees it; Kirby's own members are read
   from there (`extends.options.methods.open`, `extends.options.computed.buttons`,
   a computed with `get` read through its getter). Kirby logs
   `Plugin is replacing "k-files-field"`.
2. **`open()`**: picks (`this.$panel.upload.pick(this.uploadOptions)`) when
   all hold: the settings have come and `active` is true; `hasDropzone` (not
   disabled, below `max`, uploads allowed); `uploadOptions`; `upload.pick` is a
   function; the field's buttons still have Kirby's Select button (icon
   `checklist`). Otherwise Kirby's `open()`; without one, `null`. The picker
   opens only within the tap, so the tap never waits for the settings: before
   they come, the tap lists the files.
3. **`buttons`**: Kirby's buttons, the Select button's `click` bound to
   Kirby's `open()`, so it keeps listing the files already there. Order,
   texts and the Upload button stay Kirby's. Without Kirby's `buttons`: `[]`.
4. **`mounted`**: asks for the settings (item 4 of section A) when `attach`
   ran; a failure is swallowed and asked again later.
5. **Tests** (fake Kirby members as above): active → pick with the field's
   `uploadOptions`, Kirby's `open()` not called; Select lists, Upload picks;
   one settings request for several fields and a `select`; inactive, settings
   pending, settings failed (next field asks again), no uploads, disabled,
   full, no Select button → Kirby's `open()`; Kirby without `open`/`buttons`
   does not throw; without the upload module nothing is asked for; a computed
   with getter and setter. `PanelSignatureTest.php` checks the bundle for the
   `extends` resolution, `k-files-field`, `hasDropzone`, the Upload button's
   `pick(this.uploadOptions)`, the Select button (`checklist`, `open()`) and
   `on:{empty:…open`, with patterns that allow other minified names.
6. **Acceptance**: Playwright against a local Kirby 5.6 (Chromium, WebKit with
   an iPhone profile): active → tap on the empty field opens the file chooser,
   the photo lands in the field; Select opens the list; inactive → the empty
   field opens the list. On a real iPhone: the sheet offers photo library and
   camera.

## Acceptance (after A–C)

1. CI green on PHP 8.2–8.5 and the Node job.
2. Device test with a 24-MP photo in Safari on a phone against a local
   Kirby (`composer start` of a starter site with the plugin): dialog shows
   ≈ 1 MB, upload lands as 1800 × 2400 JPEG, `exif_read_data` finds no GPS,
   image upright in the Panel and on the page. Same photo with
   `tegaru.active` off lands byte-identical.
3. Chrome on a desktop with a HEIC file: the file goes up unchanged and the
   server refuses it. A PNG screenshot above 2400 px: resized, still PNG. A
   camera capture `image.jpg` arrives as `foto-<time>.jpg`.
4. Server net: a 20-MB JPEG via the API with `active` on → error text in
   the site language; the same with `active` off → Kirby's own behaviour.
5. Then `[1.0.0] — <date>` and the tag.
