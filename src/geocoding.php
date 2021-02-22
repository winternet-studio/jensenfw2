<?php
/**
 * Functions related to geocoding
 */

namespace winternet\jensenfw2;

class geocoding {
	public static $google_maps_api_last_req = null;

	public static function class_defaults() {
		$cfg = [];

		$corecfg = core::get_class_defaults('core');
		$cfg['db_server_id'] = '';  //database server ID as defined in core (empty for default)

		return $cfg;
	}

	/**
	 * Get latitude/longitude for an address using Google geolocation API
	 *
	 * (2016-05-25: max 2500 per day, 10 requests per second, but it requies getting an API key)
	 *
	 * - includes caching in database
	 * - API info: https://developers.google.com/maps/documentation/geocoding/#GeocodingRequests
	 *   - alternatives: http://stackoverflow.com/questions/11399069/alternative-to-google-maps-geolocation-api
	 *
	 * @param string|array $location : String with an address, or an array with the following keys:
	 *   - `address`
	 *   - `city`
	 *   - `state`
	 *   - `zip`
	 *   - `country` : name is probably preferred, but 2-letter ISO-3166 codes can also be used
	 * @param array $options : Associative array with any of the following keys:
	 *   - `google_api_key` : Google API key (provide to allow more free requets per day) (do not restrict it to IP addresses in case you want to use this function's proxy_url feature)
	 *   - `skip_database_caching` : set to true to skip caching results in database (not recommended)
	 *   - `db_name` : name of database that should be used for caching. Default is the one currently selected by the database connection.
	 *   - `table_name` : name of database table that should be used for caching. Default is `temp_cached_address_latlon`
	 *   - `skip_table_autocreate` (boolean|0|1) : set to true to skip auto-creating caching table
	 *   - `server_id` : ID of the database connection defined in core config. Default is the primary connection.
	 *   - `proxy_url` : URL with a proxy that will do the actual calls to the Google API. The query string part of the normal URL will be appended to the URL
	 *     - example: `http://myserver.com/googleproxy.php?query=`
	 *     - content of googleproxy.php: `echo file_get_contents('http://maps.googleapis.com/maps/api/geocode/json?'. $_GET['query']);`
	 *
	 * @return mixed : Possible output:
	 * - if found: associative array with keys ´latitude´, ´longitude´
	 * - if not found: false
	 * - if error: `ERROR:[status message from API]`  (string)
	 *   - eg. if query limit reached it will be: `ERROR:OVER_QUERY_LIMIT`
	 * - the raw API response (decoded JSON string) is available through `$GLOBALS['_jfw_google_address_geocoder_raw_response']` whenever the request required a call to the Google API (not using cache)
	 */
	public static function google_address_geocoder($location, $options = []) {
		if (!$options['skip_database_caching']) {
			core::require_database($options['server_id']);
		}

		if (empty($location)) {
			core::system_error('Cannot geocode an empty address.');
		}

		// Handle parameters
		$options = (array) $options;
		$default_options = [
			'google_api_key' => '',
			'server_id' => '',
			'db_name' => '',
			'table_name' => 'temp_cached_address_latlon',
		];
		$options = array_merge($default_options, $options);

		$tableSQL = ($options['db_name'] ? '`'. $options['db_name'] .'`.' : '`'. $options['table_name'] .'`');

		unset($GLOBALS['_jfw_google_address_geocoder_raw_response']);
		unset($GLOBALS['_jfw_google_addr_geocoder_url']);

		// Ensure database table exists
		if (!$options['skip_database_caching'] && !$options['skip_table_autocreate'] && !$_SESSION['_jfw_address_geocoding_cache_table_created']) {  //only run this check once per session
			$createtblSQL = "CREATE TABLE IF NOT EXISTS ". $tableSQL ." (
				`cached_addr_latlonID` INT(3) UNSIGNED NOT NULL AUTO_INCREMENT,
				`geoaddr_address` VARCHAR(255) NOT NULL,
				`geoaddr_latitude` FLOAT NOT NULL COMMENT 'Lat/long will both be -1 if address couldn\'t be geocoded',
				`geoaddr_longitude` FLOAT NOT NULL,
				`geoaddr_loc_type` VARCHAR(20) NULL DEFAULT NULL COMMENT 'Location type according to API',
				`geoaddr_is_partial` TINYINT UNSIGNED NOT NULL DEFAULT '0' COMMENT 'According to API',
				`geoaddr_date_added` DATETIME NOT NULL,
				`geoaddr_last_accessed` DATE NULL COMMENT 'Last time lat/long for this address was pulled from this cache',
				PRIMARY KEY (`cached_addr_latlonID`)
			)";
			$db_createtbl =& core::database_query(($options['server_id'] !== '' ? [$options['server_id'], $createtblSQL, []] : $createtblSQL), 'Database query for checking lat/lon cache failed.');
			$_SESSION['_jfw_address_geocoding_cache_table_created'] = true;
		}

		// Generate API URL
		if (!is_array($location)) {
			$q = 'https://maps.googleapis.com/maps/api/geocode/json?sensor=false&address='. rawurlencode($location);
		} else {
			// Differentiate between US and European/world address formats
			$tmp = mb_strtoupper((string) $location['country']);
			$country_iso3166 = (strlen($tmp) == 2 ? $tmp : false);
			if (in_array($tmp, ['US', 'USA', 'UNITED STATES', 'UNITED STATES OF AMERICA'])) {
				$str_location = $location['address'] .', '. $location['city'] . ($location['state'] ? ', '. $location['state'] : '') .' '. $location['zip'] .', United States';
			} elseif (in_array($tmp, ['NZ', 'NEW ZEALAND'])) {
				$str_location = $location['address'] .', '. $location['city'] . ($location['state'] ? ', '. $location['state'] : '') .' '. $location['zip'] .', New Zealand';
			} elseif (in_array($tmp, ['CA', 'CANADA'])) {
				$str_location = $location['address'] .', '. $location['city'] . ($location['state'] ? ', '. $location['state'] : '') .' '. $location['zip'] .', Canada';
			} else {
				// Rest of the world
				$str_location = $location['address'] .', '. $location['zip'] .' '. $location['city'] . ($location['state'] ? ', '. $location['state'] : '') .', '. $location['country'];
			}
			$q = 'https://maps.googleapis.com/maps/api/geocode/json?sensor=false&address='. rawurlencode($str_location);
			$components = [];
			if ($location['country']) {
				$components[] = 'country:'. urlencode($location['country']);
			}
			if ($location['zip']) {
				$components[] = 'postal_code:'. urlencode($location['zip']);
			}
			if (!empty($components)) {
				$q .= '&component='. implode('|', $components);
			}

			$location = $str_location;
		}

		if (!$options['skip_database_caching']) {
			// Register result in database
			$checkcacheSQL = "SELECT cached_addr_latlonID, geoaddr_latitude, geoaddr_longitude FROM ". $tableSQL ." WHERE geoaddr_address = '". core::sql_esc($location) ."'";
			$db_checkcache =& core::database_query(($options['server_id'] !== '' ? [$options['server_id'], $checkcacheSQL, []] : $checkcacheSQL), 'Database query for checking lat/lon cache failed.');
		}
		if (!$options['skip_database_caching'] && mysqli_num_rows($db_checkcache) > 0) {
			$checkcache = mysqli_fetch_assoc($db_checkcache);

			// Register that it was retrieved (so that we have an idea of it this address is no longer relevant and we can purge it from the cache)
			$regSQL = "UPDATE ". $tableSQL ." SET geoaddr_last_accessed = NOW() WHERE cached_addr_latlonID = ". $checkcache['cached_addr_latlonID'];
			core::database_query(($options['server_id'] !== '' ? [$options['server_id'], $regSQL, []] : $regSQL), 'Database query for registering latitude and longitude retrieved failed.');

			if ($checkcache['geoaddr_latitude'] !== -1 || $checkcache['geoaddr_latitude'] !== -1) {
				return [
					'latitude' => $checkcache['geoaddr_latitude'],
					'longitude' => $checkcache['geoaddr_longitude'],
					'source' => 'cache',
				];
			} else {
				return false;
			}
		} else {
			// Handle throttling
			$min_time_between = 0.5;  //seconds
			$now = microtime(true);
			if (static::$google_maps_api_last_req) {
				$diff = $now - static::$google_maps_api_last_req;
				if ($diff < $min_time_between) {  //sleep a little bit if requests are too close together (Google is throttling the usage)
					if ($GLOBALS['cli']) {
						echo ' PAUSE-'. ($min_time_between - $diff) .'  ';
					}
					usleep($min_time_between - $diff);
				}
			}
			static::$google_maps_api_last_req = microtime(true);

			if ($options['google_api_key']) {
				$q .= '&key='. $options['google_api_key'];
			}
			$GLOBALS['_jfw_google_addr_geocoder_url'] = $q;
			if ($options['proxy_url']) {
				$urlquery = parse_url($q, PHP_URL_QUERY);
				$tmp = file_get_contents($options['proxy_url'] . urlencode($urlquery));
			} else {
				$tmp = file_get_contents($q);
			}

			$json = json_decode($tmp, true);
			$GLOBALS['_jfw_google_address_geocoder_raw_response'] = $json;

			if ($json['status'] == 'OK' || $json['status'] == 'ZERO_RESULTS') {
				if ($json['results'][0]['geometry']['location']['lat'] && $json['results'][0]['geometry']['location']['lng']) {
					if (!$options['skip_database_caching']) {
						$addtocacheSQL = "INSERT INTO ". $tableSQL ." SET geoaddr_address = '". core::sql_esc($location) ."', geoaddr_latitude = '". core::sql_esc($json['results'][0]['geometry']['location']['lat']) ."', geoaddr_longitude = '". core::sql_esc($json['results'][0]['geometry']['location']['lng']) ."', geoaddr_date_added = NOW()";
						if ($json['results'][0]['partial_match']) {
							$addtocacheSQL .= ", geoaddr_is_partial = 1";
						}
						if ($json['results'][0]['geometry']['location_type']) {
							$addtocacheSQL .= ", geoaddr_loc_type = '". core::sql_esc(mb_substr($json['results'][0]['geometry']['location_type'], 0, 20)) ."'";
						}
					}
					$return = [
						'latitude' => $json['results'][0]['geometry']['location']['lat'],
						'longitude' => $json['results'][0]['geometry']['location']['lng'],
						'source' => 'google_api',
					];
				} else {
					if (!$options['skip_database_caching']) {
						// Also register those that could not be geocoded so that we don't waste time looking them up again
						$addtocacheSQL = "INSERT INTO ". $tableSQL ." SET geoaddr_address = '". core::sql_esc($location) ."', geoaddr_latitude = -1, geoaddr_longitude = -1, geoaddr_date_added = NOW()";
					}
					$return = false;
				}
				if (!$options['skip_database_caching']) {
					$db_addtocache =& core::database_query(($options['server_id'] !== '' ? [$options['server_id'], $addtocacheSQL, []] : $addtocacheSQL), 'Database update for caching lat/lon for an address failed.');
				}
				return $return;
			} else {
				return 'ERROR:'. $json['status'] .' - '. $json['error_message'];
			}
		}
	}

	/**
	 * Lookup country from IP address via MaxMind GeoIP2 Precision web service
	 *
	 * IMPORTANT! Remember to exclude robot from accessing the page calling this, otherwise your credits might be depleted faster than desired (instructions: http://en.wikipedia.org/wiki/Robots_exclusion_standard)
	 *
	 * @param string $ip
	 * @param string $userID
	 * @param string $licensekey
	 * @param array $options : Associative with any combination of the following keys:
	 *   - `skip_database` (boolean|0|1) : set to true to skip caching in database
	 *   - `max_cache_age` : max number of days to use a cached result
	 *   - `clean_cache` (boolean|0|1) : set to true to remove outdated cache entries
	 *   - `db_table` (string) : name of table for database caching
	 *   - `skip_table_autocreate` (boolean|0|1) : set to true to skip auto-creating caching table
	 * @return array : Associative array
	 */
	public static function ip2country_maxmind_service($ip, $userID, $licensekey, $options = []) {
		$skip_database = ($options['skip_database'] ? true : false);

		$continent_code = $country_iso = $err_msg = $used_cache = $data = false;

		if (inet_pton($ip) === false) {
			$err_msg = 'IP address is invalid.';
		}

		if (!$skip_database && !$err_msg) {
			$cfg = core::get_class_defaults(__CLASS__);

			core::require_database($cfg['db_server_id']);

			if (is_numeric($options['max_cache_age'])) {
				$max_age = $options['max_cache_age'];  //days
			} else {
				$max_age = 3;  //days
			}

			$tablename = ($options['db_table'] ? $options['db_table'] : 'temp_cached_maxmind_ip2country');

			// Ensure database table exists
			if (!$options['skip_table_autocreate'] && !$_SESSION['_jfw_maxmindip2country_cache_table_created']) {  //only run this check once per session
				$createtblSQL = "CREATE TABLE IF NOT EXISTS `". $tablename ."` (
					`maxmipctry_ip` VARCHAR(40) NOT NULL,
					`maxmipctry_country_iso` CHAR(2) NOT NULL,
					`maxmipctry_continent` CHAR(2) NOT NULL,
					`maxmipctry_date_added` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
					PRIMARY KEY (`maxmipctry_ip`)
				)
				COMMENT='Table caching the resolved country from IP done via MaxMind web service'
				COLLATE='utf8_general_ci'";
				$db_createtbl =& core::database_query($createtblSQL, 'Database query for checking MaxMind IP2country cache table failed.');
				$_SESSION['_jfw_maxmindip2country_cache_table_created'] = true;
			}

			//NOTE: no automatic clean-up of old cache entries is currently being done
			if ($options['clean_cache']) {
				$sql = "DELETE FROM ". $tablename ." WHERE maxmipctry_date_added < DATE_SUB(NOW(), INTERVAL ". ($max_age+2) ." DAY)";
				core::database_result($sql, false, 'Database query for cleaning cached IP2country results failed.');
			}

			$sql = "SELECT * FROM ". $tablename ." WHERE maxmipctry_ip = '". core::sql_esc($ip) ."' AND maxmipctry_date_added >= DATE_SUB(NOW(), INTERVAL ". $max_age ." DAY)";
			$cached = core::database_result($sql, 'onerow', 'Database query for checked cached IP2country result failed.');
			if (!empty($cached)) {
				$country_iso = $cached['maxmipctry_country_iso'];
				$continent_code = $cached['maxmipctry_continent'];
				$used_cache = true;
			}
		}

		if (!$country_iso && !$err_msg) {
			$ch = curl_init();
			curl_setopt($ch, CURLOPT_URL, 'https://geoip.maxmind.com/geoip/v2.1/country/'. $ip);
			curl_setopt($ch, CURLOPT_HTTPHEADER, ['Authorization: Basic '. base64_encode($userID .':'. $licensekey)]);
			curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);  //without this I get SSL3_GET_SERVER_CERTIFICATE:certificate verify failed (and setting neither CURLOPT_SSL_CIPHER_LIST => 'TLSv1' or CURLOPT_SSLVERSION worked)
			curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
			$response = curl_exec($ch);

			if ($response === false) {
				core::notify_webmaster('developer', 'MaxMind IP->Country query failed', 'cURL error: '. curl_error($ch), session_id() );
				$err_msg = 'Failed to query web service.';
			} else {
				$data = json_decode($response, true);
				if ($data === null) {
					$err_msg = 'Failed to parse JSON.';
				} elseif (!$data['country']['iso_code']) {
					$err_msg = 'Failed to find country code.';
				} else {
					$country_iso = $data['country']['iso_code'];
					$continent_code = $data['continent']['code'];
				}

				if ($data['maxmind']['queries_remaining'] && $data['maxmind']['queries_remaining'] < 1000) {
					core::notify_webmaster('developer', 'MaxMind IP->Country queries remaining: '. $data['maxmind']['queries_remaining'], 'Time to purchase more MaxMind web service credits for userID '. $userID .' at https://www.maxmind.com/en/geoip2-precision-country', session_id() );
				}
			}

			curl_close($ch);

			if (!$skip_database && $country_iso) {
				$sql = "REPLACE INTO ". $tablename ." SET maxmipctry_ip = '". core::sql_esc($ip) ."', maxmipctry_country_iso = '". core::sql_esc($country_iso) ."', maxmipctry_continent = '". core::sql_esc($continent_code) ."'";
				core::database_result($sql, false, 'Database query for caching MaxMind IP2country result failed.');
			}
		}

		if ($err_msg) {
			return [
				'status' => 'error',
				'msg' => $err_msg,
			];
		} else {
			return [
				'status' => 'ok',
				'country_iso3166' => strtoupper($country_iso),
				'continent_code' => $continent_code,
				'used_cache' => $used_cache,
				'rawdata' => $data,
			];
		}
	}

	/**
	 * Lookup country from IP address via MaxMind GeoLite2 free downloadable country database
	 *
	 * @param string $ip
	 * @param string $country_database_file : Path to the MaxMind GeoLite2 country database file
	 * @param array $options : Associative array with any combination of the following keys:
	 *   - `composer_autoload_path` : override the default path to the Composer autoload.php file
	 * @return array : Associative array
	 */
	public static function ip2country_maxmind_free($ip, $country_database_file, $options = []) {
		$continent_code = $continent_name = $country_iso = $country_name = $err_msg = null;

		if (inet_pton($ip) === false) {
			$err_msg = 'IP address is invalid.';
		}

		if (!$country_iso && !$err_msg) {
			$reader = new \GeoIp2\Database\Reader($country_database_file);

			try {
				$record = $reader->country($ip);

				$continent_code = $record->continent->code;
				$continent_name = $record->continent->names['en'];
				$country_iso = $record->country->isoCode;
				$country_name = $record->country->name;
			} catch (Exception $e) {
				$err_msg = $e->getMessage();
			}
		}

		if ($err_msg) {
			return [
				'status' => 'error',
				'msg' => $err_msg,
			];
		} else {
			return [
				'status' => 'ok',
				'country_iso3166' => $country_iso,
				'country_name' => $country_name,
				'continent_code' => $continent_code,
				'continent_name' => $continent_name,
				'rawdata' => $record,
			];
		}
	}

	/**
	 * Lookup city, region, country, etc from IP address via MaxMind GeoLite2 free downloadable city database
	 *
	 * @param string $ip
	 * @param string $city_database_file : Path to the MaxMind GeoLite2 city database file
	 * @param array $options : Associative array with any combination of the following keys:
	 *   - `composer_autoload_path` : override the default path to the Composer autoload.php file
	 * @return array : Associative array
	 */
	public static function ip2city_maxmind_free($ip, $city_database_file, $options = []) {
		$continent_code = $continent_name = $country_iso = $country_name = $state_or_region_code = $state_or_region_name = $city = $city_geoname_id = $postalcode = $timezone = $latitude = $longitude = $err_msg = null;

		if (inet_pton($ip) === false) {
			$err_msg = 'IP address is invalid.';
		}

		if (!$country_iso && !$err_msg) {
			$reader = new \GeoIp2\Database\Reader($city_database_file);

			try {
				$record = $reader->city($ip);

				$continent_code = $record->continent->code;
				$continent_name = $record->continent->names['en'];
				$country_iso = $record->country->isoCode;
				$country_name = $record->country->name;
				$state_or_region_code = $record->subdivisions[0]->isoCode;
				$state_or_region_name = $record->subdivisions[0]->names['en'];
				$city = $record->city->names['en'];
				$city_geoname_id = $record->city->geonameId;
				$postalcode = $record->postal->code;
				$timezone = $record->location->timeZone;
				$latitude = $record->location->latitude;
				$longitude = $record->location->longitude;
			} catch (Exception $e) {
				$err_msg = $e->getMessage();
			}
		}

		if ($err_msg) {
			return [
				'status' => 'error',
				'msg' => $err_msg,
			];
		} else {
			return [
				'status' => 'ok',
				'country_iso3166' => $country_iso,
				'country_name' => $country_name,
				'continent_code' => $continent_code,
				'continent_name' => $continent_name,
				'state_or_region_code' => $state_or_region_code,
				'state_or_region_name' => $state_or_region_name,
				'city' => $city,
				'city_geoname_id' => $city_geoname_id,
				'postalcode' => $postalcode,
				'timezone' => $timezone,
				'latitude' => $latitude,
				'longitude' => $longitude,
				'rawdata' => $record,
			];
		}
	}
}
