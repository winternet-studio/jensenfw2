<?php
// This next line is not suppose to be here but that was the only way I knew how to run the tests by running the command "phpunit" in the parent directory! (while phpunit is globally installed)
require_once('src/country_specifics.php');

use winternet\jensenfw2\country_specifics;

class country_specificsTest extends PHPUnit_Framework_TestCase {
	public function testValidateZip() {
		$this->assertTrue(country_specifics::validate_zip('DK', '4690'));

		$this->assertTrue(country_specifics::validate_zip('US', '85645'));
		$this->assertFalse(country_specifics::validate_zip('US', '85645-1234'));
		$this->assertTrue(country_specifics::validate_zip('US', '85645', ['US_allow_zip4' => true]));
		$this->assertTrue(country_specifics::validate_zip('US', '85645-1234', ['US_allow_zip4' => true]));
		$this->assertFalse(country_specifics::validate_zip('US', '856454'));
		$this->assertFalse(country_specifics::validate_zip('US', '8866'));


		$this->assertTrue(country_specifics::validate_zip('CA', 'V4C 6S3'));
		$this->assertTrue(country_specifics::validate_zip('CA', 'V4C6S3'));
		$this->assertTrue(country_specifics::validate_zip('CA', 'V4C-6S3'));
		$this->assertTrue(country_specifics::validate_zip('CA', 'V4C.6S3'));
		$this->assertFalse(country_specifics::validate_zip('CA', 'V4C_6S3'));
		$this->assertFalse(country_specifics::validate_zip('CA', 'V4C56S3'));
		$this->assertFalse(country_specifics::validate_zip('CA', 'V4CK6S3'));
		$this->assertEquals('V4C 6S3', country_specifics::validate_zip('CA', 'V4C-6S3', ['reformat' => true]));
		$this->assertEquals('V4C 6S3', country_specifics::validate_zip('CA', 'V4C.6S3', ['reformat' => true]));

		$this->assertTrue(country_specifics::validate_zip('GB', 'EC1A 1BB'));
		$this->assertTrue(country_specifics::validate_zip('GB', 'W1A 0AX'));
		$this->assertTrue(country_specifics::validate_zip('GB', 'M1 1AE'));
		$this->assertTrue(country_specifics::validate_zip('GB', 'B33 8TH'));
		$this->assertTrue(country_specifics::validate_zip('GB', 'CR2 6XH'));
		$this->assertTrue(country_specifics::validate_zip('GB', 'DN55 1PT'));
		$this->assertTrue(country_specifics::validate_zip('GB', 'SS143SG'));
		$this->assertEquals('SS14 3SG', country_specifics::validate_zip('GB', 'SS143SG', ['reformat' => true]));
		$this->assertFalse(country_specifics::validate_zip('GB', 'DN55Y1PT'));

		$this->assertFalse(country_specifics::validate_zip('NL', '1000-AP'));
		$this->assertEquals('1000 AP', country_specifics::validate_zip('NL', '1000-AP', ['reformat' => true]));
		$this->assertTrue(country_specifics::validate_zip('NL', '1000 AP'));
		$this->assertTrue(country_specifics::validate_zip('NL', '1000AP'));

		$this->assertTrue(country_specifics::validate_zip('BR', '02649010'));
		$this->assertEquals('02649-010', country_specifics::validate_zip('BR', '02649010', ['reformat' => true]));
		$this->assertTrue(country_specifics::validate_zip('BR', '02649-010'));
		$this->assertTrue(country_specifics::validate_zip('BR', '13.840-000'));
		$this->assertFalse(country_specifics::validate_zip('BR', '0055'));

		$this->assertTrue(country_specifics::validate_zip('KR', '026-490'));
		$this->assertTrue(country_specifics::validate_zip('KR', '026010'));
		$this->assertEquals('026-010', country_specifics::validate_zip('KR', '026010', ['reformat' => true]));
	}

	public function testValidateMinimumPhoneNumberDigits() {
		$this->assertEquals(10, country_specifics::minimum_phone_num_digits('US'));
		$this->assertEquals(10, country_specifics::minimum_phone_num_digits('CA'));
		$this->assertEquals(8, country_specifics::minimum_phone_num_digits('DK'));
		$this->assertEquals(5, country_specifics::minimum_phone_num_digits('BJ'));
		$this->assertEquals(10, country_specifics::minimum_phone_num_digits(null, 1));
		$this->assertEquals(8, country_specifics::minimum_phone_num_digits(null, 45));
		$this->assertEquals(8, country_specifics::minimum_phone_num_digits(null, '45'));
		$this->assertEquals(5, country_specifics::minimum_phone_num_digits(null, 229));
	}
}
