# Tegaru

Lighter photo uploads from the phone into the [Kirby CMS](https://getkirby.com) 5 Panel.

*Tegaru* (手軽) is Japanese for "easy, light to handle". A 24-megapixel photo from a phone weighs 3–4 MB and often carries the GPS position of where it was taken. Tegaru shrinks it in the browser before the upload starts (longest edge 2400 px, quality 0.85, rotation applied), so about 1 MB travels over the mobile network, and the re-encoded file carries no location data. On the server a small safety net rejects files that are still too big, have too many pixels, or are HEIC.

- **Smaller before the upload.** The Panel script decodes the photo, applies its EXIF rotation, scales the longest edge to 2400 px and re-encodes it in its own format: JPEG and WebP at quality 0.85, PNG as PNG. The upload dialog shows the new size. HEIC is not converted in the browser; see [Blueprints](#blueprints).
- **No location in the re-encoded file.** A file that the browser re-encodes has no EXIF block: no GPS position, no camera model, no time of the shot. The original on the phone is not changed. Exceptions are listed under [Privacy](#privacy).
- **Unique names for camera captures.** A JPEG that the phone's camera names `image.jpg` (or `image.jpeg`, any case) is renamed when it is picked to `foto-<local time>.jpg`, for example `foto-2026-10-20-14-32-05.jpg`. Two captures on the same page then no longer clash in Kirby. Other names stay as they are.
- **Safety net on the server.** A hook on `file.create:before` and `file.replace:before` rejects files above a byte cap (15 MB), above a pixel cap (40 megapixels) and HEIC/HEIF files, with a message in the site's language. The server never resizes.
- **Off by default.** Without `'tegaru.active' => true` nothing changes: the script reads the first bytes of each picked file locally to learn its type, then asks the server once and stops.
- **No build step.** `index.js` is one plain ES2017 script that Kirby loads from the plugin root. No npm, no bundler, no modules.
- **The Panel stays Kirby's.** Tegaru wraps three functions of Kirby's upload module, `select`, `submit` and `reset` (the last only to learn that the dialog was closed), and changes nothing else. No component is replaced and the dialog is Kirby's own.
- **English and German.** See [Configuration](#configuration) for which language is used where.
- **Nothing stored, no third party.** No cookie, no browser storage, no request to any other host, no external service. The Panel script asks only its own server for the options.

## Requirements

- Kirby 5, PHP 8.2–8.5
- Nothing else: no build step, no Node, no external service. Node is needed only for the script tests (see [Development](#development)).

## Installation

```sh
composer require sayamaapps/kirby-tegaru
```

Composer installs the plugin into `site/plugins/tegaru/` (via `getkirby/composer-installer`). Add `/site/plugins/tegaru/` to `.gitignore` and make sure your deploy ships it, just as it ships `kirby/` and `vendor/`.

Until the package is on Packagist, add the repository first:

```json
"repositories": [{ "type": "vcs", "url": "https://github.com/sayamaapps/kirby-tegaru" }]
```

Everything is off by default. Switch it on in `site/config/config.php`:

```php
return [
    'tegaru.active' => true,
];
```

## Configuration

All keys are read as `tegaru.<name>`, flat or nested (`'tegaru' => ['active' => true]`). `null` counts as "not set".

| Key | Default | Meaning |
|---|---|---|
| `active` | `false` | Off by default: hook lets everything through, script changes nothing. |
| `maxEdge` | `2400` | Longest edge of the browser-side result, px. |
| `quality` | `0.85` | Quality 0.1–1.0 of the browser-side JPEG and WebP results. PNG has no quality setting. |
| `minBytes` | `1048576` | Files below this size are left alone in the browser (a 400-kB JPEG does not get worse). `0` = always process. |
| `maxUploadBytes` | `15728640` | Server cap per file (15 MB). |
| `maxPixels` | `40000000` | Server cap per raster image, width × height (40 MP). |
| `language` | site default language | `en` or `de` for the server messages. Without it: the site's default language, then Kirby's `locale`, then English. The messages in the Panel follow the Panel's own language. |

Invalid values fall back to the default (non-int, ≤ 0, quality outside 0.1–1.0); see `src/Options.php`.

The Panel script reads these values from `GET /api/tegaru/options`. The route needs a logged-in Panel user (Kirby's default API authentication), so nothing about the configuration reaches visitors:

```json
{"format":1,"active":true,"maxEdge":2400,"quality":0.85,"minBytes":1048576,"maxUploadBytes":15728640,"maxPixels":40000000,"language":"de"}
```

`format` increases only when a field is removed, renamed or changes meaning.

## How it works

Kirby 5 bundles uploading in the reactive module `window.panel.upload`, and there is no hook that transforms a file before it is sent. Tegaru wraps that module's `select`, `submit` and `reset`: after the photos are picked, each item's file is replaced by the smaller result, and `submit` waits until no photo is still being prepared. Decoding and encoding run for at most two photos at a time; the others wait their turn, and the first ready photo takes the next free slot. Each photo has 20 seconds to be prepared; one that is not ready by then goes up as it is. The settings are requested once per Panel session. If the answer does not come, it is not requested again, and the photos go up unchanged after the time limit. If the request fails, the next selection asks again. The upload button waits for the photos that are in the dialog; photos added to the open dialog meanwhile are prepared and go up with the same click. If the dialog is cancelled or closed while it waits, or another upload is started meanwhile, nothing is uploaded by that click, and no photo goes up twice. The dialog keeps working because it reads only the item fields (name, size, preview), not the file itself.

**What leaves the device.** Only the resulting file travels to the server, through Kirby's own upload request. Decoding and re-encoding happen in the browser, nothing is sent to another host, and nothing is stored in the browser.

Measured on a current iPhone, Safari, at `maxEdge` 2560 and quality 0.85:

| Input | Measured at 2560 / 0.85 | Time incl. decoding |
|---|---|---|
| 12.2 MP JPEG, 2.2–2.4 MB, orientation 6 | 983KB – 1.1MB, 1920 × 2560, upright | < 0.2 s |
| 24.5 MP JPEG, 3.5–3.7 MB, orientation 6 | 961KB – 1.1MB, 1920 × 2560, upright | < 0.2 s |
| 5 photos, 15.5 MB together | ≈ 5.3 MB | — |

The default `maxEdge` of 2400 was not measured. A 4:3 photo taken in portrait (3024 × 4032 after rotation) becomes 1800 × 2400 at 2400.

**What the server rejects.** The hook on `file.create:before` and `file.replace:before` checks the temporary file and rejects:

- files above `maxUploadBytes` (15 MB),
- raster images above `maxPixels` (40 megapixels, width × height),
- HEIC and HEIF files, which Kirby cannot resize or thumbnail.

The rejection shows as the upload error, in the server's language. The server never resizes. Size changes come only from the blueprint's `create:` option, see [Blueprints](#blueprints).

## Blueprints

Tegaru's browser part covers the Panel only. Two blueprint options cover the rest. Set them in the file blueprint of your photos, `site/blueprints/files/<template>.yml`:

```yaml
accept:
  mime: image/jpeg, image/png, image/webp
create:
  width: 2400
  height: 2400
  format: jpg
```

- **`accept`** lists the three types instead of `type: image`. This is the documented HEIC strategy, verified on an iPhone with Safari (Android not measured). With the list restricted and HEIC not named, Safari hands over a JPEG for a photo from the photo library: the test photo, a HEIC shot with the camera set to High Efficiency, arrived as a JPEG of 1800 × 2400, upright, with no EXIF or GPS data. A HEIC file from the Files app cannot be picked, as intended. With `type: image`, HEIC files can be picked (not measured on the device); they go up unchanged and the server refuses them.
- **`create`** is Kirby's server-side resize. It also covers what the script does not re-encode: uploads through the API or a server-side import, which never pass the Panel script, and photos the script leaves unchanged (a browser without canvas, files under `minBytes`; see [Privacy](#privacy)). Write it at the top level, as shown; under `options:` the key `create` is a permission setting.

## Browser support

- **Safari** (iOS and macOS): canvas path for JPEG, PNG and WebP.
- **Chrome** and **Firefox**: canvas path for JPEG and PNG. WebP is re-encoded as WebP where the browser can encode it; where it cannot, the WebP photo goes up unchanged.
- **HEIC** is left as it is in every browser; see [Blueprints](#blueprints).

The numbers above come from one device and browser (current iPhone, Safari). Other combinations are untested.

## Limits

- **No server-side resizing** by Tegaru. Use `create:` (see [Blueprints](#blueprints)).
- **Only the Panel's upload is changed.** Every file picked or dropped in the Panel passes the script: from the file dialog (on a phone also from the Files app) or dragged in from a desktop, since Kirby's drop calls the same `select` that Tegaru wraps. Files that come in through the API or a server-side import are not re-encoded.
- **No HEIC conversion.** Tegaru does not convert or remove HEIC or HEIF files, in any browser. The server refuses them. Keep them out of the picker with the blueprint's `accept` list (see [Blueprints](#blueprints)).
- **Other formats are left alone.** GIF, AVIF, SVG, PDF, HEIC and the like pass unchanged. PNG stays PNG and WebP stays WebP; both are only re-encoded in the browser.
- **Files under `minBytes`** (1 MB by default) are not re-encoded, metadata included. See [Privacy](#privacy).
- **Kirby's upload module is not a documented public API.** Tegaru checks for the functions it wraps and does nothing if they are missing (one `console.info`). A test in the package fails when a Kirby release removes them.
- **Images only.** Video and other media are not touched.
- **Same names across one upload.** Camera captures get `-2`, `-3` … before the extension when two of them would have the same name, in the same second of the same selection; the name is compared case-blind. Other same names are left to Kirby's own check in the dialog, as without Tegaru.
- **Replacing a file.** The replace dialog goes through the same script: a JPEG replaced by a JPEG is made lighter. A HEIC dropped onto a PNG or WebP is not converted; the server refuses it.
- **Duplicates in the dialog.** Kirby drops a photo picked twice by comparing name, type, size and modification time. Once Tegaru has replaced a photo by its smaller version, Kirby cannot see that any more, so Tegaru keeps the original's name, type, size and modification time on the item and drops a re-picked original itself, silently; the prepared photo stays.

## Privacy

[PRIVACY.md](PRIVACY.md) is the English summary of what happens in the browser, what the file no longer contains and what the server does. [DATENSCHUTZ.md](DATENSCHUTZ.md) is the German text block for a site's privacy policy, and it is the authoritative version. Both describe the implementation and are not legal advice.

Files that Tegaru does not re-encode keep everything they contain, GPS included, in these cases:

- every photo while `tegaru.active` is off,
- files below `minBytes` (1 MB by default; `0` processes every file),
- files the browser cannot read as an image, HEIC photos among them,
- HEIC photos, and other formats such as GIF, AVIF, SVG and PDF,
- JPEG, PNG or WebP photos whose re-encoded version would not be smaller than the original, or whose encoding fails in the browser,
- WebP photos when the browser cannot encode WebP,
- every photo when the settings cannot be fetched or the browser lacks what the script needs,
- photos that are still not ready 20 seconds after they were picked.

Renaming a camera capture (see the feature list above) does not change its content: left unchanged, it keeps its metadata, GPS included; re-encoded, it has none. The new name shows the date and time of selection, and nothing about the place or the device.

The server's size, pixel and format checks still apply in every case. Uploads that bypass the Panel script reach the server unchanged, apart from `create:`. Whether `create:` removes metadata depends on Kirby's image driver, see [DATENSCHUTZ.md](DATENSCHUTZ.md).

## Development

```sh
composer install                 # Kirby is a dev dependency (test fixture); the tests need the gd extension
sh tests/run.sh                  # or: composer test
node --test tests/*.test.mjs     # the Panel script against a fake Panel, no dependencies
```

The PHP tests are plain scripts (exit code 0 = pass), not PHPUnit. `KirbyTest.php` boots a throwaway Kirby with the plugin, and `PanelSignatureTest.php` checks that the installed Kirby's Panel still has the methods Tegaru wraps. None of the tests needs a network connection.

## License

MIT, see [LICENSE](LICENSE).
