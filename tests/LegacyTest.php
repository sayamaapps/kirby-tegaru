<?php

use Kirby\Cms\App;
use Kirby\Exception\InvalidArgumentException;
use Kirby\Filesystem\Dir;
use Kirby\Filesystem\File as Upload;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use Sayamaapps\Tegaru\I18n;

/**
 * The 1.x functions of legacy.php: they still work, delegate to the class
 * methods and report a deprecation in debug mode (README, Deprecations).
 */
#[CoversNothing]
final class LegacyTest extends TestCase
{
	private const MB = 1048576;

	private string $tmp;

	/** A new Kirby app on the throwaway site; it becomes App::instance(). */
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
		$this->tmp = sys_get_temp_dir() . '/tegaru-legacy-' . bin2hex(random_bytes(6));
		foreach (['public', 'content', 'site', 'storage'] as $dir) {
			mkdir($this->tmp . '/' . $dir, 0700, true);
		}
	}

	protected function tearDown(): void
	{
		Dir::remove($this->tmp);
	}

	public function testTheOldGuardFunctionRefusesLikeTheHookAndIsDeprecated(): void
	{
		require_once dirname(__DIR__) . '/legacy.php';

		$big = $this->tmp . '/big.jpg';
		file_put_contents($big, "\xFF\xD8\xFF\xE0" . str_repeat("\0", 20 * self::MB - 4));

		$refused = null;
		$deprecations = $this->collectDeprecations(function () use ($big, &$refused): void {
			$kirby = $this->app(['debug' => true, 'sayamaapps.tegaru.active' => true]);
			try {
				tegaru_guard($kirby, new Upload($big));
			} catch (InvalidArgumentException $e) {
				$refused = $e->getMessage();
			}
		});

		expect(
			'The file is 20.0 MB; the maximum is 15.0 MB. Tip: upload the photo from the Photos app, not from Files.',
			$refused,
			'tegaru_guard() refuses the 20-MB file with the hook message'
		);
		expect(['tegaru_guard() is deprecated, use Sayamaapps\Tegaru\Hooks::guard()'], $deprecations, 'tegaru_guard() is reported');
	}

	public function testTheOldLanguageFunctionSetsTheLanguageAndIsDeprecated(): void
	{
		require_once dirname(__DIR__) . '/legacy.php';

		$deprecations = $this->collectDeprecations(function (): void {
			$this->app(['debug' => true], [['code' => 'de', 'default' => true, 'url' => 'https://example.com', 'slug' => 'de', 'name' => 'Deutsch']]);
			tegaru_i18n();
		});

		expect('de', I18n::language(), 'tegaru_i18n() sets the language of the site');
		expect(['tegaru_i18n() is deprecated, use Sayamaapps\Tegaru\Options::applyLanguage()'], $deprecations, 'tegaru_i18n() is reported');
		I18n::setLanguage('en');
	}
}
