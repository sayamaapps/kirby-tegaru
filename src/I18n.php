<?php

namespace Sayamaapps\Tegaru;

/**
 * Strings of the server-side messages.
 *
 * The tables live in translations/<language>.php. The hook reads them
 * directly (text()), as in 1.x, so a message does not depend on a Kirby app
 * or on the site's translations. They are also registered as Kirby plugin
 * translations under `tegaru.<key>` (config/translations.php), which is how
 * the Panel script reads them: `panel.$t('tegaru.<key>')`.
 *
 * The language is static state, set by Options::applyLanguage() before a
 * message is built (Hooks::guard() does so first). The default is English;
 * missing keys fall back to English, then to the key.
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

	/**
	 * The messages as Kirby translations, keyed `tegaru.<key>` per language, as
	 * the Panel script reads them (`panel.$t('tegaru.<key>')`). Each message is
	 * also served as `sayamaapps.tegaru.<key>`, the spelling of 2.0, so that a
	 * site can switch its own translations early. Nothing in 1.x reads it.
	 *
	 * @return array<string, array<string,string>>
	 * @internal
	 * @since 1.2.0
	 */
	public static function kirbyTranslations(): array
	{
		$translations = [];
		foreach (self::LANGUAGES as $language) {
			foreach (self::table($language) as $key => $text) {
				$translations[$language]['tegaru.' . $key] = $text;
				$translations[$language]['sayamaapps.tegaru.' . $key] = $text;
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
	 * Sets the language of later messages. A language Tegaru does not ship becomes English.
	 * @since 1.0.0
	 */
	public static function setLanguage(string $language): void
	{
		self::$language = in_array($language, self::LANGUAGES, true) ? $language : 'en';
	}

	/**
	 * The 1.x method: the message from the tables, `{name}` placeholders filled in.
	 * Kept for callers of 1.x; it needs no Kirby app and gives the same text
	 * as before. No runtime notice is raised.
	 *
	 * @deprecated 1.2.0 Kept for 1.x callers. It reads the same tables as text().
	 * @since 1.0.0
	 */
	public static function t(string $key, array $values = []): string
	{
		return self::text($key, $values);
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
	 * A message in the language set by setLanguage(), English when that
	 * language lacks the key, the key when neither has it. `{name}`
	 * placeholders are replaced with the values, unescaped.
	 *
	 * @param array<string,mixed> $values
	 * @internal
	 * @since 1.2.0
	 */
	public static function text(string $key, array $values = []): string
	{
		$text = self::table(self::$language)[$key] ?? self::table('en')[$key] ?? $key;
		if ($values === []) {
			return $text;
		}
		$pairs = [];
		foreach ($values as $name => $value) {
			$pairs['{' . $name . '}'] = (string)$value;
		}
		return strtr($text, $pairs);
	}
}
