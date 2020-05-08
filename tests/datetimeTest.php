<?php
use PHPUnit\Framework\TestCase;
use winternet\jensenfw2\datetime;
 
final class datetimeTest extends TestCase {
	public function testFormatLocal() {
		$result = datetime::format_local(new \DateTime('2020-05-08 14:23:05'), 'EEEE, d. MMMM', 'da_DK');
		$expect = 'fredag, 8. maj';
		$this->assertSame($expect, $result);

		$result = datetime::format_local(new \DateTime('2020-05-08 14:23:05'), 'EEEE, d. MMMM', 'nb_NO');
		$expect = 'fredag, 8. mai';
		$this->assertSame($expect, $result);

		$result = datetime::format_local(new \DateTime('2020-05-08 14:23:05'), 'EEEE, d. MMMM', 'en_US');
		$expect = 'Friday, 8. May';
		$this->assertSame($expect, $result);
	}

	public function testSetDefaultLocale() {
		datetime::set_default_locale('da_DK');
		$result = datetime::format_local(new \DateTime('2020-05-08 14:23:05'), 'EEEE, d. MMMM');
		$expect = 'fredag, 8. maj';
		$this->assertSame($expect, $result);

		datetime::set_default_locale('nb_NO');
		$result = datetime::format_local(new \DateTime('2020-05-08 14:23:05'), 'EEEE, d. MMMM');
		$expect = 'fredag, 8. mai';
		$this->assertSame($expect, $result);

		datetime::set_default_locale('en_US');
		$result = datetime::format_local(new \DateTime('2020-05-08 14:23:05'), 'EEEE, d. MMMM');
		$expect = 'Friday, 8. May';
		$this->assertSame($expect, $result);
	}
}
