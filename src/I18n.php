<?php

namespace Sayamaapps\Tegaru;

/**
 * Strings of the server-side messages, Kirby-free.
 *
 * The tables live in translations/<language>.php and are also registered as
 * Kirby plugin translations under `tegaru.<key>` (index.php), which is how
 * the Panel script reads them (`panel.$t('tegaru.<key>')`). The hook reads
 * its own tables: a missing Kirby translation must never silently turn a
 * German message English.
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
	 * `{name}` placeholders are replaced with the given values, unescaped.
	 * @since 1.0.0
	 */
	public static function t(string $key, array $values = []): string
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
}
