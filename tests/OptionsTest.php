<?php

use Kirby\Cms\App;
use Kirby\Filesystem\Dir;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Sayamaapps\Tegaru\Options;

/**
 * Options with a throwaway Kirby app: defaults, flat and nested spelling,
 * invalid values fall back to the default, the quality range, forPanel()
 * shape and order, and language() from the option, the site language, the
 * locale, and English as the last fallback.
 */
#[CoversClass(Options::class)]
final class OptionsTest extends TestCase
{
	private const ENGLISH = [['code' => 'en', 'default' => true, 'url' => 'https://example.com', 'slug' => 'en', 'name' => 'English']];
	private const GERMAN = [['code' => 'de', 'default' => true, 'url' => 'https://example.com', 'slug' => 'de', 'name' => 'Deutsch']];

	private string $tmp;

	/** A new Kirby app with these options and site languages (none unless given). */
	private function app(array $options = [], array $languages = []): App
	{
		return new App([
			'roots' => [
				'index' => $this->tmp . '/public', 'content' => $this->tmp . '/content', 'site' => $this->tmp . '/site',
				'storage' => $this->tmp . '/storage', 'media' => $this->tmp . '/public/media',
			],
			'options' => ['url' => 'https://example.com'] + $options,
			'languages' => $languages,
		]);
	}

	/** A new app with one tegaru option set to $value. */
	private function one(string $key, mixed $value): App
	{
		return $this->app(['tegaru.' . $key => $value]);
	}

	protected function setUp(): void
	{
		$this->tmp = sys_get_temp_dir() . '/tegaru-options-' . bin2hex(random_bytes(6));
		foreach (['public', 'content', 'site/templates', 'site/snippets', 'storage'] as $dir) {
			mkdir($this->tmp . '/' . $dir, 0700, true);
		}
	}

	protected function tearDown(): void
	{
		Dir::remove($this->tmp);
	}

	public function testDefaultsWhenNothingIsSet(): void
	{
		$none = $this->app();
		expect(false, Options::active($none), 'default: off');
		expect(2400, Options::maxEdge($none), 'default: maxEdge');
		expect(0.85, Options::quality($none), 'default: quality');
		expect(1048576, Options::minBytes($none), 'default: minBytes');
		expect(15728640, Options::maxUploadBytes($none), 'default: maxUploadBytes');
		expect(40000000, Options::maxPixels($none), 'default: maxPixels');
		expect('en', Options::language($none), 'default: language');
	}

	public function testFlatAndNestedSpellingGiveTheSameValues(): void
	{
		$values = ['active' => true, 'maxEdge' => 1920, 'quality' => 0.6, 'minBytes' => 0, 'maxUploadBytes' => 5000000, 'maxPixels' => 12000000];
		$flat = $this->app(array_combine(array_map(fn ($k) => 'tegaru.' . $k, array_keys($values)), $values));
		$nested = $this->app(['tegaru' => $values]);
		expect(true, Options::active($flat), 'flat: active');
		expect(1920, Options::maxEdge($flat), 'flat: maxEdge');
		expect(0.6, Options::quality($flat), 'flat: quality');
		expect(0, Options::minBytes($flat), 'flat: minBytes 0 means always process');
		expect(5000000, Options::maxUploadBytes($flat), 'flat: maxUploadBytes');
		expect(12000000, Options::maxPixels($flat), 'flat: maxPixels');
		expect(Options::forPanel($flat), Options::forPanel($nested), 'nested spelling gives what the flat one does');
	}

	public function testForPanelHasTheDocumentedShapeAndOrder(): void
	{
		$values = ['active' => true, 'maxEdge' => 1920, 'quality' => 0.6, 'minBytes' => 0, 'maxUploadBytes' => 5000000, 'maxPixels' => 12000000];
		$flat = $this->app(array_combine(array_map(fn ($k) => 'tegaru.' . $k, array_keys($values)), $values));

		expect(
			['format' => 1, 'active' => false, 'maxEdge' => 2400, 'quality' => 0.85, 'minBytes' => 1048576, 'maxUploadBytes' => 15728640, 'maxPixels' => 40000000, 'language' => 'en'],
			Options::forPanel($this->app()),
			'forPanel: defaults, in the documented order'
		);
		expect(
			['format' => 1, 'active' => true, 'maxEdge' => 1920, 'quality' => 0.6, 'minBytes' => 0, 'maxUploadBytes' => 5000000, 'maxPixels' => 12000000, 'language' => 'en'],
			Options::forPanel($flat),
			'forPanel: the options as set'
		);
		expect('de', Options::forPanel($this->app([], self::GERMAN))['language'], 'forPanel: language from the site default');
	}

	public function testInvalidValuesFallBackToTheDefault(): void
	{
		expect(false, Options::active($this->one('active', 'yes')), 'invalid: active must be the boolean true');
		expect(false, Options::active($this->one('active', 1)), 'invalid: active 1 is not true');
		expect(2400, Options::maxEdge($this->one('maxEdge', '1920')), 'invalid: maxEdge as string');
		expect(2400, Options::maxEdge($this->one('maxEdge', 0)), 'invalid: maxEdge zero');
		expect(2400, Options::maxEdge($this->one('maxEdge', -1)), 'invalid: maxEdge negative');
		expect(2400, Options::maxEdge($this->one('maxEdge', 2.5)), 'invalid: maxEdge float');
		expect(0.85, Options::quality($this->one('quality', '0.5')), 'invalid: quality as string');
		expect(0.85, Options::quality($this->one('quality', 1.5)), 'invalid: quality above 1.0');
		expect(0.85, Options::quality($this->one('quality', 0.05)), 'invalid: quality below 0.1');
		expect(0.85, Options::quality($this->one('quality', 0)), 'invalid: quality zero');
		expect(1048576, Options::minBytes($this->one('minBytes', -1)), 'invalid: minBytes negative');
		expect(1048576, Options::minBytes($this->one('minBytes', '1000')), 'invalid: minBytes as string');
		expect(15728640, Options::maxUploadBytes($this->one('maxUploadBytes', 0)), 'invalid: maxUploadBytes zero');
		expect(15728640, Options::maxUploadBytes($this->one('maxUploadBytes', -5)), 'invalid: maxUploadBytes negative');
		expect(40000000, Options::maxPixels($this->one('maxPixels', 0)), 'invalid: maxPixels zero');
		expect(40000000, Options::maxPixels($this->one('maxPixels', 'lots')), 'invalid: maxPixels as string');
		expect(2400, Options::maxEdge($this->one('maxEdge', null)), 'null counts as not set');
	}

	public function testLanguageComesFromTheOptionThenTheSiteThenTheLocaleThenEnglish(): void
	{
		expect('de', Options::language($this->app([], self::GERMAN)), 'language: site default German');
		expect('en', Options::language($this->app([], self::ENGLISH)), 'language: site default English');
		expect('de', Options::language($this->app(['tegaru.language' => 'de'], self::ENGLISH)), 'language: the option beats the site language');
		expect('en', Options::language($this->app(['tegaru.language' => 'en'], self::GERMAN)), 'language: the option beats the site language (other way)');
		expect('en', Options::language($this->app(['tegaru.language' => 'fr'], self::ENGLISH)), 'language: unknown option falls back to the site language');
		expect('de', Options::language($this->app(['tegaru.language' => 'fr'], self::GERMAN)), 'language: unknown option, German site');
		expect('de', Options::language($this->app(['tegaru.language' => 'DE'])), 'language: option is case-insensitive');
		expect('de', Options::language($this->app(['locale' => 'de_DE'])), 'language: from the locale option');
		expect('de', Options::language($this->app(['locale' => [LC_ALL => 'de_DE.UTF-8']])), 'language: locale given as array with LC_ALL');
		expect('en', Options::language($this->app(['locale' => 'de_DE'], self::ENGLISH)), 'language: the site language beats the locale');
		expect('en', Options::language($this->app(['locale' => 'fr_FR'])), 'language: unknown locale falls back to English');
	}

	public function testQualityRangeIsInclusiveAndAnIntComesBackAsFloat(): void
	{
		expect(0.1, Options::quality($this->one('quality', 0.1)), 'quality: lower bound 0.1');
		expect(1.0, Options::quality($this->one('quality', 1.0)), 'quality: upper bound 1.0');
		expect(1.0, Options::quality($this->one('quality', 1)), 'quality: int 1 comes back as float 1.0');
	}
}
