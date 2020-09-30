<?php
use PHPUnit\Framework\TestCase;
use winternet\jensenfw2\filesystem;
 
final class filesystemTest extends TestCase {
	public function testShortenPath() {
		$result = filesystem::shorten_path('/var/www/site.com/appdata/20200930.log');
		$expect = 'appdata/20200930.log';
		$this->assertSame($expect, $result);

		$result = filesystem::shorten_path('/var/www/site.com/appdata/20200930.log', ['keep_folders' => 2]);
		$expect = 'site.com/appdata/20200930.log';
		$this->assertSame($expect, $result);

		$result = filesystem::shorten_path('/var/www/site.com/../appdata/20200930.log', ['keep_folders' => 2]);
		$expect = '../appdata/20200930.log';
		$this->assertSame($expect, $result);

		$result = filesystem::shorten_path('20200930.log');
		$expect = '20200930.log';
		$this->assertSame($expect, $result);

		$result = filesystem::shorten_path('./20200930.log');
		$expect = '20200930.log';
		$this->assertSame($expect, $result);
	}
}
