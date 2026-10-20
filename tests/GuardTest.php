<?php
/**
 * Guard::inspect() and Guard::sniff(), Kirby-free. Fixtures are made at run
 * time: JPEG, PNG and GIF with GD (WebP only where this GD has it), a fake
 * HEIC and AVIF header, SVG, PDF and text. Covers every branch, the order of
 * the checks (size, HEIC, pixels), the number format and "unreadable passes".
 *   php tests/GuardTest.php
 */
require_once __DIR__ . '/../src/Guard.php';

use Tegaru\Guard;

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

const MIB = 1048576;
const MP = 1000000;

/** The first 12 bytes of a HEIC file: `ftyp` box with major brand `heic`. */
$heicHead = hex2bin('000000186674797068656963');
$dir = sys_get_temp_dir() . '/tegaru-guard-' . bin2hex(random_bytes(6));
$hasWebp = function_exists('imagewebp');
/** Guard::inspect() with the server defaults unless told otherwise (15 MB, 40 MP). */
$inspect = fn (string $path, int $maxBytes = 15728640, int $maxPixels = 40 * MP): ?array => Guard::inspect($path, $maxBytes, $maxPixels);

try {
    mkdir($dir, 0700, true);
    $write = function (string $name, string $bytes) use ($dir): string {
        file_put_contents($dir . '/' . $name, $bytes);
        return $dir . '/' . $name;
    };
    // A flat image of $w × $h pixels in one format, written to $dir; returns its path.
    $raster = function (string $format, int $w, int $h) use ($dir): string {
        $path = $dir . '/' . $format . '-' . $w . 'x' . $h . '.' . $format;
        $image = imagecreatetruecolor($w, $h);
        match ($format) {
            'jpg'  => imagejpeg($image, $path, 80),
            'png'  => imagepng($image, $path),
            'gif'  => imagegif($image, $path),
            'webp' => imagewebp($image, $path, 80),
        };
        return $path;
    };

    // sniff(): magic bytes only, at most the first 32 bytes.
    expect('jpeg', Guard::sniff("\xFF\xD8\xFF\xE0\x00\x10JFIF"), 'sniff: JPEG');
    expect('png', Guard::sniff("\x89PNG\r\n\x1A\n\x00\x00\x00\rIHDR"), 'sniff: PNG');
    expect('gif', Guard::sniff('GIF89a' . str_repeat("\0", 6)), 'sniff: GIF 89a');
    expect('gif', Guard::sniff('GIF87a'), 'sniff: GIF 87a');
    expect('webp', Guard::sniff("RIFF\x24\x00\x00\x00WEBPVP8 "), 'sniff: WebP');
    expect('other', Guard::sniff("RIFF\x24\x00\x00\x00WAVEfmt "), 'sniff: RIFF without WEBP is not WebP');
    expect('heic', Guard::sniff($heicHead), 'sniff: HEIC, brand heic');
    foreach (['mif1', 'msf1', 'heix', 'hevc'] as $brand) {
        expect('heic', Guard::sniff("\x00\x00\x00\x18ftyp" . $brand), "sniff: HEIF brand $brand");
    }
    expect('avif', Guard::sniff("\x00\x00\x00\x1Cftypavif"), 'sniff: AVIF');
    expect('avif', Guard::sniff("\x00\x00\x00\x1Cftypavis"), 'sniff: AVIF image sequence');
    expect('other', Guard::sniff("\x00\x00\x00\x18ftypmp42"), 'sniff: MP4 is not HEIC');
    expect('other', Guard::sniff("\x00\x00\x00\x18xxxxheic"), 'sniff: ftyp must sit at offset 4');
    expect('other', Guard::sniff('%PDF-1.7'), 'sniff: PDF');
    expect('other', Guard::sniff('<svg width="5000" height="5000"/>'), 'sniff: SVG');
    expect('other', Guard::sniff(''), 'sniff: empty');
    expect('other', Guard::sniff("\xFF\xD8"), 'sniff: two bytes are not a JPEG');
    expect('other', Guard::sniff(str_repeat("\0", 32) . "\xFF\xD8\xFF"), 'sniff: a magic number after byte 32 is not seen');

    // size: the cap is inclusive; the message carries MB (MiB) with one decimal.
    $text37 = $write('text-3-7.txt', str_repeat('a', (int) (3.7 * MIB)));
    expect(3879731, filesize($text37), 'fixture: 3.7 MiB text file');
    expect(['key' => 'error.size', 'values' => ['size' => '3.7', 'max' => '1.0']], $inspect($text37, MIB), 'size: 3.7 MB over a 1 MB cap');
    expect(null, $inspect($text37, 3879731), 'size: exactly the cap passes');
    expect(['key' => 'error.size', 'values' => ['size' => '3.7', 'max' => '3.7']], $inspect($text37, 3879730), 'size: one byte over the cap');
    $text20 = $write('text-20.txt', str_repeat('a', 20 * MIB));
    expect(['key' => 'error.size', 'values' => ['size' => '20.0', 'max' => '15.0']], $inspect($text20), 'size: 20 MB over the 15 MB default');

    // order: size before HEIC before pixels.
    $heicBig = $write('huge.heic', $heicHead . str_repeat("\0", 2 * MIB));
    expect(['key' => 'error.size', 'values' => ['size' => '2.0', 'max' => '1.0']], $inspect($heicBig, MIB), 'order: a huge HEIC reports the size first');
    $heicSmall = $write('IMG_1.HEIC', $heicHead . str_repeat("\0", 52));
    expect(['key' => 'error.heic', 'values' => []], $inspect($heicSmall), 'heic: small fake HEIC rejected');
    expect(['key' => 'error.heic', 'values' => []], $inspect($write('heif.heif', "\x00\x00\x00\x18ftypmif1" . str_repeat("\0", 20))), 'heic: HEIF container mif1 rejected');
    expect(null, $inspect($write('shot.avif', "\x00\x00\x00\x1Cftypavif" . str_repeat("\0", 40))), 'avif: not rejected by the net');

    // pixels: width × height over the cap, one decimal; JPEG, PNG, GIF, WebP.
    $formats = ['jpg', 'png', 'gif'];
    if ($hasWebp) {
        $formats[] = 'webp';
    } else {
        echo "note: GD has no imagewebp, the WebP fixtures are skipped\n";
    }
    foreach ($formats as $format) {
        $path = $raster($format, 1500, 1000);
        expect(['key' => 'error.pixels', 'values' => ['megapixels' => '1.5', 'max' => '1.0']], $inspect($path, 15 * MIB, MP), "pixels: $format 1.5 MP over a 1 MP cap");
        expect(null, $inspect($path, 15 * MIB, 1500000), "pixels: $format exactly the cap passes");
        expect(null, $inspect($path), "pixels: $format under the defaults passes");
    }
    $jpg = $raster('jpg', 1500, 1000);
    expect('error.size', $inspect($jpg, 1000, 1)['key'], 'order: size before pixels');
    expect(['key' => 'error.pixels', 'values' => ['megapixels' => '1.5', 'max' => '1.0']], $inspect($write('sneaky.heic', file_get_contents($jpg)), 15 * MIB, MP), 'pixels: sniffed by the bytes, not by the name');

    // Passes: what the net does not decide, and what cannot be read.
    expect(null, $inspect($write('drawing.svg', '<svg width="5000" height="5000"/>')), 'svg passes');
    expect(null, $inspect($write('doc.pdf', '%PDF-1.7' . str_repeat(' ', 100))), 'pdf passes');
    expect(null, $inspect($write('notes.txt', 'Hallo Welt')), 'text file passes');
    expect(null, $inspect($dir . '/missing.jpg'), 'missing file: unreadable, passes');
    expect(null, $inspect($write('broken.jpg', "\xFF\xD8\xFF\xE0" . str_repeat("\0", 200)), 15 * MIB, 1), 'JPEG magic but no image: getimagesize fails, passes');

    echo "GuardTest: $checks checks passed\n";
} catch (Throwable $e) {
    fwrite(STDERR, 'GuardTest FAILED: ' . $e->getMessage() . ' (' . basename($e->getFile()) . ':' . $e->getLine() . ")\n");
    $failed = true;
} finally {
    removeTree($dir);
}
exit(isset($failed) ? 1 : 0);
