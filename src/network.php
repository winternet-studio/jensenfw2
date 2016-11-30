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

	public static function get_url_post($url, $post_data, $flags = false) {
		/*
		DESCRIPTION:
		- get content of a URL when at the same time posting data to it
		- can also be used for "plain" requests using querystring - instead of using file_get_contents() (second argument just need to be false or empty array then)
		- a more advanced version is a class I have made called walk_website (which handles cookies and more)
		- an even more advanced class is Source Forge project called Snoopy (downloaded and available in my PHP classes folder)
		REQUIREMENTS:
		- cURL functions
		INPUT:
		- $url : URL to get
		- $post_data : associative array with keys as field names and values as... values!
			- or just a string if the flag 'raw_post' is set
		- $flags (opt.) : string with any combination of these flags:
			- 'raw_post' : do a raw POST, meaning sending the string $post_data as is and do not assume it to be key/value pairs
			- 'set_curl_opt:[option]:[value]::' : set a cURL option according to the PHP manual, eg.: 'set_curl_opt:CURLOPT_CONNECTTIMEOUT:5::'
		OUTPUT:
		- output/response from the requested URL
		*/
		$flags = (string) $flags;

		// Make POST string
		if (strpos($flags, 'raw_post') !== false) {
			$post_string = (string) $post_data;
		} else {
			$post_string = '';
			if (is_array($post_data)) {
				foreach ($post_data as $key => $value) {
					$post_string .= $key .'='. urlencode($value) .'&';
					$at_least_one = true;
				}
				if ($at_least_one) {
					$post_string = substr($post_string, 0, strlen($post_string) - 1);
				}
			}
		}

		// Execute the POST
		$ch = curl_init();
		curl_setopt($ch, CURLOPT_URL, $url);
		curl_setopt($ch, CURLOPT_POST, 1);
		curl_setopt($ch, CURLOPT_POSTFIELDS, $post_string);  //NOTE from PHP manual: Passing an array to CURLOPT_POSTFIELDS will encode the data as multipart/form-data, while passing a URL-encoded string will encode the data as application/x-www-form-urlencoded.
			//Further notes: on the other end use file_get_contents('php://input') to retrieve the POSTed data ($HTTP_RAW_POST_DATA many times does not work due to some php.ini settings) (php://input does not work with enctype="multipart/form-data"! Source: http://www.codediesel.com/php/reading-raw-post-data-in-php/)
		if (strpos($flags, 'raw_post') !== false) {
			curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type: text/plain'));
		}
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);

		// Set extra cURL options
		if (preg_match_all("|set_curl_opt:([A-Z0-9_]+):(.+)::|iU", $flags, $matches, PREG_SET_ORDER)) {
			foreach ($matches as $match) {
				curl_setopt($ch, constant($match[1]), $match[2]);
			}
		}

		$response = curl_exec($ch);
		curl_close($ch);
		return $response;
	}

	public static function url_exists($url) {
		/*
		DESCRIPTION:
		- determine if a URL exists/is valid
		- currently only works with regular and unencrypted HTTP
		OUTPUT:
		- true or false
		*/
		// Parse URL
		$url_parts = parse_url($url);
		$path = $url_parts['path'];
		if ($url_parts['query']) {
			$path .= '?'. $url_parts['query'];
		}
		if ($url_parts['anchor']) {
			$path .= '#'. $url_parts['anchor'];
		}
		if ($url_parts['port']) {
			$port = (int) $url_parts['port'];
		} else {
			$port = 80;  //default
		}
		// Open socket and retrieve result
		$rsp = '';
		$timeout = ($url_parts['host'] == 'localhost' ? 1 : 2);  //seconds to try connecting to host before timing out (is not passed-by-ref)
		$errno  = '';  //must be passed by reference
		$errstr = '';  //must be passed by reference
		if ($sock = @fsockopen($url_parts['host'], $port, $errno, $errstr, $timeout)) {
			fputs($sock, "HEAD ". $path ." HTTP/1.0\r\n\r\n");
			while (!feof($sock)) {
				$rsp .= fgets($sock);
			}
		}
		$exists = (strpos($rsp, '200 OK') !== false ? true : false);
		return $exists;
	}

	public static function serve_file($file, $dont_force_download = false, $content_type = false, $flags = false) {
		/*
		DESCRIPTION:
		- send a file (from server file system or a URL) to a user/browser, with option to force a download (instead of "playing it" in the browser)
		- gives access to a file/URL from your website with the following advantages:
			- avoiding users to know the physical/actual location of the file
			- avoiding direct linking (by requiring POST'ed data that is valid before sending file, or even better (since POST can be faked) require existence of a session variable)
			- tracking all requests
		- example: serve_file('c:/2005-06-25 Tony Butenko, Naestved 32K.mp3', true, 'audio/mpeg');
		- WARNING - PROBLEM!! On ShareHim.org it locks the user from requesting any other pages (PHP page only I believe) while it is serving the file!! Is there a solution for this?!
			- alternative is to just redirect to the real file (but the user will then be able to see the location)
		REQUIREMENTS:
		- NO output must have been sent to the browser before calling this function
		- NO code or output after calling this function will be processed as the script is terminated when this function is done (unless an error occurs)
		- for serving from file system MIME.magic needs to be installed if the function should automatically determine the MIME type/Content Type
		INPUT:
		- $file (req.) : file on the server (incl. path if in different folder) or URL that you want to send (the file name suggested to the user will be the same as the original file name) or the file content itself (then the array method must be used)
			- if a two-item numeric array:
				- first item will be interpreted as the physical file to serve
				- second item will be the filename that should be suggested to the user when downloaded. If not specified the original filename will be used,
				- optional third item set to 'is_content' make the function use the first item as file content itself (and thereby do not load anything from the file system)
		- $dont_force_download : allow the browser to handle the file it if it can, instead of forcing the user to download the file
		- $content_type : Content-Type/MIME type of the file (only used if $dont_force_download = true)
			- if MIME.magic is not installed $content_type is REQUIRED for serving from file system
		- $flags (opt.) : string with any combinations of these flags:
			- 'skip_url_exist_check' : if $file is a URL skip checking that the URL exists
		*/
		$flags = (string) $flags;
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
			if (strpos($flags, 'skip_url_exist_check') !== false) {
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
			header("Content-Disposition: inline; filename=". $download_filename);
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

	public static function serverside_redirect($url, $http_response_code = null) {
		/*
		DESCRIPTION:
		- redirect to another URL (server-side)
		- cleans output buffer, sends header, and terminates script
		INPUT:
		- $url : URL to redirect to
		OUTPUT:
		- nothing
		*/
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

	public static function flush_all_output($options = '') {
		/*
		DESCRIPTION:
		- ensure all output buffering is turned of and flush the output
		- see http://no2.php.net/flush for more details
		INPUT:
		- $options : string with any combination of these flags:
			- 'skip_padding' : don't pad with an HTML comment before flush (some browsers need this padding)
		OUTPUT:
		- nothing is returned, only attempts to flush all output
		*/
		$levels = ob_get_level();
		for ($i=0; $i<$levels; $i++) {
			ob_end_flush();
		}
		flush();

		if (strpos($options, 'skip_padding') === false && !$GLOBALS['_jfw_have_padded_flush']) {
			// Firefox, IE and other browsers have a buffer which must be filled before incremental rendering kicks in
			echo '<!--'. str_repeat(' ', 1024) .'-->';
			$GLOBALS['_jfw_have_padded_flush'] = true;
		}
	}

	public static function html_head_code_begin() {
		/*
		DESCRIPTION:
		- marks the beginning of output that will be inserted within the opening and closing <head> tag
		- requires use of PHP output buffer, since that is what we manipulate
		- closing head tag must be exactly: </head>  or  </HEAD>
		OUTPUT:
		- nothing, but manipulates the current content of the output buffer
		*/
		$buf = ob_get_contents();
		if (!$buf) {
			core::system_error('Configuration error. Inserting header code is not possible since output buffering is disabled.');
		}
		#dump(htmlentities($buf));
		$buf = str_replace('</HEAD>', '</head>', $buf);
		$headpos = strpos($buf, '</head>');
		if (!$headpos) {
		}
		if (!$headpos) {
			core::system_error('Page is invalid. Closing header tag not found.');
		}
		$GLOBALS['head_code_buffer'] = $buf;
		$GLOBALS['head_code_closingtag_pos'] = $headpos;
		ob_clean();
	}

	public static function html_head_code_end() {
		/*
		DESCRIPTION:
		- marks the ending of output that will be inserted within the opening and closing <head> tag
		- requires use of PHP output buffer, since that is what we manipulate
		- closing head tag must be exactly: </head>  or  </HEAD>
		OUTPUT:
		- nothing, but manipulates the current content of the output buffer
		*/
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

	public static function html_body_add_attribute($name, $value, $conflictmode = 'append_semicolon') {
		/*
		DESCRIPTION:
		- add attributes to the <body> tag
		- requires use of PHP output buffer, since that is what we manipulate
		- requires PHP 5 as it uses the SimpleXML extension
		INPUT:
		- $name : name of attribute to add
		- $value : value of attribute
		- $conflictmode : what to do if attribute already exists. One o (default), true will append the value
			- 'overwrite' : just overwrite the current value
			- 'append' : append directly after the end of current value
			- 'append_semicolon' (default) : append first a semi-colon (unless the last character is already a semi-colon) and then append the new value (used for Javascript)
		OUTPUT:
		- nothing, but manipulates the current content of the output buffer
		*/
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

	public static function get_ip_hostname($ip) {
		/*
		DESCRIPTION:
		- get the host name of a certain IP address
		OUTPUT:
		- if found: host name
		- if not found: false
		*/
		$hostname = gethostbyaddr($ip);
		if ($hostname == $ip) {
			return false;
		} else {
			return $hostname;
		}
	}

	public static function ip_cidr_match($ip, $range) {
		/*
		DESCRIPTION:
		- determine if an IP address is within the range of a given CIDR
		- source: http://stackoverflow.com/q/594112/2404541
		INPUT:
		- $ip : IPv4 address, eg.: 10.2.0.57
		- $range : CIDR range, eg.: 10.2.0.0/16
		OUTPUT:
		- boolean
		*/
		list ($subnet, $bits) = explode('/', $range);
		$ip = ip2long($ip);
		$subnet = ip2long($subnet);
		$mask = -1 << (32 - $bits);
		$subnet &= $mask; # nb: in case the supplied subnet wasn't correctly aligned
		return ($ip & $mask) == $subnet;
	}

	public static function limit_ip_access($resource_id, $timeperiod, $timeperiod_unit, $allowed_hits, $flags = '') {
		/*
		DESCRIPTION:
		- limit the number of times an IP address is allowed to access a resource
		- it is currently not precise because the database function for calculating the time difference rounds off numbers
		- requires the table 'system_ip_access' in the database:  (table name can be changed in config)
			CREATE TABLE `system_ip_access` (
				`accesstime` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
				`resource_id` VARCHAR(100) NOT NULL,
				`ip_addr` VARCHAR(45) NOT NULL,
				`expire_days` SMALLINT UNSIGNED NOT NULL,
				INDEX `resource_id` (`resource_id`),
				INDEX `ip_addr` (`ip_addr`)
			);
		INPUT:
		- $resource_id : a name/ID of the resource being accessed
		- $timeperiod : the time period over which the given number of hits are allowed (in the unit set in $timeperiod_unit)
		- $timeperiod_unit : the unit $timeperiod is given in. Options: 'second', 'minute', 'hour', 'day', 'week', 'month', 'quarter', 'year'
		- $allowed_hits : the number of hits allowed within the time period
		- $flags (opt.) : string with any combination of these flags:
			- 'return_status' : return the status ('allow' or 'prohibit') instead of raising error
			- 'check_only' : don't register current request but only check if resource is allowed to be accessed by returning either 'allow' or 'prohibit'
				- automatically implies the flag 'return_status' as well
		OUTPUT:
		- nothing
		- unless the flag 'return_status' is set, in which case the strings 'allow' or 'prohibit' are returned
		*/
		$cfg = core::get_class_defaults(__CLASS__);

		$flags = (string) $flags;
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
		if (strpos($flags, 'check_only') !== false) {
			$flags .= ' return_status';
		}

		core::require_database();
		$sql = "SELECT * FROM `". $cfg['limit_ip_access_db_name'] ."`.`". $cfg['limit_ip_access_db_table'] ."` WHERE resource_id = '". core::sql_esc($resource_id) ."' AND ip_addr = '". core::sql_esc($_SERVER['REMOTE_ADDR']) ."' AND TIMESTAMPDIFF(". $timeperiod_unit .", accesstime, NOW()) <= ". $timeperiod;
		$actual_hits = core::database_result($sql, 'countonly', 'Database query for checking hits by IP address failed.');

		if ($actual_hits >= $allowed_hits) {
			$err_msg = 'You have exceeded the number of allowed requests to this resource. Wait a while and try again.';
			if (strpos($flags, 'return_status') !== false) {
				return 'prohibit';
			} else {
				core::system_error($err_msg, array('Resource' => $resource_id, 'Time period' => $timeperiod .' '. $timeperiod_unit, 'Allowed hits' => $allowed_hits, 'Actual hits' => $actual_hits) );
			}
		}

		if (strpos($flags, 'check_only') === false) {
			// Register current hit, only if flag 'check_only' has not been set
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
			$sql = "INSERT INTO `". $cfg['limit_ip_access_db_name'] ."`.`". $cfg['limit_ip_access_db_table'] ."` SET `resource_id` = '". core::sql_esc($resource_id) ."', `ip_addr` ='". core::sql_esc($_SERVER['REMOTE_ADDR']) ."', `expire_days` = ". $expiredays;
			core::database_query($sql, 'Database query for registering IP address hit failed.');
		}

		// Purge unneded records once per session
		if (!$_SESSION['_system_ip_access_has_been_purged']) {
			$sql = "DELETE FROM `". $cfg['limit_ip_access_db_name'] ."`.`". $cfg['limit_ip_access_db_table'] ."` WHERE DATEDIFF(NOW(), `accesstime`) > `expire_days`";
			$recordsdeleted = core::database_result($sql, false, 'Database query for purging IP access table failed.');
			$_SESSION['_system_ip_access_has_been_purged'] = true;
		}

		if (strpos($flags, 'return_status') !== false) {
			return 'allow';
		}
	}

	public static function disconnect_client_but_continue_script($output_callback = null) {
		/*
		DESCRIPTION:
		- close connection to the client/user but keep running the PHP script
		- source: http://php.net/manual/en/features.connection-handling.php#93441
		INPUT:
		- $output_callback (opt.) : function that can be used for echoing data to the user
		OUTPUT:
		- nothing
		*/
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
