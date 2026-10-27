<?php

// Tegaru's messages, English. Same keys in every language (tests/I18nTest.php).
// Registered as Kirby translations `tegaru.<key>` as well, for the Panel script.
return [
	// Server-side safety net (hook file.create:before); shown by the Panel as the upload error.
	'error.size'      => 'The file is {size} MB; the maximum is {max} MB. Tip: upload the photo from the Photos app, not from Files.',
	'error.pixels'    => 'The image has {megapixels} megapixels; the maximum is {max}.',
	'error.heic'      => 'HEIC photos cannot be processed here. Please upload a JPEG (on an iPhone: Settings → Camera → Formats → Most Compatible).',
	// Panel script (index.js), after the photos have been prepared.
	'panel.resized'   => '{count} photos made lighter: {from} → {to}',
	'panel.resizedOne' => 'Photo made lighter: {from} → {to}',
	'panel.unreadable' => '{name} could not be read as an image; it is uploaded unchanged.',
];
