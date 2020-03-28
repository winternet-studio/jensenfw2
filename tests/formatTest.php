<?php
use PHPUnit\Framework\TestCase;
use winternet\jensenfw2\format;
 
final class formatTest extends TestCase {
	public function testExtractTags() {
		$result = format::extractTags('The {document} is {number} pages long');
		$expect = '["The ",["document"]," is ",["number"]," pages long"]';
		$this->assertSame($expect, json_encode($result));

		$result = format::extractTags('This is a {leftOrRight,select,left{left} right{right}} page');
		$expect = '["This is a ",["leftOrRight","select","left{left} right{right}"]," page"]';
		$this->assertSame($expect, json_encode($result));

		$result = format::extractTags('This is a {leftOrRight,select,left{left} right{right}} page', ['recursive' => true]);
		$expect = '["This is a ",[["leftOrRight"],["select"],["left",[["left"]]," right",[["right"]],""]]," page"]';
		$this->assertSame($expect, json_encode($result));

		$result = format::extractTags('This is a {leftOrRight,select,left{left} right{right}} page', ['fieldSeparator' => false]);
		$expect = '["This is a ","leftOrRight,select,left{left} right{right}"," page"]';
		$this->assertSame($expect, json_encode($result));

		$result = format::extractTags('Red fox <div>jumping <div>over</div> the fence</div> every day', ['recursive' => false, 'open' => '<div>', 'close' => '</div>']);
		$expect = '["Red fox ",["jumping <div>over<\/div> the fence"]," every day"]';
		$this->assertSame($expect, json_encode($result));

		$result = format::extractTags('Red fox <div>jumping <div>over</div> the fence</div> every day', ['recursive' => true, 'open' => '<div>', 'close' => '</div>']);
		$expect = '["Red fox ",[["jumping ",[["over"]]," the fence"]]," every day"]';
		$this->assertSame($expect, json_encode($result));
	}
}
