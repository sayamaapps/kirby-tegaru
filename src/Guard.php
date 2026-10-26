<?php

namespace Sayamaapps\Tegaru;

/**
 * The server-side safety net, Kirby-free: looks at an uploaded file on disk
 * and says whether it may be created. The caller (index.php, hook
 * `file.create:before`) turns a violation into the exception the Panel shows.
 *
 * Rules (see docs/SPEC.md), checked in this order; the first violation wins:
 *  - size:   filesize > $maxBytes             → ['key' => 'error.size',   'values' => ['size' => <MB>, 'max' => <MB>]]
 *  - heic:   magic bytes say HEIC/HEIF        → ['key' => 'error.heic',   'values' => []]
 *  - pixels: JPEG/PNG/GIF/WebP (by magic bytes) with width × height > $maxPixels
 *                                             → ['key' => 'error.pixels', 'values' => ['megapixels' => <MP>, 'max' => <MP>]]
 *  - anything else (PDF, SVG, unreadable, a file getimagesize() cannot read) → null
 *
 * MB is MiB (1,048,576 bytes), the unit of the 15 MB default; MP is 1,000,000
 * pixels. Both are strings with one decimal and a point, not localised.
 * Only the first 32 bytes are read for sniffing; getimagesize() runs only for
 * raster images.
 */
final class Guard
{
	/** Bytes read from the start of a file; every magic number used here fits. */
	private const HEAD_BYTES = 32;

	/** HEIC/HEIF brands in the `ftyp` box; also mif1/msf1 containers. */
	public const HEIC_BRANDS = ['heic', 'heix', 'hevc', 'hevx', 'heim', 'heis', 'mif1', 'msf1'];
	private const MEGAPIXEL = 1000000;
	private const MIB = 1048576;

	/**
	 * @return array{key:string,values:array<string,string>}|null null = allowed
	 */
	public static function inspect(string $path, int $maxBytes, int $maxPixels): array|null
	{
		$size = @filesize($path);
		if ($size === false) {
			return null; // unreadable: Kirby's own checks take over
		}
		if ($size > $maxBytes) {
			return ['key' => 'error.size', 'values' => ['size' => self::mib($size), 'max' => self::mib($maxBytes)]];
		}

		$handle = @fopen($path, 'rb');
		if ($handle === false) {
			return null;
		}
		$head = (string)fread($handle, self::HEAD_BYTES);
		fclose($handle);

		$type = self::sniff($head);
		if ($type === 'heic') {
			return ['key' => 'error.heic', 'values' => []];
		}
		if (!in_array($type, ['jpeg', 'png', 'gif', 'webp'], true)) {
			return null;
		}

		$info = @getimagesize($path);
		if ($info === false) {
			return null; // not readable as an image: Kirby's own checks take over
		}
		$pixels = (int)$info[0] * (int)$info[1];
		if ($pixels > $maxPixels) {
			return [
				'key'    => 'error.pixels',
				'values' => ['megapixels' => self::megapixels($pixels), 'max' => self::megapixels($maxPixels)],
			];
		}
		return null;
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

	/** 'jpeg' | 'png' | 'gif' | 'webp' | 'heic' | 'avif' | 'other', from the first bytes. */
	public static function sniff(string $head): string
	{
		$head = substr($head, 0, self::HEAD_BYTES);
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
		if (substr($head, 4, 4) === 'ftyp') {
			$brand = substr($head, 8, 4);
			if (in_array($brand, self::HEIC_BRANDS, true)) {
				return 'heic';
			}
			if ($brand === 'avif' || $brand === 'avis') {
				return 'avif';
			}
		}
		return 'other';
	}
}
