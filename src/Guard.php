<?php

namespace Sayamaapps\Tegaru;

/**
 * The server-side safety net, Kirby-free: looks at an uploaded file on disk
 * and says whether it may be created. The caller (Hooks::guard()) turns a
 * violation into the exception the Panel shows.
 *
 * Rules (see docs/SPEC.md), checked in this order; the first violation wins:
 *  - size:   filesize > $maxBytes
 *            → ['key' => 'error.size', 'values' => ['size' => <MB>, 'max' => <MB>]]
 *  - heic:   magic bytes say HEIC/HEIF
 *            → ['key' => 'error.heic', 'values' => []]
 *  - pixels: JPEG/PNG/GIF/WebP (by magic bytes) with width × height > $maxPixels
 *            → ['key' => 'error.pixels', 'values' => ['megapixels' => <MP>, 'max' => <MP>]]
 *  - anything else (PDF, SVG, unreadable, a file getimagesize() cannot read) → null
 *
 * MB is MiB (1,048,576 bytes), the unit of the 15 MB default; MP is 1,000,000
 * pixels. Both are strings with one decimal and a point, not localised.
 * Only the first 32 bytes are read for sniffing; getimagesize() runs only for
 * raster images.
 *
 * @package   Kirby Tegaru
 * @author    Simon Räder
 * @link      https://github.com/sayamaapps/kirby-tegaru
 * @copyright Simon Räder
 * @license   https://opensource.org/licenses/MIT
 * @since 1.0.0
 */
class Guard
{
	/** Bytes read from the start of a file; every magic number used here fits. */
	private const HEAD_BYTES = 32;

	/**
	 * HEIC/HEIF brands in the `ftyp` box; also mif1/msf1 containers.
	 * @since 1.0.0
	 */
	public const HEIC_BRANDS = ['heic', 'heix', 'hevc', 'hevx', 'heim', 'heis', 'mif1', 'msf1'];
	private const MEGAPIXEL = 1000000;
	private const MIB = 1048576;

	/**
	 * Checks an uploaded file. Null means the file may be created.
	 *
	 * @return array{key:string,values:array<string,string>}|null
	 * @since 1.0.0
	 */
	public static function inspect(string $path, int $maxBytes, int $maxPixels): array|null
	{
		// Unreadable files are left to Kirby's own checks.
		if (is_file($path) === false || is_readable($path) === false) {
			return null;
		}

		$size = filesize($path);
		if ($size === false) {
			return null;
		}
		if ($size > $maxBytes) {
			return ['key' => 'error.size', 'values' => ['size' => self::mib($size), 'max' => self::mib($maxBytes)]];
		}

		$type = self::typeOf($path);
		if ($type === 'heic') {
			return ['key' => 'error.heic', 'values' => []];
		}
		if (in_array($type, ['jpeg', 'png', 'gif', 'webp'], true) === false) {
			return null;
		}
		return self::pixelViolation($path, $maxPixels);
	}

	/** Pixels as megapixels with one decimal, e.g. '1.5'. */
	private static function megapixels(int $pixels): string
	{
		return number_format($pixels / self::MEGAPIXEL, 1, '.', '');
	}

	/** Bytes as MiB with one decimal, e.g. '3.7'. */
	private static function mib(int $bytes): string
	{
		return number_format($bytes / self::MIB, 1, '.', '');
	}

	/** The pixel violation of a raster image; null when it is within the cap or not readable as an image. */
	private static function pixelViolation(string $path, int $maxPixels): array|null
	{
		$info = getimagesize($path);
		if ($info === false) {
			return null; // not readable as an image: Kirby's own checks take over
		}
		$pixels = $info[0] * $info[1];
		if ($pixels <= $maxPixels) {
			return null;
		}
		return [
			'key'    => 'error.pixels',
			'values' => ['megapixels' => self::megapixels($pixels), 'max' => self::megapixels($maxPixels)],
		];
	}

	/**
	 * 'jpeg' | 'png' | 'gif' | 'webp' | 'heic' | 'avif' | 'other', from the first bytes.
	 * @since 1.0.0
	 */
	public static function sniff(string $head): string
	{
		$head = substr($head, 0, self::HEAD_BYTES);
		return self::sniffImage($head) ?? self::sniffFtyp($head) ?? 'other';
	}

	/** HEIC/HEIF or AVIF from the `ftyp` box at offset 4, or null. */
	private static function sniffFtyp(string $head): string|null
	{
		if (substr($head, 4, 4) !== 'ftyp') {
			return null;
		}
		$brand = substr($head, 8, 4);
		if (in_array($brand, self::HEIC_BRANDS, true)) {
			return 'heic';
		}
		if ($brand === 'avif' || $brand === 'avis') {
			return 'avif';
		}
		return null;
	}

	/** JPEG, PNG, GIF or WebP by their first bytes, or null. */
	private static function sniffImage(string $head): string|null
	{
		if (str_starts_with($head, "\xFF\xD8\xFF")) {
			return 'jpeg';
		}
		if (str_starts_with($head, "\x89PNG\r\n\x1A\n")) {
			return 'png';
		}
		if (str_starts_with($head, 'GIF8')) {
			return 'gif';
		}
		if (str_starts_with($head, 'RIFF') && substr($head, 8, 4) === 'WEBP') {
			return 'webp';
		}
		return null;
	}

	/** The format of a file, from its first bytes: see sniff(). */
	private static function typeOf(string $path): string
	{
		$handle = fopen($path, 'rb');
		if ($handle === false) {
			return 'other';
		}
		$head = (string)fread($handle, self::HEAD_BYTES);
		fclose($handle);
		return self::sniff($head);
	}
}
