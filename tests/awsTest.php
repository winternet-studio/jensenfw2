<?php
// This next line is not suppose to be here but that was the only way I knew how to run the tests by running the command "phpunit" in the parent directory! (while phpunit is globally installed)
require_once('src/aws.php');

use winternet\jensenfw2\aws;
 
class awsTest extends PHPUnit_Framework_TestCase {
	public function testMyCase() {
		$result = aws::calculate_etag(__DIR__ .'/sampleEtagTestFile.txt', 1);
		$expect = '1637fa7110f8d109d48bf2a65173516e';
		$this->assertSame($expect, $result);
	}
}
