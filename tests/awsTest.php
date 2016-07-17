<?php
use winternet\jensenfw2\Aws;
 
class awsTest extends PHPUnit_Framework_TestCase {
	public function testMyCase() {
		$this->assertTrue(Aws::calculateEtag());
	}
}
