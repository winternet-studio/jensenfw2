<?php
/**
 * Class related to information that is specific to a country, eg. address formatting, use of state and postal code fields etc.
 *
 * This should match the code in https://github.com/winternet-studio/jensen-js-libs/blob/master/src/CountrySpecifics.js
 *
 * See also: http://stackoverflow.com/questions/13438461/formatting-a-shipping-address-by-country-in-php-or-perl
 */

namespace winternet\jensenfw2;

class country_specifics {
	public static function address_field_order($country) {
		if (in_array($country, ['US', 'CA', 'AU'])) {
			$order = ['city', 'state', 'zip'];
		} elseif (in_array($country, ['GB'])) {
			$order = ['city', 'zip'];
		} else {
			$order = ['zip', 'city', 'state'];
		}
		return $order;
	}

	public static function address_field_labels($country, &$city_label, &$state_label, &$zip_label) {
		if ($country === 'US') {
			$labels = [
				'city' => 'City', '_city' => 'City',
				'state' => 'State', '_state' => 'State',
				'zip' => 'ZIP', '_zip' => 'Zip',
			];
		} elseif ($country === 'CA') {
			$labels = [
				'city' => 'City', '_city' => 'City',
				'state' => 'Province', '_state' => 'Province',
				'zip' => 'Postal Code', '_zip' => 'Postal_Code',
			];
		} elseif ($country === 'AU') {
			$labels = [
				'city' => 'Town / Suburb', '_city' => 'Town_Suburb',
				'state' => 'State / Territory', '_state' => 'State_Territory',
				'zip' => 'Postcode', '_zip' => 'Postcode',
			];
		} elseif ($country === 'GB') {
			$labels = [
				'city' => 'Town / City', '_city' => 'Town_City',
				'state' => '', '_state' => '',
				'zip' => 'Postcode', '_zip' => 'Postcode',
			];
		} else {
			$labels = [
				'city' => 'City', '_city' => 'City',
				'state' => 'State / Province / Region (if required)', '_state' => 'State_Province_Region',
				'zip' => 'Postal Code', '_zip' => 'Postal_Code',
			];
		}

		if ($city_label) $city_label = $labels['city'];
		if ($state_label) $state_label = $labels['state'];
		if ($zip_label) $zip_label = $labels['zip'];

		return $labels;
	}

	/**
	 * Get countries that require state/province in address
	 *
	 * Countries not listed here and not listed in countriesWithoutStateProvince() are countries where using state/province is optional.
	 *
	 * @see http://webmasters.stackexchange.com/questions/3206/what-countries-require-a-state-province-in-the-mailing-address
	 *
	 * @return array
	 */
	public static function countries_requiring_state_province() {
		return ['US','CA','AU','CN','CZ','MX','MY','IT'];  //currently not an exhaustive list
	}

	/**
	 * Get countries that do not use state/province in address at all
	 *
	 * @return array
	 */
	public static function countries_without_state_province() {
		return ['GB','DK','NO','SE'];  //not an exhaustive list at all!
	}

	/**
	 * Get countries that do not use postal codes
	 *
	 * @see https://gist.github.com/bradydan/e172c3f99e211e6e47ad84f08f83dfe3
	 *
	 * @return array
	 */
	public static function countries_without_postalcodes() {
		return ['AO','AG','AW','BS','BZ','BJ','BW','BF','BI','CM','CF','KM','CG','CD','CK','CI','DJ','DM','GQ','ER','FJ','TF','GM','GH','GD','GN','GY','HK','IE','JM','KE','KI','MO','MW','ML','MR','MU','MS','NR','AN','NU','KP','PA','QA','RW','KN','LC','ST','SC','SL','SB','SO','ZA','SR','SY','TZ','TL','TK','TO','TT','TV','UG','AE','VU','YE','ZW'];
	}

	/**
	 * Validate the zip/postal code for at specific country
	 *
	 * @see https://gist.github.com/bradydan/e172c3f99e211e6e47ad84f08f83dfe3
	 *
	 * @param object $options : Available options (opt.):
	 * 	- `reformat` : reformat the value according to the country's format, eg. for Canada "K1G6Z3" or "k1g-6z3" would be converted to "K1G 6Z3"
	 * 		- this flag can also cause less strict validation rules since we can now automatically fix small inconsistencies!
	 * 		- when used the reformatted value is returned if valid and false if returned if value is not valid
	 * 	- `US_allow_zip4` : allow the format #####-#### in United States (http://en.wikipedia.org/wiki/ZIP_code)
	 * @return boolean|string : Normally boolean but if reformat flag is used: reformatted value if valid or false if invalid
	 */
	public static function validate_zip($country, $zip_value, $options = []) {
		$is_valid = false;
		$do_reformat = (@$options['reformat'] ? true : false);

		if ($do_reformat) {
			$zip_value = ($zip_value === null ? '' : trim( (string) $zip_value));
		} else {
			$zip_value = ($zip_value === null ? '' : (string) $zip_value);
		}

		if ($country === 'US') {
			//exactly 5 digits or 5+4 if flag is set
			if (preg_match("/^\\d{5}$/", $zip_value)) {
				$is_valid = true;
			} elseif (@$options['US_allow_zip4'] && preg_match("/^\\d{5}\\-\\d{4}$/", $zip_value)) {
				$is_valid = true;
			}
		} elseif ($country === 'CA') {
			//require format "A1A 1A1", where A is a letter and 1 is a digit and with a space in the middle
			if (preg_match("/^[A-Z]\\d[A-Z][\\.\\- ]?\\d[A-Z]\\d$/i", $zip_value)) {
				$is_valid = true;
				if ($do_reformat) {
					$zip_value = strtoupper(substr($zip_value, 0, 3) .' '. substr($zip_value, -3));
				}
			}
		} elseif ($country === 'GB') {
			//require format specified on http://en.wikipedia.org/wiki/Postcodes_in_the_United_Kingdom#Validation
			if (preg_match("/^([A-Z]{1,2}\\d[A-Z]?|[A-Z]{1,2}\\d{2})[\\.\\- ]?\\d[A-Z][A-Z]$/i", $zip_value)) {
				$is_valid = true;
				if ($do_reformat) {
					$zip_value = preg_replace("/[^A-Z\\d]/i", '', $zip_value);
					$zip_value = strtoupper(substr($zip_value, 0, -3) .' '. substr($zip_value, -3));
				}
			}
		} elseif ($country === 'AU' || $country === 'DK' || $country === 'NO' || $country === 'AT' || $country === 'CH') {
			//exactly 4 digits
			if (preg_match("/^\\d{4}$/", $zip_value)) {
				$is_valid = true;
			}
		} elseif ($country === 'SE' || $country === 'DE' || $country === 'FI' || $country === 'ES' || $country === 'IT' || $country === 'FR') {
			//exactly 5 digits
			if (preg_match("/^\\d{5}$/", $zip_value)) {
				$is_valid = true;
			}
		} elseif ($country === 'NL') {
			//4 digits followed by 2 uppercase letters (http://en.wikipedia.org/wiki/Postal_codes_in_the_Netherlands)
			if ($do_reformat) {
				if (preg_match("/^\\d{4}[ \\-]?[A-Z]{2}$/", $zip_value)) {
					$is_valid = true;
				}
				$zip_value = substr($zip_value, 0, 4) .' '. strtoupper(substr($zip_value, -2));
			} else {
				if (preg_match_all("/^\\d{4} ?[A-Z]{2}$/", $zip_value)) {
					$is_valid = true;
				}
			}
		} elseif ($country === 'BR') {
			//5 digits, a dash, then 3 digits (http://en.wikipedia.org/wiki/List_of_postal_codes_in_Brazil)
			$zip_value = str_replace('.', '', $zip_value);  //some people seem to put a dot after the first two digits
			if (preg_match("/^\\d{5}\\-?\\d{3}$/", $zip_value)) {
				$is_valid = true;
				if ($do_reformat) {
					$zip_value = substr($zip_value, 0, 5) .'-'. substr($zip_value, -3);
				}
			}
		} elseif ($country === 'KR') {
			//3 digits, a dash, then 3 digits
			if ($do_reformat) {
				if (preg_match("/^\\d{3}[^\\d]?\\d{3}$/", $zip_value)) {
					$is_valid = true;
					$zip_value = substr($zip_value, 0, 3) .'-'. substr($zip_value, -3);
				}
			} else {
				if (preg_match("/^\\d{3}\\-?\\d{3}$/", $zip_value)) {
					$is_valid = true;
				}
			}
		} else {
			//for all other countries don't do validation and assume it is valid
			$is_valid = true;
		}
		if ($is_valid && $do_reformat) {
			$is_valid = $zip_value;
		}
		return $is_valid;
	}

	public static function firstname_lastname_order($country) {
		if (in_array($country, ['US', 'CA', 'AU', 'NZ', /*Western Europe from here: */ 'AD', 'AT', 'BE', 'DK', 'FI', 'FR', 'DE', 'GR', 'IS', 'IE', 'IT', 'LI', 'LU', 'MT', 'MC', 'NL', 'NO', 'SM', 'SE', 'CH', 'GB'])) {  // Western Europe: http://en.wikipedia.org/wiki/Western_Europe#Western_European_and_Others_Group
			$order = ['firstname', 'lastname'];
		} else {
			$order = ['lastname', 'firstname'];
		}
		return $order;
	}

	public static function date_field_order($country) {
		if (in_array($country, ['US', 'CA'])) {
			$order = ['month', 'day', 'year'];
		} elseif (in_array($country, ['CN', 'HK', 'TW', 'HU', 'JP', 'KR', 'LT', 'MN'])) {
			$order = ['year', 'month', 'day'];
		} else {
			$order = ['day', 'month', 'year'];
		}
		return $order;
	}

	/**
	 * List of states for a few selected countries
	 *
	 * Used as default if full list is not provided. Full list in Allan Jensen's file `Country states worldwide - my master list.sql`.
	 *
	 * @return object
	 */
	public static function basic_country_states_list() {
		return [
			'AU' => [
				['ACT','Australian Capital Territory'],['NSW','New South Wales'],['NT','Northern Territory'],['QLD','Queensland'],['SA','South Australia'],['TAS','Tasmania'],['VIC','Victoria'],['WA','Western Australia']
			],
			'CA' => [
				['AB','Alberta'],['BC','British Columbia'],['MB','Manitoba'],['NB','New Brunswick'],['NL','Newfoundland and Labrador'],['NT','Northwest Territories'],['NS','Nova Scotia'],['NU','Nunavut'],['ON','Ontario'],['PE','Prince Edward Island'],['QC','Quebec'],['SK','Saskatchewan'],['YT','Yukon Territories']
			],
			'US' => [
				['AL','Alabama'],['AK','Alaska'],['AZ','Arizona'],['AR','Arkansas'],['AP','Armed Forces Pacific'],['CA','California'],['CO','Colorado'],['CT','Connecticut'],['DE','Delaware'],['DC','District of Columbia'],['FL','Florida'],['GA','Georgia'],['GU','Guam'],['HI','Hawaii'],['ID','Idaho'],['IL','Illinois'],['IN','Indiana'],['IA','Iowa'],['KS','Kansas'],['KY','Kentucky'],['LA','Louisiana'],['ME','Maine'],['MD','Maryland'],['MA','Massachusetts'],['MI','Michigan'],['MN','Minnesota'],['MS','Mississippi'],['MO','Missouri'],['MT','Montana'],['NE','Nebraska'],['NV','Nevada'],['NH','New Hampshire'],['NJ','New Jersey'],['NM','New Mexico'],['NY','New York'],['NC','North Carolina'],['ND','North Dakota'],['OH','Ohio'],['OK','Oklahoma'],['OR','Oregon'],['PA','Pennsylvania'],['PR','Puerto Rico'],['RI','Rhode Island'],['SC','South Carolina'],['SD','South Dakota'],['TN','Tennessee'],['TX','Texas'],['VI','US Virgin Islands'],['UT','Utah'],['VT','Vermont'],['VA','Virginia'],['WA','Washington'],['WV','West Virginia'],['WI','Wisconsin'],['WY','Wyoming']
			],
		];
	}

	/**
	 * Return the minimum number of digits in phone number for a given country or country dialing code
	 *
	 * See https://en.wikipedia.org/wiki/National_conventions_for_writing_telephone_numbers
	 *
	 * @param {string} $country - ISO country code (set to null if using $country_code instead)
	 * @param {string} $country_code - Country dialing code (set $country to null)
	 * @return {number}
	 */
	public static function minimum_phone_num_digits($country, $country_code = null) {
		if (is_string($country_code)) $country_code = (int) $country_code;

		if (in_array($country, ['US', 'CA', 'IN', 'CN']) || in_array($country_code, [1, /*ID*/ 91, /*CN*/ 86], true)) {
			return 10;
		} elseif (in_array($country, ['DK', 'NO', 'SE', 'HK']) || in_array($country_code, [45, 47, 46, /*HK*/ 852], true)) {
			return 8;
		} else {
			// rest of the world (Solomon Islands have 5 digit phone numbers)
			return 5;
		}
	}

	/**
	 * Validate a country's phone number
	 *
	 * See https://en.wikipedia.org/wiki/National_conventions_for_writing_telephone_numbers
	 *
	 * @param string $phone_num : Phone number (excluding country dialing code!)
	 * @param string $country : ISO country code (set to null if using $country_code instead)
	 * @param string $country_code : Country dialing code (set $country to null)
	 * @return boolean|integer : Boolean `true` if okay, integer with number of required digits if validation fails
	 */
	public static function validate_phone_num($phone_num, $country, $country_code = null) {
		$minimum_digits = static::minimum_phone_num_digits($country, $country_code);
		if (strlen(preg_replace("/[^\\d]/", '', $phone_num)) < $minimum_digits) {
			return $minimum_digits;
		} else {
			return true;
		}
	}

	/**
	 * Format a phone number according to the country
	 *
	 * See https://en.wikipedia.org/wiki/National_conventions_for_writing_telephone_numbers
	 *
	 * @param string $phone_num : Phone number (excluding country dialing code!)
	 * @param string $country : ISO country code (set to null if using $country_code instead)
	 * @param string $country_code : Country dialing code (set $country to null)
	 * @param array $options : Available options:
	 *   - `DK-format` : set to `4groups` to use "## ## ## ##" instead of the default "#### ####"
	 *   - `US-format` : set to `dotted` to use "###.###.####" or `spaced` to use "### ### ####" instead of the default "###-###-####"
	 * @return string
	 */
	public static function format_phone_num($phone_num, $country, $country_code = null, $options = []) {
		if (is_string($country_code)) $country_code = (int) $country_code;

		if (in_array($country, ['US', 'CA']) || in_array($country_code, [1], true)) {
			if (preg_match("/^(.*\\d.*\\d.*\\d.*\\d.*\\d.*\\d.*\\d.*\\d.*\\d.*\\d)(.*)$/U", (string) $phone_num, $match)) {
				if (@$options['US-format'] === 'dotted') {
					$sep = '.';
				} elseif (@$options['US-format'] === 'spaced') {
					$sep = ' ';
				} else {
					$sep = '-';
				}

				$clean = preg_replace("/[^\\d]/", '', $match[1]);
				return trim(substr($clean, 0, 3) . $sep . substr($clean, 3, 3) . $sep . substr($clean, 6) .' '. trim($match[2]));
			} else {
				return $phone_num;
			}
		} elseif (in_array($country, ['DK', 'NO', 'SE']) || in_array($country_code, [45, 47, 46], true)) {
			if (preg_match("/^(.*\\d.*\\d.*\\d.*\\d.*\\d.*\\d.*\\d.*\\d)(.*)$/U", (string) $phone_num, $match)) {
				$clean = preg_replace("/[^\\d]/", '', $match[1]);
				if (@$options['DK-format'] === '4groups') {
					return trim(substr($clean, 0, 2) .' '. substr($clean, 2, 2) .' '. substr($clean, 4, 2) .' '. substr($clean, 6) .' '. trim($match[2]));
				} else {
					return trim(substr($clean, 0, 4) .' '. substr($clean, 4) .' '. trim($match[2]));
				}
			} else {
				return $phone_num;
			}
		} else {
			return $phone_num;
		}
	}
}
