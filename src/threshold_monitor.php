<?php
/**
 * Monitor when reoccurring incidents surpass a given threshold
 *
 * Good for ignoring intermittent errors
 */
class threshold_monitor {

	public $name = 'noname';

	public $threshold = 10;

	public $period = 'day';

	public $custom_file_path = null;

	/**
	 * @param string $name : Name/identifier of the instance (eg. `vesselfinderHttpErrors`)
	 * @param integer $threshold : Number of occurrences within the given period before max is reached
	 * @param string $period : Available options:
	 *   - `week` : within the current week
	 *   - `day` : within the current day
	 *   - `12hrs` : within the current 12 hour period (before or after noon)
	 *   - `6hrs` : within the current 6 hour period
	 *   - `hour` : within the current hour
	 */
	public function __construct($name, $threshold = 10, $period = 'day', $options = []) {
		$this->name = $name;
		$this->threshold = $threshold;
		$this->period = $period;
		if (!empty($options['file_folder'])) {
			$this->custom_file_path = $options['file_folder'];
		}
	}

	/**
	 * @param callable $callback_when_max_reached : If code should be executed when max is reached provide a callback function for that. One argument is provided: the number of recorded incidents
	 */
	public function record_incident($callback_when_max_reached = null) {
		$data = $this->get_data();

		$period = $this->get_period_identifier();
		if (!isset($data[$this->name][$period])) {
			// Clear old data
			$data[$this->name] = [];
		}
		$data[$this->name][$period]++;

		$this->set_data($data);

		if (is_callable($callback_when_max_reached) && $this->max_reached()) {
			$callback_when_max_reached($data[$this->name][$period]);
		}
	}

	/**
	 * Check if threshold has been reached
	 *
	 * Eg. if there has been more than 15 incidents per day
	 */
	public function max_reached() {
		$data = $this->get_data();

		$period = $this->get_period_identifier();
		if ($data[$this->name][$period] >= $this->threshold) {
			return true;
		}
		return false;
	}

	public function get_period_identifier() {
		if ($this->period == 'week') {
			return date('Y W');
		} elseif ($this->period == 'day') {
			return date('Y-m-d');
		} elseif ($this->period == '12hrs') {
			return date('Y-m-d') .' '. (date('G') - date('G') % 12);
		} elseif ($this->period == '6hrs') {
			return date('Y-m-d') .' '. (date('G') - date('G') % 6);
		} elseif ($this->period == 'hour') {
			return date('Y-m-d H');
		} else {
			throw new \Exception('Unknown period for threshold monitor.');
		}
	}

	public function set_data($data) {
		if (!file_put_contents($this->get_file_path(), json_encode($data))) {
			throw new \Exception('Failed to write to system temporary folder.');
		}
	}

	public function get_data() {
		if (file_exists($this->get_file_path())) {
			$content = file_get_contents($this->get_file_path());
			return json_decode($content, true);
		} else {
			return [];
		}
	}

	public function get_file_path() {
		if ($this->custom_file_path) {
			return $this->custom_file_path;
		} else {
			return sys_get_temp_dir() .'/threshold_monitor.json';
		}
	}

}
