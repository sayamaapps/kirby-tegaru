<?php

use Kirby\Cms\App;
use Kirby\Filesystem\Dir;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Sayamaapps\Tegaru\Options;

/**
 * Options are read as sayamaapps.tegaru.<key>, with the 1.x spelling
 * tegaru.<key> as fallback. The fallback is reported as a deprecation
 * in debug mode.
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

	public function testTheNewSpellingIsRead(): void
	{
		$kirby = $this->app(['sayamaapps.tegaru.active' => true, 'sayamaapps.tegaru.maxEdge' => 1920]);
		expect(true, Options::active($kirby), 'sayamaapps.tegaru.active is read');
		expect(1920, Options::maxEdge($kirby), 'sayamaapps.tegaru.maxEdge is read');
	}

	public function testTheNewSpellingWinsOverTheOldOneWithoutADeprecation(): void
	{
		$deprecations = $this->collectDeprecations(function (): void {
			$kirby = $this->app(['debug' => true, 'sayamaapps.tegaru.active' => false, 'tegaru.active' => true]);
			expect(false, Options::active($kirby), 'sayamaapps.tegaru.active wins when both are set');
		});
		expect([], $deprecations, 'the old spelling is not read, so nothing is reported');
	}

	public function testTheOldSpellingIsReportedOncePerKeyAndApp(): void
	{
		$deprecations = $this->collectDeprecations(function (): void {
			$kirby = $this->app(['debug' => true, 'tegaru.active' => true]);
			Options::active($kirby);
			Options::active($kirby);
			Options::forPanel($kirby);
			expect(true, Options::active($kirby), 'tegaru.active keeps being read as fallback');

			Options::active($this->app(['debug' => true, 'tegaru.active' => true]));
		});
		expect(
			[
				'Tegaru: tegaru.active is deprecated, use sayamaapps.tegaru.active',
				'Tegaru: tegaru.active is deprecated, use sayamaapps.tegaru.active',
			],
			$deprecations,
			'one report per App; a second App reports again'
		);
	}

	public function testTheOldSpellingIsSilentOutsideDebugMode(): void
	{
		$deprecations = $this->collectDeprecations(function (): void {
			$kirby = $this->app(['tegaru.active' => true]);
			expect(true, Options::active($kirby), 'tegaru.active is read without debug');
		});
		expect([], $deprecations, 'no deprecation without debug mode');
	}

	public function testTheOldSpellingStillWorksAndIsDeprecatedInDebugMode(): void
	{
		$deprecations = $this->collectDeprecations(function (): void {
			$kirby = $this->app(['debug' => true, 'tegaru.active' => true]);
			expect(true, Options::active($kirby), 'tegaru.active is read as fallback');
		});
		expect(['Tegaru: tegaru.active is deprecated, use sayamaapps.tegaru.active'], $deprecations, 'the fallback is reported');
	}

	public function testTheOptionsBlockDeclaresEveryKeyWithANullDefault(): void
	{
		$block = require dirname(__DIR__) . '/config/options.php';
		expect(['active', 'language', 'maxEdge', 'maxPixels', 'maxUploadBytes', 'minBytes', 'quality'], array_keys($block), 'the seven keys');
		expect([null, null, null, null, null, null, null], array_values($block), 'null defaults: the 1.x fallback must stay visible');
	}

	public function testThePrefixConstantsKeepTheirValues(): void
	{
		expect('tegaru.', Options::PREFIX, 'PREFIX is the 1.x prefix, unchanged for code that reads it');
		expect('sayamaapps.tegaru.', Options::OPTION_PREFIX, 'OPTION_PREFIX is the prefix of the new names');
	}
}
