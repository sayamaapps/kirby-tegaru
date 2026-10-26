<?php

use Kirby\Cms\App;
use Kirby\Cms\File;
use Kirby\Exception\AuthException;
use Kirby\Exception\InvalidArgumentException;
use Kirby\Filesystem\Dir;
use Kirby\Filesystem\File as Upload;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use Sayamaapps\Tegaru\Hooks;
use Sayamaapps\Tegaru\Options;

/**
 * The plugin with real Kirby, in a throwaway directory. index.php is
 * required BEFORE the App is built: App::plugin() fills a static registry
 * that the App reads while it is built; registered later, the routes and
 * the translations are missing (verified on Kirby 5.6.1).
 *  (a) tegaru.active off: the hook lets a 20 MB file through;
 *  (b) on: it throws InvalidArgumentException, in German or English after
 *      the site's default language; the options caps apply;
 *  (c) the plugin translations contain tegaru.error.* in en and de;
 *  (d) Options::forPanel() through the real app reflects the options;
 *  (e) the API route tegaru/options is registered and returns that array;
 *  (f) the hook file.create:before is registered;
 *  (g) the hook file.replace:before is registered, and a 20-MB replacement is refused;
 *  (h) the options route refuses a request without a login and answers with one.
 */
#[CoversNothing]
final class KirbyTest extends TestCase
{
	private const MB = 1048576;

	private string $tmp;

	/** A new Kirby app with these options; a German site (default language de) when $german. */
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

	/** A 20 MB JPEG-looking file (size is checked first). */
	private function fixtureBig(): string
	{
		$big = $this->tmp . '/big.jpg';
		file_put_contents($big, "\xFF\xD8\xFF\xE0" . str_repeat("\0", 20 * self::MB - 4));
		return $big;
	}

	/** A fake HEIC: the ftyp box with brand heic and padding. */
	private function fixtureHeic(): string
	{
		$heic = $this->tmp . '/IMG_0001.HEIC';
		file_put_contents($heic, hex2bin('000000186674797068656963') . str_repeat("\0", 52));
		return $heic;
	}

	/** A real 1500 × 1000 JPEG. */
	private function fixtureJpeg(): string
	{
		$jpg = $this->tmp . '/photo.jpg';
		imagejpeg(imagecreatetruecolor(1500, 1000), $jpg, 80);
		return $jpg;
	}

	private function fixtureNote(): string
	{
		$note = $this->tmp . '/note.txt';
		file_put_contents($note, 'Hallo Welt');
		return $note;
	}

	/** The hook body on the file at $path; the message of the exception, or null when it lets the file through. */
	private function guard(App $kirby, string $path): string|null
	{
		try {
			Hooks::guard($kirby, new Upload($path));
			return null;
		} catch (InvalidArgumentException $e) {
			return $e->getMessage();
		}
	}

	/** The options array the Panel script gets with maxEdge 1920, quality 0.6 and German. */
	private function panelShape(): array
	{
		return ['format' => 1, 'active' => true, 'maxEdge' => 1920, 'quality' => 0.6, 'minBytes' => 1048576, 'maxUploadBytes' => 15728640, 'maxPixels' => 40000000, 'language' => 'de'];
	}

	protected function setUp(): void
	{
		$this->tmp = sys_get_temp_dir() . '/tegaru-kirby-' . bin2hex(random_bytes(6));
		foreach (['public', 'content', 'site/templates', 'site/snippets', 'storage'] as $dir) {
			mkdir($this->tmp . '/' . $dir, 0700, true);
		}
	}

	public static function setUpBeforeClass(): void
	{
		// The plugin registers itself with App::plugin(); it must be loaded once before any App is built.
		require_once dirname(__DIR__) . '/index.php';
	}

	protected function tearDown(): void
	{
		// Kirby commits its sessions in a shutdown function registered during the checks, which runs
		// before this one; removing the temp dir now would make that commit fail on the session file.
		$tmp = $this->tmp;
		register_shutdown_function(fn () => Dir::remove($tmp));
	}

	public function testAReplacementGoesThroughTheSameSafetyNet(): void
	{
		$big = $this->fixtureBig();
		$jpg = $this->fixtureJpeg();

		$replacing = $this->app(['tegaru.active' => true]);
		$replacing->impersonate('kirby'); // replacing needs a user with the permission
		holds(!empty($replacing->extensions('hooks')['file.replace:before'] ?? null), 'hook: file.replace:before is registered');
		copy($jpg, $this->tmp . '/content/replace-me.jpg');
		$target = $replacing->site()->file('replace-me.jpg');
		holds($target !== null, 'replace: the site file is found');
		$refused = null;
		try {
			$target->replace($big);
		} catch (InvalidArgumentException $e) {
			$refused = $e->getMessage();
		}
		expect(
			'The file is 20.0 MB; the maximum is 15.0 MB. Tip: upload the photo from the Photos app, not from Files.',
			$refused,
			'replace: the 20-MB replacement is refused with the size message'
		);
		holds($target->replace($jpg) instanceof File, 'replace: a normal replacement goes through');
	}

	public function testForPanelThroughTheRealAppReflectsTheOptions(): void
	{
		$panel = $this->app(['tegaru.active' => true, 'tegaru.maxEdge' => 1920, 'tegaru.quality' => 0.6], true);
		expect($this->panelShape(), Options::forPanel($panel), 'forPanel: the real app reflects the options');
	}

	public function testTheApiRouteIsRegisteredAndReturnsTheOptions(): void
	{
		$panel = $this->app(['tegaru.active' => true, 'tegaru.maxEdge' => 1920, 'tegaru.quality' => 0.6], true);
		$route = null;
		foreach ($panel->extensions('api')['routes'] ?? [] as $candidate) {
			if (($candidate['pattern'] ?? null) === 'tegaru/options') {
				$route = $candidate;
			}
		}
		holds($route !== null, 'api: tegaru/options is registered');
		expect('GET', $route['method'] ?? null, 'api: GET');
		expect($this->panelShape(), ($route['action'])(), 'api: the route returns the options array');
	}

	public function testTheCreateHookIsRegistered(): void
	{
		$panel = $this->app(['tegaru.active' => true], true);
		holds(!empty($panel->extensions('hooks')['file.create:before'] ?? null), 'hook: file.create:before is registered');
	}

	public function testTheHookLetsEverythingThroughWhileOff(): void
	{
		$big = $this->fixtureBig();
		expect(null, $this->guard($this->app(), $big), 'off: the 20 MB file passes');
		expect(null, $this->guard($this->app(['tegaru.active' => false]), $big), 'off: explicitly false, the 20 MB file passes');
	}

	public function testTheHookRefusesInTheLanguageOfTheSite(): void
	{
		$big = $this->fixtureBig();
		$heic = $this->fixtureHeic();
		$jpg = $this->fixtureJpeg();
		$note = $this->fixtureNote();

		expect(
			'The file is 20.0 MB; the maximum is 15.0 MB. Tip: upload the photo from the Photos app, not from Files.',
			$this->guard($this->app(['tegaru.active' => true]), $big),
			'on, English site: the size message'
		);
		expect(
			'Die Datei ist 20.0 MB groß, erlaubt sind 15.0 MB. Tipp: das Foto aus der Fotos-App hochladen, nicht aus „Dateien“.',
			$this->guard($this->app(['tegaru.active' => true], true), $big),
			'on, German site: the size message'
		);
		expect(
			'HEIC photos cannot be processed here. Please upload a JPEG (on an iPhone: Settings → Camera → Formats → Most Compatible).',
			$this->guard($this->app(['tegaru.active' => true]), $heic),
			'on: HEIC message'
		);
		expect(
			'The image has 1.5 megapixels; the maximum is 1.0.',
			$this->guard($this->app(['tegaru.active' => true, 'tegaru.maxPixels' => 1000000]), $jpg),
			'on: pixel message, cap from the option'
		);
		expect(null, $this->guard($this->app(['tegaru.active' => true]), $note), 'on: a text file passes');
		expect(null, $this->guard($this->app(['tegaru.active' => true, 'tegaru.maxUploadBytes' => 25 * self::MB]), $big), 'on: cap raised by the option, the 20 MB file passes');
	}

	public function testTheHookRunsWithTheNewOptionNameInFlatForm(): void
	{
		$big = $this->fixtureBig();
		expect(
			'The file is 20.0 MB; the maximum is 15.0 MB. Tip: upload the photo from the Photos app, not from Files.',
			$this->guard($this->app(['sayamaapps.tegaru.active' => true]), $big),
			'on with sayamaapps.tegaru.active: the size message'
		);
	}

	public function testTheOptionsRouteNeedsALoggedInPanelUser(): void
	{
		// One app: impersonating null is the logged-out state.
		$api = $this->app(['tegaru.active' => true, 'tegaru.maxEdge' => 1920, 'tegaru.quality' => 0.6, 'api.allowImpersonation' => true], true);
		$refusedAnonymous = false;
		try {
			$api->api()->call('tegaru/options', 'GET');
		} catch (AuthException) {
			$refusedAnonymous = true;
		}
		holds($refusedAnonymous, 'api: without a login the options route throws AuthException');
		$api->impersonate('kirby');
		expect($this->panelShape(), $api->api()->call('tegaru/options', 'GET'), 'api: with a logged-in user the route answers with the options');
	}

	public function testThePluginTranslationsAreRegisteredInEnglishAndGerman(): void
	{
		$en = $this->app();
		$translations = $en->extensions('translations');
		holds(isset($translations['en']['tegaru.error.size']) && isset($translations['de']['tegaru.error.size']), 'translations: tegaru.error.size registered in en and de');
		holds(str_starts_with((string)$en->translation('en')->get('tegaru.error.size'), 'The file is {size} MB'), 'translations: en reads the message');
		holds(str_starts_with((string)$this->app([], true)->translation('de')->get('tegaru.error.size'), 'Die Datei ist {size} MB'), 'translations: de reads the message');
		holds(str_starts_with((string)$en->translation('en')->get('tegaru.panel.unreadable'), '{name} could not be read'), 'translations: the Panel keys are there too');
	}
}
