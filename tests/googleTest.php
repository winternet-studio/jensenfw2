<?php
use PHPUnit\Framework\TestCase;
use winternet\jensenfw2\google;

final class googleTest extends TestCase {

	public function testParseGoogleSheetsDate() {
		$this->assertEquals('H:i', google::google_sheet_datetime_format_to_php('hh:mm'));

		$this->assertEquals('21:00', google::parse_google_sheets_date('Date(1899,11,30,21,0,0)', 'H:i'));
		$this->assertEquals('2025-01-15 09:30:45', google::parse_google_sheets_date('Date(2025,0,15,9,30,45)', 'Y-m-d H:i:s'));
	}

}
