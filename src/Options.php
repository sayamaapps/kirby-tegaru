<?php

namespace Tegaru;

use Kirby\Cms\App;

/**
 * Reads `tegaru.*` options.
 *
 * `null` counts as "not set", like Kirby's own option(), so a key set to
 * null falls back to the default. Both spellings work, flat
 * (`'tegaru.active' => true`) and nested (`'tegaru' => ['active' => true]`).
 *
 * Defaults are passed as fallback values at each call, NOT in an 'options'
 * block of the plugin (Kirby would file them under sayamaapps.tegaru.*).
 */
final class Options
{
    public const PREFIX = 'tegaru.';

    /** Longest edge of the browser-side result, in pixels. */
    public const DEFAULT_MAX_EDGE = 2400;
    /** JPEG quality of the browser-side result, 0–1. */
    public const DEFAULT_QUALITY = 0.85;
    /** Files below this size are not touched in the browser. */
    public const DEFAULT_MIN_BYTES = 1048576;
    /** Server cap: bytes per uploaded file. */
    public const DEFAULT_MAX_UPLOAD_BYTES = 15728640;
    /** Server cap: pixels (width × height) per uploaded raster image. */
    public const DEFAULT_MAX_PIXELS = 40000000;

    /** Format of the options route; it increases only when a field is removed, renamed or changes meaning. */
    public const FORMAT = 1;

    public static function get(App $kirby, string $key, mixed $default = null): mixed
    {
        return $kirby->option(self::PREFIX . $key) ?? $default;
    }

    /** Off by default: without `'tegaru.active' => true` the plugin does nothing. */
    public static function active(App $kirby): bool
    {
        return self::get($kirby, 'active', false) === true;
    }

    public static function maxEdge(App $kirby): int
    {
        return self::positiveInt($kirby, 'maxEdge', self::DEFAULT_MAX_EDGE);
    }

    /** Quality is clamped to 0.1–1.0; anything else falls back to the default. */
    public static function quality(App $kirby): float
    {
        $value = self::get($kirby, 'quality', self::DEFAULT_QUALITY);
        if (!is_int($value) && !is_float($value)) {
            return self::DEFAULT_QUALITY;
        }
        $value = (float) $value;
        return $value >= 0.1 && $value <= 1.0 ? $value : self::DEFAULT_QUALITY;
    }

    public static function minBytes(App $kirby): int
    {
        return self::nonNegativeInt($kirby, 'minBytes', self::DEFAULT_MIN_BYTES);
    }

    public static function maxUploadBytes(App $kirby): int
    {
        return self::positiveInt($kirby, 'maxUploadBytes', self::DEFAULT_MAX_UPLOAD_BYTES);
    }

    public static function maxPixels(App $kirby): int
    {
        return self::positiveInt($kirby, 'maxPixels', self::DEFAULT_MAX_PIXELS);
    }

    /**
     * Language of the messages: `tegaru.language`, otherwise the site's
     * default language, otherwise the first two letters of Kirby's `locale`
     * option, otherwise English. Only languages Tegaru ships are returned.
     */
    public static function language(App $kirby): string
    {
        $candidates = [self::get($kirby, 'language')];
        try {
            $candidates[] = $kirby->defaultLanguage()?->code();
        } catch (\Throwable) {
            // No languages configured.
        }
        $locale = $kirby->option('locale');
        if (is_array($locale)) {
            $locale = $locale[LC_ALL] ?? reset($locale);
        }
        $candidates[] = is_string($locale) ? substr($locale, 0, 2) : null;

        foreach ($candidates as $candidate) {
            if (is_string($candidate) && in_array(strtolower($candidate), I18n::LANGUAGES, true)) {
                return strtolower($candidate);
            }
        }
        return 'en';
    }

    /**
     * What the Panel script needs, as the options route returns it
     * (`GET /api/tegaru/options`, Panel users only).
     *
     * @return array{format:int,active:bool,maxEdge:int,quality:float,minBytes:int,maxUploadBytes:int,maxPixels:int,language:string}
     */
    public static function forPanel(App $kirby): array
    {
        // The fields of the options route and their defaults, in this order.
        return [
            'format'         => self::FORMAT,
            'active'         => self::active($kirby),
            'maxEdge'        => self::maxEdge($kirby),
            'quality'        => self::quality($kirby),
            'minBytes'       => self::minBytes($kirby),
            'maxUploadBytes' => self::maxUploadBytes($kirby),
            'maxPixels'      => self::maxPixels($kirby),
            'language'       => self::language($kirby),
        ];
    }

    private static function positiveInt(App $kirby, string $key, int $default): int
    {
        $value = self::get($kirby, $key, $default);
        return is_int($value) && $value > 0 ? $value : $default;
    }

    private static function nonNegativeInt(App $kirby, string $key, int $default): int
    {
        $value = self::get($kirby, $key, $default);
        return is_int($value) && $value >= 0 ? $value : $default;
    }
}
