<?php
/**
 * Functions related to SWIFT, BIC, IBAN codes etc. (SWIFT: Society for Worldwide Interbank Financial Telecommunication)
 */

namespace winternet\jensenfw2;

/*
ISO13616 IBAN Registry:
http://www.swift.com/solutions/messaging/information_products/directory_products/iban_format_registry/index.page

Javascript IBAN validator: (PHP validators also exist - search Google)
http://auctionfeecalculator.com/afc_js/0c5j8u3n4b5/validate_iban.js
*/

class economy {
	/**
	 * Validate a BIC code against online database (validates not only the format of the code)
	 * 
	 * @param string $bic_code
	 * @return array : Associative array:
	 *   - `is_valid` (true|false) : boolean for whether or not the code is valid
	 *   - `err_msg` (string) : error message if any
	 */
	public static function validate_bic($bic_code) {
		$err_msg = false;

		// Validate format
		if (!preg_match('|^([a-zA-Z]){4}([a-zA-Z]){2}([0-9a-zA-Z]){2}([0-9a-zA-Z]{3})?$|', $bic_code)) {
			$err_msg = 'Format is invalid.';
		}

		// 2010-02-22: discovered that www.swift.com had changed their website so that it's no longer possible to screen-scrape their information
		// Only manual lookup can be done at http://www.swift.com/bsl/

		if ($err_msg) {
			return [
				'is_valid' => false,
				'err_msg' => $err_msg
			];
		} else {
			return [
				'is_valid' => true,
				'err_msg' => ''
			];
		}
	}

	/**
	 * Validate an ABA routing number (or RTN, Routing Transit Number) (used in United States)
	 * 
	 * @param string $aba_number : ABA routing number to validate
	 * @return array : Associative array:
	 *   - `is_valid` (true|false) : boolean for whether or not the code is valid
	 *   - `err_msg` (string) : error message if any
	 */
	public static function validate_aba(string $aba_number) {
		$err_msg = false;

		if (!is_numeric($aba_number) || strlen( (string) $aba_number) < 9 || strlen( (string) $aba_number) > 9) {
			$err_msg = 'ABA routing number is not 9 digits.';
		}

		// Check the checksum digit (see http://en.wikipedia.org/wiki/Routing_transit_number)
		if (!$err_msg) {
			$no = $aba_number;
			$given_checksum = $no[8];
			$calc_checksum = (7 * ($no[0] + $no[3] + $no[6]) + 3 * ($no[1] + $no[4] + $no[7]) + 9 * ($no[2] + $no[5]) ) % 10;
			$calc_checksum = (string) $calc_checksum;
			if ($given_checksum != $calc_checksum) {
				$err_msg = 'ABA routing number is invalid.';
			}
		}

		if ($err_msg) {
			return [
				'is_valid' => false,
				'err_msg' => $err_msg
			];
		} else {
			return [
				'is_valid' => true,
				'err_msg' => ''
			];
		}
	}
}
