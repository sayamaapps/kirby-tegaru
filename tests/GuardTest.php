<?php

use Kirby\Cms\App;
use Kirby\Filesystem\Dir;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Sayamaapps\Tegaru\Guard;

/**
 * Guard::inspect() and Guard::sniff(), Kirby-free. Fixtures are made at run
 * time: JPEG, PNG and GIF with GD (WebP only where this GD has it), a fake
 * HEIC and AVIF header, SVG, PDF and text. Covers every branch, the order of
 * the checks (size, HEIC, pixels), the number format and "unreadable passes".
 */
#[CoversClass(Guard::class)]
final class GuardTest extends TestCase
{
	/** The first 12 bytes of a HEIC file: `ftyp` box with major brand `heic`. */
	private const HEIC_HEAD = "\x00\x00\x00\x18ftypheic";
	private const MIB = 1048576;
	private const MP = 1000000;

	private string $dir;

	/** Guard::inspect() with the server defaults (15 MB, 40 MP) unless told otherwise. */
	private function inspect(string $path, int $maxBytes = 15728640, int $maxPixels = 40 * self::MP): array|null
	{
		return Guard::inspect($path, $maxBytes, $maxPixels);
	}

	/** A flat image of $width × $height pixels in one format, written to the temporary directory. */
	private function raster(string $format, int $width, int $height): string
	{
		$path = $this->dir . '/' . $format . '-' . $width . 'x' . $height . '.' . $format;
		$image = imagecreatetruecolor($width, $height);
		match ($format) {
			'jpg'  => imagejpeg($image, $path, 80),
			'png'  => imagepng($image, $path),
			'gif'  => imagegif($image, $path),
			'webp' => imagewebp($image, $path, 80),
		};
		return $path;
	}

	protected function setUp(): void
	{
		$this->dir = sys_get_temp_dir() . '/tegaru-guard-' . bin2hex(random_bytes(6));
		mkdir($this->dir, 0700, true);
	}

	protected function tearDown(): void
	{
		App::destroy();
		Dir::remove($this->dir);
	}

	public function testADamagedJpegIsCheckedWithoutAWarning(): void
	{
		// 267 junk bytes before the frame marker: getimagesize() still reads the size, but warns.
		$bytes = file_get_contents($this->raster('jpg', 1500, 1000));
		$frame = strpos($bytes, "\xFF\xC0");
		$damaged = $this->write('damaged.jpg', substr($bytes, 0, $frame) . str_repeat('A', 267) . substr($bytes, $frame));
		expect(null, $this->inspect($damaged), 'damaged JPEG under the defaults passes without a warning');
		expect(['key' => 'error.pixels', 'values' => ['megapixels' => '1.5', 'max' => '1.0']], $this->inspect($damaged, 15 * self::MIB, self::MP), 'damaged JPEG still checked for pixels');
	}

	public function testADirectoryFailsTheReadAsIn1x(): void
	{
		// 1.x: the size and the open succeed for a directory, the read fails. Kirby's error handler turns that into an exception.
		$folder = $this->dir . '/folder';
		mkdir($folder);
		set_error_handler(function (int $level, string $message): bool {
			throw new ErrorException($message, 0, $level);
		});
		try {
			$this->expectException(ErrorException::class);
			$this->inspect($folder);
		} finally {
			restore_error_handler();
		}
	}

	public function testAnUnreadableFileOverTheCapIsRefusedForItsSize(): void
	{
		// 1.x order: the size is read before the file is opened, so the size error wins over the read error.
		$path = $this->write('locked-big.txt', str_repeat('a', 2 * self::MIB));
		chmod($path, 0000);
		try {
			expect(['key' => 'error.size', 'values' => ['size' => '2.0', 'max' => '1.0']], $this->inspect($path, self::MIB), 'unreadable over the cap: size error, as in 1.x');
		} finally {
			chmod($path, 0644);
		}
	}

	public function testAnUnreadableFileWithinTheCapPasses(): void
	{
		$path = $this->write('locked-small.txt', 'Hallo Welt');
		chmod($path, 0000);
		try {
			expect(null, $this->inspect($path), 'unreadable within the cap: left to Kirby');
		} finally {
			chmod($path, 0644);
		}
	}

	public function testChecksRunInOrderSizeThenHeicThenPixels(): void
	{
		$heicBig = $this->write('huge.heic', self::HEIC_HEAD . str_repeat("\0", 2 * self::MIB));
		expect(['key' => 'error.size', 'values' => ['size' => '2.0', 'max' => '1.0']], $this->inspect($heicBig, self::MIB), 'order: a huge HEIC reports the size first');

		$jpg = $this->raster('jpg', 1500, 1000);
		expect('error.size', $this->inspect($jpg, 1000, 1)['key'], 'order: size before pixels');
	}

	public function testHeicAndHeifAreRejectedWhileAvifPasses(): void
	{
		$heicSmall = $this->write('IMG_1.HEIC', self::HEIC_HEAD . str_repeat("\0", 52));
		expect(['key' => 'error.heic', 'values' => []], $this->inspect($heicSmall), 'heic: small fake HEIC rejected');
		expect(['key' => 'error.heic', 'values' => []], $this->inspect($this->write('heif.heif', "\x00\x00\x00\x18ftypmif1" . str_repeat("\0", 20))), 'heic: HEIF container mif1 rejected');
		expect(null, $this->inspect($this->write('shot.avif', "\x00\x00\x00\x1Cftypavif" . str_repeat("\0", 40))), 'avif: not rejected by the net');
	}

	public function testPixelCapForEachRasterFormatAndSniffingByBytes(): void
	{
		$formats = ['jpg', 'png', 'gif'];
		if (function_exists('imagewebp')) {
			$formats[] = 'webp';
		}
		foreach ($formats as $format) {
			$path = $this->raster($format, 1500, 1000);
			expect(['key' => 'error.pixels', 'values' => ['megapixels' => '1.5', 'max' => '1.0']], $this->inspect($path, 15 * self::MIB, self::MP), "pixels: $format 1.5 MP over a 1 MP cap");
			expect(null, $this->inspect($path, 15 * self::MIB, 1500000), "pixels: $format exactly the cap passes");
			expect(null, $this->inspect($path), "pixels: $format under the defaults passes");
		}

		$jpg = $this->raster('jpg', 1500, 1000);
		expect(['key' => 'error.pixels', 'values' => ['megapixels' => '1.5', 'max' => '1.0']], $this->inspect($this->write('sneaky.heic', file_get_contents($jpg)), 15 * self::MIB, self::MP), 'pixels: sniffed by the bytes, not by the name');
	}

	public function testSizeCapIsInclusiveAndReportsMiB(): void
	{
		$text37 = $this->write('text-3-7.txt', str_repeat('a', (int)(3.7 * self::MIB)));
		expect(3879731, filesize($text37), 'fixture: 3.7 MiB text file');
		expect(['key' => 'error.size', 'values' => ['size' => '3.7', 'max' => '1.0']], $this->inspect($text37, self::MIB), 'size: 3.7 MB over a 1 MB cap');
		expect(null, $this->inspect($text37, 3879731), 'size: exactly the cap passes');
		expect(['key' => 'error.size', 'values' => ['size' => '3.7', 'max' => '3.7']], $this->inspect($text37, 3879730), 'size: one byte over the cap');
		$text20 = $this->write('text-20.txt', str_repeat('a', 20 * self::MIB));
		expect(['key' => 'error.size', 'values' => ['size' => '20.0', 'max' => '15.0']], $this->inspect($text20), 'size: 20 MB over the 15 MB default');
	}

	public function testSniffDoesNotMistakeLookalikesForImagesOrHeic(): void
	{
		expect('other', Guard::sniff("\x00\x00\x00\x18ftypmp42"), 'sniff: MP4 is not HEIC');
		expect('other', Guard::sniff("\x00\x00\x00\x18xxxxheic"), 'sniff: ftyp must sit at offset 4');
		expect('other', Guard::sniff('%PDF-1.7'), 'sniff: PDF');
		expect('other', Guard::sniff('<svg width="5000" height="5000"/>'), 'sniff: SVG');
		expect('other', Guard::sniff(''), 'sniff: empty');
		expect('other', Guard::sniff("\xFF\xD8"), 'sniff: two bytes are not a JPEG');
		expect('other', Guard::sniff(str_repeat("\0", 32) . "\xFF\xD8\xFF"), 'sniff: a magic number after byte 32 is not seen');
	}

	public function testSniffRecognisesFormatsByTheirMagicBytes(): void
	{
		expect('jpeg', Guard::sniff("\xFF\xD8\xFF\xE0\x00\x10JFIF"), 'sniff: JPEG');
		expect('png', Guard::sniff("\x89PNG\r\n\x1A\n\x00\x00\x00\rIHDR"), 'sniff: PNG');
		expect('gif', Guard::sniff('GIF89a' . str_repeat("\0", 6)), 'sniff: GIF 89a');
		expect('gif', Guard::sniff('GIF87a'), 'sniff: GIF 87a');
		expect('webp', Guard::sniff("RIFF\x24\x00\x00\x00WEBPVP8 "), 'sniff: WebP');
		expect('other', Guard::sniff("RIFF\x24\x00\x00\x00WAVEfmt "), 'sniff: RIFF without WEBP is not WebP');
		expect('heic', Guard::sniff(self::HEIC_HEAD), 'sniff: HEIC, brand heic');
		foreach (['mif1', 'msf1', 'heix', 'hevc'] as $brand) {
			expect('heic', Guard::sniff("\x00\x00\x00\x18ftyp" . $brand), "sniff: HEIF brand $brand");
		}
		expect('avif', Guard::sniff("\x00\x00\x00\x1Cftypavif"), 'sniff: AVIF');
		expect('avif', Guard::sniff("\x00\x00\x00\x1Cftypavis"), 'sniff: AVIF image sequence');
	}

	public function testWhatTheNetDoesNotDecideOrCannotReadPasses(): void
	{
		expect(null, $this->inspect($this->write('drawing.svg', '<svg width="5000" height="5000"/>')), 'svg passes');
		expect(null, $this->inspect($this->write('doc.pdf', '%PDF-1.7' . str_repeat(' ', 100))), 'pdf passes');
		expect(null, $this->inspect($this->write('notes.txt', 'Hallo Welt')), 'text file passes');
		expect(null, $this->inspect($this->dir . '/missing.jpg'), 'missing file: unreadable, passes');
		expect(null, $this->inspect($this->write('broken.jpg', "\xFF\xD8\xFF\xE0" . str_repeat("\0", 200)), 15 * self::MIB, 1), 'JPEG magic but no image: getimagesize fails, passes');
	}

	/** Writes a file into the temporary directory and returns its path. */
	private function write(string $name, string $bytes): string
	{
		file_put_contents($this->dir . '/' . $name, $bytes);
		return $this->dir . '/' . $name;
	}
}
