<?php

/**
 * Names of the 1.x API, kept until 2.0 (README, Deprecations).
 *
 * Loaded once by index.php, after the class map is registered. Class aliases
 * and function wrappers only; no logic here. Nothing here raises a notice:
 * the 1.x names are documented as deprecated and keep working until 2.0.
 */

class_alias(\Sayamaapps\Tegaru\Guard::class, 'Tegaru\\Guard');
class_alias(\Sayamaapps\Tegaru\I18n::class, 'Tegaru\\I18n');
class_alias(\Sayamaapps\Tegaru\Options::class, 'Tegaru\\Options');

if (Kirby\Cms\Helpers::hasOverride('tegaru_i18n') === false) {
	/**
	 * Sets the language of the messages.
	 *
	 * @deprecated 1.2.0 use Sayamaapps\Tegaru\Options::applyLanguage()
	 * @since 1.0.0
	 */
	function tegaru_i18n(): void
	{
		Sayamaapps\Tegaru\Options::applyLanguage(Kirby\Cms\App::instance());
	}
}

if (Kirby\Cms\Helpers::hasOverride('tegaru_guard') === false) {
	/**
	 * The upload check of the hooks.
	 *
	 * @deprecated 1.2.0 use Sayamaapps\Tegaru\Hooks::guard()
	 * @since 1.0.0
	 */
	function tegaru_guard(Kirby\Cms\App $kirby, Kirby\Filesystem\File $upload): void
	{
		Sayamaapps\Tegaru\Hooks::guard($kirby, $upload);
	}
}
