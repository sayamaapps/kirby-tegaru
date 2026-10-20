<?php
/**
 * Options with a throwaway Kirby app: defaults, flat and nested spelling,
 * invalid values fall back to the default, the quality range, forPanel()
 * shape and order, and language() from the option, the site language, the
 * locale, and English as the last fallback.
 *   php tests/OptionsTest.php
 */
$base = dirname(__DIR__);
if (!is_file($base . '/vendor/autoload.php')) { fwrite(STDERR, "Composer dependencies missing; run composer install first\n"); exit(1); }
require $base . '/vendor/autoload.php';
require_once $base . '/src/I18n.php';
require_once $base . '/src/Options.php';

use Kirby\Cms\App;
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

$tmp = sys_get_temp_dir() . '/tegaru-options-' . bin2hex(random_bytes(6));
/** A new Kirby app with these options and site languages (none unless given). */
$app = function (array $options = [], array $languages = []) use ($tmp): App {
    return new App([
        'roots' => [
            'index' => $tmp . '/public', 'content' => $tmp . '/content', 'site' => $tmp . '/site',
            'storage' => $tmp . '/storage', 'media' => $tmp . '/public/media',
        ],
        'options' => ['url' => 'https://example.com'] + $options,
        'languages' => $languages,
    ]);
};
/** A new app with one option set to $value. */
$one = fn (string $key, mixed $value): App => $app(['tegaru.' . $key => $value]);
$german = [['code' => 'de', 'default' => true, 'url' => 'https://example.com', 'slug' => 'de', 'name' => 'Deutsch']];
$english = [['code' => 'en', 'default' => true, 'url' => 'https://example.com', 'slug' => 'en', 'name' => 'English']];

try {
    foreach (['public', 'content', 'site/templates', 'site/snippets', 'storage'] as $dir) {
        mkdir($tmp . '/' . $dir, 0700, true);
    }

    // Defaults: nothing set.
    $none = $app();
    expect(false, Options::active($none), 'default: off');
    expect(2400, Options::maxEdge($none), 'default: maxEdge');
    expect(0.85, Options::quality($none), 'default: quality');
    expect(1048576, Options::minBytes($none), 'default: minBytes');
    expect(15728640, Options::maxUploadBytes($none), 'default: maxUploadBytes');
    expect(40000000, Options::maxPixels($none), 'default: maxPixels');
    expect('en', Options::language($none), 'default: language');

    // Flat and nested spelling give the same values.
    $values = ['active' => true, 'maxEdge' => 1920, 'quality' => 0.6, 'minBytes' => 0, 'maxUploadBytes' => 5000000, 'maxPixels' => 12000000];
    $flat = $app(array_combine(array_map(fn ($k) => 'tegaru.' . $k, array_keys($values)), $values));
    $nested = $app(['tegaru' => $values]);
    expect(true, Options::active($flat), 'flat: active');
    expect(1920, Options::maxEdge($flat), 'flat: maxEdge');
    expect(0.6, Options::quality($flat), 'flat: quality');
    expect(0, Options::minBytes($flat), 'flat: minBytes 0 means always process');
    expect(5000000, Options::maxUploadBytes($flat), 'flat: maxUploadBytes');
    expect(12000000, Options::maxPixels($flat), 'flat: maxPixels');
    expect(Options::forPanel($flat), Options::forPanel($nested), 'nested spelling gives what the flat one does');

    // Invalid values fall back to the default.
    expect(false, Options::active($one('active', 'yes')), 'invalid: active must be the boolean true');
    expect(false, Options::active($one('active', 1)), 'invalid: active 1 is not true');
    expect(2400, Options::maxEdge($one('maxEdge', '1920')), 'invalid: maxEdge as string');
    expect(2400, Options::maxEdge($one('maxEdge', 0)), 'invalid: maxEdge zero');
    expect(2400, Options::maxEdge($one('maxEdge', -1)), 'invalid: maxEdge negative');
    expect(2400, Options::maxEdge($one('maxEdge', 2.5)), 'invalid: maxEdge float');
    expect(0.85, Options::quality($one('quality', '0.5')), 'invalid: quality as string');
    expect(0.85, Options::quality($one('quality', 1.5)), 'invalid: quality above 1.0');
    expect(0.85, Options::quality($one('quality', 0.05)), 'invalid: quality below 0.1');
    expect(0.85, Options::quality($one('quality', 0)), 'invalid: quality zero');
    expect(1048576, Options::minBytes($one('minBytes', -1)), 'invalid: minBytes negative');
    expect(1048576, Options::minBytes($one('minBytes', '1000')), 'invalid: minBytes as string');
    expect(15728640, Options::maxUploadBytes($one('maxUploadBytes', 0)), 'invalid: maxUploadBytes zero');
    expect(15728640, Options::maxUploadBytes($one('maxUploadBytes', -5)), 'invalid: maxUploadBytes negative');
    expect(40000000, Options::maxPixels($one('maxPixels', 0)), 'invalid: maxPixels zero');
    expect(40000000, Options::maxPixels($one('maxPixels', 'lots')), 'invalid: maxPixels as string');
    expect(2400, Options::maxEdge($one('maxEdge', null)), 'null counts as not set');

    // The quality range is inclusive; an int is accepted and comes back as float.
    expect(0.1, Options::quality($one('quality', 0.1)), 'quality: lower bound 0.1');
    expect(1.0, Options::quality($one('quality', 1.0)), 'quality: upper bound 1.0');
    expect(1.0, Options::quality($one('quality', 1)), 'quality: int 1 comes back as float 1.0');

    // forPanel(): the shape and order the Panel script reads.
    expect(
        ['format' => 1, 'active' => false, 'maxEdge' => 2400, 'quality' => 0.85, 'minBytes' => 1048576, 'maxUploadBytes' => 15728640, 'maxPixels' => 40000000, 'language' => 'en'],
        Options::forPanel($none),
        'forPanel: defaults, in the documented order'
    );
    expect(
        ['format' => 1, 'active' => true, 'maxEdge' => 1920, 'quality' => 0.6, 'minBytes' => 0, 'maxUploadBytes' => 5000000, 'maxPixels' => 12000000, 'language' => 'en'],
        Options::forPanel($flat),
        'forPanel: the options as set'
    );
    expect('de', Options::forPanel($app([], $german))['language'], 'forPanel: language from the site default');

    // language(): option, then site default, then locale, then English.
    expect('de', Options::language($app([], $german)), 'language: site default German');
    expect('en', Options::language($app([], $english)), 'language: site default English');
    expect('de', Options::language($app(['tegaru.language' => 'de'], $english)), 'language: the option beats the site language');
    expect('en', Options::language($app(['tegaru.language' => 'en'], $german)), 'language: the option beats the site language (other way)');
    expect('en', Options::language($app(['tegaru.language' => 'fr'], $english)), 'language: unknown option falls back to the site language');
    expect('de', Options::language($app(['tegaru.language' => 'fr'], $german)), 'language: unknown option, German site');
    expect('de', Options::language($app(['tegaru.language' => 'DE'])), 'language: option is case-insensitive');
    expect('de', Options::language($app(['locale' => 'de_DE'])), 'language: from the locale option');
    expect('de', Options::language($app(['locale' => [LC_ALL => 'de_DE.UTF-8']])), 'language: locale given as array with LC_ALL');
    expect('en', Options::language($app(['locale' => 'de_DE'], $english)), 'language: the site language beats the locale');
    expect('en', Options::language($app(['locale' => 'fr_FR'])), 'language: unknown locale falls back to English');

    echo "OptionsTest: $checks checks passed\n";
} catch (Throwable $e) {
    fwrite(STDERR, 'OptionsTest FAILED: ' . $e->getMessage() . ' (' . basename($e->getFile()) . ':' . $e->getLine() . ")\n");
    $failed = true;
} finally {
    removeTree($tmp);
}
exit(isset($failed) ? 1 : 0);
