<?php

use Kirby\Cms\App;
use Kirby\Cms\File;
use Kirby\Exception\InvalidArgumentException;
use Kirby\Filesystem\F;
use Kirby\Filesystem\File as Upload;
use Sayamaapps\Tegaru\Guard;
use Sayamaapps\Tegaru\I18n;
use Sayamaapps\Tegaru\Options;

F::loadClasses(require __DIR__ . '/autoload.php', __DIR__);
require_once __DIR__ . '/legacy.php';

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

Kirby::plugin(
	name: 'sayamaapps/tegaru',
	extends: [
		'translations' => require __DIR__ . '/config/translations.php',
		'api'          => require __DIR__ . '/config/api.php',
		'hooks'        => require __DIR__ . '/config/hooks.php',
	],
	root: __DIR__
);
