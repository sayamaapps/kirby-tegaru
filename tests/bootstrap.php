<?php

$base = dirname(__DIR__);
require $base . '/vendor/autoload.php';

Kirby\Filesystem\F::loadClasses(require $base . '/autoload.php', $base);
require_once __DIR__ . '/helpers.php';

// Kirby's Whoops error handler stays registered after every App is created, so
// the risky-test check would flag each Kirby test. PHPUnit reports errors itself.
Kirby\Cms\App::$enableWhoops = false;
