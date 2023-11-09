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

		// Test the special DAYMTH tag
		$this->assertSame('June 8', datetime::format_local(new \DateTime('2020-06-08 14:23:05'), 'DAYMTH', 'en_US'));
		$this->assertSame('8. juni', datetime::format_local(new \DateTime('2020-06-08 14:23:05'), 'DAYMTH', 'da_DK'));
		$this->assertSame('Jun 8', datetime::format_local(new \DateTime('2020-06-08 14:23:05'), 'DAYMTH', 'en_US', ['short_month' => true]));
		$this->assertSame('8. Juni', datetime::format_local(new \DateTime('2020-06-08 14:23:05'), 'DAYMTH', 'de_DE', ['short_month' => true]));
		$this->assertSame('8. jun.', datetime::format_local(new \DateTime('2020-06-08 14:23:05'), 'DAYMTH', 'da_DK', ['short_month' => true]));
		$this->assertSame('8. jun', datetime::format_local(new \DateTime('2020-06-08 14:23:05'), 'DAYMTH', 'da_DK', ['short_month_no_dot' => true]));
		$this->assertSame('8. jun.', datetime::format_local(new \DateTime('2020-06-08 14:23:05'), 'DAYMTH', 'nb_NO', ['short_month' => true]));
		$this->assertSame('8. jun', datetime::format_local(new \DateTime('2020-06-08 14:23:05'), 'DAYMTH', 'es_ES', ['short_month' => true]));
		$this->assertSame('8. kesäk.', datetime::format_local(new \DateTime('2020-06-08 14:23:05'), 'DAYMTH', 'fi_FI', ['short_month' => true]));

		// Test automatic handling of comma between day and year
		$this->assertSame('August 8, 2020', datetime::format_local(new \DateTime('2020-08-08 14:23:05'), 'DAYMTH yyyy', 'en_US'));
		$this->assertSame('8. august 2020', datetime::format_local(new \DateTime('2020-08-08 14:23:05'), 'DAYMTH yyyy', 'da_DK'));

		// Test the special HOUR and AMPM tags
		$this->assertSame('2:23pm', datetime::format_local(new \DateTime('2020-08-08 14:23:05'), 'HOUR:mmAMPM', 'en_US'));
		$this->assertSame('2:23pm', datetime::format_local(new \DateTime('2020-08-08 14:23:05'), 'HOUR:mmAMPM', 'DONTUSE', ['country' => 'CA']));
		$this->assertSame('02:23pm', datetime::format_local(new \DateTime('2020-08-08 14:23:05'), 'HOUR2:mmAMPM', 'en_US'));
		$this->assertSame('2:23p', datetime::format_local(new \DateTime('2020-08-08 14:23:05'), 'HOUR:mmAMPM', 'en_US', ['short_ampm' => true]));
		$this->assertSame('14:23', datetime::format_local(new \DateTime('2020-08-08 14:23:05'), 'HOUR:mmAMPM', 'da_DK'));
		$this->assertSame('8:23', datetime::format_local(new \DateTime('2020-08-08 08:23:05'), 'HOUR:mmAMPM', 'da_DK'));
		$this->assertSame('08:23', datetime::format_local(new \DateTime('2020-08-08 08:23:05'), 'HOUR2:mmAMPM', 'da_DK'));

		// Verify that the behavior of when to add the dot (for longer month names) doesn't change
		// June
		$this->assertSame('Jun', datetime::format_local(new \DateTime('2020-06-08 14:23:05'), 'MMM', 'en_US'));
		$this->assertSame('Juni', datetime::format_local(new \DateTime('2020-06-08 14:23:05'), 'MMM', 'de_DE'));
		$this->assertSame('jun.', datetime::format_local(new \DateTime('2020-06-08 14:23:05'), 'MMM', 'da_DK'));
		$this->assertSame('jun.', datetime::format_local(new \DateTime('2020-06-08 14:23:05'), 'MMM', 'nb_NO'));
		$this->assertSame('jun', datetime::format_local(new \DateTime('2020-06-08 14:23:05'), 'MMM', 'es_ES'));
		$this->assertSame('kesäk.', datetime::format_local(new \DateTime('2020-06-08 14:23:05'), 'MMM', 'fi_FI'));
		// August
		$this->assertSame('Aug', datetime::format_local(new \DateTime('2020-08-08 14:23:05'), 'MMM', 'en_US'));
		$this->assertSame('Aug.', datetime::format_local(new \DateTime('2020-08-08 14:23:05'), 'MMM', 'de_DE'));
		$this->assertSame('aug.', datetime::format_local(new \DateTime('2020-08-08 14:23:05'), 'MMM', 'da_DK'));
		$this->assertSame('aug.', datetime::format_local(new \DateTime('2020-08-08 14:23:05'), 'MMM', 'nb_NO'));
		$this->assertSame('ago', datetime::format_local(new \DateTime('2020-08-08 14:23:05'), 'MMM', 'es_ES'));
		$this->assertSame('elok.', datetime::format_local(new \DateTime('2020-08-08 14:23:05'), 'MMM', 'fi_FI'));

		// Test overriding time formatting
		$this->assertSame('June 8, 2:23pm', datetime::format_local(new \DateTime('2020-06-08 14:23:05'), 'DAYMTH, HOUR:mmAMPM', 'en_US'));
		$this->assertSame('June 8, 14:23', datetime::format_local(new \DateTime('2020-06-08 14:23:05'), 'DAYMTH, HOUR:mmAMPM', 'en_US', ['time_country' => 'DK']));
		$this->assertSame('June 8, 14:23', datetime::format_local(new \DateTime('2020-06-08 14:23:05'), 'DAYMTH, HOUR:mmAMPM', 'en_US', ['force_clock' => '24hr']));
		$this->assertSame('8. juni, 2:23pm', datetime::format_local(new \DateTime('2020-06-08 14:23:05'), 'DAYMTH, HOUR:mmAMPM', 'da_DK', ['force_clock' => '12hr']));
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

	public function testFormatTimeperiod() {
		$this->assertSame('Oct. 18-22, 2020', datetime::format_timeperiod('2020-10-18 17:00:00', '2020-10-22 12:00:00'));
		$this->assertSame('Oct 18-22, 2020',  datetime::format_timeperiod('2020-10-18 17:00:00', '2020-10-22 12:00:00', ['no_dot_after_month' => true]));
		$this->assertSame('June 18-22, 2020', datetime::format_timeperiod('2020-06-18 17:00:00', '2020-06-22 12:00:00'));
		$this->assertSame('Jun. 18-22, 2020', datetime::format_timeperiod('2020-06-18 17:00:00', '2020-06-22 12:00:00', ['always_abbrev_months' => true]));
		$this->assertSame('Jun 18-22, 2020',  datetime::format_timeperiod('2020-06-18 17:00:00', '2020-06-22 12:00:00', ['always_abbrev_months' => true, 'no_dot_after_month' => true]));
		$this->assertSame('Oct. 18-22, 2020', datetime::format_timeperiod('2020-10-18 17:00:00', '2020-10-22 12:00:00', ['always_abbrev_months' => true]));
		$this->assertSame('Oct. 18 - Nov. 22, 2020', datetime::format_timeperiod('2020-10-18 17:00:00', '2020-11-22 12:00:00'));
		$this->assertSame('Oct 18 - Nov 22, 2020',   datetime::format_timeperiod('2020-10-18 17:00:00', '2020-11-22 12:00:00', ['no_dot_after_month' => true]));
		$this->assertSame('June 18 - Nov. 22, 2020', datetime::format_timeperiod('2020-06-18 17:00:00', '2020-11-22 12:00:00'));
		$this->assertSame('June 18 - Nov 22, 2020',  datetime::format_timeperiod('2020-06-18 17:00:00', '2020-11-22 12:00:00', ['no_dot_after_month' => true]));
		$this->assertSame('June 18, 2020 - Nov. 22, 2021', datetime::format_timeperiod('2020-06-18 17:00:00', '2021-11-22 12:00:00'));
		$this->assertSame('June 18, 2020 - November 22, 2021', datetime::format_timeperiod('2020-06-18 17:00:00', '2021-11-22 12:00:00', ['never_abbrev_months' => true]));
		$this->assertSame('Oct. 18, 2020', datetime::format_timeperiod('2020-10-18 17:00:00', '2020-10-18 20:00:00'));

		// Test use of timezones
		$this->assertSame('Oct. 18-22, 2020', datetime::format_timeperiod('2020-10-18 05:00:00', '2020-10-22 05:00:00', ['input_timezone' => 'America/Los_Angeles', 'output_timezone' => 'UTC']));
		$this->assertSame('Oct. 17-21, 2020', datetime::format_timeperiod('2020-10-18 05:00:00', '2020-10-22 05:00:00', ['output_timezone' => 'America/Los_Angeles']));
		$this->assertSame('Oct. 19-23, 2020', datetime::format_timeperiod('2020-10-18 15:00:00', '2020-10-22 15:00:00', ['input_timezone' => 'America/Los_Angeles', 'output_timezone' => 'Australia/Brisbane']));
	}

	public function testFormatTimeperiodLocal() {
		$this->assertSame('Oct 18-22, 2020', datetime::format_timeperiod_local('2020-10-18 17:00:00', '2020-10-22 12:00:00', 'en_US'));

		// Denmark (and Norway) uses date before month, and a dot after the date
		$this->assertSame('18-22. okt. 2020', datetime::format_timeperiod_local('2020-10-18 17:00:00', '2020-10-22 12:00:00', 'da_DK'));
		$this->assertSame('18-22. okt 2020',  datetime::format_timeperiod_local('2020-10-18 17:00:00', '2020-10-22 12:00:00', 'da_DK', ['no_dot_after_month' => true]));
		$this->assertSame('18-22. juni 2020', datetime::format_timeperiod_local('2020-06-18 17:00:00', '2020-06-22 12:00:00', 'da_DK'));
		$this->assertSame('18-22. jun. 2020', datetime::format_timeperiod_local('2020-06-18 17:00:00', '2020-06-22 12:00:00', 'da_DK', ['always_abbrev_months' => true]));
		$this->assertSame('18-22. jun 2020',  datetime::format_timeperiod_local('2020-06-18 17:00:00', '2020-06-22 12:00:00', 'da_DK', ['always_abbrev_months' => true, 'no_dot_after_month' => true]));
		$this->assertSame('18-22. okt. 2020', datetime::format_timeperiod_local('2020-10-18 17:00:00', '2020-10-22 12:00:00', 'da_DK', ['always_abbrev_months' => true]));
		$this->assertSame('18. okt. - 22. nov. 2020', datetime::format_timeperiod_local('2020-10-18 17:00:00', '2020-11-22 12:00:00', 'da_DK'));
		$this->assertSame('18. okt - 22. nov 2020',   datetime::format_timeperiod_local('2020-10-18 17:00:00', '2020-11-22 12:00:00', 'da_DK', ['no_dot_after_month' => true]));
		$this->assertSame('18. juni - 22. nov. 2020', datetime::format_timeperiod_local('2020-06-18 17:00:00', '2020-11-22 12:00:00', 'da_DK'));
		$this->assertSame('18. juni - 22. nov 2020',  datetime::format_timeperiod_local('2020-06-18 17:00:00', '2020-11-22 12:00:00', 'da_DK', ['no_dot_after_month' => true]));
		$this->assertSame('18. juni 2020 - 22. nov. 2021', datetime::format_timeperiod_local('2020-06-18 17:00:00', '2021-11-22 12:00:00', 'da_DK'));
		$this->assertSame('18. juni 2020 - 22. november 2021', datetime::format_timeperiod_local('2020-06-18 17:00:00', '2021-11-22 12:00:00', 'da_DK', ['never_abbrev_months' => true]));
		$this->assertSame('18. okt. 2020', datetime::format_timeperiod_local('2020-10-18 17:00:00', '2020-10-18 20:00:00', 'da_DK'));

		// Sweden also uses date before month, but no dot after the date
		$this->assertSame('18-22 okt. 2020', datetime::format_timeperiod_local('2020-10-18 17:00:00', '2020-10-22 12:00:00', 'sv_SE'));
		$this->assertSame('18-22 okt 2020',  datetime::format_timeperiod_local('2020-10-18 17:00:00', '2020-10-22 12:00:00', 'sv_SE', ['no_dot_after_month' => true]));
		$this->assertSame('18-22 juni 2020', datetime::format_timeperiod_local('2020-06-18 17:00:00', '2020-06-22 12:00:00', 'sv_SE'));
		$this->assertSame('18-22 juni 2020', datetime::format_timeperiod_local('2020-06-18 17:00:00', '2020-06-22 12:00:00', 'sv_SE', ['always_abbrev_months' => true]));  // ICU format "MMM" does not abbreviate short month names in Swedish
		$this->assertSame('18-22 juni 2020',  datetime::format_timeperiod_local('2020-06-18 17:00:00', '2020-06-22 12:00:00', 'sv_SE', ['always_abbrev_months' => true, 'no_dot_after_month' => true]));  // ICU format "MMM" does not abbreviate short month names in Swedish
		$this->assertSame('18-22 okt. 2020', datetime::format_timeperiod_local('2020-10-18 17:00:00', '2020-10-22 12:00:00', 'sv_SE', ['always_abbrev_months' => true]));
		$this->assertSame('18 okt. - 22 nov. 2020', datetime::format_timeperiod_local('2020-10-18 17:00:00', '2020-11-22 12:00:00', 'sv_SE'));
		$this->assertSame('18 okt - 22 nov 2020',   datetime::format_timeperiod_local('2020-10-18 17:00:00', '2020-11-22 12:00:00', 'sv_SE', ['no_dot_after_month' => true]));
		$this->assertSame('18 juni - 22 nov. 2020', datetime::format_timeperiod_local('2020-06-18 17:00:00', '2020-11-22 12:00:00', 'sv_SE'));
		$this->assertSame('18 juni - 22 nov 2020',  datetime::format_timeperiod_local('2020-06-18 17:00:00', '2020-11-22 12:00:00', 'sv_SE', ['no_dot_after_month' => true]));
		$this->assertSame('18 juni 2020 - 22 nov. 2021', datetime::format_timeperiod_local('2020-06-18 17:00:00', '2021-11-22 12:00:00', 'sv_SE'));
		$this->assertSame('18 juni 2020 - 22 november 2021', datetime::format_timeperiod_local('2020-06-18 17:00:00', '2021-11-22 12:00:00', 'sv_SE', ['never_abbrev_months' => true]));
		$this->assertSame('18 okt. 2020', datetime::format_timeperiod_local('2020-10-18 17:00:00', '2020-10-18 20:00:00', 'sv_SE'));

		// Test use of timezones
		$this->assertSame('18-22. okt. 2020', datetime::format_timeperiod_local('2020-10-18 05:00:00', '2020-10-22 05:00:00', 'da_DK', ['input_timezone' => 'America/Los_Angeles', 'output_timezone' => 'UTC']));
		$this->assertSame('17-21. okt. 2020', datetime::format_timeperiod_local('2020-10-18 05:00:00', '2020-10-22 05:00:00', 'da_DK', ['output_timezone' => 'America/Los_Angeles']));
		$this->assertSame('19-23. okt. 2020', datetime::format_timeperiod_local('2020-10-18 15:00:00', '2020-10-22 15:00:00', 'da_DK', ['input_timezone' => 'America/Los_Angeles', 'output_timezone' => 'Australia/Brisbane']));
	}

	public function testPeriodToDatetime() {
		$this->assertSame((new \DateTime('+6 hours'))->format('Y-m-d H:i'),                           (datetime::period_to_datetime('6h'))->format('Y-m-d H:i'));
		$this->assertSame((new \DateTime('+6 hours', new \DateTimeZone('UTC')))->format('Y-m-d H:i'), (datetime::period_to_datetime('6h', ['timezone' => 'UTC']))->format('Y-m-d H:i'));
		$this->assertSame((new \DateTime('+12 days'))->format('Y-m-d H:i'),                           (datetime::period_to_datetime('12d'))->format('Y-m-d H:i'));
		$this->assertSame((new \DateTime('+12 days', new \DateTimeZone('UTC')))->format('Y-m-d H:i'), (datetime::period_to_datetime('12d', ['timezone' => 'UTC']))->format('Y-m-d H:i'));

		$this->assertSame(null, (datetime::period_to_datetime('invalid value', ['null_on_fail' => true])));
	}
}
