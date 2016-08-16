<?php
/*
This file contains general Javascript formatting functions
*/
namespace winternet\jensenfw2;

class js {
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
		INPUT:
		- $array : associate array where keys are the references that will later be used in Javascript to retrieve the corresponding values
			- for translation system use the translation tag as the key and set to value to '_txt' in order for this function to retrieve the translation itself
		- $options : associative array with any of these flags:
			- 'js_function_name' : use a different name for the Javascript function. Default is 'getphp'
			- 'is_js_context' : set to true when the output is placed directly in a Javascript block (= skip the <script> tags)
		OUTPUT:
		- writes a block of Javascript to the browser
		*/
		$default_options = array(
			'js_function_name' => 'getphp',
			'is_js_context' => false,
		);
		$options = array_merge($default_options, (array) $options);

		if (!is_array($array)) {
			throw new core\system_error('Invalid array to pass on to Javascript.');
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
		$out .= "function ". $options['js_function_name'] ."(ref){\r\n";  //default: function getphp()
		$out .= "var v=". json_encode($a) .";\r\n";
		$out .= "if(typeof ref==\"undefined\"){return v};\r\n";
		$out .= "if(typeof v[ref]==\"undefined\"){alert(\"Configuration error. The reference '\"+ ref +\"' does not exist.\");return\"UNKNOWN_REFERENCE:\"+ref}";
		$out .= "return v[ref];";
		$out .= "}\r\n";
		if (!$options['is_js_context']) {
			$out .= "/* ]]> */\r\n";
			$out .= "</script>";
		}
		return $out;
	}
}
