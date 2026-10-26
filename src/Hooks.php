<?php

namespace Sayamaapps\Tegaru;

use Kirby\Cms\App;
use Kirby\Exception\InvalidArgumentException;
use Kirby\Filesystem\File as Upload;

/**
 * The upload hooks, Kirby-aware only at the edge: the caller passes the app.
 *
 * Tegaru never resizes on the server. That stays with the blueprint's
 * `create:` option, which also covers uploads that bypass the Panel script
 * (the API, a server-side import) and photos the script leaves unchanged. The
 * hook is a safety net only: byte cap, pixel cap, and HEIC/HEIF rejected
 * because Kirby cannot resize or thumbnail it (README, Limits).
 */
class Hooks
{
	/**
	 * Refuses an upload that violates the safety net, with the message the
	 * Panel shows. Shared by `file.create:before` and `file.replace:before`,
	 * which pass the same (File, Upload) arguments. Does nothing while the
	 * plugin is off, or when the file is not on disk.
	 *
	 * @throws InvalidArgumentException
	 */
	public static function guard(App $kirby, Upload $upload): void
	{
		if (Options::active($kirby) === false) {
			return;
		}
		$root = $upload->root();
		if (is_string($root) === false || $root === '') {
			return; // nothing on disk to inspect: Kirby's own checks take over
		}
		$violation = Guard::inspect($root, Options::maxUploadBytes($kirby), Options::maxPixels($kirby));
		if ($violation === null) {
			return;
		}

		Options::applyLanguage($kirby);
		throw new InvalidArgumentException(message: I18n::t($violation['key'], $violation['values']));
	}
}
