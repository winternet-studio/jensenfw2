<?php
use winternet_studio\jensenfw\aws;
 
class awsTest extends PHPUnit_Framework_TestCase {
	public function testMyCase() {
		$this->assertTrue(aws::calculate_etag());
	}
}
