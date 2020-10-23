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
}
