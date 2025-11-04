<?php
/**
 * This file contains functions related to Google
 */
namespace winternet\jensenfw2;

class google {

	public static $latest_meta_data = null;

	/**
	 * Get data from a Google Sheet (currently only public sheets)
	 *
	 * @param string $sheet_id : Google Sheet ID, eg. `1kCiN5s6UqeG1zV1WlxVgYkfs_uukcv7zdlwaOWKiLlY` (req.)
	 * @return array
	 */
	public static function get_google_sheet($sheet_id, $options = []) {
		$url = "https://docs.google.com/spreadsheets/d/$sheet_id/gviz/tq?tqx=out:json";
		$json = file_get_contents($url);

		// Clean the response (remove Google's JS wrapper)
		$json = substr($json, strpos($json, '(') + 1, -2);

		$data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
		if (!isset($data['table']['rows'])) {
			throw new \Exception('JSON had unexpected format. Not table with rows found.');
		}

		$meta = $data;
		unset($meta['table']['rows']);

		$get_values = function($value, $column) use (&$options) {
			if (!is_null($value)) {
				if (!empty($options['parse_datetime']) && $column['type'] === 'datetime') {
					$gs_pattern = str_replace('"', '', $column['pattern']);  // Fix `hh":"mm` so that it becomes `hh:mm`
					$php_format = static::google_sheet_datetime_format_to_php($gs_pattern);
					return static::parse_google_sheets_date($value, $php_format);
				}
				return $value;
			}
			return null;
		};

		$sheet_data = [];
		if (!empty($options['use_column_label'])) {
			foreach ($data['table']['rows'] as $r) {
				$row = [];
				foreach ($r['c'] as $i => $cell) {
					$row[ $data['table']['cols'][$i]['label'] ] = $get_values($cell['v'] ?? null, $data['table']['cols'][$i]);
				}
				$sheet_data[] = $row;
			}
		} else {
			foreach ($data['table']['rows'] as $r) {
				$row = [];
				foreach ($r['c'] as $i => $cell) {
					$row[] = $get_values($cell['v'] ?? null, $data['table']['cols'][$i]);
				}
				$sheet_data[] = $row;
			}
		}

		static::$latest_meta_data = $meta;

		return $sheet_data;
	}

	/**
	 * @param string $date_string : Eg. `Date(2025,0,15,9,30,45)`
	 * @param string|null $format : String according to PHP's date format, see https://www.php.net/manual/en/datetime.format.php. Or leave null to return DateTime object.
	 * @return string|DateTime : String with formatted time or DateTime object
	 */
	public static function parse_google_sheets_date($date_string, $format = null) {
		// Extract numbers from Date(...)
		preg_match_all('/\d+/', $date_string, $matches);
		if (count($matches[0]) < 6) {
			return null; // invalid format
		}
		list($year, $month, $day, $hour, $minute, $second) = $matches[0];

		// Adjust month (Google Sheets months are 0-indexed)
		$month += 1;

		// Build DateTime
		$dt = new \DateTime();
		$dt->setDate($year, $month, $day);
		$dt->setTime($hour, $minute, $second);

		if ($format) {
			return $dt->format($format);
		} else {
			return $dt;
		}
	}

	public static function google_sheet_datetime_format_to_php($pattern) {
		// Mapping for placeholders
		$map = [
			'yyyy' => 'Y',
			'yy' => 'y',
			'mmmm' => 'F',
			'mmm' => 'M',
			'dd' => 'd',
			'd' => 'j',
			'HH' => 'H',
			'hh' => 'H',
			'ss' => 's',
			'AM/PM' => 'A',
			'am/pm' => 'a',
		];

		// First, handle mm ambiguity
		$pattern = preg_replace_callback('/(.*?)(mm)/', function($matches) {
			$before = $matches[1];
			// If there's a ":" or h/H before mm, treat as minutes
			if (preg_match('/[Hh]:?$/', $before)) {
				return $before .'i'; // minutes
			}
			return $before .'m'; // month
		}, $pattern);

		// Replace other placeholders
		$php_format = strtr($pattern, $map);

		return $php_format;
	}

}
