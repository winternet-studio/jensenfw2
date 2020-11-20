<?php
use PHPUnit\Framework\TestCase;
use winternet\jensenfw2\csv_parser;

final class csv_parserTest extends TestCase {
	public function testHeaderRow() {
		$parser = new csv_parser(['delimiter' => "\t"]);
		$output = $parser->parse_csv(file_get_contents(__DIR__ .'/fixtures/csv_parser/header-row.csv'));

		$this->assertEquals(str_replace("\r", '', '[
    {
        "Identifier": "179f6d15-a6fb-48e8-8426-46a904517463",
        "Name": "Netgear EX3700 AC750",
        "Type": "BRIDGED",
        "Comment": "Something; Something else, or not"
    },
    {
        "Identifier": "07a5ec98-59ce-49ff-a188-796804a73fb2",
        "Name": "Netgear EX3700 AC750",
        "Type": "BRIDGED",
        "Comment": "John Doe"
    },
    {
        "Identifier": "b99da170-4eab-4d62-a5ff-d0628424e046",
        "Name": "Netgear EX3700 AC750",
        "Type": "BRIDGED",
        "Comment": "John Doe"
    },
    {
        "Identifier": "209a0d2d-5d16-4abf-9aaf-2df598a23472",
        "Name": "Netgear EX3700 AC750",
        "Type": "BRIDGED",
        "Comment": "John Doe"
    }
]'), json_encode($output, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES));
	}

	public function testSemicolon() {
		$parser = new csv_parser(['delimiter' => ';', 'first_line_is_header' => false]);
		$output = $parser->parse_csv(file_get_contents(__DIR__ .'/fixtures/csv_parser/semicolon.csv'));

		$this->assertEquals(str_replace("\r", '', '[
    {
        "1": "179f6d15-a6fb-48e8-8426-46a904517463",
        "2": "Netgear EX3700 AC750",
        "3": "BRIDGED",
        "4": "Something\tSomething else, or not"
    },
    {
        "1": "07a5ec98-59ce-49ff-a188-796804a73fb2",
        "2": "Netgear EX3700 AC750",
        "3": "BRIDGED",
        "4": "John Doe"
    },
    {
        "1": "b99da170-4eab-4d62-a5ff-d0628424e046",
        "2": "Netgear EX3700 AC750",
        "3": "BRIDGED",
        "4": "John Doe"
    },
    {
        "1": "209a0d2d-5d16-4abf-9aaf-2df598a23472",
        "2": "Netgear EX3700 AC750",
        "3": "BRIDGED",
        "4": "John Doe"
    }
]'), json_encode($output, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES));
	}

	public function testTabs() {
		$parser = new csv_parser(['delimiter' => "\t", 'first_line_is_header' => false]);
		$output = $parser->parse_csv(file_get_contents(__DIR__ .'/fixtures/csv_parser/tabs.csv'));
		// var_export($output);
		// file_put_contents('dump.txt', json_encode($output, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES));

		$this->assertEquals(str_replace("\r", '', '[
    {
        "1": "179f6d15-a6fb-48e8-8426-46a904517463",
        "2": "Netgear EX3700 AC750",
        "3": "BRIDGED",
        "4": "Something; Something else, or not"
    },
    {
        "1": "07a5ec98-59ce-49ff-a188-796804a73fb2",
        "2": "Netgear EX3700 AC750",
        "3": "BRIDGED",
        "4": "John Doe"
    },
    {
        "1": "b99da170-4eab-4d62-a5ff-d0628424e046",
        "2": "Netgear EX3700 AC750",
        "3": "BRIDGED",
        "4": "John Doe"
    },
    {
        "1": "209a0d2d-5d16-4abf-9aaf-2df598a23472",
        "2": "Netgear EX3700 AC750",
        "3": "BRIDGED",
        "4": "John Doe"
    }
]'), json_encode($output, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES));
	}

    public function testSpecialCharacters() {
        $parser = new csv_parser();
        $output = $parser->parse_csv(file_get_contents(__DIR__ .'/fixtures/csv_parser/special-characters.csv'));  //this intentionally has Windows line-breaks to show that they are retained as-is within values
        // var_export($output);
        // file_put_contents('dump.txt', json_encode($output, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES));

        $this->assertEquals(str_replace("\r", '', '[
    {
        "firstname": "John II",
        "lastname": "Doe",
        "address": "1 Street\tNew York"
    },
    {
        "firstname": "Mary",
        "lastname": "Smith",
        "address": "1 Street\r\nNew York"
    }
]'), json_encode($output, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES));
    }
}
