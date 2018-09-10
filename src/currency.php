<?php
/**
 * This file contains functions related to currencies
 */
namespace winternet\jensenfw2;

class currency {
	/**
	 * Get most currenct exchange rates from currencylayer.com
	 *
	 * Features a fallback option and caching in session
	 *
	 * @param string $from_currency : (req.) From currency (if source amount is in this currency divide it with the return value)
	 * @param string $to_currency : (req.) To currency (if source amount is in this currency multiply it with the return value)
	 * @param float $fallback_rate : (req.) Fallback exchange rate to use if today's rate could not be retrieved. Set to null if you don't want any fallback but then check output for null as well.
	 * @return float
	 */
	public static function get_ecb_live_exchange_rate($from_currency, $to_currency, $fallback_rate) {
		$from_currency = strtoupper($from_currency);
		$to_currency = strtoupper($to_currency);

		if (@constant('YII_BEGIN_TIME') && \Yii::$app->session) {
			$session_value = \Yii::$app->session['latest_exchrate_'. $from_currency .'_'. $to_currency];
		} else {
			$session_value = $_SESSION['latest_exchrate_'. $from_currency .'_'. $to_currency];
		}

		if (!$session_value) {
			$ch = curl_init();
			curl_setopt($ch, CURLOPT_URL, 'https://www.ecb.europa.eu/stats/eurofxref/eurofxref-daily.xml');
			curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
			curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
			$xmlStr = curl_exec($ch);
			try {
				$xml = simplexml_load_string($xmlStr);
				if ($xml === false) {
					//use fallback below
				} else {
					$from_rate = $to_rate = false;
					if ($from_currency == 'EUR') $from_rate = 1;
					if ($to_currency == 'EUR') $to_rate = 1;
					$json = json_decode(json_encode($xml), true);
					foreach ($json['Cube']['Cube']['Cube'] as $curr) {
						if ($curr['@attributes']['currency'] == $from_currency) {
							$from_rate = $curr['@attributes']['rate'];
						} elseif ($curr['@attributes']['currency'] == $to_currency) {
							$to_rate = $curr['@attributes']['rate'];
						}
					}
				}
				if ($from_rate && $to_rate && is_numeric($from_rate) && is_numeric($to_rate)) {
					$exchrate = $from_rate / $to_rate;
				}
			} catch (\Exception $e) {
				//use fallback below
			}

			if (!$exchrate) {
				$exchrate = $fallback_rate;
			}

			if (@constant('YII_BEGIN_TIME') && \Yii::$app->session) {
				\Yii::$app->session['latest_exchrate_'. $from_currency .'_'. $to_currency] = $session_value = $exchrate;
			} else {
				$_SESSION['latest_exchrate_'. $from_currency .'_'. $to_currency] = $session_value = $exchrate;
			}

		}
		return $session_value;
	}
}
