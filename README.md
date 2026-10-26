# Tegaru

Lighter photo uploads from the phone into the [Kirby CMS](https://getkirby.com) 5 Panel.

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

- Kirby 5 and PHP 8.2–8.5.
- A browser with canvas support. Measured on iPhone Safari only; other browsers are untested.

## Installation

```sh
composer require sayamaapps/kirby-tegaru
```

Composer installs to `site/plugins/tegaru/`. Add that path to `.gitignore` and deploy it like `vendor/`.

Everything is off by default. Switch it on in `site/config/config.php`:

```php
return [
    'tegaru.active' => true,
];
```

## Usage

Tegaru wraps `select`, `submit` and `reset` of Kirby's Panel upload module. Each picked JPEG, PNG or WebP photo of at least `minBytes` is decoded, rotated, resized and re-encoded in the browser; the upload button waits until the photos are ready. The Panel script reads its settings from `GET /api/tegaru/options`, which needs a logged-in Panel user.

Tegaru also extends Kirby's files field: while `tegaru.active` is `true`, a tap on the empty field does what the field's Upload button does. The Select button still lists the files already there. A field without uploads (`uploads: false`), disabled or full keeps Kirby's behaviour. Kirby logs `Plugin is replacing "k-files-field"` in the browser console.

On the server, `file.create:before` and `file.replace:before` reject files above `maxUploadBytes` (15 MB), JPEG, PNG, GIF or WebP images above `maxPixels` (40 megapixels), and HEIC/HEIF files, with a message in the site's language. The server never resizes. These checks run only while `tegaru.active` is `true`.

### Blueprints

Set these two options in your photo blueprint, `site/blueprints/files/<template>.yml`:

```yaml
accept:
  mime: image/jpeg, image/png, image/webp
create:
  width: 2400
  height: 2400
  format: jpg
```

- **HEIC:** listing `mime` instead of `type: image` keeps HEIC out of the picker. On iPhone Safari a library photo then arrives as a JPEG (verified on iPhone only). Tegaru never converts HEIC; with `tegaru.active` on, the server refuses it.
- **`create`** is Kirby's server-side resize. It covers uploads that skip the Panel script (API, server-side import) and photos the script leaves unchanged. Whether it removes metadata depends on Kirby's image driver (`thumbs.driver`), so do not rely on it to remove GPS. Keep it at the top level; under `options:`, `create` is a permission setting.

### Limits

- **No server-side resizing** by Tegaru; use `create:`.
- **Only Panel uploads are changed.** Files that come in through the API or a server-side import are not re-encoded.
- **Metadata stays** in photos Tegaru leaves unchanged: while `tegaru.active` is off, below `minBytes`, not decodable in the browser, HEIC, GIF, AVIF, SVG or PDF, not smaller once re-encoded, WebP the browser cannot encode, settings unavailable, or not ready 20 seconds after they were picked.
- **Images only.** Video and other media are not touched.
- **Kirby's upload module is not a public API.** If the wrapped functions are missing, Tegaru logs one `console.info` and changes nothing. The package's tests fail when a Kirby release removes them.

## Options

All keys are `tegaru.<name>`, flat or nested. `null` means "not set"; invalid values fall back to the default.

| Option | Default | Description |
|---|---|---|
| `active` | `false` | Master switch. Off: the hook lets everything through and the script changes nothing. |
| `maxEdge` | `2400` | Longest edge of the browser-side result, in px. |
| `quality` | `0.85` | JPEG and WebP quality, 0.1–1.0. PNG has no quality setting. |
| `minBytes` | `1048576` | Files below this size (1 MB) are left alone in the browser. `0` processes every file. |
| `maxUploadBytes` | `15728640` | Server cap per file (15 MB). |
| `maxPixels` | `40000000` | Server cap in pixels (width × height) for JPEG, PNG, GIF and WebP images (40 MP). |
| `language` | site default language | `en` or `de` for server messages. Otherwise the site's default language, then Kirby's `locale`, then English. Panel messages follow the Panel's language. |

## Privacy

Tegaru processes no visitor data. Nothing is stored in the browser, and nothing is sent to a third party. Re-encoded photos carry no EXIF or GPS data; files Tegaru leaves unchanged keep their metadata (see [Limits](#limits)).

## Maintenance

Tests: `composer test`, and `node --test tests/*.test.mjs` for the Panel script. Issues and pull requests are welcome, without a support promise. Report security problems privately through GitHub's vulnerability reporting. Changes: [CHANGELOG.md](CHANGELOG.md).

## License

MIT, see [LICENSE.md](LICENSE.md).
