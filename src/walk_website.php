<?php
/**
 * Library for web scrabing - "walking through a website" using script instead of a browser where user manually points and clicks where to go
 */
namespace winternet\jensenfw2;

class walk_website {
	/*
	DESCRIPTION:
	- class with functions for easily walking through a website like you do in the browser (retrieving HTML pages and processing them, setting cookies)
	- preserves cookies
	- for a more advanced class see Source Forge project called Snoopy (downloaded and available in my PHP classes folder)
	- for a more simple function to just post a form see get_url_post() in php_functions_network.php
	INPUT:
	- $options : available options:
		- 'authorization_basic' : array with keys 'username' and 'password' for sending Authorization header using the given user name and password
		- 'enable_cookies' : set true to use and save cookies when doing multiple requests
			- make sure that the cookie jar file is configured, exists and is writable
		- 'cookie_jar_file' : full path to file to use as cookie jar (default is cookiejar.txt in parent folder of this file)
			- you need to include session_id() in the filename if you need separate cookies from different sessions
		- 'use_ssl_version3' : set true to specifically use SSL version 3
	PUBLIC METHODS:
	- fetch_page()
	- fetch_page_form_fields_and_submit()
	- get_form_details()
	- ...and more
	PUBLIC PROPERTIES:
	- debug_path : set to a custom path of where you want to save the debug files
	*/

	public $cookie_file_path;  //file holding the cookie
	public $ch;  //cURL handle
	public $transfer_info; //property to hold transfer information from the latest request/response
	public $curl_error;  //property to hold the curl error (string) from the latest request
	public $curl_errno;  //property to hold the curl error number from the latest request
	public $last_headers;  //property to hold the headers from the latest response
	public $cookies_set = '';
	public $debug_path = false;  //property to hold a custom path to where you want to save the debug files (with or without trailing slash)
	public $defaultheaders = [];
	public $is_debug = 0;  //set to true or 1 to enable debugging, both echoing and saving to file. Set to 'echo' to only output info to screen, or to 'file' to only save to file.

	public $option_ignore_response = false;
	public $option_throw_exception = false;

	public function __construct($options = []) {
		$this->ch = curl_init();
		curl_setopt($this->ch, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($this->ch, CURLOPT_HEADER, true);
		curl_setopt($this->ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; WOW64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/79.0.3945.130 Safari/537.36');
		curl_setopt($this->ch, CURLOPT_AUTOREFERER, true);
		curl_setopt($this->ch, CURLOPT_SSL_VERIFYPEER, false);  //sometimes needed for pages with SSL to work, otherwise you might just get an empty string
		if (@$options['use_ssl_version3']) {
			curl_setopt($this->ch, CURLOPT_SSLVERSION, 3);
		}
		curl_setopt($this->ch, CURLINFO_HEADER_OUT, true);
		if (!ini_get('safe_mode') && !ini_get('open_basedir')) {  //CURLOPT_FOLLOWLOCATION is not allowed in safe mode and when open basedir is set
			curl_setopt($this->ch, CURLOPT_FOLLOWLOCATION, true);  //automatically follow redirects
		}
		if (!empty(@$options['authorization_basic'])) {
			$authhead = 'Authorization: Basic '. base64_encode($options['authorization_basic']['username'] .':'. $options['authorization_basic']['password']);
			$this->defaultheaders[] = $authhead;
			curl_setopt($this->ch, CURLOPT_HTTPHEADER, [$authhead]);
		}
		if (@$options['enable_cookies']) {
			// Set full path
			if (@$options['cookie_jar_file']) {
				$this->cookie_file_path = $options['cookie_jar_file'];
			} else {
				$this->cookie_file_path = dirname(dirname(__FILE__)) .'/cookiejar.txt';
			}
			// Check that cookie file exists and is writable
			$fp = fopen($this->cookie_file_path, 'a') or core::system_error('Unable to open cookie jar for walking a website.', ['Path' => $this->cookie_file_path]);
			fclose($fp);
			curl_setopt($this->ch, CURLOPT_COOKIEFILE, $this->cookie_file_path);
			curl_setopt($this->ch, CURLOPT_COOKIEJAR, $this->cookie_file_path);
		}
	}
	public function __destruct() {
		curl_close($this->ch);
	}

	protected function fetch_page_oldformat($url, $arr_post_variables = [], $raw_post = false, $extraheaders = false) {
		/*
		DESCRIPTION:
		- retrieve a page of a URL, optionally POSTing variables to it at the same time
		INPUT:
		- $url
		- $arr_post_variables : array of key/value pairs to be POSTed (can also be the URL-encoded string)
		- $raw_post (true|false|'application/json','[some-other-content-type]') : do a raw POST? $arr_post_variables then MUST be a string
		- $extraheaders : array of extra headers to set in the request (string: "headername: headervalue")
			- empty array will still be set
		OUTPUT:
		- HTML or whatever response came from the server/URL
		- or nothing if response is ignored
		*/
		if ($this->is_debug && $this->is_debug !== 'file') {
			$bt = debug_backtrace();
			if ($bt[1]['function'] != 'fetch_page_form_fields_and_submit') {  //only if this method is not calling
				$this->debug('NEW CALL', $url);
			}
		}
		curl_setopt($this->ch, CURLOPT_URL, $url);
		if ($this->option_ignore_response) {
			if (is_array($extraheaders)) {
				$extraheaders[] = 'Connection: Close';
			} else {
				$extraheaders = ['Connection: Close'];
			}
			curl_setopt($this->ch, CURLOPT_TIMEOUT_MS, 1);
			curl_setopt($this->ch, CURLOPT_HEADER, false);
		}
		if (empty($arr_post_variables)) {
			curl_setopt($this->ch, CURLOPT_POST, 0);
			if (is_array($extraheaders)) {
				curl_setopt($this->ch, CURLOPT_HTTPHEADER, $extraheaders);
			}
			if ($this->is_debug && $this->is_debug !== 'file') {
				$this->debug('>>>>>>>>>>>>>>>>>>>> URL fetched <<<<<<<<<<<<<<<<<<<<', 'GET '. $url);
			}
		} else {
			curl_setopt($this->ch, CURLOPT_POST, 1);
			if (is_string($arr_post_variables)) {
				$post_string = $arr_post_variables;
			} else {
				$post_string = [];
				foreach ($arr_post_variables as $key => $value) {
					$post_string[] = urlencode($key) .'='. urlencode($value);
				}
				$post_string = implode('&', $post_string);
			}
			curl_setopt($this->ch, CURLOPT_POSTFIELDS, $post_string);
			if ($raw_post && is_string($arr_post_variables)) {
				if ($raw_post == 'application/json') {
					$headers = ['Content-Type: application/json; charset=UTF-8'];
				} elseif (is_string($raw_post) && strlen($raw_post) > 5) {
					$headers = ['Content-Type: '. $raw_post];
				} else {
					$headers = ['Content-Type: text/plain'];
				}
				if (!empty($this->defaultheaders)) {
					$headers = array_merge($this->defaultheaders, $headers);
				}
			} else {
				$headers = [];
			}
			if (is_array($extraheaders)) {
				$headers = array_merge($headers, $extraheaders);
			}
			if (!empty($headers)) {
				curl_setopt($this->ch, CURLOPT_HTTPHEADER, $headers);
			}
			if ($this->is_debug && $this->is_debug !== 'file') {
				$this->debug('>>>>>>>>>>>>>>>>>>>> URL fetched <<<<<<<<<<<<<<<<<<<<', 'POST '. $url ."\r\nPOST string: ". $post_string);
			}
		}
		$html = curl_exec($this->ch);

		$this->transfer_info = curl_getinfo($this->ch);
		if ($this->option_ignore_response) {
			//reset options
			curl_setopt($this->ch, CURLOPT_TIMEOUT, 0);
			curl_setopt($this->ch, CURLOPT_HEADER, true);
		}
		$this->curl_error = curl_error($this->ch);
		$this->curl_errno = curl_errno($this->ch);
		if (curl_errno($this->ch) || ($this->transfer_info['http_code'] >= 400 && strlen($html) == 0) ) {
			if ($this->option_throw_exception) {
				throw new \Exception('Request for fetching URL failed.');
			} else {
				core::system_error('Request for fetching URL failed.', ['Req.info' => print_r($this->transfer_info, true), 'cURL error' => $this->curl_error, 'cURL error no.' => $this->curl_errno, 'Response body' => $html]);
			}
		}

		if ($this->is_debug && $this->is_debug !== 'echo') {
			$log_filename = 'walk_website_dump_'. date('His') .'_'. str_pad(++$GLOBALS['__lskjaoietjslkg'], 3, '0', STR_PAD_LEFT) .'_'. substr(str_replace(['?', '=', '/', '\\', ':', ';', '|', '&', '+', '%', '*', '#'], '-', basename($url)), 0, 45) .'.html';
			if ($this->debug_path) {
				file_put_contents($this->debug_path .'/'. $log_filename, $url ."\r\n\r\n". $html);
			} elseif (function_exists('jfw__core_cfg')) {
				file_put_contents(jfw__core_cfg('path_filesystem') .'/'. $log_filename, $url ."\r\n\r\n". $html);
			} else {
				file_put_contents($log_filename, $url ."\r\n\r\n". $html);
			}
		}
		// Split header from body
		if (!$this->option_ignore_response) {
			$this->last_headers = substr($html, 0, $this->transfer_info['header_size']);
		}
		if ($this->is_debug && $this->is_debug !== 'file') {
			$this->debug('Request header', $this->transfer_info['request_header']);
			if ($this->option_ignore_response) {
				$this->debug('Response headers', 'RESPONSE IGNORED');
			} else {
				$this->debug('Response headers', $this->last_headers);
			}
		}

		if ($this->option_ignore_response) {
			//return nothing
		} else {
			$html = substr($html, $this->transfer_info['header_size']);
			return $html;
		}
	}

	public function fetch_page($url, $options = []) {
		/*
		DESCRIPTION:
		- retrieve a page of a URL, optionally POSTing variables to it at the same time
		INPUT:
		- $options : associative array with any of the following keys:
			- 'method' : !NOT YET IMPLEMENTED!  GET, POST, PUT, PATCH etc.
			- 'post_variables' : array of key/value pairs to be POSTed (can also be the URL-encoded string)
			- 'raw_post' (true|false) : do a raw POST? 'post_variables' then MUST be a string. Default false
			- 'extraheaders' : array of extra headers to set in the request. Key is header name and value is it's value
			- 'ignore_response' (true|false) : skip waiting for the response and just ignore it? Default false
			- 'throw_exception_on_error' (true|false) : throw PHP exception when an errors occcurs instead of using core::system_error()? Default false
		OUTPUT:
		- HTML or whatever response came from the server/URL
		- or nothing if response is ignored
		*/
		if (@$options['ignore_response']) {
			$this->option_ignore_response = true;
		}
		if (@$options['throw_exception_on_error']) {
			$this->option_throw_exception = true;
		}
		if (!empty($options['extraheaders']) && is_array($options['extraheaders'])) {
			$extraheaders = [];
			foreach ($options['extraheaders'] as $headername => $headervalue) {
				$extraheaders[] = $headername .': '. $headervalue;
			}
		}
		$return = $this->fetch_page_oldformat($url, @$options['post_variables'], @$options['raw_post'], $extraheaders);
		if (@$options['throw_exception_on_error']) {
			$this->option_throw_exception = false;
		}
		if (@$options['ignore_response']) {
			$this->option_ignore_response = false;
		} else {
			return $return;
		}
	}

	public function fetch_page_form_fields_and_submit($html_or_url, $form_ref = 1, $arr_set_fields = [], $arr_set_headers = false, $options = []) {
		/*
		DESCRIPTION:
		- retrieve a page from the web, determine the form fields, set custom values, and then submit the form to the action URL
		INPUT:
		- $html_or_url : HTML code or URL to retrieve HTML code from to find the form and determine the available fields
			- can also be an array where first entry is the above and second entry is the submit URL of the form
				- this can be used if the function cannot automatically determine the URL itself
		- $form_ref : form to retrieve in one of these formats:
			- integer : the sequential number of the form starting from 1
			- array with key `name` and the value being the name attribute of the <form> element
			- array with key `id` and the value being the id attribute of the <form> element
			- '*' : ignore form scope, just get any inputs found on the page
		- $arr_set_fields : associative array with name/value pairs of fields to be POSTed
			- set value to '_REMOVE_FIELD' if the field should be removed and not POSTed
		- $arr_set_headers : array of extra headers to send with each request. To send different headers with the two requests set the array into this address instead:
			- $arr_set_headers['first'] and $arr_set_headers['second']
		- $options : associative array with any of these keys:
			- 'callback_before_submit' : anonymous function to be called before submitting the form. 1st argument will be the retrieved HTML as a string, 2nd the HTML as a DOM object (might want to define pass-by-reference in the function)
				- see code below for details
		OUTPUT:
		- HTML or whatever response came from the server/URL after submitting the form
		- or false if the process was cancelled via a callback
		*/
		if ($this->is_debug && $this->is_debug !== 'file') {
			$this->debug('NEW CALL', $html_or_url);
		}
		if (is_array($html_or_url)) {
			$submit_url = $html_or_url[1];
			$html_or_url = $html_or_url[0];
		} else {
			$submit_url = false;
		}

		// Determine extra headers
		$first_headers = $second_headers = false;
		if (is_array($arr_set_headers)) {
			if ($arr_set_headers['first']) {
				$first_headers = $arr_set_headers['first'];
			} elseif (!array_key_exists('second', $arr_set_headers)) {
				$first_headers = $arr_set_headers;
			}
			if ($arr_set_headers['second']) {
				$second_headers = $arr_set_headers['second'];
			} elseif (!array_key_exists('first', $arr_set_headers)) {
				$second_headers = $arr_set_headers;
			}
		}

		// If URL given, retrieve the contents
		if (substr($html_or_url, 0, 4) == 'http') {
			# $html = \winternet\jensenfw2\simple_html_dom::file_get_html($html_or_url);
			$str_html = $this->fetch_page($html_or_url, ['extraheaders' => $first_headers]);
			$html = \winternet\jensenfw2\simple_html_dom::str_get_html($str_html);
			$is_url = true;
		} else {
			$html = \winternet\jensenfw2\simple_html_dom::str_get_html($html_or_url);
			$is_url = false;
		}

		if (array_key_exists('callback_before_submit', $options) && is_callable($options['callback_before_submit'])) {
			$callback_return = $options['callback_before_submit']($str_html, $html);
			if ($callback_return['cancel']) {
				return $str_html;
			}
		}

		// Retrieve the form fields
		$forminfo = $this->get_form_details($html, $form_ref);

		$html->clear();  //clean-up memory
		$html = null;

		if ($submit_url) {
			$action = $submit_url;
		} else {
			$action = $forminfo['action'];
		}
		$method = $forminfo['method'];
		$formfields = $forminfo['formfields'];

		// Generate the array of field to submit to the action URL
		$submitfields = $formfields;  //copy the original form
		foreach ($arr_set_fields as $cname => $cvalue) {  //then replace given fields with our own values
			//TODO: maybe check if $cname is defined in $formfields??
			if ($cvalue == '_REMOVE_FIELD') {
				unset($submitfields[$cname]);
			} else {
				$submitfields[$cname] = $cvalue;
			}
		}

		// Submit the form and return the response
		if (substr($action, 0, 4) != 'http') {
			// Attempt to complete the URL automatically
			//NOTE: maybe use http_build_url() instead - it's a smart function!
			$slashpos = strrpos($html_or_url, '/');
			$action = substr($html_or_url, 0, $slashpos+1) . $action;
			if (substr($action, 0, 4) != 'http') {
				core::system_error('Missing complete submission/action URL.', ['Action' => $action]);
			}
		}
		if ($this->is_debug && $this->is_debug !== 'file') {
			$this->debug('>>>>>>>>>>>>>>>>>>>> Form Processing <<<<<<<<<<<<<<<<<<<<', $html_or_url);
			$this->debug('FORM Action', $action);
			$this->debug('FORM Method', $method);
			$this->debug('FORM Fields Empty', print_r($formfields, true));
			$this->debug('FORM Fields Filled Out', print_r($submitfields, true));
		}
		if ($method == 'post') {
			return $this->fetch_page($action, ['post_variables' => $submitfields, 'extraheaders' => $second_headers]);
		} else {
			return $this->fetch_page($action . (strpos($action, '?') === false ? '?' : '&') . http_build_str($submitfields), ['extraheaders' => $second_headers]);
		}
	}

	public function get_form_details($html, $form_ref = 1) {
		/*
		DESCRIPTION:
		- get the action, method and post fields from a form
		INPUT:
		- $html : string or simple_html_dom object (eg. returned by str_get_html() )
		- $form_ref : form to retrieve in one of these formats:
			- integer : the sequential number of the form starting from 1
			- array with key `name` and the value being the name attribute of the <form> element
			- array with key `id` and the value being the id attribute of the <form> element
			- '*' : ignore form scope, just get any inputs found on the page
		OUTPUT:
		- associative array with 'action', (string) 'method' (string) and 'formfields' (array)
		*/
		if (is_string($html)) {
			$html = \winternet\jensenfw2\simple_html_dom::str_get_html($html);
		}

		// Retrieve the form fields
		if ($form_ref !== '*') {
			if (is_array($form_ref) && isset($form_ref['name'])) {
				$form = $html->find('form[name="'. $form_ref['name'] .'"]', 0);
			} elseif (is_array($form_ref) && isset($form_ref['id'])) {
				$form = $html->find('form[id="'. $form_ref['id'] .'"]', 0);
			} elseif (is_numeric($form_ref)) {
				$form = $html->find('form', $form_ref-1);
			} else {
				core::system_error('Invalid form reference for walking the website.', ['Form ref' => $form_ref]);
			}
			if ($form == null) {
				core::system_error('The form could not be found.', ['Form ref' => $form_ref]);
			}

			$action = $form->action;
			$method = strtolower($form->method);
		} else {
			$method = $action = null;
		}

		$formfields = [];

		if ($form_ref === '*') {
			$search_dom =& $html;
		} else {
			$search_dom =& $form;
		}

		foreach ($search_dom->find('input') as $cinput) {
			if ($cinput->name) {
				if ($cinput->type == 'radio') {
					if ($cinput->checked) {
						$formfields[$cinput->name] = html_entity_decode($cinput->value);
					}
				} elseif ($cinput->type == 'checkbox') {
					if ($cinput->checked) {
						$formfields[$cinput->name] = html_entity_decode($cinput->value);
					}
				} else {
						$formfields[$cinput->name] = html_entity_decode($cinput->value);
				}
			}
		}
		foreach ($search_dom->find('select') as $cinput) {
			if ($cinput->name) {
				foreach ($cinput->find('option') as $coption) {
					if ($coption->selected) {
						if ($cinput->multiple) {
							$formfields[$cinput->name][] = html_entity_decode( (string) $coption->value);
						} else {
							$formfields[$cinput->name] = html_entity_decode( (string) $coption->value);
							break;  //no reason to check any further
						}
					}
				}
				if (!$cinput->multiple && !is_string($formfields[$cinput->name])) {
					// set to first possible value (since it would automatically have been selected in the browser - correct?)
					$formfields[$cinput->name] = html_entity_decode( (string) $cinput->find('option', 0)->value);
				}
			}
		}
		foreach ($search_dom->find('textarea') as $cinput) {
			if ($cinput->name) {
				$formfields[$cinput->name] = html_entity_decode($cinput->innertext);
			}
		}

		$html->clear();  //clean-up memory
		$html = null;

		if ($form_ref === '*') {
			return [
				'formfields' => $formfields,
			];
		} else {
			return [
				'action' => $action,
				'method' => $method,
				'formfields' => $formfields,
			];
		}
	}

	public function get_links(&$html) {
		//TODO: use simple_html_dom to find and return all links (<a> tags) in the given HTML
	}

	public function get_images(&$html) {
		//TODO: use simple_html_dom to find and return all images (<img> tags) in the given HTML
	}

	public function set_cookie($name, $value) {
		/*
		DESCRIPTION:
		- set a cookie to be sent in the request header
		- cookies will be ADDED to those automatically set by cURL itself on subsequent requests where a cookie was returned by the server
		INPUT:
		- $name
		- $value
		OUTPUT:
		- nothing
		*/
/*
OLD METHOD where we looked for the existing cookies/headers
		$cookiestr = '';
		if (preg_match('/^Set-Cookie: (.*?);/m', $this->last_headers, $m)) {  //add to any existing cookie data if any
			if ($m[1]) {
				$cookiestr = $m[1] .'; ';
			}
		}
*/
		$cookiestr = $name .'='. urlencode($value);
		if ($this->cookies_set) {
			$this->cookies_set = $this->cookies_set .'; '. $cookiestr;
		} else {
			$this->cookies_set = $cookiestr;
		}
		curl_setopt($this->ch, CURLOPT_COOKIE, $this->cookies_set);
	}

	public function set_referer($url) {
		curl_setopt($this->ch, CURLOPT_REFERER, $url);
	}

	public function set_useragent($string) {
		curl_setopt($this->ch, CURLOPT_USERAGENT, $string);
	}

	public function set_proxy($host, $port = 80, $type = 'HTTP') {
		if ($host) {
			curl_setopt($this->ch, CURLOPT_HTTPPROXYTUNNEL, true);
			curl_setopt($this->ch, CURLOPT_PROXY, $host);
			curl_setopt($this->ch, CURLOPT_PROXYPORT, $port);
			if ($type == 'SOCKS5') {
				curl_setopt($this->ch, CURLOPT_PROXYTYPE, CURLPROXY_SOCKS5);
			} else {
				curl_setopt($this->ch, CURLOPT_PROXYTYPE, CURLPROXY_HTTP);
			}
		} else {
			curl_setopt($this->ch, CURLOPT_HTTPPROXYTUNNEL, false);
			curl_setopt($this->ch, CURLOPT_PROXY, null);
			curl_setopt($this->ch, CURLOPT_PROXYPORT, null);
			curl_setopt($this->ch, CURLOPT_PROXYTYPE, null);
		}
	}

	public function set_gzip_handling() {
		curl_setopt($this->ch, CURLOPT_ENCODING, '');  //causes Curl to automatically unzip the gzip data
	}

	public function parse_curl_command($cmd, $options = []) {
		/*
		DESCRIPTION:
		- parse a curl command (as it is copied from Firefox Firebug)
		INPUT:
		- $options : Available options:
			- 'incl_cookie' : set true to include Cookie header in 'headers_simple' output (normally not needed since curl tracks cookies itself)
		OUTPUT:
		- associative array
		*/
		$output = [];

		// Check for doing POST
		if (strpos($cmd, ' -X POST') !== false) {
			$cmd = str_replace(' -X POST', '', $cmd);
			$output['do_post'] = true;
		}

		if (preg_match("|curl '(.*?)' -H (.*)|", $cmd, $match)) {
			$output['url'] = $match[1];
			$therest = $match[2];

			// Check for "compressed" flag
			if (strpos($therest, '--compressed') !== false) {
				$output['is_compressed'] = true;
				$therest = trim(str_replace('--compressed', '', $therest));
			} else {
				$output['is_compressed'] = false;
			}

			//Check for "data"
			if (preg_match("|--data '(.*?)'|", $therest, $datamatch)) {  //TODO: can ' occur in a value???
				$output['do_post'] = true;
				parse_str($datamatch[1], $output['postdata']);
				$therest = trim(str_replace($output['postdata'], '', $therest));
			} else {
				$output['postdata'] = false;
			}

			$output['headers'] = explode(' -H ', $therest);  //TODO: the last one might not only be a header but some other curl options as well. In that case we might want to look for "' -" before we split the string
			$output['headers_simple'] = [];
			array_walk($output['headers'], function(&$item) use (&$output) {
				$item = trim($item, "'");
				if (preg_match("|^(.*?):(.*)$|", $item, $match)) {
					$orig_item = $item;
					$item = [];
					$item['_raw'] = [$match[1], trim($match[2])];
					if ($match[1] == 'Cookie') {
						$cookies = explode(';', trim($match[2]));  //TODO: can ; occur in a value???
						$cookie_array = [];
						foreach ($cookies as $cookie) {
							list($cookname, $cookval) = explode('=', $cookie, 2);
							$cookie_array[] = [trim($cookname), trim($cookval)];
						}
						$item['_parsed'] = [$match[1], $cookie_array];

						if (@$options['incl_cookie']) {
							$output['headers_simple'][] = $orig_item;
							// Remember which one is the Cookie
							end($output['headers_simple']);
							$output['headers_simple_cookie_indx'] = key($output['headers_simple']);
						}
					} else {
						$output['headers_simple'][] = $orig_item;
					}
				} else {
					core::system_error('Seems to not be a header: '. $item);
				}
			});
		} else {
			core::system_error('Invalid cURL command.');
		}

		return $output;
	}

	public function parse_firebug_post_copy($data) {
		$output = [];
		$data = trim($data);
		$lines = explode("\n", $data);
		foreach ($lines as $line) {
			list($name, $value) = explode('=', trim($line), 2);
			$output[$name] = $value;
		}
		return $output;
	}

	public function debug($title, $data) {
		if (PHP_SAPI == 'cli') {
			echo "\r\n". $title ."\r\n". $data ."\r\n\r\n";
		} else {
			echo '<div style="border: '. ($title == 'NEW CALL' ? '1px solid #b97800' : '1px solid #7e93af') .'; background-color: '. ($title == 'NEW CALL' ? 'orange' : 'LightSteelBlue') .'; font-family: monospace; padding: 10px; border-radius: 3px"><small><b style="font-size: 150%; font-family: Tahoma,Verdana,Arial; opacity: 0.8">'. $title;
			if ($title == 'NEW CALL') {
				$bt = debug_backtrace();
				echo ' in '. basename($bt[1]['file']) .' line '. $bt[1]['line'];
			}
			echo '</b><div style="padding: 5px">';
			if (is_array($data)) {
				array_walk($data, function($item, $index) {
					echo nl2br(htmlentities($item));
				});
			} else {
				echo nl2br(htmlentities($data));
			}
			echo '</div></small></div>';
		}
	}
}
