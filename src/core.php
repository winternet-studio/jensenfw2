<?php
namespace winternet\jensenfw2;

class core {
	public static function system_error($msg, $vars = [], $dirs = []) {
		/*
		TODO:
		- what do I do with $vars ??? How can I let the programmer decide what happens to that information?
		*/
		throw new \Exception($msg);
	}
}
