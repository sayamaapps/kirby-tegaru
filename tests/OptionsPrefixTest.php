<?php

use Kirby\Cms\App;
use Kirby\Filesystem\Dir;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Sayamaapps\Tegaru\Options;

/**
 * Options are read as sayamaapps.tegaru.<key>, falling back to the 1.x names
 * tegaru.<key>. A set new name wins. No notice is raised for the 1.x spelling.
 */
#[CoversClass(Options::class)]
final class OptionsPrefixTest extends TestCase
{
	private string $tmp;

	/** A new Kirby app on the throwaway site with these options. */
	private function app(array $options = []): App
	{
		return new App([
			'roots' => [
				'index' => $this->tmp . '/public', 'content' => $this->tmp . '/content', 'site' => $this->tmp . '/site',
				'storage' => $this->tmp . '/storage', 'media' => $this->tmp . '/public/media',
			],
			'options' => ['url' => 'https://example.com'] + $options,
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

	protected function setUp(): void
	{
		$this->tmp = sys_get_temp_dir() . '/tegaru-prefix-' . bin2hex(random_bytes(6));
		foreach (['public', 'content', 'site/templates', 'site/snippets', 'storage'] as $dir) {
			mkdir($this->tmp . '/' . $dir, 0700, true);
		}
	}

	protected function tearDown(): void
	{
		App::destroy();
		Dir::remove($this->tmp);
	}

	public function testANewValueThatIsInvalidIsNotReplacedByTheOneXSpelling(): void
	{
		$kirby = $this->app(['sayamaapps.tegaru.maxEdge' => '1920', 'tegaru.maxEdge' => 1000]);
		expect(2400, Options::maxEdge($kirby), 'set but invalid new name: the default, not the 1.x value');
	}

	public function testANullNewValueFallsBackToTheOneXSpelling(): void
	{
		$kirby = $this->app(['sayamaapps.tegaru.maxEdge' => null, 'tegaru.maxEdge' => 1920]);
		expect(1920, Options::maxEdge($kirby), 'null new name: the 1.x value is read');
	}

	public function testNotSetFallsBackToTheDefault(): void
	{
		$kirby = $this->app();
		expect(false, Options::active($kirby), 'unset: default off');
		expect(2400, Options::maxEdge($kirby), 'unset: default maxEdge');
	}

	public function testTheLanguageOptionUsesTheNewSpellingToo(): void
	{
		expect('de', Options::language($this->app(['sayamaapps.tegaru.language' => 'de'])), 'language: new spelling');
		expect('de', Options::language($this->app(['tegaru.language' => 'de'])), 'language: 1.x spelling');
	}

	public function testTheNestedOneXSpellingIsRead(): void
	{
		$kirby = $this->app(['tegaru' => ['active' => true, 'maxEdge' => 1920]]);
		expect(true, Options::active($kirby), 'nested 1.x: active');
		expect(1920, Options::maxEdge($kirby), 'nested 1.x: maxEdge');
	}

	public function testTheNewSpellingIsRead(): void
	{
		$kirby = $this->app(['sayamaapps.tegaru.active' => true, 'sayamaapps.tegaru.maxEdge' => 1920]);
		expect(true, Options::active($kirby), 'sayamaapps.tegaru.active is read');
		expect(1920, Options::maxEdge($kirby), 'sayamaapps.tegaru.maxEdge is read');
	}

	public function testTheNewSpellingWinsWhenBothAreSet(): void
	{
		$kirby = $this->app([
			'sayamaapps.tegaru.active' => false, 'tegaru.active' => true,
			'sayamaapps.tegaru.maxEdge' => 1920, 'tegaru.maxEdge' => 1000,
		]);
		expect(false, Options::active($kirby), 'both set: the new name wins (false)');
		expect(1920, Options::maxEdge($kirby), 'both set: the new name wins (1920)');
	}

	public function testTheOneXSpellingIsReadWithoutANotice(): void
	{
		$deprecations = $this->collectDeprecations(function (): void {
			$kirby = $this->app(['debug' => true, 'tegaru.active' => true, 'tegaru.maxEdge' => 1920]);
			expect(true, Options::active($kirby), 'tegaru.active is read in debug mode');
			expect(1920, Options::maxEdge($kirby), 'tegaru.maxEdge is read');
			Options::forPanel($kirby);
		});
		expect([], $deprecations, 'no runtime notice for the 1.x spelling');
	}

	public function testThePrefixesKeepTheirValues(): void
	{
		expect('tegaru.', Options::PREFIX, 'PREFIX is the 1.x prefix, unchanged for code that reads it');
		expect('sayamaapps.tegaru.', Options::OPTION_PREFIX, 'OPTION_PREFIX is the 2.0 spelling');
	}
}
