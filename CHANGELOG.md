# Changelog

All notable changes to this project are documented here. Versions follow
[Semantic Versioning](https://semver.org) with these project rules:

- **A change to what leaves the device** (more bytes, metadata kept, a new
  file type converted) is at least a **minor** version and described under
  "Reach" so a site operator can re-check their blueprints.
- The options route (`/api/tegaru/options`) carries `format`; it increases
  only when a field is removed, renamed or changes meaning.

## [Unreleased]

### Fixed

- **A name typed in the dialog is kept for a camera capture.** The camera name `foto-<time>.jpg` was applied when the options answer arrived, even if the name had been changed in the open dialog meanwhile. The rename is now skipped once the name differs from the one Kirby gave the item at selection.

## [1.1.0] — 2026-10-25

### Added

- **Upload from the empty files field.** While Tegaru is active, a tap on an empty files field opens the device's picker, as the field's Upload button does, so a phone offers its photo library and camera. Select still lists the files already there. Fields without uploads, disabled or full keep Kirby's behaviour. The package's tests fail when a Kirby release changes the members this relies on.

### Removed

- **`DATENSCHUTZ.md` and `PRIVACY.md`.** Tegaru processes no visitor data, so it has no place in a site's privacy policy. The README says so under "Privacy".

## [1.0.0] — 2026-10-20

### Added

- Panel script: photos picked for upload are decoded in the browser, rotated
  as their EXIF orientation says, scaled to a longest edge of 2400 px and
  re-encoded in their own type before the upload starts: JPEG and WebP at
  quality 0.85, PNG as PNG. A re-encoded picture that is not smaller than the
  original is not used. The re-encoded
  file carries no EXIF and therefore no
  GPS position. GIF, HEIC, AVIF and other formats are left alone, and so are
  files under 1 MB.
- Panel script: HEIC photos are not converted or removed in the browser, in any
  browser. They go up unchanged, and the server refuses them. The blueprint's
  `accept` list without HEIC keeps them out of the picker; this is the documented
  HEIC strategy (see README, Blueprints).
- Panel script: a camera capture named `image.jpg` or `image.jpeg` (any case) is
  renamed when it is picked to `foto-<local time>.jpg`, for example
  `foto-2026-10-20-14-32-05.jpg`, so that a second capture on the same page does
  not clash with the first. The time is the device's local time at the selection,
  to the second. Only JPEG content is renamed, and only while Tegaru is active.
  Two captures in the same second get `-2`, `-3` … before the extension, as other
  same names in one upload do. Other names are kept.
- Panel script: `submit` waits until no photo is still being prepared, including
  photos selected while it waits. At most two photos are decoded and encoded at
  a time; the others wait in one queue, and the first ready photo takes the next
  free slot.
- Panel script: a photo that is still not ready 20 seconds after it was picked
  goes up as it is; a late result is discarded.
- Panel script: one notification per selection, once the photos are ready,
  with the sizes before and after. Photos whose dialog was cancelled meanwhile
  (Kirby empties its list) are not counted.
- Panel script: feature detection. If Kirby's upload module lacks `select` or
  `submit`, the script logs one `console.info` and changes nothing. Without
  `reset` it runs and notices a cancel through the dialog check or the poll;
  without the Panel's dialog store it runs, logs one `console.info`, and a
  dialog closed by Escape is not detected.
- Server: hooks `file.create:before` and `file.replace:before` reject uploads
  above a byte cap (15 MB) or a pixel cap (40 megapixels), and HEIC/HEIF files,
  with a message in the site's language. No server-side resizing — that stays
  with the blueprint's `create:` option.
- Options `tegaru.active` (off by default), `maxEdge`, `quality`, `minBytes`,
  `maxUploadBytes`, `maxPixels`, `language`. Invalid values fall back to the
  default.
- Route `GET /api/tegaru/options` for the Panel script, Panel login required,
  answering with `format: 1`.
- Messages in English and German, registered as Kirby translations `tegaru.*`.
- **[privacy text]** `DATENSCHUTZ.md` (German, authoritative) and `PRIVACY.md`
  (English summary): what the browser and the server do with a photo, what the
  file no longer contains, and what stays when a file is not re-encoded.
- Tests: standalone PHP scripts (guard, options, translations, Panel signature,
  throwaway Kirby) and Node tests for the Panel script, run in CI on PHP
  8.2–8.5 and Node.

### Changed

- Panel script: the upload button uploads what the dialog holds once its
  photos are prepared; photos added to the open dialog while it waits go up
  with the same click. Nothing is uploaded by that click if meanwhile the
  dialog was cancelled (Kirby's `reset`, wrapped for that signal and run
  unchanged) or closed otherwise (the Panel dialog of the click is no longer
  the open one), or another upload was started (only the latest click
  uploads). A photo picked after that waits for its own click, and no photo
  goes up twice. While a click waits, the dialog is looked at again every
  100 ms, so a removed photo is not waited for.
- Panel script: a photo that left the dialog during its decode is not encoded.
- Panel script: a photo picked again after its item was prepared is dropped
  silently and the prepared item stays (Kirby's own duplicate filter can no
  longer see the match once the file is replaced; the original's name, type,
  size and modification time are kept on the item for that). Kirby itself
  revokes no preview URL when its filter or `max` drops an item; Tegaru
  revokes only what it removes or replaces.
- Panel script: a cancelled selection is let go. No message for a photo whose
  dialog was cancelled before its preparation ended (undecodable photos
  included), a photo that left the dialog while it waited for a slot is not
  decoded, and `submit` waits only for the photos the dialog still holds.
- **[privacy text]** The list of photos that go up unchanged names two more
  cases: every photo while `tegaru.active` is off, and a JPEG, PNG or WebP
  whose encoding fails in the browser.
- **[privacy text]** The texts name only the API and server-side imports as
  uploads that bypass the script: Kirby's drop and its file dialog both call the
  `select` that Tegaru wraps.

- **[privacy text]** The privacy texts state the metadata of a server-side
  resize by Kirby's driver. With the GD driver (Kirby's default) it writes no
  EXIF block; with the ImageMagick driver only PNG files are stripped, so JPEG
  files keep EXIF and GPS. The texts also list every case
  in which a photo goes up unchanged: the settings cannot be fetched, the browser
  lacks what the script needs, or WebP cannot be encoded.
- **[privacy text]** The list of photos that go up unchanged is the same in
  SPEC, README, PRIVACY.md and DATENSCHUTZ.md, and includes a photo still not
  ready 20 seconds after it was picked.
- **[privacy text]** HEIC photos are not converted or removed in the browser:
  they go up unchanged, and the server refuses them, so they are not stored.
- **[privacy text]** A camera capture named `image.jpg` or `image.jpeg` is
  renamed to `foto-<local time of the selection>.jpg`. The name then shows the
  date and time of the selection, nothing about the place or the device; the
  content is not changed.
- **[privacy text]** A PNG under `maxEdge` is re-encoded too, so it goes up
  without its metadata.
- **[privacy text]** The statement on HEIC from the Safari side is verified on a
  device: an iPhone with Safari handed over a photo from the photo
  library as a JPEG, with the `accept` list restricted and HEIC not named, with no
  EXIF or GPS data (one measurement). Android is not measured
  (DATENSCHUTZ.md, PRIVACY.md, README).
- **[privacy text]** The longest edge of 2400 px is named as the default, which
  the operator can change.

### Reach

- Re-check blueprints and privacy text for: JPEG, PNG and WebP photos are
  re-encoded in the browser at `maxEdge` and `quality` (PNG without quality);
  the size and pixel caps now also apply to replaced files.
- A PNG under `maxEdge` is re-encoded in the browser too, so it goes up without
  its metadata.
- A photo still not ready 20 seconds after it was picked goes up unchanged,
  metadata included.
- HEIC photos go up unchanged in every case and the server refuses them. The
  blueprint's `accept` list (README, Blueprints) keeps them out of the picker.
- Camera captures named `image.jpg` or `image.jpeg` reach the server as
  `foto-<time>.jpg` while Tegaru is active. Anything that looks for the camera name
  needs the new one.
