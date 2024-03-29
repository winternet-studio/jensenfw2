<?php
/**
 * Functions related to dumping the content of variables for debugging
 *
 * We have a more fancy (= prettier) method of dumping variables in the dump class.
 */

namespace winternet\jensenfw2;

class debug {

	public static $visualize_chars = false;  //set true or 1 to visualize basic characters, set 2 (NOT YET IMPLEMENTED) to visualize all non-normal characters

	public static $hide_all_prop_prefixes = false;

	public static $prefix_public_props = false;

	public static $table_border_color = '#e6c200';

	/**
	 * Dump any variable to the screen
	 *
	 * - can have unlimited number of arguments which will each be dumped with this function
	 */
	public static function dump($variable) {
		/*
		TODO: somehow enable use of Krumo in this new context of a static class
		// Use krumo if available
		if (0&&file_exists('includes/krumo/class.krumo.php')) {
			// Documentation: https://github.com/oodle/krumo
			require_once('includes/krumo/class.krumo.php');
			if (strpos((string) $flags, '0') !== false) {
				krumo($variable);
			} else {
				krumo($variable, KRUMO_EXPAND_ALL);
			}
			return;
		}
		*/

		if (PHP_SAPI == 'cli') {
			echo "\033[93m-----------------------------------------------------------------------------------------------\033[0m". PHP_EOL;
			var_dump($input);
			echo "\033[93m-----------------------------------------------------------------------------------------------\033[0m". PHP_EOL;
			return;
		}

		if (is_array($variable)) {
			$out = static::array_print($variable);
		} elseif (is_object($variable)) {
			$out = static::object_print($variable);
		} else {
			ob_start();
			echo '<pre>';
			if (static::$visualize_chars) {
				var_dump(static::visualize_characters($variable));
			} else {
				var_dump($variable);
			}
			echo '</pre>';
			$out = ob_get_clean();
		}
		$bcktr = debug_backtrace();
		$out = '<table border="0" cellpadding="0" cellspacing="0"><tr><td><div style="padding: 3px; margin: 5px; color: darkred; border: solid darkgoldenrod 1px; background-color: gold">'. ($bcktr[1]['function'] != 'dump_table' ? '<div align="right"><small><b>VARIABLE DUMP<br/>'. basename($bcktr[0]['file']) .' -- line '. $bcktr[0]['line'] .'<br/>&nbsp;</b></small></div>' : '') . $out . '</div></td></tr></table>';
		echo $out;
		// Check if multiple arguments and dump all
		$arguments_count = func_num_args();
		if ($arguments_count > 1) {
			for ($i = 1; $i < $arguments_count; $i++) {
				$curr_arg = func_get_arg($i);
				static::dump($curr_arg);
			}
		}
	}

	/**
	 * Dumps a two-dimension array to a table
	 *
	 * @param array $arr : Array with at least two dimenstions
	 * @return void : Writes HTML to screen
	 */
	public static function dump_table(&$arr) {
		$bcktr = debug_backtrace();
		echo '<div style="padding: 3px; margin: 5px; color: darkred; border: solid darkgoldenrod 1px; background-color: gold"><div align="left"><small><b>VARIABLE DUMP<br/>'. basename($bcktr[0]['file']) .' -- line '. $bcktr[0]['line'] .'</b></small></div>';
		echo '<table border="1" style="border: 1px solid black">';
		reset($arr);  //ensure pointer is at start
		$allkeys = array_keys(current($arr));
		echo '<tr>';
		echo '<td></td>';
		foreach ($allkeys as $x) {
			echo '<td><b>'. htmlentities($x) .'</b></td>';
		}
		echo '</tr>';
		foreach ($arr as $akey => $a) {
			echo '<tr>';
			echo '<td><b>'. htmlentities($akey) .'</b><br/><small style="color: goldenrod">'. ++$count .'</small></td>';
			foreach ($a as $bkey => $b) {
				echo '<td title="'. htmlentities($akey) .' , '. htmlentities($bkey) .'">';
				if (is_array($b) || is_object($b)) {
					static::dump($b);
				} else {
					echo nl2br(htmlentities($b));
				}
				echo '</td>';
			}
			echo '</tr>';
		}
		echo '</table>';
		echo '</div>';
	}

	/**
	 * Prints an array with unlimited dimensions (with all keys and values)
	 *
	 * @return string : HTML code
	 */
	public static function array_print(&$myarray, $level = false) {
		if (!$level) {
			$level = 1;
		}
		if (is_array($myarray)) {
			$arrayHTML = '<table border="1" style="border-collapse:collapse; border-color: '. static::$table_border_color .'" class="dump-array-tbl">';
			$arrayHTML .= '<tr><td style="font-size: 70%; opacity: 0.3"><b>Array '. $level .'</b></td><td></td></tr>';
			foreach ($myarray as $c_key => $c_value){
				$arrayHTML .= '<tr><td style="vertical-align:top">'. (is_int($c_key) ? '<span style="color:#888">'. $c_key .'</span>' : $c_key) .'</td><td>';
				if (is_array($c_value)) {
					$arrayHTML .= static::array_print($c_value, $level + 1);
				} elseif (is_object($c_value)) {
					$arrayHTML .= static::object_print($c_value, $level + 1);
				} else {
					$arrayHTML .= '<b style="color:#ff6600">'. static::dump_scalar($c_value) .'</b>';
				}
				$arrayHTML .= "</td></tr>\n";
			}
			$arrayHTML .= '</table>';
			return $arrayHTML;
		} else {
			return 'The argument given is not an array.';
		}
	}

	/**
	 * Prints an object with unlimited dimensions (with all properties and values)
	 *
	 * @return string : HTML code
	 */
	public static function object_print(&$myobject, $level = false) {
		if (!$level) {
			$level = 1;
		}
		if (is_object($myobject)) {
			$objectHTML = '<table border="1" style="border-collapse:collapse; border-color: '. static::$table_border_color .'" class="dump-obj-tbl">';
			$classname = get_class($myobject);
			$objectHTML .= '<tr><td colspan="2" style="font-size: 70%; opacity: 0.3"><b>Object '. $level .'</b>'. ($classname == 'stdClass' ? '' : ' '. $classname) .'</td></tr>';
			$reflection = new \ReflectionObject($myobject);
			$properties = $reflection->getProperties();
			foreach ($properties as $property) {
				$prefix = null;
				$setAccessible = false;
				if (!static::$hide_all_prop_prefixes) {
					if ($property->isPrivate()) {
						$prefix = '<strong>!</strong>';
						$setAccessible = true;
					} elseif ($property->isProtected()) {
						$prefix = '<strong>*</strong>';
						$setAccessible = true;
					} elseif ($property->isPublic()) {
						if (static::$prefix_public_props) {
							$prefix = 'public';
						}
					}
				}

				$c_key = $property->getName();
				if ($setAccessible) {
					$property->setAccessible(true);
				}
				$c_value = $property->getValue($myobject);

				$objectHTML .= '<tr><td><span style="color:#0061E0" class="propprefix">'. $prefix .'</span>'. $c_key .'</td><td>';
				if (is_object($c_value) && get_class($c_value) == 'SimpleXMLElement') {
					if (empty($c_value) || strlen((string) $c_value) > 0) {  //convert those strange objects that are actually scalar values
						$c_value = (string) $c_value;
					}
				}
				if (is_object($c_value)) {
					$objectHTML .= static::object_print($c_value, $level + 1);
				} elseif (is_array($c_value)) {  //in case of multi-dimensional arrays
					$objectHTML .= static::array_print($c_value, $level + 1);
				} else {
					$objectHTML .= '<b style="color:#ff6600">'. static::dump_scalar($c_value) .'</b>';
				}
				$objectHTML .= "</td></tr>\n";
			}
			$objectHTML .= '</table>';
			return $objectHTML;
		} else {
			return 'The argument given is not an object.';
		}
	}

	public static function dump_scalar($var) {
		if ($var === null) {
			return '<span style="font-size:70%;color:#C54F00">null</span>';
		} elseif ($var === true) {
			return '<span style="font-size:70%;color:#C54F00">true</span>';
		} elseif ($var === false) {
			return '<span style="font-size:70%;color:#C54F00">false</span>';
		} elseif (is_string($var)) {
			if (static::$visualize_chars) {
				return nl2br(htmlentities(static::visualize_characters($var)));
			} else {
				return nl2br(htmlentities($var));
			}
		} else {
			if (static::$visualize_chars) {
				return '<span style="color:#C54F00">'. nl2br(htmlentities( static::visualize_characters((string) $var))) .'</span>';
			} else {
				return '<span style="color:#C54F00">'. nl2br(htmlentities( (string) $var)) .'</span>';
			}
		}
	}

	/**
	 * Visualize normally invisible characters
	 *
	 * @param array $options : Available options:
	 *   - `html` : set true to HTML for nicer visualization
	 */
	public static function visualize_characters($string, $options = []) {
		if ((int) static::$visualize_chars === 2) {
			// TODO: visualize all non-normal characters
			throw new \Exception('visualize_chars=2 is not yet implemented');
		} else {
			if ($options['html']) {
				$b = $char_begin_html = '<span style="display:inline-block;border:1px solid #dbdbdb;border-radius:3px;margin:0 1px;padding:0 2px;background-color:#f2f2f2;color:#df7e00;font-weight:bold">';
				$e = $char_en_html = '</span>';
				return str_replace([' ', "\r", "\n", "\t"], [$b .'·'. $e, $b .'\r'. $e, $b .'\n'. $e .'<br>', $b .'\t'. $e], $string);
			} else {
				return str_replace([' ', "\r", "\n", "\t"], ['·', '\r', "\\n\n", '\t'], $string);
			}
		}
	}
}
