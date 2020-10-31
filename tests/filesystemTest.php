<?php
use PHPUnit\Framework\TestCase;
use winternet\jensenfw2\filesystem;
 
final class filesystemTest extends TestCase {
	public function testSaveShortlivedFile() {
		// Test without path
		@unlink('temp-shortlived.json');
		@unlink('temp-shortlived.EXPIRE'. date('YmdHi', time()+7*24*3600) .'.json');

		$result = filesystem::save_shortlived_file('temp-shortlived.json', 'temporary file', '7d');
		$timestamp = date('YmdHi', time()+7*24*3600);
		$this->assertTrue(file_exists('temp-shortlived.json'));
		$this->assertTrue(file_exists('temp-shortlived.EXPIRE'. $timestamp .'.json'));

		unlink('temp-shortlived.json');
		unlink('temp-shortlived.EXPIRE'. $timestamp .'.json');


		// Test with path
		$path = dirname(__DIR__);
		@unlink($path .'/temp-expiring-file.json');
		@unlink($path .'/temp-expiring-file.EXPIRE'. date('YmdHi', time()+7*24*3600) .'.json');

		$result = filesystem::save_shortlived_file($path .'/temp-expiring-file.json', 'temporary file', '7d');
		$timestamp = date('YmdHi', time()+7*24*3600);
		$this->assertTrue(file_exists($path .'/temp-expiring-file.json'));
		$this->assertTrue(file_exists($path .'/temp-expiring-file.EXPIRE'. $timestamp .'.json'));

		unlink($path .'/temp-expiring-file.json');
		unlink($path .'/temp-expiring-file.EXPIRE'. $timestamp .'.json');


		// Test without extension
		@unlink('temp-shortlived-noext');
		@unlink('temp-shortlived-noext.EXPIRE'. date('YmdHi', time()+7*24*3600) .'.json');

		$result = filesystem::save_shortlived_file('temp-shortlived-noext', 'temporary file', '7d');
		$timestamp = date('YmdHi', time()+7*24*3600);
		$this->assertTrue(file_exists('temp-shortlived-noext'));
		$this->assertTrue(file_exists('temp-shortlived-noext.EXPIRE'. $timestamp));

		unlink('temp-shortlived-noext');
		unlink('temp-shortlived-noext.EXPIRE'. $timestamp);
	}

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
