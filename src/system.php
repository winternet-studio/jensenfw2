<?php
/*
This file contains functions related to system/programming/PHP
*/
namespace winternet\jensenfw2;

class system {
	public static $command_line_return_status = null;

	public static function class_defaults() {
		$cfg = array();

		$corecfg = core::get_class_defaults('core');
		$cfg['db_server_id'] = '';  //database server ID as defined in core (empty for default)
		$cfg['db_name'] = $corecfg['databases'][0]['db_name'];
		$cfg['db_table_buffer'] = 'system_buffer';

		return $cfg;
	}

	public static function user_setting($name, $value) {
		/*
		DESCRIPTION:
		- register a user setting
		- use with caution! It is cookie based. If cookies are disabled it won't work.
		- all data must be as short as possible since there are big limitations in storing data in cookies
		- should maybe add option for storing it in database instead
		INPUT:
		- $name : name of cookie (as short as possible)
		- $value : cookie value (false or a length of zero will delete the setting) (as short as possible)
		- read notes above!
		*/
		$host = strtolower($_SERVER['HTTP_HOST']);
		if ($host == 'localhost') {
			//running on localhost
			$domain = false;
		} elseif (is_numeric(substr($host, strlen($host)-1) ) ) {  //if last character is a number
			//domain is an IP address
			$domain = false;
		} else {
			//domain is a domain name (either 'subname.mydomain.tld' or 'mydomain.tld')
			$dotcount = substr_count($host, '.');
			if ($dotcount == 1) {
				$domain = '.'. $host;
			} elseif ($dotcount >= 2) {
				$hostparts = explode('.', $host);
				$hostparts = array_reverse($hostparts);  //TLD is now index 0, main domain name is index 1
				$domain = '.'. $hostparts[1] .'.'. $hostparts[0];
			}
		}
		if ($value === false || strlen($value) == 0) {
			//delete cookie
			setcookie($name, $value, 1, '/', $domain);
			unset($_COOKIE[$name]);
		} else {
			//create cookie
			setcookie($name, $value, time()+60*60*24* 365, '/', $domain);  //for now set for one year...
			$_COOKIE[$name] = $value;  //simulate that cookie has come from browser on this same page
		}
	}

	public static function set_buffer_value($key, $value, $expiration = false) {
		/*
		DESCRIPTION:
		- set a value in the temporary buffer table
		- good for information that doesn't fit into the existing table structure and is temporary anyway
		INPUT:
		- $key : number or string with the key
		- $value : number or string with the value to store
		- $expiration : the expiration date (UTC) of this value in MySQL format (yyyy-mm-dd or yyyy-mm-dd hh:mm:ss)
			- or number of hours to expire (eg. 6 hours: '6h')
			- or days to expire (eg. 14 days: '14d')
			- or 'NOW' in order to delete a buffer value before current expiration (when overwriting an existing one)
		OUTPUT:
		- nothing
		*/
		$cfg = core::get_class_defaults(__CLASS__);
		core::require_database($cfg['db_server_id']);

		// Auto-overwrite any record with the same key
		$sql = "REPLACE INTO `". $cfg['db_name'] ."`.`". $cfg['db_table_buffer'] ."` SET tmpd_key = '". core::sql_esc($key) ."', tmpd_value = '". core::sql_esc($value) ."'";
		if ($expiration) {
			if (preg_match('|^\\d{2,4}-\\d{1,2}-\\d{1,2}$|', $expiration) || preg_match('|^\\d{2,4}-\\d{1,2}-\\d{1,2}\\s+\\d{1,2}:\\d{2}:\\d{2}$|', $expiration)) {
				//do nothing, use raw value
			} elseif (preg_match('/^(\\d+)(h|d)$/', $expiration, $match)) {
				switch ($match[2]) {
				case 'h':
					$expiration = gmdate('Y-m-d H:i:s', time() + $match[1]*60*60);
					break;
				case 'd':
					$expiration = gmdate('Y-m-d H:i:s', time() + $match[1]*24*60*60);
					break;
				default:
					core::system_error('Undefined unit for expiration date for setting a buffer value.', array('Unit' => $unit) );
				}
			} elseif ($expiration == 'NOW') {
				$expiration = '2000-01-01 00:00:00';
			} else {
				core::system_error('Invalid expiration date for setting a value in temporary buffer table.');
			}
			$sql .= ", tmpd_date_expire = '". $expiration ."'";
		}
		core::database_result($sql, false, 'Database query for setting value in temporary buffer table failed.');
	}

	public static function get_buffer_value($key) {
		/*
		DESCRIPTION:
		- get a value from the temporary buffer table
		- also cleans the buffer table once per session
		INPUT:
		- $key : key to get the value for
		OUTPUT:
		- string with the value
		- or empty array if key was not found
		*/
		$cfg = core::get_class_defaults(__CLASS__);
		core::require_database($cfg['db_server_id']);

		// Clean up the buffer once per session
		if (!$_SESSION['_jfw_cleaned_buffer']) {
			$sql = "DELETE FROM `". $cfg['db_name'] ."`.`". $cfg['db_table_buffer'] ."` WHERE tmpd_date_expire IS NOT NULL AND tmpd_date_expire < UTC_TIMESTAMP()";
			core::database_result($sql, false, 'Database query for cleaning temporary buffer table failed.');
			$_SESSION['_jfw_cleaned_buffer'] = true;
		}

		// Get the value
		$sql = "SELECT tmpd_value FROM `". $cfg['db_name'] ."`.`". $cfg['db_table_buffer'] ."` WHERE tmpd_key = '". core::sql_esc($key) ."' AND (tmpd_date_expire IS NULL OR tmpd_date_expire > UTC_TIMESTAMP())";
		return core::database_result($sql, 'onevalue', 'Database query for getting value from temporary buffer table failed.');
	}

	/**
	 * Execute a shell command and return the output
	 *
	 * OBS! For some reason you cannot call php.exe using this without looping the output several times...
	 *
	 * @param string $command : Shell command/DOS command/command line. MUST have been properly escaped!
	 * @param array $options : Available options:
	 *   - `niceness` (number) (opt.) : add a `nice` value (process priority) to the command (mostly useful when running command in background)
	 *     - values can range from -20 to 19 (higher means lower priority (= nicer to the system resources))
	 *     - root privileges is required to use values below 0.
	 *     - default is 0
	 *   - `background` : set true to run the command in the background. The process id is then returned
	 *   - `output_file` : path (incl. file) to send output to from the background process (requires background=true)
	 *   - `append` : set true to append output from the background process to the output file (requires background=true and output_file set)
	 *   - `skip_exitcode` : set true to not append exit code to the output file (requires background=true and output_file set)
	 *   - `id` : set an ID that will be prepended to the exit code so it becomes eg. `412:EXITCODE:0` instead of just `EXITCODE:0`
	 *   TODO: implement delayed execution using `at` (it doesn't work on Amazon Linux though)
	 *
	 * @return string|array : output from the command, or for background processes an array with the actually used command in `cmd` and the process id in `pid`
	 *   - for non-background command return status is found in `system::$command_line_return_status`
	 */
	public static function shell_command($command, $options = []) {
		if ($options['niceness']) {
			$command = 'nice -'. (int) $options['niceness'] .' '. $command;
		}

		if ($options['background']) {
			if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
				throw new \Exception('The method shell_command() with option background=true is not yet supported on Windows.');
			}

			// Source: https://stackoverflow.com/questions/45953/php-execute-a-background-process
			// Composer package: https://github.com/diversen/background-job
			if ($options['output_file']) {
				if ($options['append']) {
					$redir = '>>';
				} else {
					$redir = '>';
				}
				if ($options['id']) {
					$options['id'] = preg_replace("/[^a-zA-Z0-9_\\-\\.]/", '', $options['id']);
					$options['id'] .= ':';
				}
				if ($options['skip_exitcode']) {
					$command = sprintf("%s ". $redir ." %s 2>&1 & echo $!", $command, escapeshellarg($options['output_file']));
				} else {
					$command = sprintf("(%s; printf \"\\n". $options['id'] ."EXITCODE:$?\") ". $redir ." %s 2>&1 & echo $!", $command, escapeshellarg($options['output_file']));
				}
			} else {
				$command = sprintf("%s >/dev/null 2>&1 & echo $!", $command);
			}
			exec($command, $pid_array);
			// exec(sprintf("%s > %s 2>&1 & echo $! >> %s", $cmd, $options['output_file'], $pidfile));  //write pid to a file instead
			return ['cmd' => $command, 'pid' => $pid_array[0]];
		} else {
			ob_start();
			static::$command_line_return_status = null;
			passthru($command, static::$command_line_return_status);
			$output = ob_get_clean();
			return $output;
		}
	}

	public static function check_php_syntax($file) {
		/*
		DESCRIPTION:
		- check for syntax errors in PHP file
		- source: http://feeds.feedburner.com/phpadvent (PHP Advent 2008)
		INPUT:
		- $file : file to check
		OUTPUT:
		- true if no errors, false if syntax errors
		- output from php.exe can be found in $GLOBALS['checksyntax_output']
		*/
		$filename_pattern = '/\.php$/';
		if (!preg_match($filename_pattern, $file)) {
			core::system_error('File to check syntax on is not a PHP file.', array('File' => $file) );
			return;
		}
		$lint_output = array();
		$GLOBALS['checksyntax_output'] = '';
		exec('php -l '. escapeshellarg($file), $lint_output, $return);
		if ($return == 0) {
			return true;
		} else {
			$GLOBALS['checksyntax_output'] = implode("\r\n", $lint_output);
			return false;
		}
	}
}
