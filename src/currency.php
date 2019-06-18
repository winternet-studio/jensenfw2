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

		$storage = null;
		$storage_key = 'exchRate_'. $from_currency .'_'. $to_currency;
		if (@constant('YII_BEGIN_TIME')) {
			if (\Yii::$app->cache) {
				$storage = 'yii_cache';
				$current_value = \Yii::$app->cache->get($storage_key);
			} elseif (PHP_SAPI !== 'cli' && \Yii::$app->session) {
				$storage = 'yii_session';
				$current_value = \Yii::$app->session[$storage_key];
			}
		}
		if (!$storage) {
			$storage = 'native_session';
			$current_value = $_SESSION[$storage_key];
		}

		if (!$current_value) {
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
				} else {
					// One or both currencies were not found, register the problem so that we can be made aware of non-working systems
					core::system_error('One or both currencies were not found in get_ecb_live_exchange_rate().'.$from_currency.$to_currency, array('From currency' => $from_currency, 'To currency' => $to_currency, 'XML' => $xmlStr), array('xsilent' => true, 'xterminate' => false, 'xsevere' => 'WARNING'));
					return null;
				}
			} catch (\Exception $e) {
				//use fallback below
			}

			if ($exchrate) {
				$current_value = $exchrate;
				if ($storage === 'yii_cache') {
					\Yii::$app->cache->set($storage_key, $current_value, 21600);
				} elseif ($storage === 'yii_session') {
					\Yii::$app->session[$storage_key] = $current_value;
				} elseif ($storage === 'native_session') {
					$_SESSION[$storage_key] = $current_value;
				}
			} else {
				$current_value = $fallback_rate;
				// Register the problem so that we can be made aware of non-working systems
				core::system_error('Failed to obtain exchange rate in get_ecb_live_exchange_rate().', array('XML' => $xmlStr), array('xsilent' => true, 'xterminate' => false, 'xsevere' => 'WARNING'));
			}
		}
		return $current_value;
	}
}
