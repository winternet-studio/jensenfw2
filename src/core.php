<?php
namespace winternet\jensenfw2;

class core {

	public static $is_dev = false;

	//////////////////////////// Hook/plugin system ////////////////////////////

	public static function run_hooks($hook_id, $value = '.NO-VALUE.') {
		/*
		DESCRIPTION:
		- function that will make the code "pluggable" as it will execute the hooks that have been set up by the customized code in order to run code or modify a value
		- check for more aspects we need to consider: http://www.smashingmagazine.com/2011/10/07/definitive-guide-wordpress-hooks/
		- took me 1,5 hour to make this basic/first version of the hook system
		- consider implement an "all" hook like Wordpress does (see _wp_call_all_hook() )
		INPUT:
		- $hook_id (string) : hook reference
		- $value : value to be passed to the callback function
		- any additional arguments are passed on to the callback function as well
		OUTPUT:
		- the new value that might have been changed by the hook
		*/
		if (@isset($GLOBALS['sys']['hook_system']['hooks'][$hook_id])) {
			$args = func_get_args();  //retrieve ALL arguments (not just the first one which is $value)

			foreach ($GLOBALS['sys']['hook_system']['hooks'][$hook_id] as &$hooks_for_curr_priority) {
				foreach ($hooks_for_curr_priority as &$curr_callback) {
					if (is_callable($curr_callback['function']) || is_string($curr_callback['function'])) {
						if ($value === '.NO-VALUE.') {
							call_user_func($curr_callback['function']);
						} else {
							$value = call_user_func_array($curr_callback['function'], array_slice($args, 1));
							$args[1] = $value;  //prepare for next loop if any
						}
					}
				}
			}
		}
		return $value;
	}

	public static function run_hooks_array($hook_id, $args) {
		/*
		DESCRIPTION:
		- function that will make the code "pluggable" as it will execute the hooks that have been set up by the customized code in order to run code or modify a value
		INPUT:
		- $hook_id (string) : hook reference
		- $args : array of arguments to be passed to the callback function
		OUTPUT:
		- the new array that was returned by the hook
		*/
		if (@isset($GLOBALS['sys']['hook_system']['hooks'][$hook_id])) {
			foreach ($GLOBALS['sys']['hook_system']['hooks'][$hook_id] as &$hooks_for_curr_priority) {
				foreach ($hooks_for_curr_priority as &$curr_callback) {
					if (is_callable($curr_callback['function']) || is_string($curr_callback['function'])) {
						$args = call_user_func_array($curr_callback['function'], $args);
					}
				}
			}
		}
		return $args;
	}

	public static function connect_hook($hook_id, $callback_function, $priority = 10) {
		/*
		DESCRIPTION:
		- function to be called by the customized code that want to connect a callback function into the original code
		INPUT:
		- $hook_id (string) : hook reference
		- $callback_function : a closure (anonymous function) or string with function to call
			- the function will be passed the additional arguments that were passed to run_hooks()
		- $priority : a number indicating the priority of this hook. Default is 10
		OUTPUT:
		- true
		*/
		if (!@isset($GLOBALS['sys']['hook_system']['hooks'])) {
			$GLOBALS['sys']['hook_system']['hooks'] = array();
		}
		if (!@isset($GLOBALS['sys']['hook_system']['hooks'][$hook_id][$priority])) {
			$GLOBALS['sys']['hook_system']['hooks'][$hook_id][$priority] = array();
		}

		$id = self::_hook_unique_callback_id($callback_function);
		$GLOBALS['sys']['hook_system']['hooks'][$hook_id][$priority][$id] = array(
			'function' => $callback_function,
		);

		return true;
	}

	public static function disconnect_hook($hook_id, $callback_function, $priority = 10) {
		/*
		DESCRIPTION:
		- function to be called by the customized code that want disconnect a callback it set up earlier
		INPUT:
		- $hook_id (string) : hook reference
		- $callback_function : a closure (anonymous function) or string with function to call
		- $priority : a number indicating the priority of this hook. Default is 10
		OUTPUT:
		- if not found or failure : false
		- if disconnected successfully : true
		*/
		$id = self::_hook_unique_callback_id($callback_function);
		if (@isset($GLOBALS['sys']['hook_system']['hooks'][$hook_id][$priority][$id])) {
			unset($GLOBALS['sys']['hook_system']['hooks'][$hook_id][$priority][$id]);
			return true;
		} else {
			return false;
		}
	}

	public static function disconnect_all_hooks($hook_id, $priority = false) {
		/*
		DESCRIPTION:
		- function to be called by the customized code that want disconnect ALL callback it set up earlier for a given hook, optionally only those of a certain priority
		INPUT:
		- $hook_id (string) : hook reference
		- $priority : a number indicating the priority of this hook. Default is 10
		OUTPUT:
		- nothing
		*/
		if (@isset($GLOBALS['sys']['hook_system']['hooks'][$hook_id])) {
			if (false === $priority) {
				$GLOBALS['sys']['hook_system']['hooks'][$hook_id] = array();
			} elseif (isset($GLOBALS['sys']['hook_system']['hooks'][$hook_id][$priority])) {
				$GLOBALS['sys']['hook_system']['hooks'][$hook_id][$priority] = array();
			}
		}
	}

	public static function _hook_unique_callback_id($callback_function) {
		if (is_string($callback_function)) {
			return $callback_function;
		}

		if ( is_object($callback_function) ) {
			// Closures are currently implemented as objects
			$callback_function = array($callback_function, '');
		} else {
			$callback_function = (array) $callback_function;
		}

		if (is_object($callback_function[0])) {
			// Object Class Calling
			return spl_object_hash($callback_function[0]) . $callback_function[1];
		} elseif (is_string($callback_function[0])) {
			// Static Calling
			return $function[0] .'::'. $function[1];
		}
	}


	//////////////////////////// Debugging and error handling ////////////////////////////

	public static function system_error($msg, $vars = [], $dirs = []) {
		if (self::$is_dev) {
			$msg .= PHP_EOL.PHP_EOL .'EXTRA INFORMATION:'. PHP_EOL . json_encode($vars, JSON_PRETTY_PRINT);
		}
		throw new \Exception($msg);
	}

	public static function notify_webmaster($who, $subj, $message, $reference = false) {
		/*
		DESCRIPTION:
		- send an e-mail to the developer
		INPUT:
		- $who ('developer'|'admin') : notify developer or webmaster/system administrator?
		- $subj : subject of the message
		- $message (string or array) : body of the message. An array will be converted to list key/value pairs.
		- $reference (opt.) : a unique reference to the message. Used to send this notification only once when this function is called multiple times with the same reference.
			- alternatively use an array instead with these keys:
				- 'ref' (req.) : the unique reference
				- 'persist' (opt.) : set to true to use set_buffer_value() to globally remember this reference (instead of within current session only)
				- 'expire' (opt.) : make it expire after a certain time, by specifying one of the following formats according to set_buffer_value(): (only effective together with 'persist')
					- '2017-11-05:' : make it expire on this date (yyyy-mm-dd)
					- '6h:' : make it expire in 6 hours
					- '2d:' : make it expire in 2 days
				- note that the system_buffer must have been created beforehand
		OUTPUT:
		- e-mail sent : true
		- e-mail not sent : false (due to being a "duplicate")
		*/
		require_function('send_email');

		if (is_array($reference)) {
			$use_systembuffer = ($reference['persist'] ? true : false);

			$expire = false;
			if ($reference['expire']) {
				$expire = $reference['expire'];
			}

			$reference = $reference['ref'];
			if (!$reference) {
				self::system_error('Missing reference for recording we have sent webmaster a message.');
			}
		} else {
			$use_systembuffer = false;
		}

		// Don't send duplicate notifications
		if ($use_systembuffer) {
			require_function('get_buffer_value');
			if ($reference && get_buffer_value('jfwnotifd'. $reference) && !$GLOBALS['_send_all_webmaster_notifs']) {
				return false;
			}
		} else {
			if ($reference && $_SESSION['_jfw_webmaster_notifd_'. $reference] && !$GLOBALS['_send_all_webmaster_notifs']) {
				return false;
			}
		}

		$cfg = jfw__core_cfg();
		$bt = debug_backtrace();

		if ($who == 'developer') {
			$to = array($cfg['developer_name'], $cfg['developer_email']);
			$to2 = 'developer';
		} elseif ($who == 'admin') {
			$to = array($cfg['administrator_name'], $cfg['administrator_email']);
			$to2 = 'system administrator';
		}

		if (is_array($message)) {
			$tmp = $subj ."\r\n\r\nDETAILS\r\n-------\r\n";
			foreach ($message as $key => $value) {
				$tmp .= str_pad($key, 10, ' ') .': '. $value ."\r\n";
			}
			$message = '';
			$message .= $tmp;
		}

		$body  = 'SYSTEM NOTIFICATION to '. $to2 ."\r\n";
		$body .= "==============================================================================\r\n\r\n";
		$body .= $message ."\r\n\r\n";
		$body .= "==============================================================================\r\n";
		$body .= "Source: ". basename($bt[0]['file']) ." line ". $bt[0]['line'];
		if ($bt[1]['function']) {
			$body .= " - in ". $bt[1]['function'] ."()";
		}
		$body .= "\r\nURI: ". $_SERVER['REQUEST_URI'];  // $bt[0]['file'] is the lowest in the stack and this is the highest
		if ($reference) {
			$body .= "\r\nReference: ". $reference;
		}
		send_email($cfg['administrator_email'], $cfg['system_name'], $to, $subj, $body);

		if ($reference) {
			if ($use_systembuffer) {
				set_buffer_value('jfwnotifd'. $reference, '1', $expire);
			} else {
				$_SESSION['_jfw_webmaster_notifd_'. $reference] = true;
			}
		}

		return true;
	}


	//////////////////////////// Extra utility functions ////////////////////////////
	public static function arristr($haystack = '', $needle = array() ) {
		/*
		DESCRIPTION:
		- searches for a given string (full or part) in an array, case-insensitive
		*/
		foreach($needle as $n) {
			if (stristr($haystack, $n) !== false) {
				return true;
			}
		}
		return false;
	}

	public static function any_in_array($arr_needle, $array) {
		/*
		DESCRIPTION:
		- check if one or more values from one array exists in another
		- if needle is a string it will be auto-converted to an array so that it can also be used instead of in_array()
		INPUT:
		- $arr_needle : array of values to look for
		- $array : array to search
		OUTPUT:
		- true or false
		*/
		if (is_string($arr_needle)) $arr_needle = array($arr_needle);
		if (!is_array($arr_needle)) self::system_error('Invalid needle for searching array.');
		if (!is_array($array)) self::system_error('Invalid array to search.');
		$a = array_intersect($arr_needle, $array);
		return !empty($a);
	}

	public static function array_search_column(&$array, $key, $value) {
		/*
		DESCRIPTION:
		- search a two-dimensional array for a certain key/value pair (= 'column'), case-insensitive
		- this differs from array_keys(array, search_arg) and in_array() in that this searches on the SECOND level
		INPUT:
		- $array : array to search
		- $key : key which need to contain the value that we search for
		- $value : the value we want to find
		OUTPUT:
		- if found: the key that contained the key/value pair (= 1st level key)
		- if not found: false
		*/
		if (!is_array($array)) {
			self::system_error('Parameter for column search is not an array.', array('Array' => $array));
		} else {
			$value_lc = mb_strtolower($value);
			foreach ($array as $curr_key => $curr_val) {
				if (mb_strtolower($curr_val[$key]) == $value_lc) {
					return $curr_key;
				}
			}
			return false;  //if we get this far the key/value pair does not exist
		}
	}

	public static function txtdb($str) {
		/*
		DESCRIPTION:
		- parses a string with multiple translations of a piece of text
		- uses the session language but can be overridden by $GLOBALS['_override_current_language']
		INPUT:
		- $str : string in the format: EN=Text in English ,,, ES=Text in Spanish
			- unlimited number of translations
			- upper case of language identifier is optional
			- spaces are allowed around both identifiers and texts (will be trimmed)
		OUTPUT:
		- string
		- if no matches found, the raw string is returned
		- if language is not found, the first language is returned
		*/
		$str = (string) $str;
		if (!$str) {
			return $str;
		} else {
			if (preg_match('/,,,\\s*[a-zA-Z]{2}\\s*=/'._RXU, $str)) {
				$str = explode(',,,', $str);
				foreach ($str as &$a) {
					if (preg_match('|^\\s*([a-zA-Z]{2})\\s*=\\s*(.*?)\\s*$|s'._RXU, $a, $match)) {
						$clang = strtolower($match[1]);
						if ($_SESSION['runtime']['currlang'] == $clang || ($GLOBALS['_override_current_language'] && $GLOBALS['_override_current_language'] == $clang) ) {
							return $match[2];
						}
					}
				}
				$b = explode('=', $str[0]);  //fallback to first language
				return trim($b[1]);
			} else {
				return $str;
			}
		}
	}

	public static function get_system_setting($name, $flags = '') {
		/*
		DESCRIPTION:
		- get a system setting from database
		INPUT:
		- $name : name of system setting to get
		- $flags : string with any combination of these flags:
			- 'no_error' : don't raise error if setting is not found, but return false
		OUTPUT:
		- the database field value
		- returns false if setting was not found
		*/
		if (!isset($GLOBALS['cache']['system_settings'])) {
			$GLOBALS['cache']['system_settings'] = array();
			require_database();
			$sql = "SELECT settingname, settingvalue FROM ". jfw__core_cfg('db_table_system_settings');
			$db_query =& database_query($sql, 'Database query for getting system settings failed.');

			$GLOBALS['cache']['system_settings'] = array();
			if (mysqli_num_rows($db_query) > 0) {
				while ($v = mysqli_fetch_assoc($db_query)) {
					$GLOBALS['cache']['system_settings'][$v['settingname']] = $v['settingvalue'];
				}
			}
		}
		if (!array_key_exists($name, $GLOBALS['cache']['system_settings'])) {
			if (strpos( (string) $flags, 'no_error') === false) {
				self::system_error('Configuration error. A system setting could not be found.', array('Name' => $name) );
			} else {
				return false;
			}
		}
		return $GLOBALS['cache']['system_settings'][$name];
	}

	public static function get_parent_function($f = 1) {
		/*
		DESCRIPTION:
		- get the name of the function calling the current function/scope
		OUTPUT:
		- name of function
		- false if it was not called from a function
		*/
		$parent_info = debug_backtrace();
		$parent_function = $parent_info[1 + $f]['function'];  //the parent function of the function that called this function, has the index no. 2 in the debug array. Higher levels has higher numbers.
		if ($parent_function) {
			return $parent_function;
		} else {
			return false;
		}
	}

	public static function pageurl($array = array(), $flags = '') {
		/*
		DESCRIPTION:
		- generate URL for this same page but with modified query string parameters according to the specified array
		INPUT:
		- $array : associative array with keys being query string variable name and value of course being the corresponding value
			- leave empty to generate the same URL as current one (but without the one-time variables)
			- to delete an existing parameter set the value to null
			- to make the variable a one-time variable only, set the value to an array where first item is the actual value and the second item is 'once' (string). Then that variable will not be kept on next page when calling jfw_pageurl()
			- a dot (.) is not allowed in the variable name of one-time variables because it is used as a separator (you could use dash (-) instead)
		- $options : string with any of these options, separated by a space:
			'querystring_only' : only generate the query string (exlude path and '?')
			'varname:[name of variable]' : use this name for the query string variable containing one-time values (instead of the default '1')
		OUTPUT:
		- string with URL
		*/
		if (!is_array($array)) {
			self::system_error('Invalid array for generating query string.');
		}

		$flags = (string) $flags;

		$onetime_varname = '1';
		if (preg_match("/varname:([^ ]+)/", $flags, $onematch)) {
			$onetime_varname = $onematch[1];
		} elseif ($GLOBALS['_jfw_onetime_varname']) {
			$onetime_varname = $GLOBALS['_jfw_onetime_varname'];
		}

		$qs = $_GET;

		if ($qs[$onetime_varname]) {
			foreach (explode('.', $qs[$onetime_varname]) as $onetimevar) {
				unset($qs[$onetimevar]);
			}
		}
		unset($qs[$onetime_varname]);

		$onetime_vars = array();
		foreach ($array as $name => $value) {
			if (is_array($value)) {
				$flags = $value[1];
				$value = $value[0];

				if (strpos($flags, 'once') !== false) {
					$onetime_vars[] = $name;
				}
			}
			if ($value === null) {
				unset($qs[$name]);
			} else {
				$qs[$name] = $value;
			}
		}

		if (!empty($onetime_vars)) {
			$qs[$onetime_varname] = implode('.', $onetime_vars);
		}

		if (strpos($flags, 'querystring_only') !== false) {
			return http_build_query($qs);
		} else {
			$uri = $_SERVER['REQUEST_URI'];
			$quest_pos = strpos($uri, '?');
			if ($quest_pos) {
				$url = substr($uri, 0, $quest_pos+1);
				return $url . http_build_query($qs);
			} else {
				return $uri .'?'. http_build_query($qs);
			}
		}
	}
}
