<?php
/**
 * Translations: every language has the same keys and placeholders as
 * English; the keys the hook and the Panel script use exist; an unknown
 * language falls back to English; t() fills {placeholders}; a missing key
 * comes back as the key.
 *   php tests/I18nTest.php
 */
require_once __DIR__ . '/../src/I18n.php';

use Tegaru\I18n;

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
$placeholders = function (string $text): array {
    preg_match_all('/\{(\w+)\}/', $text, $m);
    $names = array_unique($m[1]);
    sort($names);
    return $names;
};

try {
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
            expect($placeholders($text), $placeholders($table[$key]), "$language.$key: same placeholders as English");
        }
    }
    expect([], I18n::table('fr'), 'unknown language: no table');

    I18n::setLanguage('fr');
    expect('en', I18n::language(), 'unknown language falls back to English');
    I18n::setLanguage('de');
    expect('de', I18n::language(), 'German is set');
    expect(true, str_starts_with(I18n::t('error.heic'), 'HEIC-Fotos können hier nicht verarbeitet werden.'), 'German string');
    expect('Die Datei ist 3.7 MB groß, erlaubt sind 1.0 MB. Tipp: das Foto aus der Fotos-App hochladen, nicht aus „Dateien“.', I18n::t('error.size', ['size' => '3.7', 'max' => '1.0']), 'German placeholders filled');
    I18n::setLanguage('en');
    expect('The file is 3.7 MB; the maximum is 1.0 MB. Tip: upload the photo from the Photos app, not from Files.', I18n::t('error.size', ['size' => '3.7', 'max' => '1.0']), 'English placeholders filled');
    expect('<b>x</b> could not be read as an image; it is uploaded unchanged.', I18n::t('panel.unreadable', ['name' => '<b>x</b>']), 'values are inserted unescaped');
    expect('nope.key', I18n::t('nope.key'), 'missing key comes back as the key');
    expect('nope.key', I18n::t('nope.key', ['name' => 'x']), 'missing key with values comes back as the key');

    echo "I18nTest: $checks checks passed\n";
} catch (Throwable $e) {
    fwrite(STDERR, 'I18nTest FAILED: ' . $e->getMessage() . "\n");
    exit(1);
}
