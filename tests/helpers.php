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

/**
 * Sessions in memory: Kirby's file store writes at shutdown, after a test has removed its temp dir.
 */
final class MemorySessionStore extends Kirby\Session\SessionStore
{
	/** @var array<string,string> */
	private array $sessions = [];

	public function collectGarbage(): void
	{
	}

	public function createId(int $expiryTime): string
	{
		return self::generateId();
	}

	public function destroy(int $expiryTime, string $id): void
	{
		unset($this->sessions[$id]);
	}

	public function exists(int $expiryTime, string $id): bool
	{
		return isset($this->sessions[$id]);
	}

	public function get(int $expiryTime, string $id): string
	{
		return $this->sessions[$id] ?? '';
	}

	public function lock(int $expiryTime, string $id): void
	{
	}

	public function set(int $expiryTime, string $id, string $data): void
	{
		$this->sessions[$id] = $data;
	}

	public function unlock(int $expiryTime, string $id): void
	{
	}
}
