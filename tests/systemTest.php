<?php
use PHPUnit\Framework\TestCase;
use winternet\jensenfw2\system;
 
final class systemTest extends TestCase {

	public function testMyCase() {
		$result = system::format_winternet_git_message('feat: browser-based barcode scanner #1289 (cl)');
		$expect = 'Feature: Browser-based barcode scanner #1289';
		$this->assertSame($expect, $result['standard']);

		$result = system::format_winternet_git_message('feat(api): browser-based barcode scanner #1289 (cl)');
		$expect = 'Feature (api): Browser-based barcode scanner #1289';
		$this->assertSame($expect, $result['standard']);

		$result = system::format_winternet_git_message('feat: MAJOR: browser-based barcode scanner #1289 (cl)');
		$expect = 'Feature MAJOR: Browser-based barcode scanner #1289';
		$this->assertSame($expect, $result['standard']);

		$result = system::format_winternet_git_message('feat(api): MAJOR: browser-based barcode scanner #1289 (cl)');
		$expect = 'Feature (api) MAJOR: Browser-based barcode scanner #1289';
		$this->assertSame($expect, $result['standard']);

		$result = system::format_winternet_git_message('feat: MAJOR: browser-based barcode scanner #1289 (cl)', ['remove_issue_number' => true]);
		$expect = 'Feature MAJOR: Browser-based barcode scanner';
		$this->assertSame($expect, $result['standard']);

		$result = system::format_winternet_git_message('fix!: browser-based barcode scanner (cl)');
		$expect = 'Fix: Browser-based barcode scanner (BREAKING CHANGE)';
		$this->assertSame($expect, $result['standard']);

		$result = system::format_winternet_git_message('chore: browser-based barcode scanner (cl)');
		$expect = 'Browser-based barcode scanner';
		$this->assertSame($expect, $result['standard']);
	}

}
