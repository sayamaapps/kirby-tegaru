<?php

/**
 * Class map for Kirby's F::loadClasses(). Keys are lowercase class names,
 * values are paths relative to the plugin root. tests/AutoloadTest.php checks
 * the map against src/.
 */

return [
	'sayamaapps\\tegaru\\guard' => 'src/Guard.php',
	'sayamaapps\\tegaru\\i18n' => 'src/I18n.php',
	'sayamaapps\\tegaru\\options' => 'src/Options.php',
];
