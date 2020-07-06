<?php
use PHPUnit\Framework\TestCase;
use winternet\jensenfw2\core;
 
final class coreTest extends TestCase {
	public function testIsInteger() {
		$this->assertTrue(core::is_integer(0));
		$this->assertTrue(core::is_integer(1));
		$this->assertTrue(core::is_integer(-1));
		$this->assertTrue(core::is_integer('0'));
		$this->assertTrue(core::is_integer('1'));
		$this->assertTrue(core::is_integer('-1'));
		$this->assertTrue(core::is_integer(' 1'));
		$this->assertTrue(core::is_integer('1 '));
		$this->assertFalse(core::is_integer('1.0'));
		$this->assertFalse(core::is_integer('1.4'));
		$this->assertTrue(core::is_integer(1.0));
		$this->assertFalse(core::is_integer(1.4));
		$this->assertFalse(core::is_integer(0.45));
		$this->assertFalse(core::is_integer('a1'));
		$this->assertFalse(core::is_integer('1a'));
	}
}
