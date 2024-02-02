<?php
/**
 * Core functions used through the library
 */

namespace winternet\jensenfw2;

class core {

	public static $is_dev = false;
	public static $preg_u = 'u';

	public static $userconfig = [];  //format: array('\path\to\namespace\ClassName', 'static_method')

	// Property that can be used by any class to store globally accessible data (instead of using $GLOBALS)
	public static $globaldata = [];

	public static $max_errors = 10;
	private static $system_error_in_process;
	private static $system_error_count;

	public static function class_defaults() {
		$cfg = [];

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
		$cfg['databases'] = [];

		//   1st and primary server
		$cfg['databases'][0] = [   //key 0 is the server ID (primary server must always be 0) (IDs must always be numeric)
			'db_host' => 'localhost',
			'db_port' => 3306,
			'db_user' => '',
			'db_pw'   => '',
			'db_name' => ''  //primary database
		];

		// Errors
		$cfg['log_errors_to'] = 'database';  //'file' or 'database'
		$cfg['errorlog_file'] = false;  //format: full path to file. Required if log_errors_to='file'
		$cfg['errorlog_table'] = "`". $cfg['databases'][0]['db_name'] ."`.`system_ctl_errors`";  //format: databasename.tablename (SQL format). Required if log_errors_to='database'
		$cfg['errorlog_userdata'] = null;  //function that returns an associative array with information about the logged in user. Keys: logged_in (true|false) (req.), userID, username, fullname, accesslevels, is_emulating, emulator_username
		$cfg['errorlog_extra'] = null;  //function that returns an associative array with key/value pairs of extra data you want to log with each error, eg. session data
		$cfg['custom_user_error_msgHTML'] = false;  //for making a custom error message. The tags %%timestamp%% and %%errormessage%% will be replaced with the actual value, the URL encoded timestamp and the actual error message respectively.

		// System settings in database
		$cfg['db_table_system_settings'] = $cfg['databases'][0]['db_name'] .".system_settings";   //databasename.tablename

		// Translation
		$cfg['translation_mode'] = 'inline';  //'database', 'file', 'inline' - designation of where text translations are to be found
		$cfg['default_language'] = 'en';
		$cfg['languages_available'] = ['en'];
		$cfg['db_table_translations'] = $cfg['databases'][0]['db_name'] .'.system_translations';   //databasename.tablename
		$cfg['missing_lang_log_mode'] = false;  //how should missing translation be logged? 'email', 'file', or false (if a *tag* is missing it will ALWAYS be notified by email)

		// Other
		$cfg['debug_level'] = null;  //set to 1 or higher to output debugging data

		return $cfg;
	}

	/**
	 * Get the defaults for a given class, possibly modified by user configuration
	 *
	 * @param string $class_name : Name of class. It will be automatically prefixed with `\winternet\jensenfw2\` if is it not a fully qualified class name.
	 * @return array : Associative array with the defaults
	 */
	public static function get_class_defaults($class_name, $get_var = null) {
		if (strpos($class_name, 'jensenfw2') === false) {
			$class_name = "\\winternet\\jensenfw2\\". $class_name;
		}
		$class = call_user_func([$class_name, 'class_defaults']);

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

	/**
	 * Require a database connection
	 *
	 * If not connected, try to connect.
	 *
	 * @param integer $serverID
	 */
	public static function require_database($serverID = 0) {
		$serverID = (int) $serverID;  //convert empty strings to 0
		$server_id = ($serverID == 0 ? '' : $serverID);
		if (!@$GLOBALS['_jfw_db_connection'.$server_id]) {  //don't open the database if it is already open
			$cfg = self::get_class_defaults(__CLASS__, 'databases');
			$GLOBALS['_jfw_db_connection'.$server_id] = mysqli_connect($cfg[$serverID]['db_host'], $cfg[$serverID]['db_user'], $cfg[$serverID]['db_pw'], $cfg[$serverID]['db_name'], $cfg[$serverID]['db_port']);
			if (!$GLOBALS['_jfw_db_connection'.$server_id]) {
				self::system_error('Could not connect to the database.', ['MySQL error' => mysqli_connect_error(), 'MySQL error no.' => mysqli_connect_errno()], ['xsevere' => 'CRITICAL ERROR']);
			}
			mysqli_set_charset($GLOBALS['_jfw_db_connection'.$server_id], 'utf8');
		}
	}

	public static function disconnect_database($serverID = 0) {
		$server_id = ($serverID == 0 ? '' : (int) $serverID);
		if (@$GLOBALS['_jfw_db_connection'.$server_id]) {
			mysqli_close($GLOBALS['_jfw_db_connection'.$server_id]);
			unset($GLOBALS['_jfw_db_connection'.$server_id]);
		}
	}

	/**
	 * Pass-through function for running queries against the database (to have a single place where all queries go through)
	 *
	 * @param string|array $sql : SQL statement to execute
	 *	- array:
	 *		- first element is the SQL statement having position holders (?-marks) for each data element that is given in the rest of the array
	 *			- example with basic use      : INSERT INTO mytable VALUE (?, ?, ?)
	 *			- example with named positions: INSERT INTO mytable VALUE (?firstname, ?lastname, ?email)
	 *		- all subsequent elements are each one piece of data that will be inserted into the SQL at the position holders with the first data element being inserted at the first ?-mark found and so on
	 *			- the data will be escaped and put in quotes automatically
	 *			- to use named positions the key for each data element must match exactly the name used in the SQL
	 *	- to run the query on another server use the array method and make the first entry use key 'server_id' and it's value the ID of the server as defined in core config
	 * @param string $err_msg : Error message to show to user if query fails
	 * @return \mysqli_result : Output from mysqli_query() by reference. If output is used REMEMBER to assign like this: `$dbresource =& database_query()`
	 */
	public static function &database_query($sql, $err_msg = 'Communication with the database failed.', $varinfo = [], $directives = 'AUTO' ) {
		if (!is_array($varinfo)) {
			self::system_error('Configuration error. Extra information for error debugging is invalid.', ['Varinfo' => $varinfo]);
		}
		// Prepare query
		$server_id = '';
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
			$db_query = mysqli_query($GLOBALS['_jfw_db_connection'.$server_id], $sql) or self::system_error($err_msg, array_merge(['MySQL error' => mysqli_error($GLOBALS['_jfw_db_connection'.$server_id]), 'SQL' => $sql], $varinfo), $directives);
		} else {
			$db_query = mysqli_query($GLOBALS['_jfw_db_connection'.$server_id], $sql) or self::system_error($err_msg, ['MySQL error' => mysqli_error($GLOBALS['_jfw_db_connection'.$server_id]), 'SQL' => $sql], $directives);
		}
		return $db_query;
	}

	/**
	 * Execute a database query and return the result recordset in an associative array
	 *
	 * @param string|array $sql (req.) : String or array with SQL statement to execute (according to database_query() )
	 * @param string $format (opt.) : The dataset has a certain format that output should be optimized to:
	 *		- 'onerow' : result will only have one row and multiple column, and output will therefore only be "one-dimensional" (one array with keys corresponding to field names)
	 *			- in case SQL statement would return multiple rows only the first row will be returned
	 *		- 'onecolumn' : result will only have multiple rows but only one column, and output will be an array of those values (one array with no specific keys, only sequentially numeric index values)
	 *			- in case SQL statement would return multiple columns only the first column will be returned
	 *		- 'onevalue' : result will only have one row and one column, and output will just that specific value (no array) (empty array returned if SQL returned no data)
	 *			- in case SQL statement would return multiple rows and/or columns only the value from the first row in the first column will be returned
	 *			- in case no rows were found in database, an empty array is returned
	 *		- 'multirow' or false : result has multiple rows and multiple columns
	 *		- 'keyvalue' : result will be an associative array where first column is the key and the second column the value (additional columns will be ignored)
	 *		- 'first_as_key' : result will be same as multirow but the key of the first level array will be the value of the first column (which won't be included in the second level array)
	 *		- 'countonly' : only count how many records would be retrieved and that number would be returned (SELECT clause is replaced with COUNT(*). Function return number of records)
	 *		- append ':both' to 'onerow' and 'multirow' to return both numeric and associative array (normally only associative is used)
	 * @param string $err_msg (req.) : User-friendly error message to display if query fails (use only common language everybody understands)
	 * @return mixed : Associative "two-dimensional" array, unless $format specifies differently:
	 *	- for INSERT the ID of the new record is returned
	 *	- for UPDATE, DELETE, DROP, etc. the number of affected rows is returned
	 *	- for SELECT if no records were found, an empty array is returned
	 */
	public static function database_result($sql, $format = false, $err_msg = 'Communication with the database failed.', $varinfo = [], $directives = 'AUTO' ) {
		$server_id = '';
		if (is_array($sql)) {
			if (array_key_exists('server_id', $sql) && $sql['server_id'] != 0) {
				$server_id = $sql['server_id'];
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
			$arr_return = [];
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

	/**
	 * Prepare an SQL statement by safely inserting variables into the SQL
	 *
	 * @param array $array : Array with SQL statement and variables
	 *		- first element is the SQL statement having position holders (?-marks) for each data element that is given in the rest of the array
	 *			- example with basic use      : INSERT INTO mytable VALUE (?, ?, ?)
	 *			- example with named positions: INSERT INTO mytable VALUE (?firstname, ?lastname, ?email)
	 *		- all subsequent elements are each one piece of data that will be inserted into the SQL at the position holders with the first data element being inserted at the first ?-mark found and so on
	 *			- the data will be escaped and put in quotes automatically
	 *			- to use named positions the key for each data element must match exactly the name used in the SQL
	 * @param array $parms : Instead of providing everything in $array you can also provide the base SQL in $array as a string and
	 *	           provide all the data in $parms as an associative array (using numeric keys has not been tested).
	 *	           This is an alternative format that matches Yii2.
	 * @param string $prefix : Can be set to ':' instead of '?' if needed
	 * @return string : The final SQL statement
	 */
	public static function prepare_sql($array, $parms = null, $prefix = '?') {
		if ($parms === null) {
			$data = array_slice($array, 1);
			$sql = $array[0];
		} else {
			$data =& $parms;
			$sql  =& $array;
		}
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
			if ($prefix === ':') {
				if (!is_numeric($key)) {
					$sql = preg_replace("|:". $key ."\\b|".(mb_internal_encoding() == 'UTF-8' ? 'u' : ''), $valueSQL, $sql);
				} else {
					$sql = preg_replace("|:(?!\\w)|".(mb_internal_encoding() == 'UTF-8' ? 'u' : ''), $valueSQL, $sql, 1);
				}
			} else {
				if (!is_numeric($key)) {
					$sql = preg_replace("|\\?". $key ."\\b|".(mb_internal_encoding() == 'UTF-8' ? 'u' : ''), $valueSQL, $sql);
				} else {
					$sql = preg_replace("|\\?(?!\\w)|".(mb_internal_encoding() == 'UTF-8' ? 'u' : ''), $valueSQL, $sql, 1);
				}
			}
		}
		return $sql;
	}

	/**
	 * Convenience alias for mysqli_real_escape_string()
	 */
	public static function sql_esc($str) {
		if ($str && !is_string($str) && !is_numeric($str)) {
			self::system_error('A non-string was passed to SQL escaping function.', ['Argument' => print_r($str, true)], ['xnotify' => 'developer']);
		}
		if (!@$GLOBALS['_jfw_db_connection']) {
			self::system_error('Database connection was not found in SQL escaping function.', ['Argument' => print_r($str, true)], ['xnotify' => 'developer']);
		}
		return mysqli_real_escape_string($GLOBALS['_jfw_db_connection'], (string) $str);  //cast numbers as strings
	}

	//////////////////////////// Hook/plugin system ////////////////////////////

	/**
	 * Execute hooks
	 *
	 * Function that will make the code "pluggable" as it will execute the hooks that have been set up by the customized code in order to run code or modify a value.
	 *
	 * Check for more aspects we need to consider: http://www.smashingmagazine.com/2011/10/07/definitive-guide-wordpress-hooks/
	 *
	 * Took me 1,5 hour to make this basic/first version of the hook system.
	 *
	 * Consider implement an "all" hook like Wordpress does (see _wp_call_all_hook() )
	 *
	 * @param string $hook_id : Hook reference
	 * @param mixed $value : Value to be passed to the callback function
	 *	- any additional arguments are passed on to the callback function as well
	 * @return mixed : The new value that might have been changed by the hook
	 */
	public static function run_hooks($hook_id, $value = '.NO-VALUE.') {
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

	/**
	 * Execute hooks with arguments passed on in an array
	 *
	 * Function that will make the code "pluggable" as it will execute the hooks that have been set up by the customized code in order to run code or modify a value.
	 *
	 * @param string $hook_id : Hook reference
	 * @param array $args : Array of arguments to be passed to the callback function
	 * @return array : The new array that was returned by the hook
	 */
	public static function run_hooks_array($hook_id, $args) {
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

	/**
	 * Connect/setup a hook
	 *
	 * Function to be called by the customized code that want to connect a callback function into the original code.
	 *
	 * @param string $hook_id : Hook reference
	 * @param \closure|string $callback_function : A closure (anonymous function) or string with function to call
	 *	- the function will be passed the additional arguments that were passed to run_hooks()
	 * @param integer $priority : A number indicating the priority of this hook. Default is 10
	 * @return boolean : Always returns true
	 */
	public static function connect_hook($hook_id, $callback_function, $priority = 10) {
		if (!@isset($GLOBALS['sys']['hook_system']['hooks'])) {
			$GLOBALS['sys']['hook_system']['hooks'] = [];
		}
		if (!@isset($GLOBALS['sys']['hook_system']['hooks'][$hook_id][$priority])) {
			$GLOBALS['sys']['hook_system']['hooks'][$hook_id][$priority] = [];
		}

		$id = self::_hook_unique_callback_id($callback_function);
		$GLOBALS['sys']['hook_system']['hooks'][$hook_id][$priority][$id] = [
			'function' => $callback_function,
		];

		return true;
	}

	/**
	 * Disconnect a hook
	 *
	 * Function to be called by the customized code that want disconnect a callback it set up earlier.
	 *
	 * @param string $hook_id : Hook reference
	 * @param \closure|string $callback_function : A closure (anonymous function) or string with function to call
	 * @param integer $priority : A number indicating the priority of this hook. Default is 10
	 * @return boolean : false if not found or failure, true if disconnected successfully
	 */
	public static function disconnect_hook($hook_id, $callback_function, $priority = 10) {
		$id = self::_hook_unique_callback_id($callback_function);
		if (@isset($GLOBALS['sys']['hook_system']['hooks'][$hook_id][$priority][$id])) {
			unset($GLOBALS['sys']['hook_system']['hooks'][$hook_id][$priority][$id]);
			return true;
		} else {
			return false;
		}
	}

	/**
	 * Disconnect all hooks
	 *
	 * Function to be called by the customized code that want disconnect ALL callback it set up earlier for a given hook, optionally only those of a certain priority.
	 * @param string $hook_id : Hook reference
	 * @param integer $priority : A number indicating the priority of this hook. Default is 10
	 * @return void
	 */
	public static function disconnect_all_hooks($hook_id, $priority = false) {
		if (@isset($GLOBALS['sys']['hook_system']['hooks'][$hook_id])) {
			if (false === $priority) {
				$GLOBALS['sys']['hook_system']['hooks'][$hook_id] = [];
			} elseif (isset($GLOBALS['sys']['hook_system']['hooks'][$hook_id][$priority])) {
				$GLOBALS['sys']['hook_system']['hooks'][$hook_id][$priority] = [];
			}
		}
	}

	public static function _hook_unique_callback_id($callback_function) {
		if (is_string($callback_function)) {
			return $callback_function;
		}

		if ( is_object($callback_function) ) {
			// Closures are currently implemented as objects
			$callback_function = [$callback_function, ''];
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

	public static function system_error($msg, $varinfo = [], $directives = 'AUTO') {
		// Protect against infinite loops (in case a function (eg. send_email() or require_database() ) is called within system_error() that again creates an error)
		if (self::$system_error_in_process) {
			@self::run_hooks('jfw.looped_system_error', ['msg' => $msg, 'bt' => debug_backtrace() ]);
			error_log('JFW2: '. $msg);
			die($msg .' Please report.');  //the original error is not echoed but only this "lowest level" error
		}
		self::$system_error_in_process = true;

		if ($varinfo === false || $varinfo === null) $varinfo = [];
		if (!is_array($varinfo)) {
			$varinfo = ['ALERT!!!' => 'Configuration error. Incorrect varinfo argument for error function.'];
		}

		// ---------------------------------------------------------------
		// HANDLE DIRECTIVES
		// ---------------------------------------------------------------

		// Set defaults
		$silent = false;
		$register = true;
		$notify = false;
		$terminate = true;
		$severe = 'ERROR';
		$expire = false;

		// Get those that have been set at location of the error
		if (is_array($directives)) {
			//note: doing it this way we secure that nothing bad can happen to any of these variables
			if (array_key_exists('xsevere', $directives)) $directives['xsevere'] = strtoupper($directives['xsevere']);
			if ($directives['xnotify'] === true) $directives['xnotify'] = 'developer';  //this ONLY happens if one mistakenly set notify to true instead of one of the values! This is a safety against that.
			//
			if (array_key_exists('xsilent', $directives)) $silent = $directives['xsilent'];
			if (array_key_exists('xregister', $directives)) $register = $directives['xregister'];
			if (array_key_exists('xnotify', $directives) && ($directives['xnotify'] == 'developer' || $directives['xnotify'] == 'sysadmin' || $directives['xnotify'] === false)) $notify = $directives['xnotify'];
			if (array_key_exists('xterminate', $directives)) $terminate = $directives['xterminate'];
			if ($directives['xsevere'] == 'WARNING' || $directives['xsevere'] == 'ERROR' || $directives['xsevere'] == 'CRITICAL ERROR') $severe = $directives['xsevere'];
			if (array_key_exists('xexpire', $directives)) $expire = $directives['xexpire'];
		}

		// Override any other settings if global settings has been set
		if (self::$globaldata['errors_global_xsevere']) self::$globaldata['errors_global_xsevere'] = strtoupper(self::$globaldata['errors_global_xsevere']);
		if (self::$globaldata['errors_global_xnotify'] == true) self::$globaldata['errors_global_xnotify'] = 'developer';  //this ONLY happens if one mistakenly set notify to true instead of one of the values! This is a safety against that.
		//
		if (array_key_exists('errors_global_xsilent', self::$globaldata)) $silent = self::$globaldata['errors_global_xsilent'];
		if (array_key_exists('errors_global_xregister', self::$globaldata)) $register = self::$globaldata['errors_global_xregister'];
		if (array_key_exists('errors_global_xnotify', self::$globaldata) && (self::$globaldata['errors_global_xnotify'] == 'developer' || self::$globaldata['errors_global_xnotify'] == 'sysadmin' || self::$globaldata['errors_global_xnotify'] === false)) $notify = self::$globaldata['errors_global_xnotify'];
		if (array_key_exists('errors_global_xterminate', self::$globaldata)) $terminate = self::$globaldata['errors_global_xterminate'];
		if (self::$globaldata['errors_global_xsevere'] == 'WARNING' || self::$globaldata['errors_global_xsevere'] == 'ERROR' || self::$globaldata['errors_global_xsevere'] == 'CRITICAL ERROR') $severe = self::$globaldata['errors_global_xsevere'];
		if (array_key_exists('errors_global_xexpire', self::$globaldata)) $expire = self::$globaldata['errors_global_xexpire'];

		// Automated directives
		//    don't register errors caused by robots (they are usually trying to access a non-existing URL)
		if (is_object(self::$globaldata['statistics']) && self::$globaldata['statistics']->skip_useragent) {
			$is_robot = true;
		}
		if ($is_robot) {
			$register = false;
			$silent = false;
			$terminate = true;
		}

		// Manage derived directives (level above global)
		if ($severe == 'CRITICAL ERROR') {  //if error should be very severe force an instant-notification
			//note: this should generally not be considered in your coding practices as this is only meant to be a safe-guard against "bad" coding
			if (!$notify) {
				$notify = 'developer';
			}
		}
		if (is_array($directives)) {
			if (array_key_exists('xterminate', $directives)) { //if it is individually whether or not to continue after this error, override any other setting (even global setting)
				$terminate = $directives['xterminate'];
			}
		}


		// Count the number of errors in the current script (if error terminates the script this will of course always be only 1)
		if ($severe != 'WARNING') {  //do not count warnings as errors
			self::$system_error_count++;
		}


		// ---------------------------------------------------------------
		// GATHER ERROR INFORMATION
		// ---------------------------------------------------------------

		$cfg = self::get_class_defaults(__CLASS__);
		if (!$cfg['administrator_email'] || !$cfg['developer_email']) {
			echo 'JFW2 basic cfg missing. Reduced handling. '. $msg;
			exit;
		}

		// Get information about current user
		if (is_callable($cfg['errorlog_userdata'])) {
			$userinfo = $cfg['errorlog_userdata']();
		} else {
			$userinfo = null;
		}

		// Use different mechanism in a Yii application that uses the winternet\yii2\SystemError module
		if (defined('YII_BEGIN_TIME') && \Yii::$app->system && get_class(\Yii::$app->system) == 'winternet\yii2\SystemError') {
			self::$system_error_in_process = false;  //again allow new errors to occur
 			\Yii::$app->system->error($msg, $varinfo, ['silent' => $silent, 'register' => $register, 'notify' => $notify, 'terminate' => $terminate, 'severe' => $severe, 'expire' => $expire]);
			return;
		}

		$err_timestamp = date('M j Y, H:i:s');

		$errordata = 'Message: '. $msg . "\r\n";

		// Get specifically provided values
		$keys = array_keys($varinfo);
		foreach ($keys as $k) {
			if (is_array($varinfo[$k]) || is_object($varinfo[$k])) {
				ob_start();
				var_dump($varinfo[$k]);  //NOTE: not using var_export() because it can result in recursive death (http://stackoverflow.com/a/5039497/2404541)
				$val = ob_get_clean();
			} else {
				$val = $varinfo[$k];
			}
			$val = str_replace("\0", '', $val);
			$errordata .= $k .': '. $val ."\r\n";
		}

		// Write error occurance number
		if (self::$system_error_count > 1) {
			//if more errors occur in the same script execution notify what number the current error has
			$errordata .= 'Script error occurance number: '. self::$system_error_count . " (could be a derived error from or identical error as previous)\r\n";
		}

		$errordata .= "--- GENERAL ---------------------------\r\n";
		$errordata .= 'Date/time: '. $err_timestamp ."\r\n";
		$errordata .= 'URL: '. @$_SERVER['REQUEST_URI'] ."\r\n";

		// Process the backtrace information
		$backtrace = debug_backtrace();
		if (!@$GLOBALS['phpunit_is_running'] && is_array($backtrace)) {
			$backtraces_count = count($backtrace);
			if ($backtraces_count > 0) {
				if ($backtraces_count == 1) {  //if only 1 entry the reference is always the same file as in URL
					//just write the line number
					$errordata .= 'Line: '. $backtrace[0]['line'] ."\r\n";
				} else {  //more than one entry
					//write the different files, lines, and functions and their arguments that were used
					foreach ($backtrace as $key => $b) {
						$errordata .= 'Level '. ($key+1) .' file: '. $b['file'] .' / line '. $b['line'];
						if ($key != 0) {  //the first entry will always reference to this function (system_error) so skip that
							$errordata .= ' / '. $b['function'] .'(';
							if (count($b['args']) > 0) {
								$arr_args = [];
								foreach ($b['args'] as $xarg) {
									if (is_array($xarg) || is_object($xarg)) {
										try {
											$vartmp = var_export($xarg, true);
											$vartmp = str_replace('array (', ' array(', $vartmp);
										} catch (\Exception $e) {
											// use print_r instead when variable has circular references (which var_export does not handle)
											$vartmp = print_r($xarg, true);
										}
									} else {
										$arr_args[] = var_export($xarg, true);
									}
								}
								$errordata .= implode(', ', $arr_args);
							}
							$errordata .= ')';
						}
						$errordata .= "\r\n";
					}
				}
			}
		}

		// Process posted info
		$postkeys = array_keys($_POST);
		foreach ($postkeys as $ckey) {
			$poststring .= $ckey .'='. $_POST[$ckey] .' | ';
		}
		$poststring = ($poststring ? substr($poststring, 0, -3) : '--nothing--');  //clean up if there was posted data
		$errordata .= 'POST string: '. $poststring ."\r\n";

		/*
		// Process session info
		CURRENTLY WE DON'T WANT TO CLOG THE ERRORS WITH ALL THIS INFORMATION
		$sess_pairs = '';
		foreach ($_SESSION as $sess_var => $sess_value) {
			if (strlen($sess_value) < 50) {  //skip the very long values!
				$sess_pairs .= $sess_var .'='. $sess_value .' | ';
			}
		}
		$errordata .= 'Session: '. $sess_pairs . "\r\n";
		*/

		// Process other info
		$errordata .= 'Referer: '. @$_SERVER['HTTP_REFERER'] ."\r\n";
		$errordata .= 'User agent: '. @$_SERVER['HTTP_USER_AGENT'] . "\r\n";
		$errordata .= 'IP: '. (!empty($_SERVER['HTTP_X_FORWARDED_FOR']) ? $_SERVER['HTTP_X_FORWARDED_FOR'] : @$_SERVER['REMOTE_ADDR']) . "\r\n";
		if (is_callable($cfg['errorlog_extra'])) {
			$extradata = (array) $cfg['errorlog_extra']();
			foreach ($extradata as $clabel => $cdata) {
				$errordata .= $clabel .': '. $cdata . "\r\n";
			}
		}
		$errordata .= 'HTTP Accept language: '. @$_SERVER['HTTP_ACCEPT_LANGUAGE'] . "\r\n";
		if ($userinfo === null) {
			$errordata .= "User: DATA NOT PROVIDED\r\n";
		} else {
			if ($userinfo['logged_in']) {
				$errordata .= "--- USER ------------------------------\r\n";
				$errordata .= 'Username: '. (!@$userinfo['is_emulating'] ?  $userinfo['username']  :  'EMULATING '. $userinfo['username'] .' BY '. $userinfo['emulator_username']) ."\r\n";
				$errordata .= 'Full name: '. $userinfo['fullname'] ."\r\n";
				$errordata .= 'Access levels (text): '. implode(', ', $userinfo['accesslevels']) . "\r\n";
				$errordata .= 'User ID: '. $userinfo['userID'] . "\r\n";
			} else {
				$errordata .= "User: USER WAS NOT LOGGED IN\r\n";
			}
		}
		$errordata .= "--- DIRECTIVES ------------------------\r\n";
		$errordata .= 'Severeness: '. $severe ."\r\n";
		$errordata .= 'Silent error: '. ($silent ? 'Yes, user not was notified' : ($notify ? 'No (instant-notification is ON, you might receive this twice)' : 'No (the user reported this error)') ) ."\r\n";  //If notify=true the output will be in both emails, If notify=false we are sure that it comes from a user reported error
		$errordata .= 'Registered in file/database: '. ($register ? 'Yes' : 'No' ) ."\r\n";
		$errordata .= 'Script terminated: '. ($terminate ? 'Yes' : 'No' ) ."\r\n";
		if ($expire) {
			$errordata .= 'Expiration: '. (int) $expire ."d\r\n";
		}

		$errordata .= "\r\n";
		$errordata .= "====================================[ ". $err_timestamp ." ]===========\r\n";


		// CHECK NUMBER OF ERRORS
		//  if we have more than 10 errors in this script execution, it is very likely to be derived errors and we don't register and report more errors, and we terminate the script
		if (self::$system_error_count > self::$max_errors) {
			mail::send_email($cfg['administrator_email'], $cfg['system_name'], $cfg['developer_email'], 'ERRORS - too many!', 'Too many errors, more than '. self::$max_errors .', was encountered in '. $_SERVER['REQUEST_URI'] .' and further execution was terminated.', false);
			echo 'Too many errors occured, further processing was terminated and developer was notified.';
			exit;
		}

		// REGISTER ERROR IN FILE OR DATABASE
		if ($register) {
			if ($cfg['log_errors_to'] == 'file' && $cfg['error_logfile']) {
				$fp = @fopen($cfg['error_logfile'], 'a');
				@fwrite($fp, $errordata ."\r\n");
			} elseif ($cfg['log_errors_to'] == 'database') {
				self::require_database();
				//   Create table if it does not exist
				if (!@$_SESSION['_jfw_error_table_created']) {  //only run this check once per session
					$createtblSQL = "CREATE TABLE IF NOT EXISTS ". $cfg['errorlog_table'] ." (
					  `errorID` int(10) unsigned NOT NULL auto_increment,
					  `err_msg` varchar(255),
					  `url` varchar(255) NOT NULL,
					  `errorinfo` text NOT NULL,
					  `fullname` varchar(100),
					  `localID` varchar(60),
					  `date_added` datetime NOT NULL,
					  `expire_days` SMALLINT UNSIGNED NULL DEFAULT NULL,
					  PRIMARY KEY (`errorID`)
					)";
					$db_createtbl = mysqli_query($GLOBALS['_jfw_db_connection'], $createtblSQL) or error_log('JFW2: Database update for creating error logging table failed while logging:'. PHP_EOL . $errordata) and die('Database update for creating error logging table failed.'); //mysqli_error($GLOBALS['_jfw_db_connection'])
					@$_SESSION['_jfw_error_table_created'] = true;
				}
				$sql = [];
				$sql[] = "`errorinfo` = '". self::sql_esc(mb_substr($errordata, 0, 65000)) ."'";
				$sql[] = "`err_msg` = '". self::sql_esc(mb_substr($msg, 0, 255)) ."'";
				$sql[] = "`url` = '". self::sql_esc(substr($_SERVER['REQUEST_URI'], 0, 255)) ."'";
				if (@$userinfo['logged_in']) {
					if ($userinfo['fullname']) {
						$sql[] = "`fullname` = '". self::sql_esc(mb_substr($userinfo['fullname'], 0, 100)) ."'";
					}
					if ($userinfo['userID']) {
						$sql[] = "`localID` = '". self::sql_esc($userinfo['userID']) ."'";
					}
				}
				if ($expire) {
					$sql[] = "`expire_days` = '". (int) $expire ."'";
				}
				$regerrSQL = "INSERT INTO ". $cfg['errorlog_table'] ." SET `date_added` = NOW()";
				if (count($sql) > 0) {
					$regerrSQL .= ", ". implode(',', $sql);
				}
				$regerrSQL = preg_replace('/[^\x20-\x7E]/', '?', $regerrSQL);  //convert non-ASCII characters in binary data that might have been passed as arguments to PHP functions
				$db_regerr = mysqli_query($GLOBALS['_jfw_db_connection'], $regerrSQL) or error_log('JFW2: Database update for registering error details failed while logging:'. PHP_EOL . $errordata) and  die('Database update for registering error details failed.'); //mysqli_error($GLOBALS['_jfw_db_connection'])
			}
		}

		// SAVE ERRORDATA IN SESSION for later use (notification email)
		@$_SESSION['sys_errordata_mail'] = "ERROR INFORMATION:\r\n---------------------------------------\r\n";
		$_SESSION['sys_errordata_mail'] .= $errordata;

		// NOTIFY
		if ($notify) {
			switch ($notify) {
			case 'developer':
				$to_email = [$cfg['developer_name'], $cfg['developer_email']];
				break;
			case 'sysadmin':
				if ($cfg['administrator_email']) {
					$to_email = ['System Administrator (error handling)', $cfg['administrator_email']];
				} else {
					$to_email = [$cfg['developer_name'], $cfg['developer_email']];
					$_SESSION['sys_errordata_mail'] = "OBS! Configuration error. This error should have been sent to sysadmin but he has not been defined!\r\n\r\n". $_SESSION['sys_errordata_mail'];
				}
				break;
			default:
				$_SESSION['sys_errordata_mail'] = 'OBS! Configuration error. The notification recipient for this error was not defined in '. __FUNCTION__ .'(). Tag used was: '. $notify ."\r\n\r\n". $_SESSION['sys_errordata_mail'];
				$to_email = $cfg['developer_email'];
			}
			$subject = $severe .' in '. $_SERVER['REQUEST_URI'] .' (instant-notif)';
			@mail::send_email($cfg['administrator_email'], $cfg['system_name'], $to_email, $subject, $_SESSION['sys_errordata_mail'], false);
		}

		// MAKE USER MESSAGE
		if ($cfg['custom_user_error_msgHTML']) {
			$cfg['custom_user_error_msgHTML'] = str_replace('%%errormessage%%', htmlentities($msg), $cfg['custom_user_error_msgHTML']);
			$cfg['custom_user_error_msgHTML'] = str_replace('%%timestamp%%', urlencode($err_timestamp), $cfg['custom_user_error_msgHTML']);
			$user_err_msg  = $cfg['custom_user_error_msgHTML'];
		} else {
			$user_err_msg  = '<p>';
			$user_err_msg .= '<div style="border: solid darkred 1px; padding: 10px"><b>Sorry, there was a problem...</b><div style="font-size: 14px; color: darkred"><b>';
			$user_err_msg .= htmlentities($msg);
			$user_err_msg .= '</b></div><br/>';
			$user_err_msg .= 'Please contact webmaster '. htmlentities($cfg['administrator_name']) .' and provide timestamp '. $err_timestamp .', if you think this is a persistent error.';
			$user_err_msg .= '<br/><br/>';
			$user_err_msg .= 'When reporting errors <b>please</b> provide as exact and detailed error descriptions as possible.</div>';
		}

		// MISC
		self::$system_error_in_process = false;  //again allow new errors to occur

		// OUTPUT/RETURN
		if ($is_robot) {
			ob_clean();
			echo '<html><head><meta http-equiv="content-type" content="text/html; charset=utf-8" />';
			echo '<meta name="robots" content="noarchive, nofollow, noindex">';
			echo '</head><body>';
			echo 'Error occurred: '. htmlentities($msg) .' (this robot is probably trying to access an invalid URL)';
			echo '</body></html>';
			exit;
		} elseif (@$GLOBALS['sys_interface'] == 'webservice') {
			if (!$terminate) {
				//if not terminating don't do anything concerning the output
			} else {
				global $ws_server;
				if (is_object($ws_server)) {
					$ws_server->raise_error($msg);
				} else {
					//in case it's a custom webservice not using ws.php
					echo $user_err_msg;
					if ($cfg['debug_level']) {
						echo '<pre>'. htmlentities($_SESSION['sys_errordata_mail']) .'</pre><hr color="red" size="3">';
					}
				}
				exit;
			}
		} elseif (PHP_SAPI == 'cli' || @$GLOBALS['sys_interface'] == 'cli') {
			if (!$terminate) {
				//if not terminating don't do anything concerning the output
			} else {
				echo PHP_EOL .'ERROR: '. $msg . PHP_EOL;
				if ($cfg['debug_level']) {
					echo '   DEBUG: '. print_r($_SESSION['sys_errordata_mail'], true) . PHP_EOL;
				}
				exit(1);
			}
		} else {
			if ($silent) {
				if ($terminate) {
					exit;
				}
			} else {
				//only write/return user-friendly error description if the error is NOT silent
				if ($terminate) {
					echo $user_err_msg;
					if ($cfg['debug_level']) {
						echo '<pre>'. htmlentities($_SESSION['sys_errordata_mail']) .'</pre><hr color="red" size="3">';
					}
					exit;
				} else {
					return $user_err_msg;
				}
			}
		}
	}

	/**
	 * Send an email to the developer
	 *
	 * @param string $who (`developer`|`admin`) : Notify developer or webmaster/system administrator?
	 * @param string $subj : Subject of the message
	 * @param string|array $message : Body of the message. An array will be converted to list key/value pairs.
	 * @param string|array $reference (opt.) : A unique reference to the message. Used to send this notification only once when this function is called multiple times with the same reference.
	 *		- alternatively use an array instead with these keys:
	 *			- `ref` (req.) : the unique reference
	 *			- `persist` (opt.) : set to true to use set_buffer_value() to globally remember this reference (instead of within current session only)
	 *			- `expire` (opt.) : make it expire after a certain time, by specifying one of the following formats according to set_buffer_value(): (only effective together with `persist`)
	 *				- `2017-11-05:` : make it expire on this date (yyyy-mm-dd)
	 *				- `6h:` : make it expire in 6 hours
	 *				- `2d:` : make it expire in 2 days
	 *			- note that the system_buffer must have been created beforehand
	 * @return boolean : True if email sent, false if email not sent (due to being a "duplicate")
	 */
	public static function notify_webmaster($who, $subj, $message, $reference = false) {
		if (is_array($reference)) {
			$use_systembuffer = ($reference['persist'] ? true : false);

			$expire = false;
			if (@$reference['expire']) {
				$expire = $reference['expire'];
			}

			$reference = @$reference['ref'];
			if (!$reference) {
				self::system_error('Missing reference for recording we have sent webmaster a message.');
			}
		} else {
			$use_systembuffer = false;
		}

		// Don't send duplicate notifications
		if ($use_systembuffer) {
			if ($reference && system::get_buffer_value('jfwnotifd'. $reference) && !@$GLOBALS['_send_all_webmaster_notifs']) {
				return false;
			}
		} else {
			if ($reference && @$_SESSION['_jfw_webmaster_notifd_'. $reference] && !@$GLOBALS['_send_all_webmaster_notifs']) {
				return false;
			}
		}

		$cfg = self::get_class_defaults(__CLASS__);
		$bt = debug_backtrace();

		if ($who == 'developer') {
			$to = [$cfg['developer_name'], $cfg['developer_email']];
			$to2 = 'developer';
		} elseif ($who == 'admin') {
			$to = [$cfg['administrator_name'], $cfg['administrator_email']];
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
		$body .= "Stack Trace:\r\n";
		foreach ($bt as $bt_key => $bt_value) {
			$body .= $bt_key .') '. basename($bt[$bt_key]['file']) . ($bt[$bt_key]['line'] ? ':'. $bt[$bt_key]['line'] : '') . ($bt[$bt_key+1]['function'] ? ' - in '. $bt[$bt_key+1]['class'] . $bt[$bt_key+1]['type'] . $bt[$bt_key+1]['function'] .'()' : '') ."\r\n";
		}
		$body .= "\r\nURI: ". @$_SERVER['REQUEST_URI'];  // $bt[0]['file'] is the lowest in the stack and this is the highest
		if ($reference) {
			$body .= "\r\nReference: ". $reference;
		}
		mail::send_email($cfg['administrator_email'], $cfg['system_name'], $to, $subj, $body);

		if ($reference) {
			if ($use_systembuffer) {
				system::set_buffer_value('jfwnotifd'. $reference, '1', $expire);
			} else {
				@$_SESSION['_jfw_webmaster_notifd_'. $reference] = true;
			}
		}

		return true;
	}


	//////////////////////////// Extra utility functions ////////////////////////////

	/**
	 * @param string|integer|float $integer
	 */
	public static function is_integer($variable) {
		if (filter_var($variable, FILTER_VALIDATE_INT) === false) {
			return false;
		}
		return true;
	}

	/**
	 * Searches for a given string (full or part) in an array, case-insensitive
	 */
	public static function arristr($haystack = '', $needle = []) {
		foreach ($needle as $n) {
			if (stristr($haystack, $n) !== false) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Check if one or more values from one array exists in another
	 *
	 * If needle is a string it will be auto-converted to an array so that it can also be used instead of in_array().
	 *
	 * @param array $arr_needle : Array of values to look for
	 * @param array $array : Array to search
	 * @return boolean
	 */
	public static function any_in_array($arr_needle, $array) {
		if (is_string($arr_needle)) $arr_needle = [$arr_needle];
		if (!is_array($arr_needle)) self::system_error('Invalid needle for searching array.');
		if (!is_array($array)) self::system_error('Invalid array to search.');
		$a = array_intersect($arr_needle, $array);
		return !empty($a);
	}

	/**
	 * Search a two-dimensional array, or array with objects, for a certain key/value pair (= 'column'), case-insensitive
	 *
	 * This differs from array_keys(array, search_arg) and in_array() in that this searches on the SECOND level, and supports objects on that level as well.
	 *
	 * @param array $array : Array to search
	 * @param string $key : Key which need to contain the value that we search for
	 * @param mixed $value : The value we want to find
	 * @return mixed : If found: the key that contained the key/value pair (= 1st level key). If not found: false
	 */
	public static function array_search_column(&$array, $key, $value) {
		if (!is_array($array)) {
			self::system_error('Parameter for column search is not an array.', ['Array' => $array]);
		} else {
			$value_lc = mb_strtolower($value);
			foreach ($array as $curr_key => $curr_val) {
				if (gettype($curr_val) == 'object') {
					if (mb_strtolower($curr_val->$key) == $value_lc) {
						return $curr_key;
					}
				} else {
					if (mb_strtolower($curr_val[$key]) == $value_lc) {
						return $curr_key;
					}
				}
			}
			return false;  //if we get this far the key/value pair does not exist
		}
	}

	public static function txt($tag, $default, $other = null) {
		if (defined('YII_BEGIN_TIME')) {
			// Can't do translation here as it won't be picked up by the text collector. Must be done manually where the text is used.
			return $default;
		} else {
			// Just return default for now!
			return $default;
		}
	}

	/**
	 * Parses a string with multiple translations of a piece of text
	 *
	 * Uses the session language but can be overridden by $GLOBALS['_override_current_language']
	 *
	 * @param string $str : string in the format: `EN=Text in English ,,, ES=Text in Spanish`
	 *	- unlimited number of translations
	 *	- upper case of language identifier is optional
	 *	- spaces are allowed around both identifiers and texts (will be trimmed)
	 * @return string : If no matches found, the raw string is returned. If language is not found, the first language is returned.
	 */
	public static function txtdb($str) {
		$str = (string) $str;
		if (!$str) {
			return $str;
		} else {
			if (preg_match('/,,,\\s*[a-zA-Z]{2}\\s*=/'.(mb_internal_encoding() == 'UTF-8' ? 'u' : ''), $str)) {
				$str = explode(',,,', $str);
				foreach ($str as &$a) {
					if (preg_match('|^\\s*([a-zA-Z]{2})\\s*=\\s*(.*?)\\s*$|s'.(mb_internal_encoding() == 'UTF-8' ? 'u' : ''), $a, $match)) {
						$clang = strtolower($match[1]);
						if (@$_SESSION['runtime']['currlang'] == $clang || ($GLOBALS['_override_current_language'] && $GLOBALS['_override_current_language'] == $clang) ) {
							return $match[2];
						}
					}
				}
				$b = explode('=', $str[0], 2);  //fallback to first language
				return trim($b[1]);
			} else {
				return $str;
			}
		}
	}

	/**
	 * Insert a multipart translation into it's template
	 *
	 * @param string $template : The final template where fields of the format `#FIELDNAME#` are to be replaced with the translation
	 *	- example:
	 * ```
	 *	<h1>#TITLE#</h1>
	 *	<p>#PARAGRAPH1#</p>
	 *	<p style="margin: 2em 0 2em 0;padding: 0;color: #606060;font-family: monospace, Courier New, Courier;font-size: 20px;line-height: 150%;text-align: center;">
	 *		<span style="border: 1px solid #E4E4E4; border-radius: 3px; font-weight: bold; padding: 5px 30px"><a href="%%resetpwURL%%">#RESET-BUTTON#</a></span>
	 *	</p>
	 * ```
	 * @param string $translation : String with the fields in the format `#FIELDNAME:` followed by the translation of the text for that field (whitespace around the fields and values is automatically trimmed)
	 *	- example:
	 * ```
	 *	#TITLE:
	 *	Reset Password
	 *	#PARAGRAPH1:
	 *	Someone requested a password reset for this email address (%%email%%%%userID_text%%) at the website. Hopefully it was you.
	 *	If not, please make sure no one else has access to your email address - then you can safely ignore this email.
	 *	#PARAGRAPH2:
	 *	To set the new password please click below.
	 *	#RESET-BUTTON:
	 *	Reset Password
	 * ```
	 * @return string
	 */
	public static function parse_multipart_translation($template, $translation) {
		if (preg_match_all("/#[A-Z0-9\\-]+:/". core::$preg_u, $translation, $matches, PREG_OFFSET_CAPTURE) > 0) {
			foreach ($matches[0] as $key => $match) {
				$fieldname = trim($match[0], '#:');
				$start_pos = $match[1] + strlen($match[0]);  //cannot use mb_strlen() because PREG_OFFSET_CAPTURE works in bytes, not characters. See also https://bugs.php.net/bug.php?id=37391 and https://stackoverflow.com/questions/1725227/preg-match-and-utf-8-in-php
				if (array_key_exists($key+1, $matches[0])) {
					$length = $matches[0][$key+1][1] - $start_pos;
				} else {
					$length = strlen($translation);   //may NOT be NULL! (see docs) So just set a high number.
				}
				$text = trim(substr($translation, $start_pos, $length));  //cannot use mb_substr() because PREG_OFFSET_CAPTURE works in bytes, not characters

				// Insert text into template
				$template = str_replace('#'. $fieldname .'#', $text, $template);
			}
		}
		return $template;
	}

	/**
	 * Improved version of native json_decode() that allows full-line comments in JSON string
	 */
	public static function json_decode($string, $associative = null, $depth = 512, $flags = 0) {
		$string = preg_replace("|^\\s*//.*[\\r\\n]+|m", '', $string);
		return json_decode($string, $associative, $depth, $flags);
	}

	/**
	 * Get a system setting from database
	 *
	 * @param string $name : Name of system setting to get
	 * @param array $options : Options available:
	 *	- `no_error` : don't raise error if setting is not found, but return false
	 * @return string|boolean : The database field value, or false if setting was not found
	 */
	public static function get_system_setting($name, $options = []) {
		if (!isset($GLOBALS['cache']['system_settings'])) {
			$GLOBALS['cache']['system_settings'] = [];
			self::require_database();
			$sql = "SELECT settingname, settingvalue FROM ". self::get_class_defaults(__CLASS__, 'db_table_system_settings');
			$db_query =& self::database_query($sql, 'Database query for getting system settings failed.');

			$GLOBALS['cache']['system_settings'] = [];
			if (mysqli_num_rows($db_query) > 0) {
				while ($v = mysqli_fetch_assoc($db_query)) {
					$GLOBALS['cache']['system_settings'][$v['settingname']] = $v['settingvalue'];
				}
			}
		}
		if (!array_key_exists($name, $GLOBALS['cache']['system_settings'])) {
			if (!@$options['no_error']) {
				self::system_error('Configuration error. A system setting could not be found.', ['Name' => $name]);
			} else {
				return false;
			}
		}
		return $GLOBALS['cache']['system_settings'][$name];
	}

	/**
	 * Get the name of the function calling the current function/scope
	 *
	 * @return string|boolean : Name of function, or false if it was not called from a function
	 */
	public static function get_parent_function($f = 1) {
		$parent_info = debug_backtrace();
		$parent_function = @$parent_info[1 + $f]['function'];  //the parent function of the function that called this function, has the index no. 2 in the debug array. Higher levels has higher numbers.
		if ($parent_function) {
			return $parent_function;
		} else {
			return false;
		}
	}

	/**
	 * Generate URL for this same page but with modified query string parameters according to the specified array
	 *
	 * @param array $array : Associative array with keys being query string variable name and value of course being the corresponding value
	 *	- leave empty to generate the same URL as current one (but without the one-time variables)
	 *	- to delete an existing parameter set the value to null
	 *	- to make the variable a one-time variable only, set the value to an array where first item is the actual value and the second item is `once` (string). Then that variable will not be kept on next page when calling this method.
	 *	- a dot (.) is not allowed in the variable name of one-time variables because it is used as a separator (you could use dash (-) instead)
	 * @param array $options : Options available:
	 *	- `querystring_only` : only generate the query string (excluding path and `?`)
	 *	- `varname` : use this name for the query string variable containing one-time values (instead of the default `1`)
	 * @return string : URL
	 */
	public static function page_url($array = [], $options = []) {
		if (!is_array($array)) {
			self::system_error('Invalid array for generating query string.');
		}

		$onetime_varname = '1';
		if (@$options['varname']) {
			$onetime_varname = @$options['varname'];
		} elseif (@$GLOBALS['_jfw_onetime_varname']) {
			$onetime_varname = $GLOBALS['_jfw_onetime_varname'];
		}

		$qs = $_GET;

		if (@$qs[$onetime_varname]) {
			foreach (explode('.', $qs[$onetime_varname]) as $onetimevar) {
				unset($qs[$onetimevar]);
			}
		}
		unset($qs[$onetime_varname]);

		$onetime_vars = [];
		foreach ($array as $name => $value) {
			if (is_array($value)) {
				$opt = $value[1];
				$value = $value[0];

				if (strpos($opt, 'once') !== false) {
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

		if (@$options['querystring_only']) {
			if (empty($qs)) {
				return '';
			} else {
				return http_build_query($qs);
			}
		} else {
			$url = (string) @$_SERVER['REQUEST_URI'];
			$quest_pos = strpos($url, '?');
			if ($quest_pos) {
				$url = substr($url, 0, $quest_pos);
			}
			if (empty($qs)) {
				return $url;
			} else {
				return $url .'?'. http_build_query($qs);
			}
		}
	}
}
