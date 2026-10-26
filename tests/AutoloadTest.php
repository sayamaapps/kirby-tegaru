<?php

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

/**
 * The class map in autoload.php matches src/: one entry per file, the file
 * name is the class name, and every class loads through the map.
 */
#[CoversNothing]
final class AutoloadTest extends TestCase
{
    public function testEveryEntryLoadsItsClass(): void
    {
        $root = dirname(__DIR__);
        foreach (require $root . '/autoload.php' as $class => $path) {
            $this->assertFileExists($root . '/' . $path);
            $this->assertTrue(class_exists($class), 'class ' . $class . ' loads through the map');
        }
    }

    public function testTheMapListsEveryClassFileAndNothingElse(): void
    {
        $root = dirname(__DIR__);
        $expected = [];
        foreach (glob($root . '/src/*.php') ?: [] as $file) {
            $expected[strtolower('tegaru\\' . basename($file, '.php'))] = 'src/' . basename($file);
        }

        $this->assertSame($expected, require $root . '/autoload.php');
    }
}
