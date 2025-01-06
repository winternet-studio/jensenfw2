<?php
/**
 * Functions related to misc formatting issues
 */

namespace winternet\jensenfw2;

class format {
	/**
	 * Convert HTML to plain text
	 */
	public static function html_to_text($html, $extract_urls = true) {
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

	/**
	 * Convert a two-dimensional array to a basic HTML table
	 *
	 * @param array $array : Array of arrays, or array of objects
	 * @return string : HTML
	 */
	public static function array_to_table($array, $options = []) {
		ob_start();

		if (empty($array)) {
			return '<div class="no-data">No data in table</div>';
		}

		// Convert array of objects to array of arrays
		if (!is_array($array[0])) {
			foreach ($array as $key => $entry) {
				if ($entry instanceof \yii\base\Model) {
					$array[$key] = $entry->toArray();
				} else {
					$array[$key] = json_decode(json_encode($entry));
				}
			}
		}

		if (!is_array(@$options['skip_columns'])) {
			$options['skip_columns'] = [];
		}

?>
		<table class="table <?= @$options['table_classes'] ?>">
		<tr>
<?php
		foreach (array_keys($array[0]) as $name) {
			if (in_array($name, $options['skip_columns'])) continue;

			if (is_callable(@$options['th_callback'])) {
				$name = $options['th_callback']($name);
			}
?>
			<th><?= htmlentities($name) ?></th>
<?php
		}
		if (is_array(@$options['extra_columns'])) {
			foreach ($options['extra_columns'] as $extra_column_name => $extra_column_callback) {
?>
			<th><?= $extra_column_name ?></th>
<?php
			}
		}
?>
		</tr>
<?php
		foreach ($array as $row_index => $row) {
?>
		<tr>
<?php
			foreach ($row as $name => $value) {
				if (in_array($name, $options['skip_columns'])) continue;

				if (is_callable(@$options['td_callback'])) {
					$value = $options['td_callback']($row, $name, $value);
				}
				if (empty($options['skip_html_encoding']) || (is_array(@$options['skip_html_encoding']) && !in_array($name, @$options['skip_html_encoding']))) {
					$value = nl2br(htmlentities($value));
				}
?>
				<td><?= $value ?></td>
<?php
			}
			if (is_array(@$options['extra_columns'])) {
				foreach ($options['extra_columns'] as $extra_column) {
?>
				<td><?= $extra_column($row, $row_index) ?></td>
<?php
				}
			}
?>
		</tr>
<?php
		}
?>
		</table>
<?php
		return ob_get_clean();
	}

	/**
	 * Resolve to using the correct singular or plural form of a noun in a sentence
	 *
	 * Eg. "1 person" or "2 people"
	 *
	 * @param integer $number : Number used to determine singular or plural
	 * @param string $sentence : The sentence where the singular and plural form of the word is written like `((person,people))` and the number like `{number}`
	 *   - example: `((There was,They were)) {number} ((person,people)) in the park, yes, just {number} ((person,people)).`
	 * @return string : Text using the correct form of the noun
	 */
	public static function noun_plural($number, $sentence) {
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
		$sentence = str_replace('{number}', $number, $sentence);
		return $sentence;
	}

	/**
	 * Truncate a sentence to a maximum length
	 *
	 * Will always truncate at spaces so that words are not chopped up.
	 *
	 * @param string $text : Text to truncate
	 * @param integer $len : Maximum length of the string
	 * @param string $el : String to add to the string to show that is has been truncated (default: '...')
	 * @return string
	 */
	public static function truncate($text, $len, $el = '...') {
		if (mb_strlen($text) > $len) {
			$xl = mb_strlen($el);
			if ($len < $xl) {
				return mb_substr($text, 0, $len);
			}
			$text = mb_substr($text, 0, $len-$xl);
			$spc = mb_strrpos($text, ' ');
			if ($spc > 0) {
				$text = mb_substr($text, 0, $spc);
			}
			return $text . $el;
		}
		return $text;
	}

	/**
	 * @param array $options : Available options:
	 *   - `separator` : set another separator than `<br>`
	 *   - `chunks_guarantor` : override the default 20% added to the chunk length to ensure we only end up with the given number of chunks. If words are long we could end up with an extra chunk if this is set too low.
	 */
	public static function split_text_into_chunks($text, $number_of_chunks = 2, $options = []) {
		$options = array_merge(['separator' => '<br>', 'chunks_guarantor' => 20], $options);

		$text_length = mb_strlen($text);
		$chunk_length = $text_length / $number_of_chunks;
		$chunk_length = round($chunk_length + $chunk_length * $options['chunks_guarantor'] / 100);
		if ($text_length <= $chunk_length) {
			return $text;
		} else {
			return wordwrap($text, $chunk_length, $options['separator']);
		}
	}

	/**
	 * Convert all letters to lower case and then capitalizes each word
	 *
	 * - examples:
	 *   - (is_person=true) : the grapes of WratH  ==>  The Grapes Of Wrath
	 *   - (is_person=true) : MARIE-LOU VAN DER PLANCK-ST. JOHN  ==>  Marie-Lou van der Planck-St. John
	 *   - (is_person=false): to be or not to be  ==>  To Be or Not to Be
	 *   - mcdonald o'neil  ==>  McDonald O'neil
	 *
	 * @param string $text : String to correct capitalization in
	 * @param array $options : Associative array with any of these keys:
	 *   - `is_person` : when true a different behaviour is applied which is more appropiate for person names
	 *   - `is_address` : when true a different behaviour is applied which is more appropiate for an address line (implies `fix_ordinals_numbers` as well)
	 *   - `fix_ordinals_numbers` : ensure ordinal numbers like 1st, 2nd, 3rd, 4th keep proper case
	 * @return string
	 */
	public static function strtotitle($text, $options = []) {
		if (@$options['is_address']) {
			$options['fix_ordinals_numbers'] = true;
		}

		// Exceptions to standard case conversion
		if (@$options['is_person']) {
			$all_uppercase = 'Ii|Iii';  //the 2nd, the 3rd
			$all_lowercase = 'De La|De Las|Der|Van De|Van Der|Vit De|Von|Or|And';
		} else {
			//addresses, essay titles ... and anything else
			$all_uppercase = 'Po|Rr|Se|Sw|Ne|Nw';
			if (@$options['is_address']) $all_uppercase .= '|Us|Hc|Pmb';  // abbreviations used in US
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
		$text = mb_convert_case(trim($text), MB_CASE_TITLE);

		// Capitalize acronymns and initialisms e.g. PHP
		if ($all_uppercase) {
			$text = preg_replace_callback("/\\b(". $all_uppercase .")\\b/". core::$preg_u, $cb_strtoupper1, $text);
		}
		// Decapitalize short words e.g. and
		if ($all_lowercase) {
			if (@$is_name /*unsure what this is suppose to mean because this variable has never existed!*/) {
				//all occurences will be changed to lowercase
				$text = preg_replace_callback("/\\b(". $all_lowercase .")\\b/". core::$preg_u, $cb_strtolower1, $text);
			} else {
				//first and last word will not be changed to lower case (i.e. titles)
				$text = preg_replace_callback("/(?<=\\W)(". $all_lowercase .")(?=\\W)/". core::$preg_u, $cb_strtolower1, $text);
			}
		}
		// Capitalize letter after certain name prefixes e.g 'Mc'
		if ($prefixes) {
			$text = preg_replace_callback("/\\b(". $prefixes .")(\\w)/". core::$preg_u, $cb_strtoupper2, $text);
		}
		// Decapitalize certain word suffixes e.g. 's
		if ($suffixes) {
			$text = preg_replace_callback("/(\\w)(". $suffixes .")\\b/". core::$preg_u, $cb_strtolower2, $text);
		}

		if (@$options['fix_ordinals_numbers']) {
			$text = preg_replace_callback("/(\\d(St|Nd|Rd|Th)\\b)/". core::$preg_u, $cb_strtolower1, $text);
		}

		return $text;
	}

	/**
	 * Calculate the percentage of characters that are upper case
	 */
	public static function uc_percentage($text) {
		$str_len = mb_strlen($text);
		if ($str_len >= 1) {
			$upper_chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZÆØÅÉÜÖ';
			$lower_chars = 'abcdefghijklmnopqrstuvwxyzæøåéüö';
			$upper_count = 0;
			$lower_count = 0;
			for ($i = 0; $i < $str_len; $i++) {
				$curr_char = mb_substr($text, $i, 1);
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

	/**
	 * Fix wrong case in a title where all words should start with an upper-case letter
	 *
	 * Explanation of the word "tolerant" used below:
	 * A string that is tolerant has not wrong case and is therefore not converted -OR- how much it allows before it changes the case.
	 *
	 * @param string $text : Text to fix
	 * @param integer $upper_percentage_low  : If the upper-case percentage is between 0 and this number it will be converted to title case (the higher the less tolerant)
	 * @param integer $upper_percentage_high : If the upper-case percentage is between this number and 100 it will be converted to title case (the lower the less tolerant)
	 * @param array $strtotitle_options : Options for strtotitle()
	 * @return string
	 */
	public static function fix_wrong_title_case($text, $upper_percentage_low = 20, $upper_percentage_high = 50, $strtotitle_options = []) {
		$upper_percentage = static::uc_percentage($text);
		if ($upper_percentage >= $upper_percentage_high || $upper_percentage <= $upper_percentage_low) {
			$text = static::strtotitle($text, (array) $strtotitle_options);
		}
		return $text;
	}

	/**
	 * Unicode compatible version of the native PHP function str_pad()
	 *
	 * @see https://stackoverflow.com/a/27194169/2404541
	 */
	public static function mb_str_pad($str, $pad_len, $pad_str = ' ', $dir = STR_PAD_RIGHT, $encoding = NULL) {
		if (PHP_VERSION_ID >= 80300) {
			return \mb_str_pad($str, $pad_len, $pad_str, $dir, $encoding);  //native function introduced in PHP 8.3
		}
		$encoding = $encoding === NULL ? mb_internal_encoding() : $encoding;
		$padBefore = $dir === STR_PAD_BOTH || $dir === STR_PAD_LEFT;
		$padAfter = $dir === STR_PAD_BOTH || $dir === STR_PAD_RIGHT;
		$pad_len -= mb_strlen($str, $encoding);
		$targetLen = $padBefore && $padAfter ? $pad_len / 2 : $pad_len;
		$strToRepeatLen = mb_strlen($pad_str, $encoding);
		$repeatTimes = ceil($targetLen / $strToRepeatLen);
		$repeatedString = str_repeat($pad_str, max(0, $repeatTimes)); // safe if used with valid unicode sequences (any charset)
		$before = $padBefore ? mb_substr($repeatedString, 0, (int)floor($targetLen), $encoding) : '';
		$after = $padAfter ? mb_substr($repeatedString, 0, (int)ceil($targetLen), $encoding) : '';
		return $before . $str . $after;
	}

	/**
	 * Unicode compatible version of the native PHP function ucfirst()
	 */
	public static function mb_ucfirst($string) {
		if (!is_string($string)) {
			return $string;
		}
		return mb_strtoupper(mb_substr($string, 0, 1)) . mb_substr($string, 1);
	}

	/**
	 * Remove multiple spaces from a string
	 *
	 * @param string $text : String to clean up. Variable is passed by reference and is hence modified.
	 * @return void
	 */
	public static function remove_multiple_spaces(&$text) {
		if (is_string($text)) {
			$text = preg_replace("| {2,}|", ' ', $text);
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

	/**
	 * Convert a distance (km, m, miles, feet)
	 *
	 * @param float $distance : Distance to convert
	 * @param string $from : Kilometers (`km`), meters (`m`), miles (`miles`), feet (`feet`)
	 * @param string $to   : Kilometers (`km`), meters (`m`), miles (`miles`), feet (`feet`)
	 */
	public static function convert_distance($distance, $from, $to) {
		$table = [
			'km' => 1,
			'm' => 1000,
			'miles' => 0.6214,
			'feet' => 3280.84,
		];
		if ($from !== 'km') {
			$dist_km = $distance / $table[$from];
		} else {
			$dist_km = $distance;
		}
		if ($to !== 'km') {
			return $dist_km * $table[$to];
		} else {
			return $dist_km;
		}
	}

	/**
	 * Replace special letter accents with normal letters
	 *
	 * Source: http://php.net/manual/en/function.preg-replace.php  (user comment of 2010-03-06)
	 *
	 * @param string $text
	 * @return string
	 */
	public static function replace_accents($text) {
		$a = ['À', 'Á', 'Â', 'Ã', 'Ä', 'Å', 'Æ',  'Ç', 'È', 'É', 'Ê', 'Ë', 'Ì', 'Í', 'Î', 'Ï', 'Ð', 'Ñ', 'Ò', 'Ó', 'Ô', 'Õ', 'Ö', 'Ø', 'Ù', 'Ú', 'Û', 'Ü', 'Ý', 'ß', 'à', 'á', 'â', 'ã', 'ä', 'å', 'æ',  'ç', 'è', 'é', 'ê', 'ë', 'ì', 'í', 'î', 'ï', 'ñ', 'ò', 'ó', 'ô', 'õ', 'ö', 'ø', 'ù', 'ú', 'û', 'ü', 'ý', 'ÿ', 'Ð', '',  '',  '', '', '', '', '', ''];
		$b = ['A', 'A', 'A', 'A', 'A', 'A', 'AE', 'C', 'E', 'E', 'E', 'E', 'I', 'I', 'I', 'I', 'D', 'N', 'O', 'O', 'O', 'O', 'O', 'O', 'U', 'U', 'U', 'U', 'Y', 's', 'a', 'a', 'a', 'a', 'a', 'a', 'ae', 'c', 'e', 'e', 'e', 'e', 'i', 'i', 'i', 'i', 'n', 'o', 'o', 'o', 'o', 'o', 'o', 'u', 'u', 'u', 'u', 'y', 'y', 'D', 'OE', 'oe', 'S', 's', 'Y', 'Z', 'z', 'f'];
		return str_replace($a, $b, $text);
	}

	/**
	 * Clean up a title to be safe to use in a URL / generate slug
	 *
	 * Example:  ` -Lo#&@rem  IPSUM. //Dolor-/sit - amét-\\-consectetür__! 12 -- ` outputs `lorem-ipsum-dolor-sit-amet-consectetur-12`
	 *
	 * Source: http://php.net/manual/en/function.preg-replace.php  (user comment of 2010-03-06)
	 *
	 * @param string $title : string
	 * @param array $options : Available options:
	 *   - `maintain_case` : set true to not convert entire string to lower case
	 * @return string
	 */
	public static function cleanup_title_url_safe($title, $options = []) {
		$output = preg_replace(['/[^a-zA-Z0-9 -]/'. core::$preg_u, '/[ -]+/'. core::$preg_u, '/^-|-$/'. core::$preg_u], ['', '-', ''], static::replace_accents($title));
		if (!@$options['maintain_case']) {
			$output = strtolower($output);
		}
		return $output;
	}

    /**
     * Extract tags, which might be nested, from a string
     *
     * Example: `This is a {leftOrRight,select,left{left} right{right}} page` becomes:
     * ```
	 * [
	 *   "This is a ",
	 *   [
	 *     "leftOrRight",
	 *     "select",
	 *     "left{left} right{right}"
	 *   ],
	 *   " page"
	 * ]
     * ```
     *
	 * Originally copied from Yii2 \yii\i18n\MessageFormatter::tokenizePattern()
	 *
     * @param string $pattern : Pattern to tokenize
     * @param array $options : Available options
     *   - `open` : character designating tag open. Default is `{`
     *   - `close` : character designating tag close. Default is `}`
     *   - `field_separator` : character separating the fields in a tag. Default is `,`. Set to false to not split string into fields.
     *   - `recursive` : set true to parse nested tags as well
     *   - `charset` : character set to use. Default is `UTF-8`
     *
     * @return array|boolean : Array of tokens, or false on failure
     */
	public static function extract_tags($pattern, $options = []) {
		$defaults = [
			'open' => '{',
			'close' => '}',
			'field_separator' => ',',
			'recursive' => false,
			'charset' => 'UTF-8',
		];
		$options = array_merge($defaults, $options);

		$open_length = strlen($options['open']);
		$close_length = strlen($options['close']);

		$depth = 1;
		if (($start = $pos = mb_strpos($pattern, $options['open'], 0, $options['charset'])) === false) {
			return [$pattern];
		}
		$tokens = [mb_substr($pattern, 0, $pos, $options['charset'])];
		while (true) {
			$open = mb_strpos($pattern, $options['open'], $pos + $open_length, $options['charset']);
			$close = mb_strpos($pattern, $options['close'], $pos + $close_length, $options['charset']);
			if ($open === false && $close === false) {
				break;
			}
			if ($open === false) {
				$open = mb_strlen($pattern, $options['charset']);
			}
			if ($close > $open) {
				$depth++;
				$pos = $open;
			} else {
				$depth--;
				$pos = $close;
			}
			if ($depth === 0) {
				$part = mb_substr($pattern, $start + $open_length, $pos - $start - $open_length, $options['charset']);
				if ($options['recursive']) {
					if ($options['field_separator'] !== false) {
						$token = explode($options['field_separator'], $part, 3);
						$token2 = [];
						foreach ($token as $t) {
							$token2[] = static::extract_tags($t, $options);
						}
						$tokens[] = $token2;
					} else {
						$tokens[] = static::extract_tags($part, $options);
					}
				} else {
					if ($options['field_separator'] !== false) {
						$tokens[] = explode($options['field_separator'], $part, 3);
					} else {
						$tokens[] = $part;
					}
				}
				$start = $pos + $close_length;
				$tokens[] = mb_substr($pattern, $start, $open - $start, $options['charset']);
				$start = $open;
			}

			if ($depth !== 0 && ($open === false || $close === false)) {
				break;
			}
		}
		if ($depth !== 0) {
			return false;
		}

		return $tokens;
	}

	/**
	 * Very simple obfuscation of a number
	 *
	 * Is easily reversible with `deobfuscate_number()`. Do NOT use for sensitive data.
	 */
	public static function obfuscate_number($no) {
		if (is_numeric($no) && $no > 0) {
			return $no * 8651 + 17;
		}
	}

	/**
	 * Deobfuscate number encoded by `obfuscate_number()`
	 */
	public static function deobfuscate_number($no) {
		if (is_numeric($no) && $no > 0) {
			return ($no - 17) / 8651;
		}
	}

	/**
	 * URL-safe version of base64_encode()
	 *
	 * Base64 alphabet: http://www.garykessler.net/library/base64.html
	 *
	 * @param string $string : String to encode
	 * @return string : Base64 string safe for URL use
	 */
	public static function base64_encode_url($string) {
		//NOTE: replace slash (/) with dot (.) because otherwise mod_rewrite won't work. Remember to reverse this when parsing
		$base64 = strtr(base64_encode($string), '+/', '-.');
		$base64 = rtrim($base64, '=');  //trim = characters to make the string as short as possible
		return $base64;
	}

	/**
	 * URL-safe version of base64_decode()
	 *
	 * @param string $base64string : Base64 string encoded by `base64_encode_url()`
	 * @return string : Plain text string
	 */
	public static function base64_decode_url($base64string) {
		$data = strtr($base64string, '-.', '+/');
		$mod4 = strlen($data) % 4;
		if ($mod4) {
			$data .= substr('====', $mod4);
		}
		return base64_decode($data);
	}

	/**
	 * Simple conversion of basic variables to YAML, with customizable options
	 *
	 * For more advanced conversion use proper libraries.
	 *
	 * @param {object} options : Available options:
	 *   - `indent` : Custom number of spaces as indentation. Default: 2
	 *   - `enclose_strings` : Set true to enclose string with "" (except if string itself contains a ") so you know it's a string and not a number or boolean
	 */
	public static function to_yaml($variable, $options = [], $level = 0) {
		$indent = $options['indent'] ?? 2; // Number of spaces for YAML indentation
		$spaces = str_repeat(' ', $indent * $level);

		if (is_object($variable)) {
			$variable = (array) $variable;
		}
		if (is_array($variable)) {
			if (core::is_array_assoc($variable)) {
				// Handle associative arrays
				return implode("\n", array_map(function ($key) use ($variable, $options, $spaces, $level) {
					$value = $variable[$key];
					$formattedValue = is_array($value) ? "\n" . static::to_yaml($value, $options, $level + 1) : ' ' . static::to_yaml($value, $options, 0);
					return "{$spaces}{$key}:{$formattedValue}";
				}, array_keys($variable)));
			} else {
				// Handle indexed arrays
				return implode("\n", array_map(function ($item) use ($options, $level, $spaces) {
					return "{$spaces}- " . trim(static::to_yaml($item, $options, $level + 1));
				}, $variable));
			}
		} elseif (is_string($variable)) {
			// Handle strings
			if (!empty($options['enclose_strings']) && strpos($variable, '"') === false) {
				return '"' . $variable . '"';
			} else {
				return $variable;
			}
		} else {
			// Handle other primitive values
			return json_encode($variable);
		}
	}

}
