<?php
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
 *   php tests/KirbyTest.php
 */
$base = dirname(__DIR__);
if (!is_file($base . '/vendor/autoload.php')) { fwrite(STDERR, "Composer dependencies missing; run composer install first\n"); exit(1); }
require $base . '/vendor/autoload.php';
require $base . '/index.php';

use Kirby\Cms\App;
use Kirby\Cms\File;
use Kirby\Exception\AuthException;
use Kirby\Exception\InvalidArgumentException;
use Kirby\Filesystem\File as Upload;
use Tegaru\Options;

$checks = 0;
function expect($expected, $actual, string $what): void
{
    global $checks;
    if ($expected !== $actual) { throw new RuntimeException($what . ': ' . var_export($actual, true)); }
    $checks++;
}
function holds(bool $ok, string $what): void
{
    global $checks;
    if (!$ok) { throw new RuntimeException($what); }
    $checks++;
}
function removeTree(string $path): void
{
    if (!is_dir($path)) { return; }
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($it as $file) {
        $file->isDir() && !$file->isLink() ? rmdir($file->getPathname()) : unlink($file->getPathname());
    }
    rmdir($path);
}

const MB = 1048576;

$tmp = sys_get_temp_dir() . '/tegaru-kirby-' . bin2hex(random_bytes(6));
/**
 * A new Kirby app with these options; a German site (default language de)
 * when $german. Build it right before a call: App::instance() is the last
 * one built, and tegaru_i18n() reads the language from there.
 */
$app = function (array $options = [], bool $german = false) use ($tmp): App {
    return new App([
        'roots' => [
            'index' => $tmp . '/public', 'content' => $tmp . '/content', 'site' => $tmp . '/site',
            'storage' => $tmp . '/storage', 'media' => $tmp . '/public/media',
        ],
        'options' => ['url' => 'https://example.com'] + $options,
        'languages' => $german ? [['code' => 'de', 'default' => true, 'url' => 'https://example.com', 'slug' => 'de', 'name' => 'Deutsch']] : [],
    ]);
};
/** The hook body on the file at $path; the message of the exception, or null when it lets the file through. */
$guard = function (App $kirby, string $path): ?string {
    try {
        tegaru_guard($kirby, new Upload($path));
        return null;
    } catch (InvalidArgumentException $e) {
        return $e->getMessage();
    }
};

try {
    foreach (['public', 'content', 'site/templates', 'site/snippets', 'storage'] as $dir) {
        mkdir($tmp . '/' . $dir, 0700, true);
    }
    // Fixtures: a 20 MB JPEG-looking file (size is checked first), a small text file, a fake HEIC, a real 1500 × 1000 JPEG.
    $big = $tmp . '/big.jpg';
    file_put_contents($big, "\xFF\xD8\xFF\xE0" . str_repeat("\0", 20 * MB - 4));
    $note = $tmp . '/note.txt';
    file_put_contents($note, 'Hallo Welt');
    $heic = $tmp . '/IMG_0001.HEIC';
    file_put_contents($heic, hex2bin('000000186674797068656963') . str_repeat("\0", 52));
    $jpg = $tmp . '/photo.jpg';
    imagejpeg(imagecreatetruecolor(1500, 1000), $jpg, 80);

    // (a) Off by default: the hook lets everything through.
    expect(null, $guard($app(), $big), 'off: the 20 MB file passes');
    expect(null, $guard($app(['tegaru.active' => false]), $big), 'off: explicitly false, the 20 MB file passes');

    // (b) On: refused, in the language of the site.
    expect(
        'The file is 20.0 MB; the maximum is 15.0 MB. Tip: upload the photo from the Photos app, not from Files.',
        $guard($app(['tegaru.active' => true]), $big),
        'on, English site: the size message'
    );
    expect(
        'Die Datei ist 20.0 MB groß, erlaubt sind 15.0 MB. Tipp: das Foto aus der Fotos-App hochladen, nicht aus „Dateien“.',
        $guard($app(['tegaru.active' => true], true), $big),
        'on, German site: the size message'
    );
    expect(
        'HEIC photos cannot be processed here. Please upload a JPEG (on an iPhone: Settings → Camera → Formats → Most Compatible).',
        $guard($app(['tegaru.active' => true]), $heic),
        'on: HEIC message'
    );
    expect(
        'The image has 1.5 megapixels; the maximum is 1.0.',
        $guard($app(['tegaru.active' => true, 'tegaru.maxPixels' => 1000000]), $jpg),
        'on: pixel message, cap from the option'
    );
    expect(null, $guard($app(['tegaru.active' => true]), $note), 'on: a text file passes');
    expect(null, $guard($app(['tegaru.active' => true, 'tegaru.maxUploadBytes' => 25 * MB]), $big), 'on: cap raised by the option, the 20 MB file passes');

    // (c) The plugin translations, as Kirby registers them and as t() reads them.
    $en = $app();
    $translations = $en->extensions('translations');
    holds(isset($translations['en']['tegaru.error.size']) && isset($translations['de']['tegaru.error.size']), 'translations: tegaru.error.size registered in en and de');
    holds(str_starts_with((string) $en->translation('en')->get('tegaru.error.size'), 'The file is {size} MB'), 'translations: en reads the message');
    holds(str_starts_with((string) $app([], true)->translation('de')->get('tegaru.error.size'), 'Die Datei ist {size} MB'), 'translations: de reads the message');
    holds(str_starts_with((string) $en->translation('en')->get('tegaru.panel.unreadable'), '{name} could not be read'), 'translations: the Panel keys are there too');

    // (d) forPanel() through the real app.
    $panel = $app(['tegaru.active' => true, 'tegaru.maxEdge' => 1920, 'tegaru.quality' => 0.6], true);
    $shape = ['format' => 1, 'active' => true, 'maxEdge' => 1920, 'quality' => 0.6, 'minBytes' => 1048576, 'maxUploadBytes' => 15728640, 'maxPixels' => 40000000, 'language' => 'de'];
    expect($shape, Options::forPanel($panel), 'forPanel: the real app reflects the options');

    // (e) The API route, as Kirby registered it (the routes closure is resolved when the app is built).
    $route = null;
    foreach ($panel->extensions('api')['routes'] ?? [] as $candidate) {
        if (($candidate['pattern'] ?? null) === 'tegaru/options') {
            $route = $candidate;
        }
    }
    holds($route !== null, 'api: tegaru/options is registered');
    expect('GET', $route['method'] ?? null, 'api: GET');
    expect($shape, ($route['action'])(), 'api: the route returns the options array');

    // (f) The hook is registered.
    holds(!empty($panel->extensions('hooks')['file.create:before'] ?? null), 'hook: file.create:before is registered');

    // (g) A replacement goes through the same safety net: the same hook body, the same message.
    $replacing = $app(['tegaru.active' => true]);
    $replacing->impersonate('kirby'); // replacing needs a user with the permission
    holds(!empty($replacing->extensions('hooks')['file.replace:before'] ?? null), 'hook: file.replace:before is registered');
    copy($jpg, $tmp . '/content/replace-me.jpg');
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

    // (h) The options route needs a logged-in Panel user: refused without one, answered with one.
    // One app: impersonating null is the logged-out state.
    $api = $app(['tegaru.active' => true, 'tegaru.maxEdge' => 1920, 'tegaru.quality' => 0.6, 'api.allowImpersonation' => true], true);
    $refusedAnonymous = false;
    try {
        $api->api()->call('tegaru/options', 'GET');
    } catch (AuthException $e) {
        $refusedAnonymous = true;
    }
    holds($refusedAnonymous, 'api: without a login the options route throws AuthException');
    $api->impersonate('kirby');
    expect($shape, $api->api()->call('tegaru/options', 'GET'), 'api: with a logged-in user the route answers with the options');

    echo "KirbyTest: $checks checks passed\n";
} catch (Throwable $e) {
    fwrite(STDERR, 'KirbyTest FAILED: ' . $e->getMessage() . ' (' . basename($e->getFile()) . ':' . $e->getLine() . ")\n");
    $failed = true;
} finally {
    // Kirby commits its sessions in a shutdown function registered during the checks, which runs
    // before this one; removing the temp dir now would make that commit fail on the session file.
    register_shutdown_function(fn () => removeTree($tmp));
}
exit(isset($failed) ? 1 : 0);
