<?php
/**
 * Functions related to security
 */

namespace winternet\jensenfw2;

class security {
	/**
	 * @param integer $length : Length of string to generate
	 * @param string $characters : Set of characters allowed in the string, or one of the following special values to use a predefined set:
	 *   - `alphanumeric` : All numbers and English letters (lower and upper case)
	 *   - `alphanumericClean` : All numbers and English letters (lower and upper case), except 0, 1, I, O, and L
	 *   - `alphanumericCleanLowerCase` : All numbers and English letters (lower and upper case), except 0, 1, I, O, and L
	 */
	static public function generate_random_string($length = 10, $characters = 'alphanumericClean') {
		$characterSets = [
			'alphanumeric' => '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ',
			'alphanumericClean' => '23456789abcdefghjkmnpqrstuvwxyzABCDEFGHJKMNPQRSTUVWXYZ',
			'alphanumericCleanLowerCase' => '23456789abcdefghjkmnpqrstuvwxyz',
			'letters' => 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ',
			'lettersClean' => 'abcdefghjkmnpqrstuvwxyzABCDEFGHJKMNPQRSTUVWXYZ',
			'lettersCleanLowerCase' => 'abcdefghjkmnpqrstuvwxyz',
		];

		if ($characterSets[$characters]) {
			$characters = $characterSets[$characters];
		}

		// Source: https://stackoverflow.com/a/31107425/2404541  referenced from  https://stackoverflow.com/questions/4356289/php-random-string-generator#4356295
		$pieces = [];
		$max = mb_strlen($characters, '8bit') - 1;
		for ($i = 0; $i < $length; ++$i) {
			$pieces []= $characters[random_int(0, $max)];
		}
		return implode('', $pieces);
	}
}
