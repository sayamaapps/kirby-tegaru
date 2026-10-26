<?php

/**
 * Names of the 1.x API, kept until 2.0 (README, Deprecations).
 *
 * Loaded once by index.php, after the class map is registered. Class aliases
 * and function wrappers only; no logic here.
 */

class_alias(\Sayamaapps\Tegaru\Guard::class, 'Tegaru\\Guard');
class_alias(\Sayamaapps\Tegaru\I18n::class, 'Tegaru\\I18n');
class_alias(\Sayamaapps\Tegaru\Options::class, 'Tegaru\\Options');

if (Kirby\Cms\Helpers::hasOverride('tegaru_i18n') === false) {
	/** Sets the language of the messages. Deprecated: use Sayamaapps\Tegaru\Options::applyLanguage(). */
	function tegaru_i18n(): void
	{
		Kirby\Cms\Helpers::deprecated(
			'tegaru_i18n() is deprecated, use Sayamaapps\Tegaru\Options::applyLanguage()',
			'tegaru-function'
		);

		Sayamaapps\Tegaru\Options::applyLanguage(Kirby\Cms\App::instance());
	}
}

if (Kirby\Cms\Helpers::hasOverride('tegaru_guard') === false) {
	/** The upload check of the hooks. Deprecated: use Sayamaapps\Tegaru\Hooks::guard(). */
	function tegaru_guard(Kirby\Cms\App $kirby, Kirby\Filesystem\File $upload): void
	{
		Kirby\Cms\Helpers::deprecated(
			'tegaru_guard() is deprecated, use Sayamaapps\Tegaru\Hooks::guard()',
			'tegaru-function'
		);

		Sayamaapps\Tegaru\Hooks::guard($kirby, $upload);
	}
}
