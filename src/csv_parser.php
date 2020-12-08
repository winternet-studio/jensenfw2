<?php
namespace winternet\jensenfw2;

/**
 * This file contains functions related to the parsing CSV (Comma-Separated Values) data format
 */
class csv_parser {
	// Configuration
	public $delimiter = ',';
	public $enclosure = '"';
	public $escape = "\\";

	/**
	 * @var boolean|integer|string : Does the first line contain a header (column names)?
	 *
	 * - Set true to use first line as header line
	 * - Set a line number to use that line as header line
	 * - Set an array of values that should be found in a given line for it to determine that it is the header line
	 *
	 * When a header line is present the headers will be used as the array key names in the output.
	 */
	public $header_line = true;
	public $replace_tab_with_comma = false;
	public $trim_values = true;
	public $max_line_length = 4096;

	// Runtime
	public $fieldcount_differs = false;

	public function __construct($options = []) {
		if (!empty($options)) {
			// For backward compatiblity
			if ($options['first_line_is_header']) {
				$this->header_line = true;
			}

			$option_names = ['delimiter', 'enclosure', 'escape', 'header_line', 'replace_tab_with_comma', 'trim_values', 'max_line_length'];
			foreach ($option_names as $option_name) {
				if (array_key_exists($option_name, $options)) {
					$this->$option_name = $options[$option_name];
				}
			}
		}
	}

	/**
	 * Parse CSV data given in a string into an array
	 *
	 * PHP now has a native function {@link https://www.php.net/str_getcsv str_getcsv()} that can parse strings directly.
	 *
	 * @param string $csv_data : string with CSV data
	 * @return array|boolean : Numeric array (first column has index no. 1), or associative array if header with field names is available,
	 *     or returns false if fgetcsv() fails.
	 *     If not all rows have the same field count the property fieldcount_differs will have been set to true.
	 */
	public function parse($csv_data) {
		// Initiate variables
		$this->fieldcount_differs = false;

		if ($this->replace_tab_with_comma) {
			$csv_data = str_replace("\t", ',', $csv_data);
		}

		$row_count = 0;
		$fieldcount = false;
		$output = [];
		$fieldnames = [];

		// Write string to memory
		$handle = fopen('php://temp', 'w');   //Source: http://no2.php.net/wrappers.php (PHP input/output streams)
		fwrite($handle, $csv_data);
		rewind($handle);

		// Go through file line by line
		while (($data = fgetcsv($handle, $this->max_line_length, $this->delimiter, $this->enclosure, $this->escape)) !== false) {
			if (!is_array($data)) {
				return false;
			}
			$row_count++;
			$curr_row = [];
			$curr_fieldcount = count($data);
			if ($fieldcount !== false && $curr_fieldcount != $fieldcount) {
				//different number of fields per line was discovered
				$this->fieldcount_differs = true;
			}
			$fieldcount = $curr_fieldcount;

			// Set field names
			if ($this->header_line) {
				if (!$has_fieldnames && is_array($this->header_line)) {
					$anyMissing = false;
					foreach ($this->header_line as $value) {
						if (!in_array($value, $data)) {
							$anyMissing = true;
							break;
						}
					}
					if (!$anyMissing) {
						$line_num_to_use_as_header = $row_count;
					}
				} elseif (is_numeric($this->header_line)) {
					$line_num_to_use_as_header = $this->header_line;
				} else {
					$line_num_to_use_as_header = 1;
				}
				if ($row_count == $line_num_to_use_as_header) {
					for ($c=0; $c < $fieldcount; $c++) {
						$fieldnames[$c] = $data[$c];
					}
					$has_fieldnames = true;
					continue;  //skip to next line (so we don't add headers as part of the output data)
				}
			}

			// Loop through values in the current row
			for ($c=0; $c < $fieldcount; $c++) {
				$value = $data[$c];
				if ($this->trim_values) {
					$value = trim($value);
				}
				if ($has_fieldnames && $fieldnames[$c]) {
					$curr_row[$fieldnames[$c]] = $value;
				} else {
					$curr_row[$c+1] = $value;
				}
			}

			$output[] = $curr_row;
		}
		fclose($handle);
		return $output;
	}

	/**
	 * @deprecated Use parse() instead
	 */
	public function parse_csv($csv_data) {
		return $this->parse($csv_data);
	}
}
