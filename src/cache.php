<?php
/**
 * Class for caching data
 *
 * By default via the filesystem.
 */

namespace winternet\jensenfw2;

class cache {

	public $file_path;
	public $file_prefix;

	/**
	 * @var boolean : Whether the cached value was used in the last call to [[get_or_set()]] or [[simple_get_or_set()]]
	 */
	protected static $last_call_info = [
		'cache_used' => null,
		'cached_at' => null,
	];

	protected static $in_memory = [];

	public function __construct($file_path, $file_prefix = '', $options = []) {
		$this->file_path = rtrim($file_path, '/');
		$this->file_prefix = $file_prefix;
	}

	/**
	 * Get or set a cache value
	 *
	 * See also [[cache::simple_get_or_set()]] which does not require an instance nor setting a file path.
	 *
	 * @param callable $callable : Function that returns the value to be cached if it wasn't cached already
	 * @param string $expiration : The expiration date (UTC) of this value in MySQL format (yyyy-mm-dd or yyyy-mm-dd hh:mm:ss)
	 *   - or number of hours to expire (eg. 6 hours: `6h`)
	 *   - or days to expire (eg. 14 days: `14d`)
	 */
	public function get_or_set($key, $callable, $expiration = null) {
		if ($expiration) {
			if (preg_match('|^\\d{2,4}-\\d{1,2}-\\d{1,2}$|', $expiration) || preg_match('|^\\d{2,4}-\\d{1,2}-\\d{1,2}\\s+\\d{1,2}:\\d{2}:\\d{2}$|', $expiration)) {
				//do nothing, use raw value
			} elseif ($expire_datetime = datetime::period_to_datetime($expiration, ['timezone' => 'UTC', 'null_on_fail' => true])) {
				$expiration = $expire_datetime->format('Y-m-d H:i:s');
			} else {
				core::system_error('Invalid expiration date for getting or setting a cache value.', ['Expiration' => $expiration]);
			}
		}

		$file = $this->get_full_filepath($key);
		if (file_exists($file)) {
			$data = unserialize(file_get_contents($file));
			if ($data['expiration']) {
				if (new \DateTime('now', new \DateTimeZone('UTC')) < new \DateTime($data['expiration'], new \DateTimeZone('UTC'))) {
					static::$last_call_info = [
						'cache_used' => true,
						'cached_at' => null,
					];
					return $data['value'];
				}
			} else {
				static::$last_call_info = [
					'cache_used' => true,
					'cached_at' => null,
				];
				return $data['value'];
			}
		}

		$value = $callable();
		if (!file_put_contents($file, serialize(['expiration' => $expiration, 'value' => $value]))) {
			throw new \Exception('Failed to write cache file.');
		}
		static::$last_call_info = [
			'cache_used' => false,
			'cached_at' => null,
		];
		return $value;
	}

	public function get_full_filepath($key) {
		return $this->file_path .'/'. $this->file_prefix .'_'. filesystem::make_valid_filename($key) .'.phpser';
	}

	/**
	 * Simple get or set a cache value
	 *
	 * Automatically determine the path to the folder to store the cache file.
	 *
	 * Similar to [[system::minimum_time_between()]].
	 *
	 * @param string $condition : Eg. `6h` or `14d`
	 */
	public static function simple_get_or_set($condition, $key, $callback, $path = null) {
		if ($path === null) {
			$path = filesystem::get_automatic_cache_folder(['subfolder' => 'jfw2_simple_cache']);
		}
		$path_exists = file_exists($path);
		if ($path_exists) {
			$filepath = rtrim($path, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $key .'.log';
			filesystem::cleanup_shortlived_files($filepath);
		}
		if (!$path_exists || !file_exists($filepath)) {  //if path doesn't exist, ALWAYS run the function! Cannot just terminate, and notifying webmaster might not be 100% stable I guess...
			$value = $callback();
			$cached_at = gmdate('Y-m-d H:i:s') .'z';
			if ($path_exists) {
				filesystem::save_shortlived_file($filepath, serialize(['cached_at' => $cached_at, 'value' => $value]), $condition);
			}
			static::$last_call_info = [
				'cache_used' => false,
				'cached_at' => $cached_at,
			];
			return $value;
		} else {
			$data = unserialize(file_get_contents($filepath));
			static::$last_call_info = [
				'cache_used' => true,
				'cached_at' => $data['cached_at'],
			];
			return $data['value'];
		}
	}

	/**
	 * Cache just in memory (in an array)
	 *
	 * @param string|integer|array $key
	 * @param callable $callable : Function that returns the value to be cached if it wasn't cached already
	 */
	public static function get_or_set_in_memory($key, $callable) {
		if (is_array($key)) {
			$key = json_encode($key, JSON_THROW_ON_ERROR);
		}
		if (!array_key_exists($key, static::$in_memory)) {
			static::$in_memory[$key] = $callable();
			static::$last_call_info = [
				'cache_used' => false,
				'cached_at' => null,
			];
		} else {
			static::$last_call_info = [
				'cache_used' => true,
				'cached_at' => null,
			];
		}
		return static::$in_memory[$key];
	}

	public static function last_call_info() {
		return (object) static::$last_call_info;
	}

}
