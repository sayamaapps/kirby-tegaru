# Tegaru: privacy notes

This is an English summary of [DATENSCHUTZ.md](DATENSCHUTZ.md), the German text
block for privacy policies, which is the authoritative version. Both describe
the implementation. They are not legal advice. Each operator has to determine
the legal basis and the wording for their own site.

## Photos uploaded through the Panel

Where the browser can process a photo, it is resized there before the upload
starts: decoded, rotated as its EXIF orientation says, scaled to a longest edge
of at most 2400 px (the default; the operator can change it) and re-encoded in
its own format. JPEG and WebP are encoded at quality 0.85 and PNG stays PNG.
HEIC photos are not resized by the browser; they go up unchanged (see below).
Only the new file is sent to the
server. The original on the device is not changed. The operator may change the
size and quality settings.

The re-encoded file has no EXIF block. It contains no GPS position, no camera
model and no time of the shot. The processing happens in the browser. The only
other request is the one for the settings, to the site's own server. Nothing is
stored in the browser.

A camera capture that the phone names `image.jpg` or `image.jpeg` is renamed
when it is picked, to `foto-YYYY-MM-DD-hh-mm-ss.jpg`, using the local time of
the selection. The name then shows the date and time of the selection and
nothing about the place or the device. Renaming does not change the content.

## When metadata stays

A file that Tegaru does not re-encode reaches the server with everything it
contains, GPS position included:

- every photo while `tegaru.active` is off,
- files below `minBytes` (1 MB by default; `0` processes every file),
- files the browser cannot read as an image, HEIC photos among them,
- HEIC photos, which the browser does not convert, and other formats such as GIF, AVIF, SVG and PDF,
- JPEG, PNG or WebP photos whose re-encoded version would not be smaller than the original, or whose encoding fails in the browser,
- WebP photos when the browser cannot encode WebP,
- every photo when the settings cannot be fetched or the browser lacks what the script needs,
- photos that are still not ready 20 seconds after they were picked.

Uploads that do not go through the Panel script (the API, a server-side import)
are not resized in the browser. A file picked or dropped in the Panel does go
through it, also one from a phone's Files app or dragged in from a desktop.
Only the blueprint's
server-side `create:` option resizes them. Whether it removes the metadata
depends on Kirby's image driver: with GD (Kirby's default) no EXIF block is
written; with ImageMagick, Kirby strips metadata from PNG files only, so JPEG
files keep their EXIF block, including the GPS position (Kirby 5, option
`thumbs.driver`).

## On the server

The server checks the uploaded file for size (15 MB by default), pixels
(40 megapixels by default) and format: HEIC and HEIF files are refused, because
Kirby cannot process them. The check also applies when a file is replaced.
Tegaru does not change the file on the server and stores nothing of its own;
the file is kept in Kirby's own storage, as any upload is. No third party and no
external service is involved.

## Operator notes

- Files below `tegaru.minBytes` (1 MB by default) are not re-encoded in the
  browser, and their metadata stays. Set it to `0` to process every file.
- HEIC: Tegaru neither converts nor removes HEIC photos. With the blueprint's
  `accept` list limited to JPEG, PNG and WebP (see the README), the picker does
  not offer HEIC files from the Files app. On an iPhone with Safari, a photo from
  the photo library then arrives as a JPEG that Safari converted itself. This was
  checked on one device on 20 October 2026; Android was not measured. In the
  test that photo arrived without EXIF or GPS data (one measurement). A HEIC file
  that does get into the upload goes up unchanged, and the server refuses it;
  it is not stored.
- The processing concerns uploads in the Panel. For visitors of the website
  nothing changes.
