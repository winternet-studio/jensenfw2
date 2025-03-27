<?php
use PHPUnit\Framework\TestCase;
use winternet\jensenfw2\filesystem;
 
final class filesystemTest extends TestCase {
	public function testSaveShortlivedFile() {

		filesystem::cleanup_shortlived_files('./');

		// Test without path
		$timestamp = gmdate('YmdHis', time()+7*24*3600);
		@unlink('temp-shortlived.json');
		@unlink('temp-shortlived.EXPIRE'. $timestamp .'.json');

		$result = filesystem::save_shortlived_file('temp-shortlived.json', 'temporary file', '7d');
		$this->assertTrue(file_exists('temp-shortlived.json'));
		$this->assertTrue(file_exists('temp-shortlived.EXPIRE'. $timestamp .'.json'));
		$this->assertTrue(filesystem::shortlived_file_exists('temp-shortlived.json'));


		unlink('temp-shortlived.json');
		unlink('temp-shortlived.EXPIRE'. $timestamp .'.json');


		// Test with path
		$path = dirname(__DIR__);
		@unlink($path .'/temp-expiring-file.json');
		@unlink($path .'/temp-expiring-file.EXPIRE'. gmdate('YmdHis', time()+7*24*3600) .'.json');

		$result = filesystem::save_shortlived_file($path .'/temp-expiring-file.json', 'temporary file', '7d');
		$timestamp = gmdate('YmdHis', time()+7*24*3600);
		$this->assertTrue(file_exists($path .'/temp-expiring-file.json'));
		$this->assertTrue(file_exists($path .'/temp-expiring-file.EXPIRE'. $timestamp .'.json'));

		unlink($path .'/temp-expiring-file.json');
		unlink($path .'/temp-expiring-file.EXPIRE'. $timestamp .'.json');


		// Test without extension
		@unlink('temp-shortlived-noext');
		@unlink('temp-shortlived-noext.EXPIRE'. gmdate('YmdHis', time()+7*24*3600) .'.json');

		$result = filesystem::save_shortlived_file('temp-shortlived-noext', 'temporary file', '7d');
		$timestamp = gmdate('YmdHis', time()+7*24*3600);
		$this->assertTrue(file_exists('temp-shortlived-noext'));
		$this->assertTrue(file_exists('temp-shortlived-noext.EXPIRE'. $timestamp));
		$this->assertTrue(filesystem::shortlived_file_exists('temp-shortlived-noext'));

		unlink('temp-shortlived-noext');
		unlink('temp-shortlived-noext.EXPIRE'. $timestamp);


		// Test non-existing file
		$this->assertFalse(filesystem::shortlived_file_exists('nonexisting-shortlived.txt'));


		// Test with specific timestamp
		$timestamp = '2045-03-27 20:11:03';
		$result = filesystem::save_shortlived_file('temp-shortlived-fixed.json', 'temporary file', $timestamp);
		$this->assertTrue(file_exists('temp-shortlived-fixed.json'));
		$this->assertTrue(file_exists('temp-shortlived-fixed.EXPIRE'. date('YmdHis', strtotime($timestamp)) .'.json'));
		$this->assertTrue(filesystem::shortlived_file_exists('temp-shortlived-fixed.json'));

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
