<?php
use PHPUnit\Framework\TestCase;
use winternet\jensenfw2\aws;
 
final class awsTest extends TestCase {
	public function testMyCase() {
		$result = aws::calculate_etag(__DIR__ .'/fixtures/aws/etagTestFile.txt', 1);
		$expect = '1637fa7110f8d109d48bf2a65173516e';
		$this->assertSame($expect, $result);
	}
}
