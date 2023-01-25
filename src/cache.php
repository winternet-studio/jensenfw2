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

	public function __construct($file_path, $file_prefix = '', $options = []) {
		$this->file_path = rtrim($file_path, '/');
		$this->file_prefix = $file_prefix;
	}

	/**
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
					return $data['value'];
				}
			} else {
				return $data['value'];
			}
		}

		$value = $callable();
		if (!file_put_contents($file, serialize(['expiration' => $expiration, 'value' => $value]))) {
			throw new \Exception('Failed to write cache file.');
		}
		return $value;
	}

	public function get_full_filepath($key) {
		return $this->file_path .'/'. $this->file_prefix .'_'. filesystem::make_valid_filename($key) .'.phpser';
	}

}
