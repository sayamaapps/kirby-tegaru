<?php

use Kirby\Cms\App;
use Kirby\Filesystem\Dir;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Sayamaapps\Tegaru\I18n;

/**
 * Translations: every language has the same keys and placeholders as
 * English; the keys the hook and the Panel script use exist; an unknown
 * language falls back to English; translate() fills {placeholders} from
 * Kirby's translations; a missing key comes back as the key; t(), the 1.x
 * method, gives the same text and reports a deprecation in debug mode.
 */
#[CoversClass(I18n::class)]
final class I18nTest extends TestCase
{
	private string $tmp;

	/** A new Kirby app on a throwaway site; German is the site's language when $german. */
	private function app(array $options = [], bool $german = false): App
	{
		return new App([
			'roots' => [
				'index' => $this->tmp . '/public', 'content' => $this->tmp . '/content', 'site' => $this->tmp . '/site',
				'storage' => $this->tmp . '/storage', 'media' => $this->tmp . '/public/media',
			],
			'options' => ['url' => 'https://example.com'] + $options,
			'languages' => $german ? [['code' => 'de', 'default' => true, 'url' => 'https://example.com', 'slug' => 'de', 'name' => 'Deutsch']] : [],
		]);
	}

	/**
	 * Runs $fn and returns the deprecation messages it triggered.
	 *
	 * @return list<string>
	 */
	private function collectDeprecations(callable $fn): array
	{
		$deprecations = [];
		set_error_handler(function (int $level, string $message) use (&$deprecations): bool {
			$deprecations[] = $message;
			return true;
		}, E_USER_DEPRECATED);

		try {
			$fn();
		} finally {
			restore_error_handler();
		}
		return $deprecations;
	}

	/** The placeholder names of a text, sorted and unique. */
	private static function placeholders(string $text): array
	{
		preg_match_all('/\{(\w+)\}/', $text, $matches);
		$names = array_unique($matches[1]);
		sort($names);
		return $names;
	}

	protected function setUp(): void
	{
		$this->tmp = sys_get_temp_dir() . '/tegaru-i18n-' . bin2hex(random_bytes(6));
		foreach (['public', 'content', 'site', 'storage'] as $dir) {
			mkdir($this->tmp . '/' . $dir, 0700, true);
		}

		// The plugin registers its translations before the App is built, as in a site.
		require dirname(__DIR__) . '/index.php';
	}

	protected function tearDown(): void
	{
		App::destroy();
		Dir::remove($this->tmp);
	}

	public function testEnglishPlaceholdersAreFilledInAndValuesAreNotEscaped(): void
	{
		I18n::setLanguage('en');
		$kirby = $this->app();
		expect('The file is 3.7 MB; the maximum is 1.0 MB. Tip: upload the photo from the Photos app, not from Files.', I18n::translate($kirby, 'error.size', ['size' => '3.7', 'max' => '1.0']), 'English placeholders filled');
		expect('<b>x</b> could not be read as an image; it is uploaded unchanged.', I18n::translate($kirby, 'panel.unreadable', ['name' => '<b>x</b>']), 'values are inserted unescaped');
	}

	public function testEveryLanguageHasTheEnglishKeysAndPlaceholders(): void
	{
		$en = I18n::table('en');
		// The keys Guard (error.*) and index.js (panel.*) ask for.
		foreach (['error.size', 'error.pixels', 'error.heic', 'panel.resized', 'panel.resizedOne', 'panel.unreadable'] as $key) {
			holds(isset($en[$key]), "English has $key");
		}
		foreach (I18n::LANGUAGES as $language) {
			$table = I18n::table($language);
			expect([], array_values(array_diff(array_keys($en), array_keys($table))), "$language: no missing keys");
			expect([], array_values(array_diff(array_keys($table), array_keys($en))), "$language: no extra keys");
			foreach ($en as $key => $text) {
				holds(is_string($table[$key]) && trim($table[$key]) !== '', "$language.$key not empty");
				expect(self::placeholders($text), self::placeholders($table[$key]), "$language.$key: same placeholders as English");
			}
		}
	}

	public function testGermanIsSetAndTheValuesAreFilledIn(): void
	{
		$kirby = $this->app([], true);
		I18n::setLanguage('de');
		expect('de', I18n::language(), 'German is set');
		expect(true, str_starts_with(I18n::translate($kirby, 'error.heic'), 'HEIC-Fotos können hier nicht verarbeitet werden.'), 'German string');
		expect('Die Datei ist 3.7 MB groß, erlaubt sind 1.0 MB. Tipp: das Foto aus der Fotos-App hochladen, nicht aus „Dateien“.', I18n::translate($kirby, 'error.size', ['size' => '3.7', 'max' => '1.0']), 'German placeholders filled');
		I18n::setLanguage('en');
	}

	public function testMissingKeyComesBackAsTheKey(): void
	{
		$kirby = $this->app();
		expect('nope.key', I18n::translate($kirby, 'nope.key'), 'missing key comes back as the key');
		expect('nope.key', I18n::translate($kirby, 'nope.key', ['name' => 'x']), 'missing key with values comes back as the key');
	}

	public function testTheOneXMethodGivesTheTranslateTextAndIsDeprecated(): void
	{
		I18n::setLanguage('en');
		$text = null;
		$deprecations = $this->collectDeprecations(function () use (&$text): void {
			$this->app(['debug' => true]);
			$text = I18n::t('error.heic');
		});
		expect('HEIC photos cannot be processed here. Please upload a JPEG (on an iPhone: Settings → Camera → Formats → Most Compatible).', $text, 't() gives the translate() text');
		expect(['Sayamaapps\Tegaru\I18n::t() is deprecated, use Sayamaapps\Tegaru\I18n::translate()'], $deprecations, 't() is reported in debug mode');
	}

	public function testUnknownLanguageFallsBackToEnglish(): void
	{
		expect([], I18n::table('fr'), 'unknown language: no table');

		I18n::setLanguage('fr');
		expect('en', I18n::language(), 'unknown language falls back to English');
	}
}
