<?php
use PHPUnit\Framework\TestCase;
use winternet\jensenfw2\sql_tokenizer;
 
final class sql_tokenizerTest extends TestCase {
	public function testMyCase() {
		$sql = 'SELECT * FROM mytable WHERE id = 5';
		$result = sql_tokenizer::tokenize($sql);
		$expect = ['SELECT', ' ', '*', ' ', 'FROM', ' ', 'mytable', ' ', 'WHERE', ' ', 'id', ' ', '=', ' ', '5'];
		$this->assertSame($expect, $result);
		$this->assertSame($sql, sql_tokenizer::untokenize($result));

		$sql = 'SELECT *, name FROM mytable WHERE mytable.id = "John \"Dong\" Doe" /*comment*/ AND approved = :is_approved AND somevar = ? ORDER BY name';
		$result = sql_tokenizer::tokenize($sql);
		$expect = ['SELECT', ' ', '*', ',', ' ', 'name', ' ', 'FROM', ' ', 'mytable', ' ', 'WHERE', ' ', 'mytable.id', ' ', '=', ' ', '"John \\"Dong\\" Doe"', ' ', '/*comment*/', ' ', 'AND', ' ', 'approved', ' ', '=', ' ', ':is_approved', ' ', 'AND', ' ', 'somevar', ' ', '=', ' ', '?', ' ', 'ORDER', ' ', 'BY', ' ', 'name'];
		$this->assertSame($expect, $result);
		$this->assertSame($sql, sql_tokenizer::untokenize($result));
	}
}
