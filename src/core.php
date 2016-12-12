<?php
namespace winternet\jensenfw2;

class core {

	public static $is_dev = false;
	public static $preg_u = 'u';

	public static $userconfig = array();  //format: array('\path\to\namespace\ClassName', 'static_method')

	// Property that can be used by any class to store globally accessible data (instead of using $GLOBALS)
	public static $globaldata = array();

	public static function class_defaults() {
		$cfg = array();

		// System
		$cfg['system_name'] = '';
		$cfg['administrator_name'] = '';
		$cfg['administrator_email'] = '';
		$cfg['developer_name'] = '';
		$cfg['developer_email'] = '';

		// Core paths and files
		$cfg['path_filesystem'] = dirname(dirname(dirname(dirname(__FILE__))));
		$cfg['path_webserver'] = str_ireplace(rtrim($_SERVER['DOCUMENT_ROOT'], '/'), '', str_replace('\\', '/', $cfg['path_filesystem']));
		$cfg['noscript_tag_html'] = '<div style="padding: 200px; font-size: 400%"><b>Your browser has Javascript disabled. This site requires Javascript.</b></div>';  //HTML to put in the <noscript> tag when testing browser for Javascript support

		// Databases
		$cfg['databases'] = array();

		//   1st and primary server
		$cfg['databases'][0] = array(   //key 0 is the server ID (primary server must always be 0) (IDs must always be numeric)
			'db_host' => 'localhost',
			'db_port' => 3306,
			'db_user' => '',
			'db_pw'   => '',
			'db_name' => ''  //primary database
		);

		// Errors
		$cfg['log_errors_to'] = 'database';  //'file' or 'database'
		$cfg['errorlog_file'] = false;  //format: full path to file. Required if log_errors_to='file'
		$cfg['errorlog_table'] = "`". $cfg['databases'][0]['db_name'] ."`.`system_ctl_errors`";  //format: databasename.tablename (SQL format). Required if log_errors_to='database'
		$cfg['custom_user_error_msgHTML'] = false;  //for making a custom error message. The tags %%timestamp%% and %%errormessage%% will be replaced with the actual value, the URL encoded timestamp and the actual error message respectively.

		// System settings in database
		$cfg['db_table_system_settings'] = $cfg['databases'][0]['db_name'] .".system_settings";   //databasename.tablename

		// Translation
		$cfg['translation_mode'] = 'inline';  //'database', 'file', 'inline' - designation of where text translations are to be found
		$cfg['default_language'] = 'en';
		$cfg['languages_available'] = array('en');
		$cfg['db_table_translations'] = $cfg['databases'][0]['db_name'] .'.system_translations';   //databasename.tablename
		$cfg['missing_lang_log_mode'] = false;  //how should missing translation be logged? 'email', 'file', or false (if a *tag* is missing it will ALWAYS be notified by email)

		return $cfg;
	}

	public static function get_class_defaults($class_name, $get_var = null) {
		/*
		DESCRIPTION:
		- get the defaults for a given class, possibly modified by user configuration
		INPUT:
		- $class_name : name of class. It will be automatically prefixed with '\winternet\jensenfw2\' if is it not a fully qualified class name.
		OUTPUT:
		- associative array with the defaults
		*/
		if (strpos($class_name, 'jensenfw2') === false) {
			$class_name = "\\winternet\\jensenfw2\\". $class_name;
		}
		$class = call_user_func(array($class_name, 'class_defaults'));

		if (self::$userconfig) {
			$class_name_short = ltrim(str_replace('winternet\jensenfw2\\', '', $class_name), '\\');  //when __CLASS__ is used the full namespace is included, so strip it. ltrim() is needed in case string starts with "\"
			$user = call_user_func(self::$userconfig, $class_name_short);

			$eff = array_merge($class, $user);
		} else {
			$eff = $class;
		}

		if ($get_var && array_key_exists($get_var, $eff)) {
			return $eff[$get_var];
		} else {
			return $eff;
		}
	}

	//////////////////////////// Database ////////////////////////////

	// TODO: make option to use Yii's database connection instead

	public static function require_database($serverID = 0) {
		/*
		DESCRIPTION:
		- require a database connection. If not connected, try to connect.
		*/
		$serverID = (int) $serverID;  //convert empty strings to 0
		$server_id = ($serverID == 0 ? '' : $serverID);
		if (!$GLOBALS['_jfw_db_connection'.$server_id]) {  //don't open the database if it is already open
			$cfg = self::get_class_defaults(__CLASS__, 'databases');
			$GLOBALS['_jfw_db_connection'.$server_id] = mysqli_connect($cfg[$serverID]['db_host'], $cfg[$serverID]['db_user'], $cfg[$serverID]['db_pw'], $cfg[$serverID]['db_name'], $cfg[$serverID]['db_port']);
			if (!$GLOBALS['_jfw_db_connection'.$server_id]) {
				self::system_error('Could not connect to the database.', array('MySQL error' => mysqli_connect_error(), 'MySQL error no.' => mysqli_connect_errno()), array('xsevere' => 'CRITICAL ERROR'));
			}
			mysqli_set_charset($GLOBALS['_jfw_db_connection'.$server_id], 'utf8');
		}
	}

	public static function disconnect_database($serverID = 0) {
		$server_id = ($serverID == 0 ? '' : (int) $serverID);
		if ($GLOBALS['_jfw_db_connection'.$server_id]) {
			mysqli_close($GLOBALS['_jfw_db_connection'.$server_id]);
			unset($GLOBALS['_jfw_db_connection'.$server_id]);
		}
	}

	public static function &database_query($sql, $err_msg = 'Communication with the database failed.', $varinfo = array(), $directives = 'AUTO' ) {
		/*
		DESCRIPTION:
		- pass-through function for running queries against the database (to have a single place where all queries go through)
		INPUT:
		- $sql : string or array with SQL statement to execute
			- array:
				- first element is the SQL statement having position holders (?-marks) for each data element that is given in the rest of the array
					- example with basic use      : INSERT INTO mytable VALUE (?, ?, ?)
					- example with named positions: INSERT INTO mytable VALUE (?firstname, ?lastname, ?email)
				- all subsequent elements are each one piece of data that will be inserted into the SQL at the position holders with the first data element being inserted at the first ?-mark found and so on
					- the data will be escaped and put in quotes automatically
					- to use named positions the key for each data element must match exactly the name used in the SQL
			- to run the query on another server use the array method and make the first entry use key 'server_id' and it's value the ID of the server as defined in core config
		- $err_msg : error message to show to user if query fails
		OUTPUT:
		- output from mysqli_query() by reference
		- if output is used REMEMBER to assign like this: $dbresource =& database_query()
		*/
		if (!is_array($varinfo)) {
			self::system_error('Configuration error. Extra information for error debugging is invalid.', array('Varinfo' => $varinfo) );
		}
		// Prepare query
		if (is_array($sql)) {
			if (array_key_exists('server_id', $sql)) {
				$server_id = ($sql['server_id'] == 0 ? '' : $sql['server_id']);
				$sql = self::prepare_sql(array_slice($sql, 1));
			} else {
				$server_id = '';
				$sql = self::prepare_sql($sql);
			}
		}
		// Execute query
		if (count($varinfo) > 0) {  //NOTE: had to do it like this for mysqli_error() to work!
			$db_query = mysqli_query($GLOBALS['_jfw_db_connection'.$server_id], $sql) or self::system_error($err_msg, array_merge(array('MySQL error' => mysqli_error($GLOBALS['_jfw_db_connection'.$server_id]), 'SQL' => $sql), $varinfo), $directives);
		} else {
			$db_query = mysqli_query($GLOBALS['_jfw_db_connection'.$server_id], $sql) or self::system_error($err_msg, array('MySQL error' => mysqli_error($GLOBALS['_jfw_db_connection'.$server_id]), 'SQL' => $sql), $directives);
		}
		return $db_query;
	}

	public static function database_result($sql, $format = false, $err_msg = 'Communication with the database failed.', $varinfo = array(), $directives = 'AUTO' ) {
		/*
		DESCRIPTION:
		- execute a database query and return the result recordset in an associative array
		INPUT:
		- $sql (req.) : string or array with SQL statement to execute (according to database_query() )
		- $format (opt.) : the dataset has a certain format that output should be optimized to:
			- 'onerow' : result will only have one row and multiple column, and output will therefore only be "one-dimensional" (one array with keys corresponding to field names)
				- in case SQL statement would return multiple rows only the first row will be returned
			- 'onecolumn' : result will only have multiple rows but only one column, and output will be an array of those values (one array with no specific keys, only sequentially numeric index values)
				- in case SQL statement would return multiple columns only the first column will be returned
			- 'onevalue' : result will only have one row and one column, and output will just that specific value (no array) (empty array returned if SQL returned no data)
				- in case SQL statement would return multiple rows and/or columns only the value from the first row in the first column will be returned
				- in case no rows were found in database, an empty array is returned
			- 'multirow' or false : result has multiple rows and multiple columns
			- 'keyvalue' : result will be an associative array where first column is the key and the second column the value (additional columns will be ignored)
			- 'first_as_key' : result will be same as multirow but the key of the first level array will be the value of the first column (which won't be included in the second level array)
			- 'countonly' : only count how many records would be retrieved and that number would be returned (SELECT clause is replaced with COUNT(*). Function return number of records)
			- append ':both' to 'onerow' and 'multirow' to return both numeric and associative array (normally only associative is used)
		- $err_msg (req.) : user-friendly error message to display if query fails (use only common language everybody understands)
		OUTPUT:
		- associative "two-dimensional" array, unless $format specifies differently
		- for INSERT the ID of the new record is returned
		- for UPDATE, DELETE, DROP, etc. the number of affected rows is returned
		- for SELECT if no records were found, an empty array is returned
		*/
		if (is_array($sql)) {
			if (array_key_exists('server_id', $sql) && $sql['server_id'] != 0) {
				$server_id = $sql['server_id'];
			} else {
				$server_id = '';
			}
			$effsql =& $sql[0];
		} else {
			$effsql =& $sql;
		}
		if ($format == 'countonly') {
			$effsql = "SELECT COUNT(*) FROM (". $effsql .") AS tmp";
		}
		$db_query =& self::database_query($sql, $err_msg, $varinfo, $directives);
		if ($db_query === true) {
			if (strtoupper(substr($effsql, 0, 6)) == 'INSERT') {
				//query was INSERT
				return mysqli_insert_id($GLOBALS['_jfw_db_connection'.$server_id]);
			} else {
				//query was UPDATE, DELETE, DROP, etc.
				return mysqli_affected_rows($GLOBALS['_jfw_db_connection'.$server_id]);
			}
		} else {
			$arr_return = array();
			if (mysqli_num_rows($db_query) == 0) {
				//no records found, leave array empty
			} else {
				if (!$format || $format == 'multirow' || $format == 'multirow:both') {
					if ($format == 'multirow:both') {
						while ($row = mysqli_fetch_array($db_query)) {
							$arr_return[] = $row;
						}
					} else {
						while ($row = mysqli_fetch_assoc($db_query)) {
							$arr_return[] = $row;
						}
					}
				} elseif ($format == 'onecolumn') {
					while ($row = mysqli_fetch_row($db_query)) {
						$arr_return[] = $row[0];
					}
				} elseif ($format == 'onerow' || $format == 'onerow:both') {
					if ($format == 'onerow:both') {
						$arr_return = mysqli_fetch_array($db_query);
					} else {
						$arr_return = mysqli_fetch_assoc($db_query);
					}
				} elseif ($format == 'onevalue' || $format == 'countonly') {
					$row = mysqli_fetch_row($db_query);
					$arr_return = $row[0];
				} elseif ($format == 'keyvalue') {
					while ($row = mysqli_fetch_row($db_query)) {
						$arr_return[$row[0]] = $row[1];
					}
				} elseif ($format == 'first_as_key') {
					while ($row = mysqli_fetch_assoc($db_query)) {
						if (!$firstcol_name) {
							$firstcol_name = key($row);
						}
						$keyval = $row[$firstcol_name];
						unset($row[$firstcol_name]);
						$arr_return[$keyval] = $row;
					}
				} else {
					self::system_error('Invalid format for getting database result.');
				}
			}
			return $arr_return;
		}
	}

	public static function prepare_sql($array) {
		/*
		DESCRIPTION:
		- prepare an SQL statement by safely inserting variables into the SQL
		INPUT:
		- $array : array with SQL statement and variables
			- first element is the SQL statement having position holders (?-marks) for each data element that is given in the rest of the array
				- example with basic use      : INSERT INTO mytable VALUE (?, ?, ?)
				- example with named positions: INSERT INTO mytable VALUE (?firstname, ?lastname, ?email)
			- all subsequent elements are each one piece of data that will be inserted into the SQL at the position holders with the first data element being inserted at the first ?-mark found and so on
				- the data will be escaped and put in quotes automatically
				- to use named positions the key for each data element must match exactly the name used in the SQL
		OUTPUT:
		- string with generated SQL statement
		*/
		$data = array_slice($array, 1);
		$sql = $array[0];
		foreach ($data as $key => $value) {
			// NOTE: convert even numbers to strings because comparing the string against number 0 in MySQL (also when written as -0 or 0.00) will always evaluate to true (http://stackoverflow.com/questions/9948389/mysql-string-conversion-return-0). Comparing numbers against numeric strings is no problem though, therefore always do that.
			if (is_array($value)) {
				$valueSQL = [];
				foreach ($value as $v) {
					if ($v === '::emptY-String') {
						$valueSQL[] = "''";
					} elseif ($v === '' || $v === false || $v === null) {
						$valueSQL[] = 'NULL';
					} else {
						$valueSQL[] = "'". self::sql_esc($v) ."'";
					}
				}
				$valueSQL = implode(', ', $valueSQL);
			} else {
				if ($value === '::emptY-String') {
					$valueSQL = "''";
				} elseif ($value === '' || $value === false || $value === null) {
					$valueSQL = 'NULL';
				} else {
					$valueSQL = "'". self::sql_esc($value) ."'";
				}
			}
			if (!is_numeric($key)) {
				$sql = preg_replace("|\\?". $key ."\\b|".(mb_internal_encoding() == 'UTF-8' ? 'u' : ''), $valueSQL, $sql);
			} else {
				$sql = preg_replace("|\\?(?!\\w)|".(mb_internal_encoding() == 'UTF-8' ? 'u' : ''), $valueSQL, $sql, 1);
			}
		}
		return $sql;
	}

	public static function sql_esc($str) {
		/*
		DESCRIPTION:
		- convenience alias for mysqli_real_escape_string()
		*/
		if ($str && !is_string($str) && !is_numeric($str)) {
			self::system_error('A non-string was passed to SQL escaping function.', array('Argument' => print_r($str, true)), array('xnotify' => 'developer') );
		}
		if (!$GLOBALS['_jfw_db_connection']) {
			self::system_error('Database connection was not found in SQL escaping function.', array('Argument' => print_r($str, true)), array('xnotify' => 'developer') );
		}
		return mysqli_real_escape_string($GLOBALS['_jfw_db_connection'], (string) $str);  //cast numbers as strings
	}

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
		if (self::$is_dev && !empty($vars)) {
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
			if ($reference && system::get_buffer_value('jfwnotifd'. $reference) && !$GLOBALS['_send_all_webmaster_notifs']) {
				return false;
			}
		} else {
			if ($reference && $_SESSION['_jfw_webmaster_notifd_'. $reference] && !$GLOBALS['_send_all_webmaster_notifs']) {
				return false;
			}
		}

		$cfg = self::get_class_defaults(__CLASS__);
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
		mail::send_email($cfg['administrator_email'], $cfg['system_name'], $to, $subj, $body);

		if ($reference) {
			if ($use_systembuffer) {
				system::set_buffer_value('jfwnotifd'. $reference, '1', $expire);
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
			if (preg_match('/,,,\\s*[a-zA-Z]{2}\\s*=/'.(mb_internal_encoding() == 'UTF-8' ? 'u' : ''), $str)) {
				$str = explode(',,,', $str);
				foreach ($str as &$a) {
					if (preg_match('|^\\s*([a-zA-Z]{2})\\s*=\\s*(.*?)\\s*$|s'.(mb_internal_encoding() == 'UTF-8' ? 'u' : ''), $a, $match)) {
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
			self::require_database();
			$sql = "SELECT settingname, settingvalue FROM ". core::get_class_defaults(__CLASS__, 'db_table_system_settings');
			$db_query =& self::database_query($sql, 'Database query for getting system settings failed.');

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
