<?php
use PHPUnit\Framework\TestCase;
use winternet\jensenfw2\economy;

final class economyTest extends TestCase {
	public function testValidations() {
		$result = economy::validate_bic('SBAKNOBB');
		$this->assertTrue($result['is_valid']);

		$result = economy::validate_bic('DNBANOKK');
		$this->assertTrue($result['is_valid']);

		$result = economy::validate_bic('DNBANOK');
		$this->assertFalse($result['is_valid']);

		$result = economy::validate_aba('026073150');
		$this->assertTrue($result['is_valid'], $result['err_msg']);

		$result = economy::validate_aba('26073150');
		$this->assertFalse($result['is_valid'], $result['err_msg']);
	}
}
