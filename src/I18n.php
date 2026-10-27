<?php

namespace Sayamaapps\Tegaru;

use Kirby\Cms\App;
use Kirby\Cms\Helpers;

/**
 * Strings of the server-side messages.
 *
 * The tables live in translations/<language>.php and are registered as Kirby
 * plugin translations under `tegaru.<key>` (config/translations.php). The hook
 * and the Panel script both read them through Kirby: translate() for the hook,
 * `panel.$t('tegaru.<key>')` in the Panel. t() is the 1.x method of Tegaru\I18n:
 * a deprecated alias of translate(), not used by the plugin.
 *
 * The language is static state, set by Options::applyLanguage() before a
 * message is built (Hooks::guard() does so first). Every read follows a set,
 * so a value left by an earlier request in the same PHP worker is never
 * read. The default is English; missing keys fall back to English, then to
 * the key.
 *
 * @package   Kirby Tegaru
 * @author    Simon Räder
 * @link      https://github.com/sayamaapps/kirby-tegaru
 * @copyright Simon Räder
 * @license   https://opensource.org/licenses/MIT
 * @since 1.0.0
 */
class I18n
{
	/**
	 * Languages Tegaru ships. English is the default and the fallback.
	 * @since 1.0.0
	 */
	public const LANGUAGES = ['en', 'de'];

	private static string $language = 'en';

	/** @var array<string, array<string,string>> */
	private static array $tables = [];

	/** @param array<string,mixed> $values */
	private static function fill(string $text, array $values): string
	{
		if ($values === []) {
			return $text;
		}
		$pairs = [];
		foreach ($values as $name => $value) {
			$pairs['{' . $name . '}'] = (string)$value;
		}
		return strtr($text, $pairs);
	}

	/**
	 * The messages as Kirby translations, keyed `tegaru.<key>` per language, as
	 * the Panel script reads them (`panel.$t('tegaru.<key>')`).
	 *
	 * @return array<string, array<string,string>>
	 */
	public static function kirbyTranslations(): array
	{
		$translations = [];
		foreach (self::LANGUAGES as $language) {
			foreach (self::table($language) as $key => $text) {
				$translations[$language]['tegaru.' . $key] = $text;
			}
		}
		return $translations;
	}

	/**
	 * The language the messages are built in: the one set by setLanguage(), English by default.
	 * @since 1.0.0
	 */
	public static function language(): string
	{
		return self::$language;
	}

	/**
	 * Sets the language of later t() calls. A language Tegaru does not ship becomes English.
	 * @since 1.0.0
	 */
	public static function setLanguage(string $language): void
	{
		self::$language = in_array($language, self::LANGUAGES, true) ? $language : 'en';
	}

	/**
	 * The 1.x method: translate() for the current app. Deprecated; the plugin uses translate().
	 * @deprecated 1.2.0, use Sayamaapps\Tegaru\I18n::translate()
	 * @since 1.0.0
	 */
	public static function t(string $key, array $values = []): string
	{
		Helpers::deprecated('Sayamaapps\Tegaru\I18n::t() is deprecated, use Sayamaapps\Tegaru\I18n::translate()', 'tegaru-method');
		return self::translate(App::instance(), $key, $values);
	}

	/**
	 * @return array<string,string>
	 * @since 1.0.0
	 */
	public static function table(string $language): array
	{
		if (!in_array($language, self::LANGUAGES, true)) {
			return [];
		}
		return self::$tables[$language] ??= require dirname(__DIR__) . '/translations/' . $language . '.php';
	}

	/**
	 * A message from Kirby's translations `tegaru.<key>` in the language set by
	 * setLanguage(), English when that language lacks the key, the key when
	 * neither has it. `{name}` placeholders are replaced with the values, unescaped.
	 */
	public static function translate(App $kirby, string $key, array $values = []): string
	{
		$text = $kirby->translation(self::$language)->get('tegaru.' . $key)
			?? $kirby->translation('en')->get('tegaru.' . $key)
			?? $key;
		return self::fill($text, $values);
	}
}
