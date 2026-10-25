<?php
/**
 * The installed Kirby's Panel bundle still has what index.js wraps: the
 * plugin hook `created` in plugins.js, and in index.min.js the upload methods
 * pick/select/submit/upload, the call that runs the plugins' `created`, and
 * the members of the files field that index.js extends.
 * Reads the files only; prints the Kirby version it checked.
 *   php tests/PanelSignatureTest.php
 */
$base = dirname(__DIR__);
$kirby = $base . '/vendor/getkirby/cms';
$checks = 0;
function holds(bool $ok, string $what): void
{
    global $checks;
    if (!$ok) { throw new RuntimeException($what); }
    $checks++;
}

try {
    holds(is_dir($kirby), 'Kirby is installed under vendor/getkirby/cms; run composer install first');

    // The version, from Composer's record of the installed packages.
    $installed = json_decode((string) file_get_contents($base . '/vendor/composer/installed.json'), true);
    $version = null;
    foreach (($installed['packages'] ?? $installed) as $package) {
        if (($package['name'] ?? null) === 'getkirby/cms') {
            $version = $package['version'] ?? null;
        }
    }
    holds(is_string($version) && $version !== '', 'getkirby/cms has a version in vendor/composer/installed.json');

    $plugins = $kirby . '/panel/dist/js/plugins.js';
    holds(is_file($plugins), 'panel/dist/js/plugins.js exists');
    holds(str_contains((string) file_get_contents($plugins), 'created'), 'plugins.js mentions created (the plugin hook)');

    $index = $kirby . '/panel/dist/js/index.min.js';
    holds(is_file($index), 'panel/dist/js/index.min.js exists');
    $bundle = (string) file_get_contents($index);
    foreach (['pick(', 'select(', 'submit(', 'upload('] as $method) {
        holds(str_contains($bundle, $method), "index.min.js contains $method");
    }
    holds(str_contains($bundle, 'plugins.created'), 'index.min.js runs plugins.created');

    // The files field extension (SPEC section D): Kirby resolves a plugin's
    // extends string to the registered component, the files field has its
    // Upload button's members, and the empty field and the Select button both
    // call open(). Patterns allow other variable names of the minifier.
    $field = [
        'a plugin component extends a registered one' => '/\.extends=\w+\[\w+\.extends\]\.extend\(/',
        'k-files-field is registered' => '/component\("k-files-field",/',
        'the files field computes hasDropzone' => '/hasDropzone\(\)\{return!this\.disabled&&this\.more&&this\.uploads\}/',
        'the Upload button picks with uploadOptions' => '/\$panel\.upload\.pick\(this\.uploadOptions\)/',
        'the Select button has the checklist icon and calls open()' => '/text:this\.\$t\("select"\),icon:"checklist",responsive:!0,click:\(\)=>this\.open\(\)/',
        'the empty collection calls open' => '/on:\{empty:\w+\.open,/',
    ];
    foreach ($field as $what => $pattern) {
        holds(preg_match($pattern, $bundle) === 1, "index.min.js: $what");
    }

    echo "PanelSignatureTest: Kirby $version, $checks checks passed\n";
} catch (Throwable $e) {
    fwrite(STDERR, 'PanelSignatureTest FAILED: ' . $e->getMessage() . "\n");
    exit(1);
}
