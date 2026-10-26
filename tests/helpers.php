<?php

use PHPUnit\Framework\Assert;

/**
 * Strict comparison with a label: expect($expected, $actual, 'what').
 * A failing check ends the test method and PHPUnit reports the label.
 */
function expect(mixed $expected, mixed $actual, string $what): void
{
	Assert::assertSame($expected, $actual, $what);
}

/** A condition that must hold, with a label. */
function holds(bool $ok, string $what): void
{
	Assert::assertTrue($ok, $what);
}
