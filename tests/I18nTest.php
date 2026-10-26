<?php

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Tegaru\I18n;

/**
 * Translations: every language has the same keys and placeholders as
 * English; the keys the hook and the Panel script use exist; an unknown
 * language falls back to English; t() fills {placeholders}; a missing key
 * comes back as the key.
 */
#[CoversClass(I18n::class)]
final class I18nTest extends TestCase
{
    public function testEveryLanguageHasTheEnglishKeysAndPlaceholders(): void
    {
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
                expect(self::placeholders($text), self::placeholders($table[$key]), "$language.$key: same placeholders as English");
            }
        }
    }

    public function testUnknownLanguageFallsBackToEnglish(): void
    {
        expect([], I18n::table('fr'), 'unknown language: no table');

        I18n::setLanguage('fr');
        expect('en', I18n::language(), 'unknown language falls back to English');
    }

    public function testGermanIsSetAndTheValuesAreFilledIn(): void
    {
        I18n::setLanguage('de');
        expect('de', I18n::language(), 'German is set');
        expect(true, str_starts_with(I18n::t('error.heic'), 'HEIC-Fotos können hier nicht verarbeitet werden.'), 'German string');
        expect('Die Datei ist 3.7 MB groß, erlaubt sind 1.0 MB. Tipp: das Foto aus der Fotos-App hochladen, nicht aus „Dateien“.', I18n::t('error.size', ['size' => '3.7', 'max' => '1.0']), 'German placeholders filled');
        I18n::setLanguage('en');
    }

    public function testEnglishPlaceholdersAreFilledInAndValuesAreNotEscaped(): void
    {
        I18n::setLanguage('en');
        expect('The file is 3.7 MB; the maximum is 1.0 MB. Tip: upload the photo from the Photos app, not from Files.', I18n::t('error.size', ['size' => '3.7', 'max' => '1.0']), 'English placeholders filled');
        expect('<b>x</b> could not be read as an image; it is uploaded unchanged.', I18n::t('panel.unreadable', ['name' => '<b>x</b>']), 'values are inserted unescaped');
    }

    public function testMissingKeyComesBackAsTheKey(): void
    {
        expect('nope.key', I18n::t('nope.key'), 'missing key comes back as the key');
        expect('nope.key', I18n::t('nope.key', ['name' => 'x']), 'missing key with values comes back as the key');
    }

    /** The placeholder names of a text, sorted and unique. */
    private static function placeholders(string $text): array
    {
        preg_match_all('/\{(\w+)\}/', $text, $matches);
        $names = array_unique($matches[1]);
        sort($names);
        return $names;
    }
}
