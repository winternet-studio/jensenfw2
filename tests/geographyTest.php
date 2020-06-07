<?php
use PHPUnit\Framework\TestCase;
use winternet\jensenfw2\geography;
 
final class geographyTest extends TestCase {
	public function testIsInPolygon() {
		$longitude_x = 37.6;
		$latitude_y = -77.446;
		$polygon = [ [37.628134, -77.458334], [37.629867, -77.449021], [37.62324, -77.445416], [37.622424, -77.457819] ];
		$result = geography::is_in_polygon($polygon, $longitude_x, $latitude_y);
		$this->assertTrue($result);
	}
}
