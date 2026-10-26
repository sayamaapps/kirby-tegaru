# Tegaru

Lighter photo uploads from the phone into the [Kirby CMS](https://getkirby.com) 5 Panel.

**Versions:** Kirby 5 · PHP 8.2 to 8.5 · MIT · [Changes](CHANGELOG.md)

*Tegaru* (手軽) is Japanese for "easy, light to handle". A 24-megapixel phone photo weighs 3–4 MB and often carries its GPS position, so Tegaru resizes it in the browser before the upload starts. The upload is typically about 1 MB, and the re-encoded file carries no location data.

## Features

- **Smaller uploads.** Photos are resized in the browser (longest edge 2400 px, EXIF rotation applied).
- **No location data.** Re-encoded photos carry no EXIF or GPS block.
- **Unique camera captures.** `image.jpg` (or `image.jpeg`) is renamed to `foto-<local time>.jpg` while active.
- **Upload from the empty field.** A tap on an empty files field opens the device's picker (photo library and camera on a phone) instead of the list of files already there.
- **Server safety net.** Byte cap (15 MB), pixel cap (40 megapixels) and HEIC/HEIF files are rejected.
- **Off by default.** No cookies, no browser storage, no request to any other host.
- **English and German.** The Panel keeps Kirby's own upload dialog.

## Requirements

- Kirby 5 and PHP 8.2 to 8.5.
- A browser with canvas support. Measured on iPhone Safari only; other browsers are untested.

## Installation

Pick one of three ways. The plugin always ends up in `site/plugins/tegaru/`.

1. **Composer.** The installer puts the plugin into `site/plugins/tegaru/`:

   ```sh
   composer require sayamaapps/kirby-tegaru
   ```

   Add `/site/plugins/tegaru/` to `.gitignore` and deploy it like `vendor/`.

2. **ZIP.** Download the source archive of a release from GitHub, unpack it and move the folder to `site/plugins/tegaru/`.

3. **Git submodule.** From the root of your site:

   ```sh
   git submodule add https://github.com/sayamaapps/kirby-tegaru.git site/plugins/tegaru
   ```

## Usage

1. **Switch it on.** Everything is off by default. In `site/config/config.php`:

   ```php
   return [
       'sayamaapps.tegaru.active' => true,
   ];
   ```

2. **Set the photo blueprint.** In `site/blueprints/files/<template>.yml`:

   ```yaml
   accept:
     mime: image/jpeg, image/png, image/webp
   create:
     width: 2400
     height: 2400
     format: jpg
   ```

   - **HEIC:** listing `mime` instead of `type: image` keeps HEIC out of the picker. On iPhone Safari a library photo then arrives as a JPEG (verified on iPhone only). Tegaru never converts HEIC; with the plugin on, the server refuses it.
   - **`create`** is Kirby's server-side resize. It covers uploads that skip the Panel script (API, server-side import) and photos the script leaves unchanged. Whether it removes metadata depends on Kirby's image driver (`thumbs.driver`), so do not rely on it to remove GPS. Keep it at the top level; under `options:`, `create` is a permission setting.

3. **Upload in the Panel.** Each picked JPEG, PNG or WebP photo of at least `minBytes` is decoded, rotated, resized and re-encoded in the browser; the upload button waits until the photos are ready. The Panel script reads its settings from `GET /api/tegaru/options`, which needs a logged-in Panel user.

   While the plugin is on, a tap on an empty files field does what the field's Upload button does. The Select button still lists the files already there. A field without uploads (`uploads: false`), disabled or full keeps Kirby's behaviour. Kirby logs `Plugin is replacing "k-files-field"` in the browser console.

4. **The server checks every new or replaced file.** `file.create:before` and `file.replace:before` reject files above `maxUploadBytes`, JPEG, PNG, GIF or WebP images above `maxPixels`, and HEIC/HEIF files, with a message in the site's language. The server never resizes.

### Limits

- **No server-side resizing** by Tegaru; use `create:`.
- **Only Panel uploads are changed.** Files that come in through the API or a server-side import are not re-encoded.
- **Metadata stays** in photos Tegaru leaves unchanged: while the plugin is off, below `minBytes`, not decodable in the browser, HEIC, GIF, AVIF, SVG or PDF, not smaller once re-encoded, WebP the browser cannot encode, settings unavailable, or not ready 20 seconds after they were picked.
- **Images only.** Video and other media are not touched.
- **Kirby's upload module is not a public API.** If the wrapped functions are missing, Tegaru logs one `console.info` and changes nothing.

## Options

Write each option as `sayamaapps.tegaru.<name>` in your site config, as in step 1. `null` means "not set"; invalid values fall back to the default. The 1.x spelling `tegaru.<name>` still works, see [Deprecations](#deprecations).

| Option | Type | Default | Description |
|---|---|---|---|
| `active` | boolean | `false` | Master switch. Off: the hooks let everything through and the script changes nothing. |
| `maxEdge` | integer | `2400` | Longest edge of the browser-side result, in px. |
| `quality` | number | `0.85` | JPEG and WebP quality, 0.1–1.0. PNG has no quality setting. |
| `minBytes` | integer | `1048576` | Files below this size (1 MB) are left alone in the browser. `0` processes every file. |
| `maxUploadBytes` | integer | `15728640` | Server cap per file (15 MB). |
| `maxPixels` | integer | `40000000` | Server cap in pixels (width × height) for JPEG, PNG, GIF and WebP images (40 MP). |
| `language` | string | site default language | `en` or `de` for server messages. Otherwise the site's default language, then Kirby's `locale`, then English. Panel messages follow the Panel's language. |

Example: `'sayamaapps.tegaru.maxEdge' => 1920,`

## Deprecations

Deprecated in this release, removed in 2.0 (planned). Each replacement works now; the old names keep working until then, and debug mode reports their use.

| 1.x | Now | Notes |
|---|---|---|
| `tegaru.<option>` in the site config | `sayamaapps.tegaru.<option>` | Still read as fallback when the new name is not set. The 1.x form may be nested (`'tegaru' => ['active' => true]`); the new form is written flat. |
| `tegaru_guard($kirby, $upload)` | `Sayamaapps\Tegaru\Hooks::guard($kirby, $upload)` | Global function, kept as a wrapper. |
| `tegaru_i18n()` | `Sayamaapps\Tegaru\Options::applyLanguage($kirby)` | Global function, kept as a wrapper. |
| `Tegaru\Guard`, `Tegaru\I18n`, `Tegaru\Options` | `Sayamaapps\Tegaru\Guard`, `Sayamaapps\Tegaru\I18n`, `Sayamaapps\Tegaru\Options` | Class aliases. `instanceof` and type hints keep working. |

Unchanged: the translation keys `tegaru.<key>`, the route `GET /api/tegaru/options`, the hook names, and the blueprint and Panel field names.

## Privacy

Tegaru processes no visitor data. Nothing is stored in the browser, and nothing is sent to a third party. Re-encoded photos carry no EXIF or GPS data; files Tegaru leaves unchanged keep their metadata (see [Limits](#limits)).

## Maintenance

Tests: `composer test`, and `node --test tests/*.test.mjs` for the Panel script. Server messages come from Tegaru's own tables in `translations/`, not from Kirby's translation layer, so a refused upload reads the same whatever the Kirby translation setup is. Issues and pull requests are welcome, without a support promise. Report security problems privately through GitHub's vulnerability reporting. Changes: [CHANGELOG.md](CHANGELOG.md).

## License

MIT, see [LICENSE.md](LICENSE.md).
