<?php
/*
This file contains functions related to the parsing CSV (Comma-Separated Values) data format
*/
namespace winternet\jensenfw2;

class csv_parser {
	// Configuration
	public $delimiter = ',';
	public $enclosure = '"';
	public $escape = "\\";
	public $first_line_is_header = true;  // Does the first line contain a header (column names)? If so, these will be used as the array key names in the output
	public $replace_tab_with_comma = true;
	public $trim_values = true;
	public $max_line_length = 4096;

	// Runtime
	public $fieldcount_differs = false;

	public function __construct($options = []) {
		if (!empty($options)) {
			$option_names = ['delimiter', 'enclosure', 'escape', 'first_line_is_header', 'replace_tab_with_comma', 'trim_values', 'max_line_length'];
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
	 * - requires minimum PHP 5.1.0 (otherwise it can only read from files or URLs, but would have to be adjusted for that)
	 * - in future PHP is probably coming with a function to also parse strings directly (str_getcsv() )
	 *
	 * @param string $csv_data : string with CSV data
	 * @return array|boolean : Mumeric array (first column has index no. 1), or associative array if header with field names is available,
	 *     or returns false if fgetcsv() fails.
	 *     If not all rows have the same field count the property fieldcount_differs will have been set to true.
	 */
	public function parse_csv($csv_data) {
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
		// Read/parse using PHP's CSV parsing function
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
			if ($this->first_line_is_header && $row_count == 1) {
			    for ($c=0; $c < $fieldcount; $c++) {
			        $fieldnames[$c] = $data[$c];
			    }
				$has_fieldnames = true;
				continue;  //skip to next line (so we don't add headers as part of the output data)
			}
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
}
