<?php

use Kirby\Cms\App;
use Kirby\Filesystem\Dir;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Sayamaapps\Tegaru\Options;

/**
 * Options are read as tegaru.<key>, the 1.x names, and only those. The
 * sayamaapps.tegaru.<key> names are planned for 2.0 and are not read in 1.x.
 * No notice is raised for the 1.x spelling.
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

	public function testNotSetFallsBackToTheDefault(): void
	{
		$kirby = $this->app();
		expect(false, Options::active($kirby), 'unset: default off');
		expect(2400, Options::maxEdge($kirby), 'unset: default maxEdge');
	}

	public function testReadingRaisesNoNoticeInDebugMode(): void
	{
		$deprecations = $this->collectDeprecations(function (): void {
			$kirby = $this->app(['debug' => true, 'tegaru.active' => true]);
			expect(true, Options::active($kirby), 'tegaru.active is read in debug mode');
			Options::forPanel($kirby);
		});
		expect([], $deprecations, 'no runtime notice for the 1.x spelling');
	}

	public function testTheNewSpellingIsNotReadYet(): void
	{
		$kirby = $this->app(['sayamaapps.tegaru.active' => true, 'sayamaapps.tegaru.maxEdge' => 1920]);
		expect(false, Options::active($kirby), 'sayamaapps.tegaru.active is not read in 1.x');
		expect(2400, Options::maxEdge($kirby), 'sayamaapps.tegaru.maxEdge is not read in 1.x');
	}

	public function testTheOneXSpellingIsRead(): void
	{
		$kirby = $this->app(['tegaru.active' => true, 'tegaru.maxEdge' => 1920]);
		expect(true, Options::active($kirby), 'tegaru.active is read');
		expect(1920, Options::maxEdge($kirby), 'tegaru.maxEdge is read');
	}

	public function testThePrefixKeepsItsValue(): void
	{
		expect('tegaru.', Options::PREFIX, 'PREFIX is the 1.x prefix, unchanged for code that reads it');
	}
}
