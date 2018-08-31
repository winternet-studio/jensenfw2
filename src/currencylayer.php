<?php
/**
 * This file contains functions related to apilayer.com
 */
namespace winternet\jensenfw2;

class currencylayer {
	/**
	 * Get most currenct exchange rates from currencylayer.com
	 *
	 * @param string $api_access_key : (req.)
	 * @param string $base_currency : (opt.) Set base currency other than USD. REQUIRES a paid subscription!
	 * @param array $limit_to_currencies : (opt.) Get only currencies listed in the given array
	 * @return array
	 */
	public static function get_live_exchange_rates($api_access_key, $base_currency = false, $limit_to_currencies = []) {
		$url = 'http://apilayer.net/api/live?access_key='. $api_access_key;

		if ($base_currency) {
			$url .= '&source='. $base_currency;
		}
		if (!empty($limit_to_currencies)) {
			$url .= '&currencies='. implode(',', $limit_to_currencies);
		}

		$ch = curl_init($url);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		$json = curl_exec($ch);
		curl_close($ch);

		$output = [
			'exch_rates' => json_decode($json, true),
		];
		return $output;
	}
}
