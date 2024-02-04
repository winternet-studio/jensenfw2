<?php
use PHPUnit\Framework\TestCase;
use winternet\jensenfw2\format;
 
final class formatTest extends TestCase {
	public function testNounPlural() {
		$this->assertSame('They were 3 people in the park, yes, just 3 people', format::noun_plural(3, '((There was,They were)) {number} ((person,people)) in the park, yes, just {number} ((person,people))'));
		$this->assertSame('There was 1 person in the park, yes, just 1 person', format::noun_plural(1, '((There was,They were)) {number} ((person,people)) in the park, yes, just {number} ((person,people))'));
	}

	public function testTruncate() {
		$this->assertSame('This is a very long sentence we want to...', format::truncate('This is a very long sentence we want to shorten down to something short and sweet.', 50));
		$this->assertSame('This is a very long sentence we want to shorten--', format::truncate('This is a very long sentence we want to shorten down to something short and sweet.', 50, '--'));
		$this->assertSame('This is a short sentence.', format::truncate('This is a short sentence.', 50));
	}

	public function testSplitTextIntoChunks() {
		$this->assertSame('The quick brown fox jumps<br>over the lazy dog', format::split_text_into_chunks('The quick brown fox jumps over the lazy dog'));
	}

	public function testStrToTitle() {
		$this->assertSame('The Grapes of Wrath', format::strtotitle('the grapes of WratH'));
		$this->assertSame('The Grapes Of Wrath', format::strtotitle('the grapes of WratH', ['is_person' => true]));
		$this->assertSame('Marie-Lou van der Planck-St. John', format::strtotitle('MARIE-LOU VAN DER PLANCK-ST. JOHN', ['is_person' => true]));
		$this->assertSame('To Be or Not to Be', format::strtotitle('to be or not to be'));
		$this->assertSame('McDonald O\'neil', format::strtotitle('mcdonald o\'neil', ['is_person' => true]));
		$this->assertSame('232 1st Avenue', format::strtotitle('232 1ST AVENUE', ['is_address' => true]));
		$this->assertSame('US Highway 1', format::strtotitle('Us Highway 1', ['is_address' => true]));
		$this->assertSame('US Highway 1', format::strtotitle('us highway 1', ['is_address' => true]));
	}

	public function testFixWrongTitleCase() {
		$this->assertSame('John Doe', format::fix_wrong_title_case('JOHN DOE'));
		$this->assertSame('John Doe', format::fix_wrong_title_case('JOHN Doe'));
		$this->assertSame('John Doe', format::fix_wrong_title_case('john doe'));
		$this->assertSame('John Doe', format::fix_wrong_title_case('JOhn doe', 30));
	}

	public function testMbStrPad() {
		$this->assertSame('BAAZ😃😃😃😃😃😃', format::mb_str_pad('BAAZ', 10, '😃'));
		$this->assertSame('BAAZàèòàèò', format::mb_str_pad('BAAZ', 10, 'àèò'));
	}

	public function testRemoveMultipleSpaces() {
		$text = 'John  Doe';
		format::remove_multiple_spaces($text);
		$this->assertSame('John Doe', $text);

		$text = 'John    Doe  ';
		format::remove_multiple_spaces($text);
		$this->assertSame('John Doe ', $text);
	}

	public function testConvertDistance() {
		$this->assertEquals(3280.84, format::convert_distance(1, 'km', 'feet'));
		$this->assertEquals(1000, format::convert_distance(1, 'km', 'm'));
		$this->assertEquals(0.6214, format::convert_distance(1, 'km', 'miles'));
		$this->assertEquals(1, format::convert_distance(1, 'km', 'km'));
		$this->assertEquals(1, format::convert_distance(1, 'm', 'm'));
		$this->assertEquals(1.609, round(format::convert_distance(1, 'miles', 'km'), 3));
	}

	public function testReplaceAccents() {
		$this->assertSame('AAAAAAAECEEEEIIIIDN', format::replace_accents('ÀÁÂÃÄÅÆÇÈÉÊËÌÍÎÏÐÑ'));
	}

	public function testCleanupTitleUrlSafe() {
		$this->assertSame('lorem-ipsum-dolor-sit-amet-consectetur-12', format::cleanup_title_url_safe(' -Lo#&@rem  IPSUM. //Dolor-/sit - amét-\\-consectetür__! 12 -- '));
	}

	public function testExtractTags() {
		$result = format::extract_tags('The {document} is {number} pages long');
		$expect = '["The ",["document"]," is ",["number"]," pages long"]';
		$this->assertSame($expect, json_encode($result));

		$result = format::extract_tags('This is a {leftOrRight,select,left{left} right{right}} page');
		$expect = '["This is a ",["leftOrRight","select","left{left} right{right}"]," page"]';
		$this->assertSame($expect, json_encode($result));

		$result = format::extract_tags('This is a {leftOrRight,select,left{left} right{right}} page', ['recursive' => true]);
		$expect = '["This is a ",[["leftOrRight"],["select"],["left",[["left"]]," right",[["right"]],""]]," page"]';
		$this->assertSame($expect, json_encode($result));

		$result = format::extract_tags('This is a {leftOrRight,select,left{left} right{right}} page', ['field_separator' => false]);
		$expect = '["This is a ","leftOrRight,select,left{left} right{right}"," page"]';
		$this->assertSame($expect, json_encode($result));

		$result = format::extract_tags('Red fox <div>jumping <div>over</div> the fence</div> every day', ['recursive' => false, 'open' => '<div>', 'close' => '</div>']);
		$expect = '["Red fox ",["jumping <div>over<\/div> the fence"]," every day"]';
		$this->assertSame($expect, json_encode($result));

		$result = format::extract_tags('Red fox <div>jumping <div>over</div> the fence</div> every day', ['recursive' => true, 'open' => '<div>', 'close' => '</div>']);
		$expect = '["Red fox ",[["jumping ",[["over"]]," the fence"]]," every day"]';
		$this->assertSame($expect, json_encode($result));
	}

	public function testBase64UrlVersion() {
		$binary = hash('sha512', 'The quick brown fox jumps over the lazy dog', true);
		$this->assertSame('B-VH2VhvanP3P7rAQ17XaVEhj7fQyNeIownXhUNru2Quk6JSqVTyORJUfR6KO17W4b.XCXghIz-gU489uFT-5g', format::base64_encode_url($binary));

		$this->assertSame($binary, format::base64_decode_url(format::base64_encode_url($binary)));
	}
}
