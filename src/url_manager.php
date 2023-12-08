<?php
namespace winternet\jensenfw2;

/**
 * Handle rewriting URLs to specific script files
 *
 * Example:
 *
 * ```
 * $url = url_manager::instance();
 * $url->add_url('^create$', 'create.php');
 * $url->add_url('^buy-gifts$', 'buygifts.php');
 * $url->add_url('^create/book$', 'book.php');
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
 * Within the destination script you can use these calls:
 *
 * - `url_manager::uri()`
 */
class url_manager {

	public $uri = null;
	public $doc_root = null;
	// public $querystring = null;  //have no use for this yet

	public $rules = [];
	public $options = [];

	protected static $instance;
	public static function instance() {
		if (!static::$instance) {
			static::$instance = new static();
		}
		return static::$instance;
	}

	public function __construct($options = []) {
		$this->options = $options;

		// $this->options['debug'] = true;

		$this->doc_root = $_SERVER['DOCUMENT_ROOT'];

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

	public function run() {
		foreach ($this->rules as $rule) {
			if (preg_match('#'. $rule['pattern'] .'#', $this->uri)) {
				if (!empty($this->options['debug'])) {
					echo '<div style="color: green"><b>MATCH: '. htmlentities($rule['destination']) .'</b></div>';
					exit;
				}
				require($this->doc_root .'/'. $rule['destination']);
				return;  //stop after finding first matching rule
			} elseif (!empty($this->options['debug'])) {
				echo '<div style="color: red">NO MATCH: '. htmlentities($rule['pattern']) .'</div>';
			}
		}

		if (!empty($this->options['debug'])) {
			echo '<div style="color: blue">NO RULES MATCHED => SENDING 404</div>';
			exit;
		}

		header('HTTP/1.0 404 Not Found');
		echo 'Sorry, this page doesn\'t exist.';
		// TODO: maybe use this nicely styled layout: C:\Data\Information\_Computer\_Kommunikation, Internet\Internet\_Design-ideer\Blank, placeholder website template, coming soon, under construction.php
		exit;
	}

	// --------------------------------------------
	// Methods to be used in the destination script
	// --------------------------------------------

	/**
	 * Get a named parameter from the URI
	 */
	public static function param($name_or_number) {
		throw new \Exception('Method url_manager::param() not yet implemented.');
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

SPECIAL EXAMPLES to take ideas from:

	$url->add_url('^buy-gifts/<for:friends>$', 'buygifts.php');
	// "for" becomes a variable that we can retrieve the value "friends" with in the PHP script, eg. like `url_manager::param('for')`
	// And maybe to get each part between the slashes one could do `url_manager::param(1)` for the first, `url_manager::param(2)` for the second etc...
	// Could we use Yii2 URL manager class for all this actually??!


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
