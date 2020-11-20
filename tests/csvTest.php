<?php
use PHPUnit\Framework\TestCase;
use winternet\jensenfw2\csv;

final class csvTest extends TestCase {
	public function testCommaSep() {
		$array = [ ['firstname' => 'John II', 'lastname' => 'Doe', 'address' => "1 Street\tNew York"], ['firstname' => 'Mary', 'lastname' => 'Smith', 'address' => "1 Street\nNew York"]];
		$expected = "firstname,lastname,address\n\"John II\",Doe,\"1 Street\tNew York\"\nMary,Smith,\"1 Street\nNew York\"";

		$this->assertEquals($expected, csv::generate($array));
	}

	public function testTabSep() {
		$array = [ ['firstname' => 'John', 'lastname' => 'Doe'], ['firstname' => 'Mary', 'lastname' => 'Smith']];
		$expected = "firstname\tlastname\nJohn\tDoe\nMary\tSmith";

		$this->assertEquals($expected, csv::generate($array, ['delimiter' => "\t"]));
	}

	public function testNoHeader() {
		$array = [ ['firstname' => 'John', 'lastname' => 'Doe'], ['firstname' => 'Mary', 'lastname' => 'Smith']];
		$expected = "John,Doe\nMary,Smith";

		$this->assertEquals($expected, csv::generate($array, ['header' => false]));
	}
}
