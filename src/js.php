<?php
/*
This file contains general Javascript formatting functions
*/
namespace winternet\jensenfw2;

class js {

	/**
	 * Escape a string to be put into Javascript code
	 *
	 * (full name would have been js_escape_string() )
	 *
	 * @param string $string : String to escape
	 * @return string
	 */
	static public function esc($string) {
		return strtr((string) $string, ["\r" => '\r', "\n" => '\n', "\t" => '\t', "'" => "\\'", '"' => '\"', '\\' => '\\\\']);
	}

	/**
	 * Function to pass information from PHP to Javascript
	 *
	 * Call `getphp(ref)` or `getphp().ref` in Javascript to get the information from PHP, where ref is the key from the array provided to this function.
	 *
	 * Changes to the values using `getphp().ref = 'newValue'`will be retained.
	 *
	 * @param array $array : Associate array where keys are the references that will later be used in Javascript to retrieve the corresponding values
	 *   - for translation system use the translation tag as the key and set to value to `_txt` in order for this function to retrieve the translation itself
	 * @param array $options : Associative array with any of these flags:
	 *   - `js_function_name` : use a different name for the Javascript function. Default is `getphp`
	 *   - `is_js_context` : set to true when the output is placed directly in a Javascript block (= skip the <script> tags)
	 *   - `storage_var` : specify a fixed name of variable where the information will be stored (otherwise a unique name will be used each time)
	 *     - if only a local variable is used it will not be possible to change values, will effectively be read-only
	 * @return string : HTML - a <script> block - or Javascript only if `is_js_context`=`true`
	 */
	static public function pass_to_js($array, $options = []) {
		// Provide backward compatibility with when the option was called `storage_var_prefix`
		if (isset($options['storage_var_prefix'])) {
			$options['storage_var'] = $options['storage_var_prefix'];
		}

		$default_options = [
			'js_function_name' => 'getphp',
			'is_js_context' => false,
		];
		$options = array_merge($default_options, (array) $options);
		if (!isset($options['storage_var'])) {
			$options['storage_var'] = 'window.jfwJsV'. str_replace('.', '', (string) microtime(true));
		}

		if (!is_array($array)) {
			core::system_error('Invalid array to pass on to Javascript.');
		}
		$a = [];
		foreach ($array as $ref => $value) {
			if ($value === '_txt') {  //ensure boolean true doesn't match
				$a[$ref] = core::txt($ref, '#');
			} else {
				$a[$ref] = $value;
			}
		}
		if (!$options['is_js_context']) {
			$out  = "<script type=\"text/javascript\">\r\n";
			$out .= "/* <![CDATA[ */\r\n";
		}

		$out .= "function ". $options['js_function_name'] ."(ref){\r\n";  //default: function getphp()
		$out .= "if(typeof ". $options['storage_var'] ."==='undefined')". $options['storage_var'] ."=". json_encode($a) .";\r\n";  //only assign the first time we call the JS function (otherwise we can't change values)
		$out .= "if(typeof ref===\"undefined\"){return ". $options['storage_var'] ."};\r\n";
		$out .= "if(typeof ". $options['storage_var'] ."[ref]===\"undefined\"){alert(\"Configuration error. The reference '\"+ ref +\"' does not exist.\");return\"UNKNOWN_REFERENCE:\"+ref}";
		$out .= "return ". $options['storage_var'] ."[ref];";
		$out .= "}\r\n";
		if (!$options['is_js_context']) {
			$out .= "/* ]]> */\r\n";
			$out .= "</script>";
		}
		return $out;
	}
}
