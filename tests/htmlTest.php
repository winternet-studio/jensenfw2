<?php
// This next line is not suppose to be here but that was the only way I knew how to run the tests by running the command "phpunit" in the parent directory! (while phpunit is globally installed)
require_once('src/html.php');

use winternet\jensenfw2\html;
 
class htmlTest extends PHPUnit_Framework_TestCase {
	public function testMyCase() {
		$result = html::parse_to_flat_array('&aelig;&oslash;&aring;&ouml;&auml;&uuml;&ucirc;&AElig;&Oslash;&Aring;&Ouml;&Auml;&Uuml;&Ucirc;');
		$expect = 'æøåöäüûÆØÅÖÄÜÛ';
		$this->assertSame($expect, $result[0]['text']);
	}
}
