<?php
/*
This file contains functions related to misc formatting issues
*/
namespace winternet\jensenfw2;

class format {
	public static function html_to_text($html, $extract_urls = true) {
		/*
		DESCRIPTION:
		- convert HTML to plain text
		*/
		$text = $html;
		$text = html_entity_decode($text);  //reverse HTML entities
		$text = str_replace("\r", '', $text);  //first remove all newlines (source is text and therefore newlines are not considered)
		$text = str_replace("\n", '', $text);
		$text = str_replace('<br/>', "\r\n", $text);  //then convert the real newlines (<br/> tags) to actual newlines
		$text = str_replace('<p'  , "\r\n\r\n<p", $text);
		if ($extract_urls) {  //could be improved to only extract it when it's not already in the visible text
			$search = "|(href=\"(.*)\".*>)(.*)</a>|siU". core::$preg_u;
			$replace = "\\1\\3 (\\2)";
			$text = preg_replace($search, $replace, $text);
			$text = str_replace('mailto:', '', $text);
		}
		$text = strip_tags($text);  //strip all other tags
		$text = str_replace("\r\n\r\n\r\n\r\n", "\r\n\r\n", $text);  //remove exceeding amounts of newlines
		$text = trim($text);
		if (0) {  //for debugging
			$text = str_replace("\r", '\r', $text);
			$text = str_replace("\n", '\n', $text);
		}
		return $text;
	}

	public static function noun_plural($number, $sentence) {
		/*
		DESCRIPTION:
		- resolve to using the correct singular or plural form of a noun in a sentence
		- eg. "1 person" or "2 people"
		INPUT:
		- $number : number used to determine singular or plural
		- $sentence : string with the sentence where the singular and plural form of the word is written like this: ((person,people))
			- example: ((There was,They were)) 2 ((person,people)) in the park, yes, just 2 ((person,people)).
		OUTPUT:
		- string with text using the correct form of the noun
		*/
		if (preg_match_all("|(\\(\\(.*\\)\\))|U", $sentence, $matches, PREG_SET_ORDER)) {
			foreach ($matches as $match) {
				list($singular, $plural) = explode(',', substr($match[1], 2, -2), 2);
				if ($number == 1) {
					$sentence = str_replace($match[1], trim($singular), $sentence);
				} else {  // 0 or more than 1
					$sentence = str_replace($match[1], trim($plural), $sentence);
				}
			}
		}
		return $sentence;
	}

	public static function truncate($str, $len, $el = '...') {
		/*
		DESCRIPTION:
		- truncate a sentence to a maximum length
		- will always truncate at spaces so that words are not chopped up
		INPUT:
		- $str : string to truncate
		- $len : maximum length of the string
		- $el : string to add to the string to show that is has been truncated (default: '...')
		*/
		if (mb_strlen($str) > $len) {
			$xl = mb_strlen($el);
			if ($len < $xl) {
				return mb_substr($str, 0, $len);
			}
			$str = mb_substr($str, 0, $len-$xl);
			$spc = mb_strrpos($str, ' ');
			if ($spc > 0) {
				$str = mb_substr($str, 0, $spc);
			}
			return $str . $el;
		}
		return $str;
	}

	public static function strtotitle($str, $options = array() ) {
		/*
		DESCRIPTION:
		- this function converts all letters to lower case and then capitalizes each word
		- examples:
			- (is_person=true) : the grapes of WratH  ==>  The Grapes Of Wrath
			- (is_person=true) : MARIE-LOU VAN DER PLANCK-ST.JOHN  ==>  Marie-Lou van der Planc-St.John
			- (is_person=false): to be or not to be  ==>  To Be or Not to Be
			- mcdonald o'neil  ==>  McDonald O'Neil
		INPUT:
		- $str : string to correct capitalization in
		- $options : associative array with any of these keys:
			- 'is_person' : when true a different behaviour is applied which is more appropiate for person names
			- 'is_address' : when true a different behaviour is applied which is more appropiate for an address line (implies 'fix_ordinals_numbers' as well)
			- 'fix_ordinals_numbers' : ensure ordinal numbers like 1st, 2nd, 3rd, 4th keep proper case
		OUTPUT:
		- string
		*/
		if ($options['is_address']) {
			$options['fix_ordinals_numbers'] = true;
		}

		// Exceptions to standard case conversion
		if ($options['is_person']) {
			$all_uppercase = 'Ii|Iii';  //the 2nd, the 3rd
			$all_lowercase = 'De La|De Las|Der|Van De|Van Der|Vit De|Von|Or|And';
		} else {
			//addresses, essay titles ... and anything else
			$all_uppercase = 'Po|Rr|Se|Sw|Ne|Nw';
			if ($options['is_address']) $all_uppercase .= '|Us|Hc|Pmb';  // abbreviations used in US
			$all_lowercase = 'A|And|As|By|In|Of|Or|To';
		}
		$prefixes = 'Mc';  //separate with |
		$suffixes = "'S";  //separate with |

		$cb_strtoupper1 = function($matches) {
			return mb_strtoupper($matches[1]);
		};
		$cb_strtolower1 = function($matches) {
			return mb_strtolower($matches[1]);
		};
		$cb_strtoupper2 = function($matches) {
			return $matches[1] . mb_strtoupper($matches[2]);
		};
		$cb_strtolower2 = function($matches) {
			return $matches[1] . mb_strtolower($matches[2]);
		};

		// Captialize all first letters
		$str = mb_convert_case(trim($str), MB_CASE_TITLE);

		// Capitalize acronymns and initialisms e.g. PHP
		if ($all_uppercase) {
			$str = preg_replace_callback("/\\b(". $all_uppercase .")\\b/". core::$preg_u, $cb_strtoupper1, $str);
		}
		// Decapitalize short words e.g. and
		if ($all_lowercase) {
			if ($is_name) {
				//all occurences will be changed to lowercase
				$str = preg_replace_callback("/\\b(". $all_lowercase .")\\b/". core::$preg_u, $cb_strtolower1, $str);
			} else {
				//first and last word will not be changed to lower case (i.e. titles)
				$str = preg_replace_callback("/(?<=\\W)(". $all_lowercase .")(?=\\W)/". core::$preg_u, $cb_strtolower1, $str);
			}
		}
		// Capitalize letter after certain name prefixes e.g 'Mc'
		if ($prefixes) {
			$str = preg_replace_callback("/\\b(". $prefixes .")(\\w)/". core::$preg_u, $cb_strtoupper2, $str);
		}
		// Decapitalize certain word suffixes e.g. 's
		if ($suffixes) {
			$str = preg_replace_callback("/(\\w)(". $suffixes .")\\b/". core::$preg_u, $cb_strtolower2, $str);
		}

		if ($options['fix_ordinals_numbers']) {
			$str = preg_replace_callback("/(\\d(St|Nd|Rd|Th)\\b)/". core::$preg_u, $cb_strtolower1, $str);
		}

		return $str;
	}

	public static function uc_percentage($string) {
		/*
		DESCRIPTION:
		- this function return the percentage of characters that are upper-case
		*/
		$str_len = mb_strlen($string);
		if ($str_len >= 1) {
			$upper_chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZÆØÅÉÜÖ';
			$lower_chars = 'abcdefghijklmnopqrstuvwxyzæøåéüö';
			$upper_count = 0;
			$lower_count = 0;
			for ($i = 0; $i < $str_len; $i++) {
				$curr_char = mb_substr($string, $i, 1);
				if (mb_strpos($upper_chars, $curr_char) !== false) {  //char was found in the upper characters
					$upper_count++;
				} elseif (mb_strpos($lower_chars, $curr_char) !== false) {  //char was found in the lower characters
					$lower_count++;
				}
			}
			$total_counts = $upper_count + $lower_count;
			if ($total_counts > 0) {
				$percentage = $upper_count / $total_counts * 100;
			} else {
				$percentage = 0;
			}
			return $percentage;
		} else {
			return 0;
		}
	}

	public static function fix_wrong_title_case($string, $upper_percentage_low = 20, $upper_percentage_high = 50, $strtotitle_options = array() ) {
		/*
		DESCRIPTION:
		- this function uses several functions to fix wrong case in a title where all words should start with an upper-case letter
		- the word "tolerant" used below: a string that is tolerant has not wrong case and is therefore not converted -OR- how much it allows before it changes the case
		INPUT:
		- $string : string with text to fix
		- $upper_percentage_low  : if the upper-case percentage is between 0 and this number it will be converted to title case (the higher the less tolerant)
		- $upper_percentage_high : if the upper-case percentage is between this number and 100 it will be converted to title case (the lower the less tolerant)
		- $strtotitle_options (array) : array with options for strtotitle()
		OUTPUT:
		- string
		*/
		$upper_percentage = self::uc_percentage($string);
		if ($upper_percentage >= $upper_percentage_high || $upper_percentage <= $upper_percentage_low) {
			$string = self::strtotitle($string, (array) $strtotitle_options);
		}
		return $string;
	}

	public static function remove_multiple_spaces(&$string) {
		/*
		DESCRIPTION:
		- remove multiple spaces from a string
		INPUT:
		- $string
		OUTPUT:
		- nothing, the passed argument is modified
		*/
		if (is_string($string)) {
			$string = preg_replace("| {2,}|", ' ', $string);
		}
	}

	/**
	 * Make a string with possible spaces, dots and hyphens copy without those spacing characters
	 * while retaining the visual separation of between digit groups
	 *
	 * Eg. "1020.31.02846" will be pasted as "10203102846" (and shown as "1020 31 02846"), and "NO02 1020 3102 846" will be pasted as "NO0210203102846"
	 *
	 * @param string $number_string : String with number or alphanumeric characters. May not contain HTML (or at least not with spaces, dots, and hyphens).
	 * @return string : HTML
	 */
	public static function enable_copy_without_spaces($number_string) {
		if (preg_match("/[\\s\\.\\-]/", $number_string)) {
			$number_string = preg_replace("/[\\s\\.\\-]/", ' ', $number_string);
		}

		$number_string = str_replace(' ', '<span style="display:inline-block;width:6px"></span>', $number_string);

		return $number_string;
	}

	public static function convert_distance($dist, $from, $to) {
		/*
		DESCRIPTION:
		- convert a distance (km, m, miles)
		INPUT:
		- $from : kilometers ('km'), meters ('m'), miles ('miles')
		- $to   : kilometers ('km'), meters ('m'), miles ('miles')
		*/
		$table = array(
			'km' => 1,
			'm' => 1000,
			'miles' => 0.6214,
		);
		if ($from != 'km') {
			$dist_km = $dist / $table[$from];
		} else {
			$dist_to = $dist;
		}
		if ($to != 'km') {
			$dist_to = $dist * $table[$to];
		}
		return $dist_to;
	}

	public static function replace_accents($str) {
		/*
		DESCRIPTION:
		- replace special letter accents with normal letters
		- source: http://php.net/manual/en/function.preg-replace.php  (user comment of 2010-03-06)
		INPUT:
		- $str : string
		OUTPUT:
		- string
		*/
		$a = array('À', 'Á', 'Â', 'Ã', 'Ä', 'Å', 'Æ',  'Ç', 'È', 'É', 'Ê', 'Ë', 'Ì', 'Í', 'Î', 'Ï', 'Ð', 'Ñ', 'Ò', 'Ó', 'Ô', 'Õ', 'Ö', 'Ø', 'Ù', 'Ú', 'Û', 'Ü', 'Ý', 'ß', 'à', 'á', 'â', 'ã', 'ä', 'å', 'æ',  'ç', 'è', 'é', 'ê', 'ë', 'ì', 'í', 'î', 'ï', 'ñ', 'ò', 'ó', 'ô', 'õ', 'ö', 'ø', 'ù', 'ú', 'û', 'ü', 'ý', 'ÿ', 'Ð', '',  '',  '', '', '', '', '', '');
		$b = array('A', 'A', 'A', 'A', 'A', 'A', 'AE', 'C', 'E', 'E', 'E', 'E', 'I', 'I', 'I', 'I', 'D', 'N', 'O', 'O', 'O', 'O', 'O', 'O', 'U', 'U', 'U', 'U', 'Y', 's', 'a', 'a', 'a', 'a', 'a', 'a', 'ae', 'c', 'e', 'e', 'e', 'e', 'i', 'i', 'i', 'i', 'n', 'o', 'o', 'o', 'o', 'o', 'o', 'u', 'u', 'u', 'u', 'y', 'y', 'D', 'OE', 'oe', 'S', 's', 'Y', 'Z', 'z', 'f');
		return str_replace($a, $b, $str); 
	}

	public static function cleanup_title_url_safe($str, $flags = '') {
		/*
		DESCRIPTION:
		- clean up a title to be safe to use in a URL
		- example: $str = ' -Lo#&@rem  IPSUM. //Dolor-/sit - amét-\\-consectetür__! 12 -- ' outputs 'lorem-ipsum-dolor-sit-amet-consectetur-12'
		- source: http://php.net/manual/en/function.preg-replace.php  (user comment of 2010-03-06)
		INPUT:
		- $str : string
		- $flags : string with any combination of these options:
			- 'maintain_case' : do not convert entire string to lower case
		OUTPUT:
		- string
		*/
		$out = preg_replace(array('/[^a-zA-Z0-9 -]/'. core::$preg_u, '/[ -]+/'. core::$preg_u, '/^-|-$/'. core::$preg_u), array('', '-', ''), self::replace_accents($str));
		if (strpos($flags, 'maintain_case') === false) {
			$out = strtolower($out);
		}
		return $out; 
	}
}
