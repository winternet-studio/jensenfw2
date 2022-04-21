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

	/**
	 * Anonymize a scalar value
	 *
	 * @param string|number $value : The value to be anonymized
	 * @param string $hash_salt : A long secret salt for hashing the value
	 * @param array $options : Available options:
	 *   - `prefix` : set a fixed prefix for the anonymized value so that you know it has been anonymized. Defaults to `DEL!` if option is not set.
	 *   - `max_length` : set a max length for the returned value
	 *   - `is_email` : value is an email address
	 *   - `is_ip` : value is an IP address
	 */
	static public function anonymize_value($value, $hash_salt, $options = []) {
		if (!empty($value) && !is_numeric($value)) {
			if (!array_key_exists('prefix', $options)) {
				$options['prefix'] = 'DEL!';
			}
			if (@$options['is_ip']) {
				$value = preg_replace("/(\\d+\\.\\d+)\\.\\d+\\.\\d+/", "$1.00.00", $value);
			} else {
				$value = strtolower((string) $value);
				$value = md5($hash_salt . $value);
				if (@$options['is_email']) {
					$value = $value .'@anonymized.com';
				} elseif (@$options['max_length'] && strlen($value) > $options['max_length']) {
					$value = substr($value, 0, $options['max_length']);
				}

				if (!@$options['is_email']) {
					$value = $options['prefix'] . $value;
				}
			}
		}
		return $value;
	}
}
