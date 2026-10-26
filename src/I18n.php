<?php

namespace Tegaru;

/**
 * Strings of the server-side messages, Kirby-free.
 *
 * The tables live in translations/<language>.php and are also registered as
 * Kirby plugin translations under `tegaru.<key>` (index.php), which is how
 * the Panel script reads them (`panel.$t('tegaru.<key>')`). The hook reads
 * its own tables: a missing Kirby translation must never silently turn a
 * German message English.
 *
 * The language is set once per request (Options::language()) and defaults
 * to English. Missing keys fall back to English, then to the key.
 */
final class I18n
{
	/** Languages Tegaru ships. English is the default and the fallback. */
	public const LANGUAGES = ['en', 'de'];

	private static string $language = 'en';

	/** @var array<string, array<string,string>> */
	private static array $tables = [];

	public static function language(): string
	{
		return self::$language;
	}

	public static function setLanguage(string $language): void
	{
		self::$language = in_array($language, self::LANGUAGES, true) ? $language : 'en';
	}

	/** `{name}` placeholders are replaced with the given values, unescaped. */
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

	/** @return array<string,string> */
	public static function table(string $language): array
	{
		if (!in_array($language, self::LANGUAGES, true)) {
			return [];
		}
		return self::$tables[$language] ??= require dirname(__DIR__) . '/translations/' . $language . '.php';
	}
}
