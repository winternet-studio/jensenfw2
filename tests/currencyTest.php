<?php
use PHPUnit\Framework\TestCase;
use winternet\jensenfw2\currency;

final class currencyTest extends TestCase {

	public function testConversion() {
		$this->assertIsNumeric(currency::get_ecb_live_exchange_rate('EUR', 'NOK', null));

		$this->assertGreaterThan(250, currency::convert(50, 'EUR', 'NOK'));
		$this->assertGreaterThan(250, currency::convert(50, 'USD', 'NOK'));
	}

}
