<?php
/*
This file contains functions related to general network processes ( Internet / HTTP / TCP/IP etc. - but not FTP (they are in seperate file) )
*/
namespace winternet\jensenfw2;

class network {
	public static function class_defaults() {
		$cfg = array();

		$corecfg = core::get_class_defaults('core');
		$cfg['limit_ip_access_db_name'] = $corecfg['databases'][0]['db_name'];
		$cfg['limit_ip_access_db_table'] = 'system_ip_access';

		return $cfg;
	}

	/**
	 * Get content of a URL when at the same time posting data to it
	 *
	 * Can also be used for "plain" requests using querystring - instead of using file_get_contents() (second argument just need to be false or empty array then).
	 *
	 * A more advanced version is a class I have made called walk_website (which handles cookies and more)
	 *
	 * An even more advanced class is Source Forge project called Snoopy (downloaded and available in my PHP classes folder)
	 *
	 * ** REQUIREMENTS: **
	 *	- cURL functions
	 *
	 * @param string $url : URL to get
	 * @param array $post_data : Associative array with keys as field names, and values as values or associative array
	 *	- or just a string if the option `raw_post` is set
	 * @param array $options (opt.) : Associative array with any of these options:
	 *	- `raw_post` (boolean) : set true to do a raw POST, meaning sending the string $post_data as is and do not assume it to be key/value pairs
	 *	- `set_curl_opt` (array) : set a cURL option according to the PHP manual, eg. `[CURLOPT_CONNECTTIMEOUT => 5]`
	 * @return string : Output/response from the requested URL
	 */
	public static function get_url_post($url, $post_data, $options = []) {
		// Make POST string
		if ($options['raw_post']) {
			$post_string = (string) $post_data;
		} else {
			$post_string = '';
			if (is_array($post_data)) {
				$post_string = http_build_query($post_data);
			}
		}

		// Execute the POST
		$ch = curl_init();
		curl_setopt($ch, CURLOPT_URL, $url);
		curl_setopt($ch, CURLOPT_POST, 1);
		curl_setopt($ch, CURLOPT_POSTFIELDS, $post_string);  //NOTE from PHP manual: Passing an array to CURLOPT_POSTFIELDS will encode the data as multipart/form-data, while passing a URL-encoded string will encode the data as application/x-www-form-urlencoded.
			//Further notes: on the other end use file_get_contents('php://input') to retrieve the POSTed data ($HTTP_RAW_POST_DATA many times does not work due to some php.ini settings) (php://input does not work with enctype="multipart/form-data"! Source: http://www.codediesel.com/php/reading-raw-post-data-in-php/)
		if ($options['raw_post']) {
			curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type: text/plain'));
		}
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);

		// Set extra cURL options
		if (is_array($options['set_curl_opt'])) {
			foreach ($options['set_curl_opt'] as $curl_opt => $curl_value) {
				curl_setopt($ch, $curl_opt, $curl_value);
			}
		}

		$response = curl_exec($ch);
		curl_close($ch);
		return $response;
	}

	/**
	 * Determine if a URL exists/is valid
	 *
	 * @param string $url
	 * @return boolean
	 */
	public static function url_exists($url) {
		$ch = curl_init($url);
		curl_setopt($ch, CURLOPT_NOBODY, true);
		curl_exec($ch);
		$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
		curl_close($ch);
		// $http_code >= 400 -> not found, $http_code = 200 -> found.
		if ($http_code >= 400) {
			return false;
		} else {
			return true;
		}
	}

	/**
	 * Send a file (from server file system or a URL) to a user/browser, with option to force a download (instead of "playing it" in the browser)
	 *
	 * Gives access to a file/URL from your website with the following advantages:
	 * - avoiding users to know the physical/actual location of the file
	 * - avoiding direct linking (by requiring POST'ed data that is valid before sending file, or even better (since POST can be faked) require existence of a session variable)
	 * - tracking all requests
	 *
	 * Example: `serve_file('c:/2005-06-25 Tony Butenko, Naestved 32K.mp3', true, 'audio/mpeg');`
	 *
	 * ** REQUIREMENTS **
	 * - NO output must have been sent to the browser before calling this function
	 * - NO code or output after calling this function will be processed as the script is terminated when this function is done (unless an error occurs)
	 * - for serving from file system MIME.magic needs to be installed if the function should automatically determine the MIME type/Content Type
	 *
	 * WARNING - PROBLEM!! On sharehim.org it locks the user from requesting any other pages (PHP page only I believe) while it is serving the file! Is there a solution for this?! Alternative could be to just redirect to the real file (but the user will then be able to see the location)
	 *
	 * @param string|array $file (req.) : File on the server (incl. path if in different folder) or URL that you want to send (the file name suggested to the user will be the same as the original file name) or the file content itself (then the array method must be used)
	 *	- if a two-item numeric array:
	 *		- first item will be interpreted as the physical file to serve
	 *		- second item will be the filename that should be suggested to the user when downloaded. If not specified the original filename will be used,
	 *		- optional third item set to `is_content` make the function use the first item as file content itself (and thereby do not load anything from the file system)
	 * @param boolean $dont_force_download : Allow the browser to handle the file it if it can, instead of forcing the user to download the file
	 * @param string $content_type : Content-Type/MIME type of the file (only used if $dont_force_download = true)
	 *	- if MIME.magic is not installed $content_type is REQUIRED for serving from file system
	 * @param array $options (opt.) : Associative array with any of these options:
	 *	- `skip_url_exist_check` : if $file is a URL skip checking that the URL exists
	 * @return void
	 */
	public static function serve_file($file, $dont_force_download = false, $content_type = false, $options = []) {
		// Check if both source file and destination file name was provided
		if (is_array($file)) {
			$fileinmemory = ($file[2] == 'is_content' ? true : false);
			$download_filename = $file[1];
			$file = $file[0];
		}
		// Determine if file is a URL
		if (preg_match('/^(http:|https:|ftp:)/i', $file)) {
			$is_url = true;
		} else {
			$is_url = false;
		}
		if ($is_url) {
			if ($options['skip_url_exist_check']) {
				$file_exists = true;
			} else {
				$file_exists = self::url_exists($file);
			}
		} else {
			$file_exists = true;
		}
		if ($file_exists) {

			// Check that headers has not already been sent
			if (headers_sent()) {
				core::system_error('Some data has already been output to browser, cannot send file.');
			}
			// Make sure any output buffer is destroyed
			// TECHNICAL NOTE: this prohibits the server to buffer the whole output before sending it to the user/browser. When buffering it first, the user will have to wait a little time before the download dialog box pops up!
			while (@ob_end_clean()) {
				//loop to erase all output buffers
			}

			// Determine source and destination files
			if (!$download_filename) {  //if not already set above
				//automatically determine destination file name by using the original name (the name suggested to the user)
				if ($is_url) {
					$url_parts = parse_url($file);
					if (preg_match('|/([^/]*)$|', $url_parts['path'], $match)) {
						$download_filename = $match[1];
					} else {
						core::system_error('Could not automatically determine file name for serving file.');
					}
				} else {
					$fileinfo = pathinfo($file);
					$download_filename = $fileinfo['basename'];
				}
			}

			// Get file size
			if ($is_url) {
				$headers = get_headers($file);
				foreach ($headers as $h) {
					if (preg_match('|^Content-Length:(.*)|i', $h, $match)) { //hopefully this will always exist in the headers!
						$filesize = (int) trim($match[1]);
					} elseif (preg_match('|^Content-Type:(.*)|i', $h, $match)) { //hopefully this will always exist in the headers!
						$content_type = trim($match[1]);
					}
					if ($filesize && $content_type) {
						break;
					}
				}
			} else {
				if ($fileinmemory) {
					$filesize = strlen($file);
				} else {
					$filesize = filesize($file);
				}
			}
			ob_clean();

			// Prepare HTTP headers
			if ($dont_force_download == false) {
				// Force download
				if (strpos($_SERVER['HTTP_USER_AGENT'], 'MSIE')) {
					header('Content-Type: application/force-download');
				} else {
					header('Content-Type: application/octet-stream');
				}
			} else {
				// Allow handling by browser
				if ($content_type) {
					header('Content-Type: '. $content_type);
				} elseif (function_exists('mime_content_type')) {  //only if MIME.magic is installed
					$content_type = mime_content_type($file);
					header('Content-Type: '. $content_type);
				} else {
					core::system_error('MIME type/Content Type was not defined.');
				}
			}
			//NOTE: don't know if the following content headers should be sent of not forced to download???
			header('Content-Length: '. $filesize);
			#header('Content-Disposition: attachment; filename='. $download_filename);  //when using this one instead, it doesn't show you the filename in the _first_ dialog box, not until you select a location on your harddrive to save it in
			header('Content-Disposition: inline; filename="'. $download_filename .'"');  //see eg. dompdf/src/Adapter/PDFLib.php method stream() for how to deal with special characters
			header('Last-Modified: '. date('D, j M Y G:i:s T')); //(not required though) something like Thu, 03 Oct 2002 18:01:08 GMT

			if ($fileinmemory) {
				echo $file;
			} else {
				// Load and output file
				$handle = fopen($file, 'rb');
				while (!feof($handle)) {
					echo fread($handle, 8192);
					if (connection_aborted()) {
						break;  //stop if connection/client/browser has cancelled the request
					}
				}
				fclose($handle);
			}
			// Stop any further script execution
			exit;
		} else {
			core::system_error('File does not exist, cannot serve you the file.', array('File' => $file) );
		}
	}

	/**
	 * Redirect to another URL (server-side)
	 *
	 * Cleans output buffer, sends header, and terminates script
	 *
	 * @param string $url : URL to redirect to
	 * @param integer $http_response_code (opt.)
	 * @return void
	 */
	public static function serverside_redirect($url, $http_response_code = null) {
		if (!preg_match("|^[a-z0-9\\-\\._~:\\/\\?#\\[\\]@\\!\\$&'\\(\\)\\*\\+,;=%]+$|i", $url)) {
			core::system_error('Invalid address to redirect to.', array('URL' => $url));
		}
		if (ob_get_length()) {
			ob_end_clean();
		}
		if (is_numeric($http_response_code)) {
			header('Location: '. $url, true, $http_response_code);
		} else {
			header('Location: '. $url);
		}
		exit;
	}

	/**
	 * Ensure all output buffering is turned of and flush the output
	 *
	 * See http://no2.php.net/flush for more details
	 *
	 * @param array $options : Associative array with any of these options:
	 *	- `skip_padding` : set true to not pad with an HTML comment before flush (some browsers need this padding)
	 * @return void : Only attempts to flush all output
	 */
	public static function flush_all_output($options = []) {
		$levels = ob_get_level();
		for ($i=0; $i<$levels; $i++) {
			ob_end_flush();
		}
		flush();

		if ($options['skip_padding'] && !$GLOBALS['_jfw_have_padded_flush']) {
			// Firefox, IE and other browsers have a buffer which must be filled before incremental rendering kicks in
			echo '<!--'. str_repeat(' ', 1024) .'-->';
			$GLOBALS['_jfw_have_padded_flush'] = true;
		}
	}

	/**
	 * Marks the beginning of output that will be inserted within the opening and closing <head> tag
	 *
	 * Requires use of PHP output buffer, since that is what we manipulate.
	 *
	 * Closing head tag must be exactly: `</head>` or `</HEAD>`
	 *
	 * @return void : It only manipulates the current content of the output buffer
	 */
	public static function html_head_code_begin() {
		$buf = ob_get_contents();
		if (!$buf) {
			core::system_error('Configuration error. Inserting header code is not possible since output buffering is disabled.');
		}
		#dump(htmlentities($buf));
		$buf = str_replace('</HEAD>', '</head>', $buf);
		$headpos = strpos($buf, '</head>');
		if (!$headpos) {
			core::system_error('Page is invalid. Closing header tag not found.');
		}
		$GLOBALS['head_code_buffer'] = $buf;
		$GLOBALS['head_code_closingtag_pos'] = $headpos;
		ob_clean();
	}

	/**
	 * Marks the ending of output that will be inserted within the opening and closing <head> tag
	 *
	 * Requires use of PHP output buffer, since that is what we manipulate.
	 *
	 * Closing head tag must be exactly: `</head>` or `</HEAD>`
	 *
	 * @return void : It only manipulates the current content of the output buffer
	 */
	public static function html_head_code_end() {
		$headcode = ob_get_contents();
		ob_clean();
		$buf = $GLOBALS['head_code_buffer'];
		$headpos = $GLOBALS['head_code_closingtag_pos'];
		if (!$buf) {
			core::system_error('Configuration error. Page output buffer for inserting header code is not available.');
		}
		if (!$headpos) {
			core::system_error('Configuration error. Function for ending header code cannot be called before function for beginning header code.');
		}
		$buf = str_replace('</head>', $headcode.'</head>', $buf);
		echo $buf;
	}

	/**
	 * Add attributes to the <body> tag
	 *
	 * Requires SimpleXML extension. Requires use of PHP output buffer, since that is what we manipulate.
	 *
	 * @param string $name : Name of attribute to add
	 * @param mixed $value : Value of attribute
	 * @param string $conflictmode : What to do if attribute already exists. Available options:
	 *	- `overwrite` : just overwrite the current value
	 *	- `append` : append directly after the end of current value
	 *	- `append_semicolon` (default) : append first a semi-colon (unless the last character is already a semi-colon) and then append the new value (used for Javascript)
	 * @return void : It only manipulates the current content of the output buffer
	 */
	public static function html_body_add_attribute($name, $value, $conflictmode = 'append_semicolon') {
		if (!in_array($conflictmode, array('overwrite', 'append', 'append_semicolon'))) {
			core::system_error('Configuration error. Invalid conflict mode for appending attribute to body tag.', array('Conflict mode' => $conflictmode) );
		}

		$buf = ob_get_contents();
		if (!$buf) {
			core::system_error('Configuration error. Adding attribute to the body tag is not possible since output buffering is disabled.');
		}
		if (stripos($buf, '<body') === false) {
			core::system_error('Body tag for adding attribute does not exist.');
		}

		#DEBUG:
		#Enable this to test having a greater-than sign within a value
		#$buf = '<html><body leftmargin="fff>fff">Other text here<br/>';

		// Get <body> tag
		//NOTE: there is one problem with this pattern. If a ">" is included anywhere within an existing attribute, this process will fail!! It will not get the entire body tag! (try the test above)
		if (!preg_match("|<body.*>|iU", $buf, $match)) {
			core::system_error('Could not find body tag for adding attribute.');
		}
		$curr_tag = $match[0];
		#DEBUG:
		#$buf .= htmlentities($curr_tag);

		// Get any existing attributes (by parsing the string like an XML document!)
		$arr_attributes = array();
		try {
			$xml = @new SimpleXMLElement($curr_tag .'</body>');
		} catch (Exception $e) {
			core::system_error('Body tag could not be parsed correctly for adding attribute.', array('Body tag' => $curr_tag, 'Parse error' => $e->getMessage() ) );
		}
		foreach ($xml->attributes() as $c_key => $c_value) {
			$c_key = strtolower($c_key);
			$arr_attributes[$c_key] = (string) $c_value;
		}
		#DEBUG:
		#$buf .= '<pre>'. print_r($arr_attributes, true) .'</pre>';

		if (strlen($arr_attributes[$name]) > 0) {
			switch ($conflictmode) {
			case 'overwrite':
				$arr_attributes[$name] = $value;
				break;
			case 'append':
				$arr_attributes[$name] .= $value;
				break;
			case 'append_semicolon':
				if (substr($arr_attributes[$name], -1) != ';') {
					$arr_attributes[$name] .= ';'. $value;
				} else {
					$arr_attributes[$name] .= $value;
				}
				break;
			}
		} else {
			// attribute does NOT exist
			$arr_attributes[$name] = $value;
		}
		//consider that attributes, even the same attribute, might already have been added!

		// Make new <body> tag
		$new_tag = '<body';
		foreach ($arr_attributes as $k => $v) {
			$new_tag .= ' '. $k .'="'. str_replace('"', '&quot;', $v) .'"';
		}
		$new_tag .= '>';
		#DEBUG:
		#$buf .= htmlentities($new_tag);

		// Replace original <body> tag with new
		$buf = str_replace($curr_tag, $new_tag, $buf);
		ob_clean();
		echo $buf;
		#DEBUG:
		#echo '<!-- head attribute manipulated! Old : '. $curr_tag .' New : '. $new_tag .' -->';
	}

	/**
	 * Get the host name of a certain IP address
	 *
	 * @param string $ip : IP address
	 * @return string|boolean : If found: host name, if not found: false
	 */
	public static function get_ip_hostname($ip) {
		$hostname = gethostbyaddr($ip);
		if ($hostname == $ip) {
			return false;
		} else {
			return $hostname;
		}
	}

	/**
	 * Determine if an IP address is within the range of a given CIDR
	 *
	 * Source: http://stackoverflow.com/q/594112/2404541
	 *
	 * @param string $ip : IPv4 address, eg. `10.2.0.57`
	 * @param string $range : CIDR range, eg. `10.2.0.0/16`
	 * @return boolean
	 */
	public static function ip_cidr_match($ip, $range) {
		list ($subnet, $bits) = explode('/', $range);
		$ip = ip2long($ip);
		$subnet = ip2long($subnet);
		$mask = -1 << (32 - $bits);
		$subnet &= $mask; # nb: in case the supplied subnet wasn't correctly aligned
		return ($ip & $mask) == $subnet;
	}

	/**
	 * Limit the number of times an IP address is allowed to access a resource, or the number of varying requests an IP address can make to a resource
	 *
	 * It is currently not precise because the database function for calculating the time difference rounds off numbers.
	 *
	 * Requires the table `system_ip_access` in the database:  (table name can be changed in config)
	 * ```
	 * CREATE TABLE `system_ip_access` (
	 * 	`accesstime` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
	 * 	`resource_id` VARCHAR(100) NOT NULL,
	 * 	`resource_variation_key` VARCHAR(255) NULL DEFAULT NULL,
	 * 	`ip_addr` VARCHAR(45) NOT NULL,
	 * 	`expire_days` SMALLINT(5) UNSIGNED NOT NULL,
	 * 	INDEX `resource_id` (`resource_id`),
	 * 	INDEX `ip_addr` (`ip_addr`)
	 * )
	 * ```
	 *
	 * @param string $resource_id : A name/ID of the resource being accessed
	 * @param integer $timeperiod : The time period over which the given number of hits are allowed (in the unit set in $timeperiod_unit)
	 * @param string $timeperiod_unit : The unit $timeperiod is given in. Options: `second`, `minute`, `hour`, `day`, `week`, `month`, `quarter`, `year`
	 * @param integer $allowed_hits : The number of hits allowed within the time period
	 * @param array $options (opt.) : Associative array with any of these options:
	 *	- `return_status` : set true to return the status (`allow` or `prohibit`) instead of raising error
	 *	- `check_only` : set true to not register current request but only check if resource is allowed to be accessed by returning either `allow` or `prohibit`
	 *		- automatically implies the option `return_status` as well
	 *	- `custom_err_msg` : custom error message to show end-user when access to resource is blocked
	 *	- `variation_key` : to limit number of varying requests (eg. different credit card numbers an IP address may try) set this to a key/ID identifying a variant (eg. the hash of a credit card number)
	 *	- `override_db_name` : override limit_ip_access_db_name from the class defaults/configuration
	 *	- `override_db_table` : override limit_ip_access_db_table from the class defaults/configuration
	 * @return void|string : Normally nothing, unless the option `return_status` is set, in which case the strings `allow` or `prohibit` are returned
	 */
	public static function limit_ip_access($resource_id, $timeperiod, $timeperiod_unit, $allowed_hits, $options = []) {
		$cfg = core::get_class_defaults(__CLASS__);

		if ($options['override_db_name']) {
			$cfg['limit_ip_access_db_name'] = $options['override_db_name'];
		}
		if ($options['override_db_table']) {
			$cfg['limit_ip_access_db_table'] = $options['override_db_table'];
		}

		if (!is_numeric($timeperiod)) {
			core::system_error('Invalid time period for IP address limitation.');
		}
		$timeperiod_unit = strtoupper($timeperiod_unit);
		if (!in_array($timeperiod_unit, array('SECOND', 'MINUTE', 'HOUR', 'DAY', 'WEEK', 'MONTH', 'QUARTER', 'YEAR'))) {
			core::system_error('Invalid unit for IP address limitation.');
		}
		if (!is_numeric($allowed_hits)) {
			core::system_error('Invalid allowed hits for IP address limitation.');
		}

		// 'check_only' implies the 'return_status' as well, so ensure it's added
		if ($options['check_only']) {
			$options['return_status'] = true;
		}

		core::require_database();
		$sql = "SELECT ";
		if ($options['variation_key']) {
			$sql .= " resource_variation_key ";
		} else {
			$sql .= " accesstime ";
		}
		$sql .= "FROM `". $cfg['limit_ip_access_db_name'] ."`.`". $cfg['limit_ip_access_db_table'] ."` WHERE resource_id = '". core::sql_esc($resource_id) ."' AND ip_addr = '". core::sql_esc(($_SERVER['HTTP_X_FORWARDED_FOR'] ? $_SERVER['HTTP_X_FORWARDED_FOR'] : $_SERVER['REMOTE_ADDR'])) ."' AND TIMESTAMPDIFF(". $timeperiod_unit .", accesstime, NOW()) <= ". $timeperiod;
		if ($options['variation_key']) {
			$sql .= " AND resource_variation_key IS NOT NULL";
			$sql .= " AND resource_variation_key <> '". core::sql_esc($options['variation_key']) ."'";
			$sql .= " GROUP BY resource_variation_key";
		} else {
			$sql .= " AND resource_variation_key IS NULL";
		}
		$actual_hits = core::database_result($sql, 'countonly', 'Database query for checking hits by IP address failed.');
		$actual_hits++;  //add the current request to the number of hits

		if ($actual_hits > $allowed_hits) {
			if ($options['custom_err_msg']) {
				$err_msg = $options['custom_err_msg'];
			} else {
				if ($options['variation_key']) {
					$err_msg = 'You have exceeded the number of different requests allowed to this resource. Wait a while and try again.';
				} else {
					$err_msg = 'You have exceeded the number of allowed requests to this resource. Wait a while and try again.';
				}
			}
			if ($options['return_status']) {
				return 'prohibit';
			} else {
				core::system_error($err_msg, array('Resource' => $resource_id, 'Variation key' => $options['variation_key'], 'Time period' => $timeperiod .' '. $timeperiod_unit, 'Allowed hits' => $allowed_hits, 'Actual hits' => $actual_hits) );
			}
		}

		if (!$options['check_only']) {
			// Register current hit, only if options `check_only` has not been set
			switch ($timeperiod_unit) {
			case 'SECOND':
			case 'MINUTE':
				$expiredays = 1; break;
			case 'HOUR':
				$expiredays = ceil($timeperiod / 24); break;
			case 'DAY':
				$expiredays = ceil($timeperiod); break;
			case 'WEEK':
				$expiredays = ceil($timeperiod * 7); break;
			case 'MONTH':
				$expiredays = ceil($timeperiod * 31); break;
			case 'QUARTER':
				$expiredays = ceil($timeperiod * 31*3); break;
			case 'YEAR':
				$expiredays = ceil($timeperiod * 365); break;
			}
			$sql = "INSERT INTO `". $cfg['limit_ip_access_db_name'] ."`.`". $cfg['limit_ip_access_db_table'] ."` SET `resource_id` = '". core::sql_esc($resource_id) ."', `ip_addr` ='". core::sql_esc(($_SERVER['HTTP_X_FORWARDED_FOR'] ? $_SERVER['HTTP_X_FORWARDED_FOR'] : $_SERVER['REMOTE_ADDR'])) ."', `expire_days` = ". $expiredays;
			if ($options['variation_key']) {
				$sql .= ", resource_variation_key = '". core::sql_esc($options['variation_key']) ."'";
			}
			core::database_query($sql, 'Database query for registering IP address hit failed.');
		}

		// Purge unneded records once per session
		if (!$_SESSION['_system_ip_access_has_been_purged']) {
			$sql = "DELETE FROM `". $cfg['limit_ip_access_db_name'] ."`.`". $cfg['limit_ip_access_db_table'] ."` WHERE DATEDIFF(NOW(), `accesstime`) > `expire_days`";
			$recordsdeleted = core::database_result($sql, false, 'Database query for purging IP access table failed.');
			$_SESSION['_system_ip_access_has_been_purged'] = true;
		}

		if ($options['return_status']) {
			return 'allow';
		}
	}

	/**
	 * Close connection to the client/user but keep running the PHP script
	 *
	 * Source: http://php.net/manual/en/features.connection-handling.php#93441
	 *
	 * @param callable $output_callback (opt.) : Function that can be used for echoing data to the user
	 * @return void
	 */
	public static function disconnect_client_but_continue_script($output_callback = null) {
		ob_end_clean();
		header("Connection: close\r\n");
		header("Content-Encoding: none\r\n");
		ignore_user_abort(true); // optional
		ob_start();
		if (is_callable($output_callback)) {
			$output_callback();
		}
		$size = ob_get_length();
		header('Content-Length: '. $size);
		ob_end_flush();     // Strange behaviour, will not work
		flush();            // Unless both are called !
		ob_end_clean();
	}
}
