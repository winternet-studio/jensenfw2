<?php
/*
This file contains general Javascript formatting functions
*/
namespace winternet\jensenfw2;

class js {

	private static $pass_to_js_counter = 0;

	static public function esc($str) {
		/*
		DESCRIPTION:
		- escape a string to be put into Javascript code
		- (full name would have been js_escape_string() )
		INPUT:
		- $string : string to escape
		OUTPUT:
		- string
		*/
		return strtr((string) $str, array("\r" => '\r', "\n" => '\n', "\t" => '\t', "'" => "\\'", '"' => '\"', '\\' => '\\\\'));
	}

	static public function pass_to_js($array, $options = array() ) {
		/*
		DESCRIPTION:
		- function to pass information (mainly translations of text) from PHP to Javascript when it's placed in a separate file
		- call getphp(ref) in Javascript to get the information from PHP, where ref is the key from the array provided to this function
		- changes to the values will be retained
		INPUT:
		- $array : associate array where keys are the references that will later be used in Javascript to retrieve the corresponding values
			- for translation system use the translation tag as the key and set to value to '_txt' in order for this function to retrieve the translation itself
		- $options : associative array with any of these flags:
			- 'js_function_name' : use a different name for the Javascript function. Default is 'getphp'
			- 'is_js_context' : set to true when the output is placed directly in a Javascript block (= skip the <script> tags)
			- 'storage_var_prefix' : name of variable where the information will be stored. A number will be appended to the string.
				- if only a local variable is used it will not be possible to change values, will effectively be read-only
		OUTPUT:
		- writes a block of Javascript to the browser
		*/
		$default_options = array(
			'js_function_name' => 'getphp',
			'is_js_context' => false,
			'storage_var_prefix' => 'window.jfwJsV',
		);
		$options = array_merge($default_options, (array) $options);

		if (!is_array($array)) {
			core::system_error('Invalid array to pass on to Javascript.');
		}
		$a = array();
		foreach ($array as $ref => $value) {
			if ($value === '_txt') {  //ensure boolean true doesn't match
				$a[$ref] = txt($ref, '#');
			} else {
				$a[$ref] = $value;
			}
		}
		if (!$options['is_js_context']) {
			$out  = "<script type=\"text/javascript\">\r\n";
			$out .= "/* <![CDATA[ */\r\n";
		}

		self::$pass_to_js_counter++;
		$varname = $options['storage_var_prefix'] . self::$pass_to_js_counter;

		$out .= "function ". $options['js_function_name'] ."(ref){\r\n";  //default: function getphp()
		$out .= "if(typeof ". $varname ."=='undefined')". $varname ."=". json_encode($a) .";\r\n";
		$out .= "if(typeof ref==\"undefined\"){return ". $varname ."};\r\n";
		$out .= "if(typeof ". $varname ."[ref]==\"undefined\"){alert(\"Configuration error. The reference '\"+ ref +\"' does not exist.\");return\"UNKNOWN_REFERENCE:\"+ref}";
		$out .= "return ". $varname ."[ref];";
		$out .= "}\r\n";
		if (!$options['is_js_context']) {
			$out .= "/* ]]> */\r\n";
			$out .= "</script>";
		}
		return $out;
	}
}
