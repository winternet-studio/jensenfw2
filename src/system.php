<?php
/*
This file contains functions related to system/programming/PHP
*/
namespace winternet\jensenfw2;

class system {
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
		- $expiration : the expiration date of this value in MySQL format (yyyy-mm-dd)
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
			if (preg_match('|^\\d{2,4}-\\d{1,2}-\\d{1,2}$|', $expiration)) {
				//do nothing, use raw value
			} elseif (preg_match('/^(\\d+)(h|d)$/', $expiration, $match)) {
				switch ($match[2]) {
				case 'h':
					$expiration = date('Y-m-d H:i:s', time() + $match[1]*60*60);
					break;
				case 'd':
					$expiration = date('Y-m-d H:i:s', time() + $match[1]*24*60*60);
					break;
				default:
					core::system_error('Undefined unit for expiration date for setting a buffer value.', array('Unit' => $unit) );
				}
			} elseif ($expiration == 'NOW') {
				$expiration = '2000-01-01';
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
			$sql = "DELETE FROM `". $cfg['db_name'] ."`.`". $cfg['db_table_buffer'] ."` WHERE tmpd_date_expire IS NOT NULL AND tmpd_date_expire < CURDATE()";
			core::database_result($sql, false, 'Database query for cleaning temporary buffer table failed.');
			$_SESSION['_jfw_cleaned_buffer'] = true;
		}

		// Get the value
		$sql = "SELECT tmpd_value FROM `". $cfg['db_name'] ."`.`". $cfg['db_table_buffer'] ."` WHERE tmpd_key = '". core::sql_esc($key) ."' AND (tmpd_date_expire IS NULL OR tmpd_date_expire > NOW())";
		return core::database_result($sql, 'onevalue', 'Database query for getting value from temporary buffer table failed.');
	}

	public static function shell_command($command) {
		/*
		DESCRIPTION:
		- execute a shell command and return the output
		- OBS! For some reason you cannot call php.exe using this without looping the output several times...!
		INPUT:
		- $command : shell command/DOS command/command line
		OUTPUT:
		- output created by the program called
		- return status is found in $GLOBALS['command_line_return_status']
		*/
		ob_start();
		unset($GLOBALS['command_line_return_status']);
		passthru($command, $GLOBALS['command_line_return_status']);
		$output = ob_get_clean();
		return $output;
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
