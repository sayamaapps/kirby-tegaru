<?php

use Kirby\Cms\App;
use Kirby\Cms\File;
use Kirby\Filesystem\File as Upload;
use Sayamaapps\Tegaru\Hooks;

// The safety net. `$upload` is the file still in PHP's tmp directory; an
// exception here cancels the upload (or the replacement) and the Panel shows
// its message.
return [
	'file.create:before' => function (File $file, Upload $upload): void {
		Hooks::guard(App::instance(), $upload);
	},
	'file.replace:before' => function (File $file, Upload $upload): void {
		Hooks::guard(App::instance(), $upload);
	},
];
