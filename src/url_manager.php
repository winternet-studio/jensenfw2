<?php
namespace winternet\jensenfw2;

/**
 * Handle rewriting URLs to specific script files
 *
 * Example:
 *
 * ```
 * $url = url_manager::instance();
 * $url->add_url('create', 'create.php');  // leading ^ and trailing $ is automatically added to the pattern
 * $url->add_url('buy-gifts', 'buygifts.php');
 * $url->add_url('create/book', 'book.php');
 * $url->run();
 * ```
 *
 * You web server needs to redirect requests to the file you put the above code in.
 * For Apache, add this to your `.htaccess` file, assuming the above code is in `urlhandler.php`:
 *
 * ```
 * <IfModule mod_rewrite.c>
 * RewriteEngine On
 * RewriteCond %{REQUEST_FILENAME} !-f
 * RewriteCond %{REQUEST_FILENAME} !-d
 * RewriteRule . /urlhandler.php [L]
 * </IfModule>
 * ```
 *
 * The path to urlhandler.php in RewriteRule must be absolute or relative. If you have this in a subfolder folder you could use relative path like this: `./urlhandler.php`.
 *
 * Within the destination script you can use these calls:
 *
 * - `url_manager::instance()->get_param()`
 * - `url_manager::uri()`
 */
class url_manager {

	public $uri = null;
	public $doc_root = null;
	public $params = [];
	public $components = [];  //for holding the URI components split by the slash
	// public $querystring = null;  //have no use for this yet

	public $rules = [];
	public $options = [];

	protected static $instance;
	/**
	 * @param array $options : See the constructor
	 */
	public static function instance($options = []) {
		if (!static::$instance) {
			static::$instance = new static($options);
		}
		return static::$instance;
	}

	/**
	 * @param array $options : Available options:
	 *   - `subdirectory` : Set a subdirectory relative to the document that all destination file paths will reference to. Must begin with slash (/). The URL patterns must then also be relative to this path (without leading slash). Trailing slash doesn't matter.
	 */
	public function __construct($options = []) {
		// Clean up
		if (!empty($options['subdirectory'])) {
			$options['subdirectory'] = rtrim($options['subdirectory'], '/');
			if (substr($options['subdirectory'], 0, 1) !== '/') {
				core::system_error('Subdirectory for url_manager must begin with a slash.');
			}
		}

		$this->options = $options;

		// $this->options['debug'] = true;

		$this->doc_root = rtrim($_SERVER['DOCUMENT_ROOT'], '/\\');

		$this->uri = $_SERVER['REQUEST_URI'];
		if (!empty($this->options['subdirectory'])) {
			//  If application is in a subdirectory remove it from the URI
			$this->uri = preg_replace("|^". preg_quote($this->options['subdirectory']) ."|", '', $this->uri);
		}

		// Remove query string
		$qm = strpos($this->uri, '?');
		if ($qm !== false) {
			$this->uri = substr($this->uri, 0, $qm);
		}
		// $this->querystring = $_SERVER['QUERY_STRING'];

		//  Make clean URI
		$this->uri = trim($this->uri, '/');  //eg. "http://mydomain.com/sa_local_ticket_server/cancel-product/" becomes $uri = "sa_local_ticket_server/cancel-product"

		$this->components = explode('/', $this->uri);

		if (!empty($this->options['debug'])) {
			echo '<div>URI: '. var_export($this->uri, true) .'</div>';
			// echo '<div>Query String: '. var_export($this->querystring, true) .'</div>';
			// echo '<div>$_SERVER: <pre>'. var_export($_SERVER, true) .'</pre></div>';
		}
	}

	/**
	 * @param string $pattern : Pattern to match on the URI excl. the query string part and excl. any leading and trailing slashes (/). Eg. `^create$`
	 * @param string $destination : Path to PHP script to process the request, relative to the document root - or root of the application if the option `subdirectory` is used. Eg. `create.php` or `somefolder/create.php` or `../create.php`
	 */
	public function add_url($pattern, $destination) {
		$this->rules[] = [
			'pattern' => $pattern,
			'destination' => $destination,
		];
	}

	public function run($options = []) {
		foreach ($this->rules as $rule) {
			$pattern = preg_replace("|<\\w+:|i", '(', $rule['pattern']);  //handle parameters in URL by first making them normal regular expressions
			$pattern = preg_replace("|>|", ')', $pattern);
			if (preg_match('#^'. $pattern .'$#', $this->uri, $match)) {
				if (!empty($this->options['debug'])) {
					echo '<div style="color: green"><b>MATCH: '. htmlentities($rule['destination']) .'</b></div>';
					exit;
				}

				if (array_key_exists(1, $match)) {  //means we that at least one submatch/parameter
					$params = preg_match_all("|<(\\w+):|", $rule['pattern'], $params_matches, PREG_SET_ORDER);
					for ($i = 1; $i < count($match); $i++) {
						$param_name = $params_matches[$i-1][1];
						$this->params[$param_name] = $match[$i];
					}
				}
				if (empty($options['return'])) {
					require($this->get_destination_path() .'/'. $rule['destination']);
					return;  //stop after finding first matching rule
				} else {
					return $this->get_destination_path() .'/'. $rule['destination'];
				}
			} elseif (!empty($this->options['debug'])) {
				echo '<div style="color: red">NO MATCH: '. htmlentities($rule['pattern']) .'</div>';
			}
		}

		if (!empty($this->options['debug'])) {
			echo '<div style="color: blue">NO RULES MATCHED => SENDING 404</div>';
			exit;
		}

		if (empty($options['return'])) {
			header('HTTP/1.0 404 Not Found');
			echo 'Sorry, this page doesn\'t exist.';
			// TODO: maybe use this nicely styled layout: C:\Data\Information\_Computer\_Kommunikation, Internet\Internet\_Design-ideer\Blank, placeholder website template, coming soon, under construction.php
			exit;
		} else {
			return false;
		}
	}

	public function get_param($name_or_number) {
		if (is_numeric($name_or_number)) {
			return @$this->components[$name_or_number - 1];
		} else {
			return @$this->params[$name_or_number];
		}
	}

	/**
	 * @return string : No trailing slash
	 */
	public function get_destination_path() {
		$path = $this->doc_root;
		if (!empty($this->options['subdirectory'])) {
			$path .= $this->options['subdirectory'];
		}
		return $path;
	}

	// --------------------------------------------
	// Methods to be used in the destination script
	// --------------------------------------------

	/**
	 * Get a named parameter from the URI
	 */
	public static function param($name_or_number) {
		return static::instance()->get_param($name_or_number);
	}

	/**
	 * Get the clean URI
	 */
	public static function uri() {
		$instance = static::instance();
		return $instance->uri;
	}
}

/*
// file_put_contents('url_manager.log', date('Y-m-d H:i:s') ."\t". $_SERVER['REMOTE_ADDR'] ."\t". $_SERVER['REQUEST_URI'] ."\r\n", FILE_APPEND);

SPECIAL EXAMPLES to maybe take ideas from:
} elseif (preg_match("|^get-tunnel-cmd|", $uri, $match)) { //SSH tunnel
	if (empty($_POST) || $_POST['key'] != 'jUYExbK1ex7RQGvVElrAKAPEbmogDxqWUjleFTnHkdfzn2WmyW4JPf8sxy7eVBXhlp3Q7i1WDN74D') {
		http_response_code(404);
		die('Sorry, Not Found');
	}

	require_once(__DIR__ .'/ssh_tunnel_cmd.php');
	echo get_ssh_tunnel_cmd();
} elseif (preg_match("|^scan-count-report/(.+)/(.+)|", $uri, $match)) {
	$_GET['eid'] = $match[1];
	$_GET['h'] = $match[2];
	require_once($GLOBALS['sys']['path_filesystem'] .'/scancount_report.php');
} elseif ($uri == 'android') {
	require_once('includes/php_html_layout.php');
	if (stripos($_SERVER['HTTP_USER_AGENT'], 'spider') === false && stripos($_SERVER['HTTP_USER_AGENT'], 'robot') === false) {
		logentry_misc('GooglePlay');
	}
	html_top_cms();
	//redirect to avoid re-registering on reload
?>
<script type="text/javascript">
setTimeout(function() {
	location.href = '/';
}, 500);
</script>
<?php
	html_bottom_cms();
	exit;
}

function logentry_misc($operation = false) {
	$operation = date('Y-m-d H:i') ."\t". $_SERVER['REMOTE_ADDR'] ."\t". $operation ."\t". $_SERVER['HTTP_REFERER'] ."\t". $_SERVER['HTTP_USER_AGENT'] ."\t". $_SESSION['QUERY_STRING'];
	$fp = fopen('visits_special.log', 'a');
	fwrite($fp, $operation ."\r\n");
}
*/
