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

		$result = datetime::format_local(new \DateTime('2020-08-08 14:23:05'), 'EEEE, d. MMMM', 'da_DK');
		$expect = 'lørdag, 8. august';
		$this->assertSame($expect, $result);

		$result = datetime::format_local(new \DateTime('2020-08-08 14:23:05'), 'EEEE, d. MMMM', 'en_US');
		$expect = 'Saturday, 8. August';
		$this->assertSame($expect, $result);

		// Test automatic handling of comma between day and year
		$this->assertSame('August 8, 2020', datetime::format_local(new \DateTime('2020-08-08 14:23:05'), 'd. MMM yyyy', 'en_US'));
		$this->assertSame('8. august 2020', datetime::format_local(new \DateTime('2020-08-08 14:23:05'), 'd. MMM yyyy', 'da_DK'));

		$this->assertSame('June 8', datetime::format_local(new \DateTime('2020-06-08 14:23:05'), 'DAYMTH', 'en_US'));
		$this->assertSame('8. juni', datetime::format_local(new \DateTime('2020-06-08 14:23:05'), 'DAYMTH', 'da_DK'));
		$this->assertSame('Jun 8', datetime::format_local(new \DateTime('2020-06-08 14:23:05'), 'DAYMTH', 'en_US', ['shortMonth' => true]));
		$this->assertSame('8. Juni', datetime::format_local(new \DateTime('2020-06-08 14:23:05'), 'DAYMTH', 'de_DE', ['shortMonth' => true]));
		$this->assertSame('8. jun.', datetime::format_local(new \DateTime('2020-06-08 14:23:05'), 'DAYMTH', 'da_DK', ['shortMonth' => true]));
		$this->assertSame('8. jun', datetime::format_local(new \DateTime('2020-06-08 14:23:05'), 'DAYMTH', 'da_DK', ['shortMonthNoDot' => true]));
		$this->assertSame('8. jun.', datetime::format_local(new \DateTime('2020-06-08 14:23:05'), 'DAYMTH', 'nb_NO', ['shortMonth' => true]));
		$this->assertSame('8. jun.', datetime::format_local(new \DateTime('2020-06-08 14:23:05'), 'DAYMTH', 'es_ES', ['shortMonth' => true]));
		$this->assertSame('8. kesäk.', datetime::format_local(new \DateTime('2020-06-08 14:23:05'), 'DAYMTH', 'fi_FI', ['shortMonth' => true]));

		// Verify that the behavior of when to add the dot (for longer month names) doesn't change
		// June
		$this->assertSame('Jun', datetime::format_local(new \DateTime('2020-06-08 14:23:05'), 'MMM', 'en_US'));
		$this->assertSame('Juni', datetime::format_local(new \DateTime('2020-06-08 14:23:05'), 'MMM', 'de_DE'));
		$this->assertSame('jun.', datetime::format_local(new \DateTime('2020-06-08 14:23:05'), 'MMM', 'da_DK'));
		$this->assertSame('jun.', datetime::format_local(new \DateTime('2020-06-08 14:23:05'), 'MMM', 'nb_NO'));
		$this->assertSame('jun.', datetime::format_local(new \DateTime('2020-06-08 14:23:05'), 'MMM', 'es_ES'));
		$this->assertSame('kesäk.', datetime::format_local(new \DateTime('2020-06-08 14:23:05'), 'MMM', 'fi_FI'));
		// August
		$this->assertSame('Aug', datetime::format_local(new \DateTime('2020-08-08 14:23:05'), 'MMM', 'en_US'));
		$this->assertSame('Aug.', datetime::format_local(new \DateTime('2020-08-08 14:23:05'), 'MMM', 'de_DE'));
		$this->assertSame('aug.', datetime::format_local(new \DateTime('2020-08-08 14:23:05'), 'MMM', 'da_DK'));
		$this->assertSame('aug.', datetime::format_local(new \DateTime('2020-08-08 14:23:05'), 'MMM', 'nb_NO'));
		$this->assertSame('ago.', datetime::format_local(new \DateTime('2020-08-08 14:23:05'), 'MMM', 'es_ES'));
		$this->assertSame('elok.', datetime::format_local(new \DateTime('2020-08-08 14:23:05'), 'MMM', 'fi_FI'));
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

	public function testDayMonthLocalFormat() {
		$this->assertSame('MMMM d', datetime::day_month_local_format('en_US'));
		$this->assertSame('d. MMMM', datetime::day_month_local_format('nb_NO'));
	}
}
