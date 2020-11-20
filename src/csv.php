<?php
namespace winternet\jensenfw2;

/**
 * This file contains functions related to the dealing with CSV (Comma-Separated Values) data format
 *
 * A separate class `csv_parser` exists for parsing CSV data.
 */
class csv {

	/**
	 * @param array $array_of_arrays
	 * @param array $options
	 * @return string
	 */
	public static function generate($array_of_arrays, $options = []) {
		$defaults = [
			'delimiter' => ',',
			'header' => true,
		];
		$options = array_merge($defaults, $options);

		if (empty($array_of_arrays)) {
			return '';
		}

		ob_start();
		$out = fopen('php://output', 'w');
		if ($options['header']) {
			fputcsv($out, array_keys($array_of_arrays[0]), $options['delimiter']);
		}
		foreach ($array_of_arrays as $row) {
			fputcsv($out, $row, $options['delimiter']);
		}
		fclose($out);
		return rtrim(ob_get_clean(), "\n");  //https://tools.ietf.org/html/rfc4180: "The last record in the file may or may not have an ending line break"
	}

}
