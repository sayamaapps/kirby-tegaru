<?php

/**
 * Class map for Kirby's F::loadClasses(). Keys are lowercase class names,
 * values are paths relative to the plugin root. tests/AutoloadTest.php checks
 * the map against src/.
 */

return [
	'tegaru\\guard' => 'src/Guard.php',
	'tegaru\\i18n' => 'src/I18n.php',
	'tegaru\\options' => 'src/Options.php',
];
