<?php

/**
 * Tegaru (手軽, "light to handle"): lighter photo uploads for the Kirby Panel.
 *
 * Kirby loads this file. It registers the class map, the names of the 1.x API
 * and the definitions in config/. The logic lives in src/. The browser side is
 * index.js; the server side is the upload hooks in src/Hooks.php. Off by
 * default (README, Usage).
 */

use Kirby\Filesystem\F;

F::loadClasses(require __DIR__ . '/autoload.php', __DIR__);
require_once __DIR__ . '/legacy.php';

Kirby::plugin(
	name: 'sayamaapps/tegaru',
	extends: [
		'translations' => require __DIR__ . '/config/translations.php',
		'options'      => require __DIR__ . '/config/options.php',
		'api'          => require __DIR__ . '/config/api.php',
		'hooks'        => require __DIR__ . '/config/hooks.php',
	],
	root: __DIR__
);
