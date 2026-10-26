<?php

$base = dirname(__DIR__);
require $base . '/vendor/autoload.php';

require_once $base . '/src/I18n.php';
require_once $base . '/src/Options.php';
require_once $base . '/src/Guard.php';
require_once __DIR__ . '/helpers.php';

// Kirby's Whoops error handler stays registered after every App is created, so
// the risky-test check would flag each Kirby test. PHPUnit reports errors itself.
Kirby\Cms\App::$enableWhoops = false;
