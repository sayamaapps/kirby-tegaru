<?php

use Kirby\Cms\App;
use Sayamaapps\Tegaru\Options;

return [
	// What the Panel script needs: GET /api/tegaru/options. API routes need a
	// logged-in user (Kirby's default auth), so nothing about the configuration
	// leaks to visitors.
	'routes' => function (App $kirby): array {
		return [
			[
				'pattern' => 'tegaru/options',
				'method'  => 'GET',
				'action'  => function () use ($kirby): array {
					return Options::forPanel($kirby);
				},
			],
		];
	},
];
