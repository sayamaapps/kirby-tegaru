<?php

use Kirby\Cms\App;
use Kirby\Cms\File;
use Kirby\Exception\InvalidArgumentException;
use Kirby\Filesystem\File as Upload;

require_once __DIR__ . '/src/I18n.php';
require_once __DIR__ . '/src/Options.php';
require_once __DIR__ . '/src/Guard.php';

use Tegaru\Guard;
use Tegaru\I18n;
use Tegaru\Options;

/**
 * Tegaru (手軽, "light to handle") — photos from the phone reach the Panel
 * lighter: index.js resizes them in the browser before the upload starts
 * (longest edge, quality, rotation applied; the re-encoded file carries no
 * EXIF or GPS); this file is the server side. Off by default: without
 * `'tegaru.active' => true` the hook lets everything through, the options
 * route still answers (so the script learns it is off) and the script
 * changes nothing.
 *
 * The server never resizes. That stays with the blueprint's `create:`
 * option, which also covers uploads that bypass the Panel script (the API, a
 * server-side import) and photos the script leaves unchanged (a browser
 * without canvas, files under minBytes). The hook is a safety net
 * only: byte cap, pixel cap, HEIC/HEIF rejected because Kirby cannot
 * resize or thumbnail it. The browser does not convert HEIC: the script
 * leaves it as it is, and the blueprint's `accept` list keeps it out of
 * the picker (README, "HEIC"). It runs for new files and for replacements.
 *
 * Defaults are passed as fallback values directly at each option call, NOT
 * in an 'options' block (Kirby would file them under sayamaapps.tegaru.*).
 */

/** Language for this request. Called before any message is built. */
function tegaru_i18n(): void
{
    I18n::setLanguage(Options::language(App::instance()));
}

/**
 * The hook body, separate so tests can call it: throws the exception the
 * Panel shows when the uploaded file violates the safety net. Shared by
 * `file.create:before` and `file.replace:before`, which pass the same
 * (File, Upload) arguments.
 *
 * @throws InvalidArgumentException
 */
function tegaru_guard(App $kirby, Upload $upload): void
{
    if (!Options::active($kirby)) {
        return;
    }
    $root = $upload->root();
    if (!is_string($root) || $root === '') {
        return; // nothing on disk to inspect: Kirby's own checks take over
    }
    $violation = Guard::inspect($root, Options::maxUploadBytes($kirby), Options::maxPixels($kirby));
    if ($violation === null) {
        return;
    }
    tegaru_i18n();
    throw new InvalidArgumentException(I18n::t($violation['key'], $violation['values']));
}

$tegaruTranslations = [];
foreach (I18n::LANGUAGES as $tegaruLanguage) {
    foreach (I18n::table($tegaruLanguage) as $tegaruKey => $tegaruText) {
        $tegaruTranslations[$tegaruLanguage]['tegaru.' . $tegaruKey] = $tegaruText;
    }
}
unset($tegaruLanguage, $tegaruKey, $tegaruText);

App::plugin('sayamaapps/tegaru', [
    // The messages, also usable as t('tegaru.<key>') and, in the Panel, panel.$t('tegaru.<key>').
    'translations' => $tegaruTranslations,

    /**
     * What the Panel script needs: GET /api/tegaru/options. API routes
     * need a logged-in user (Kirby's default auth), so nothing about the
     * configuration leaks to visitors.
     */
    'api' => [
        'routes' => fn (App $kirby) => [
            [
                'pattern' => 'tegaru/options',
                'method'  => 'GET',
                'action'  => fn () => Options::forPanel($kirby),
            ],
        ],
    ],

    /**
     * The safety net. `$upload` is the file still in PHP's tmp directory;
     * an exception here cancels the upload (or the replacement) and the
     * Panel shows its message.
     */
    'hooks' => [
        'file.create:before' => function (File $file, Upload $upload): void {
            tegaru_guard(App::instance(), $upload);
        },
        'file.replace:before' => function (File $file, Upload $upload): void {
            tegaru_guard(App::instance(), $upload);
        },
    ],
]);
