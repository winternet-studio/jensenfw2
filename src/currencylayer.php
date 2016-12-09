<?php
/*
This file contains functions related to geocoding
*/
namespace winternet\jensenfw2;

class currencylayer {
	public static function get_live_exchange_rates($api_access_key, $base_currency = false, $limit_to_currencies = []) {
		/*
		DESCRIPTION:
		- get most currenct exchange rates from currencylayer.com
		INPUT:
		- $api_access_key (req.)
		- $base_currency (opt.) : set base currency other than USD. REQUIRES a paid subscription!
		- $limit_to_currencies (opt.) : get only currencies listed in the given array
		OUTPUT:
		- 
		*/
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
