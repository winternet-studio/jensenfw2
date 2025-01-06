<?php
use PHPUnit\Framework\TestCase;
use winternet\jensenfw2\core;
 
final class coreTest extends TestCase {
	public function testIsInteger() {
		$this->assertTrue(core::is_integer(0));
		$this->assertTrue(core::is_integer(1));
		$this->assertTrue(core::is_integer(-1));
		$this->assertTrue(core::is_integer('0'));
		$this->assertTrue(core::is_integer('1'));
		$this->assertTrue(core::is_integer('-1'));
		$this->assertTrue(core::is_integer(' 1'));
		$this->assertTrue(core::is_integer('1 '));
		$this->assertFalse(core::is_integer('1.0'));
		$this->assertFalse(core::is_integer('1.4'));
		$this->assertTrue(core::is_integer(1.0));
		$this->assertFalse(core::is_integer(1.4));
		$this->assertFalse(core::is_integer(0.45));
		$this->assertFalse(core::is_integer('a1'));
		$this->assertFalse(core::is_integer('1a'));
	}

	public function testArrayInsertAfter() {
		// Numeric array
		$array  = ['a', 'b', 'c', 'd'];
		$expect = ['a', 'b', 'e', 'c', 'd'];
		$result = core::array_insert_after($array, 'b', 'e');
		$this->assertEquals($expect, $result);

		// Insert in the beginning
		$array  = ['a', 'b', 'c', 'd'];
		$expect = ['e', 'a', 'b', 'c', 'd'];
		$result = core::array_insert_after($array, 0, 'e');
		$this->assertEquals($expect, $result);

		// The "after" value doesn't exist
		$array  = ['a', 'b', 'c', 'd'];
		$expect = ['a', 'b', 'c', 'd', 'e'];
		$result = core::array_insert_after($array, 'x', 'e');
		$this->assertEquals($expect, $result);


		// Associative array
		$array  = ['a' => 'John', 'b' => 'Michael', 'c' => 'Maria', 'd' => 'Christina'];
		$expect = ['a' => 'John', 'b' => 'Michael', 'e' => 'Linda', 'c' => 'Maria', 'd' => 'Christina'];
		$result = core::array_insert_after($array, 'b', ['e' => 'Linda']);
		$this->assertEquals($expect, $result);

		// Insert in the beginning
		$array  = ['a' => 'John', 'b' => 'Michael', 'c' => 'Maria', 'd' => 'Christina'];
		$expect = ['d' => 'Christina', 'a' => 'John', 'b' => 'Michael', 'e' => 'Linda', 'c' => 'Maria'];
		$result = core::array_insert_after($array, 0, ['e' => 'Linda']);
		$this->assertEquals($expect, $result);

		// The "after" value doesn't exist
		$array  = ['a' => 'John', 'b' => 'Michael', 'c' => 'Maria', 'd' => 'Christina'];
		$expect = ['a' => 'John', 'b' => 'Michael', 'c' => 'Maria', 'd' => 'Christina', 'e' => 'Linda'];
		$result = core::array_insert_after($array, 'x', ['e' => 'Linda']);
		$this->assertEquals($expect, $result);
	}

	public function testIsArrayAssoc() {
		$array  = ['Denver', 'San Francisco', 'Orlando'];
		$result = core::is_array_assoc($array);
		$this->assertFalse($result);

		$array  = [0 => 'Denver', 1 => 'San Francisco', 2 => 'Orlando'];
		$result = core::is_array_assoc($array);
		$this->assertFalse($result);

		$array  = [0 => 'Denver', 1 => 'San Francisco', 2 => 'Orlando'];
		$result = core::is_array_assoc($array, ['require_strings' => true]);
		$this->assertFalse($result);

		$array  = [1 => 'Denver', 2 => 'San Francisco', 3 => 'Orlando'];  //not starting at zero
		$result = core::is_array_assoc($array);
		$this->assertTrue($result);

		$array  = [0 => 'Denver', 1 => 'San Francisco', 5 => 'Orlando'];  //non-sequential numeric indexes
		$result = core::is_array_assoc($array);
		$this->assertTrue($result);

		$array  = [0 => 'Denver', 1 => 'San Francisco', 5 => 'Orlando'];  //require strings
		$result = core::is_array_assoc($array, ['require_strings' => true]);
		$this->assertFalse($result);

		$array  = ['city' => 'Denver', 'state' => 'CO'];
		$result = core::is_array_assoc($array);
		$this->assertTrue($result);

		$array  = ['city' => 'Denver', 'state' => 'CO'];
		$result = core::is_array_assoc($array, ['require_strings' => true]);
		$this->assertTrue($result);
	}

	public function testTxtDb() {
		$GLOBALS['_override_current_language'] = 'en';
		$this->assertSame('Text in English', core::txtdb('EN=Text in English ,,, ES=Text in Spanish'));
		$this->assertSame('Here, there, and everywhere', core::txtdb('  EN = Here, there, and everywhere  ,,,  NO = Her, der, og alle vegne'));

		$GLOBALS['_override_current_language'] = 'es';
		$this->assertSame('Text in Spanish', core::txtdb('EN=Text in English ,,, ES=Text in Spanish'));
	}

	public function testParseMultipartTranslation() {
		$template  = '<h1>#TITLE#</h1>';
		$template .= '<p>#PARAGRAPH1#</p>';
		$template .= '<p><a href="%%resetpwURL%%">#RESET-BUTTON#</a></p>';

		$translation = '#TITLE:
Change your password
#PARAGRAPH1:
 Paragraph here. 
#RESET-BUTTON:
Reset Password';

		$expect  = '<h1>Change your password</h1>';
		$expect .= '<p>Paragraph here.</p>';
		$expect .= '<p><a href="%%resetpwURL%%">Reset Password</a></p>';

		$this->assertSame($expect, core::parse_multipart_translation($template, $translation));
	}

	public function testJsonDecode() {
		$json = '{
    "partner_id": 4513,
	// "customer_name": "Outpost Centers International",  
	// "customer_addr_full": "Puchong\n47170 Puchong, Selangor\nMalaysia",
    "invoice_date": "2023-11-08",
    "invoice_currency": "EUR",
    // "orderID": 15053,
    "invoice_items": [
        {
            "desc": "registerseat.com fee // event OCI23",
            "qty": 1,
            "unit_price": 96,
            "account_ref": "website_licenses"
        }
    ]
}';

		$expect = '{
    "partner_id": 4513,
    "invoice_date": "2023-11-08",
    "invoice_currency": "EUR",
    "invoice_items": [
        {
            "desc": "registerseat.com fee // event OCI23",
            "qty": 1,
            "unit_price": 96,
            "account_ref": "website_licenses"
        }
    ]
}';

		$this->assertEquals(json_decode($expect), core::json_decode($json));
	}

}
