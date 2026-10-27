<?php

// The plugin header names the package on every class, so the Kirby style
// rule that removes @package stays off.
return Kirby\PhpCs\Config::create()
	->setRules(['@Kirby/style:risky' => true, 'phpdoc_no_package' => false])
	->setFinder(
		PhpCsFixer\Finder::create()
			->exclude('vendor')
			->in(__DIR__)
	);
