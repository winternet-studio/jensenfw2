<?php
/**
 * Functions related to date and time handling
 */

namespace winternet\jensenfw2;

class datetime {
	public static $scripttimer_loop_start = null;
	public static $scripttimer_loop_meantimecount = null;
	public static $scripttimer_loop_lastmeantime = null;
	public static $scripttimer_start = null;
	public static $scripttimer_current_loop = [];
	public static $scripttimer_table = [];

	public static $_formatters = [];
	public static $_defaultLocale = null;

	/**
	 * Format dates and/or times according to locale settings
	 *
	 * Uses the Intl extension.
	 *
	 * @param DateTime|integer $datetime : DateTime object (timezone not respected) or Unix timestamp or anything IntlDateFormatter::format() accepts.
	 * @param string $format : According to https://www.php.net/manual/en/intldateformatter.setpattern.php. Eg. `EEEE, d. MMMM yyyy`
	 *   - use string `DAYMTH` to automatically insert day and month in correct order. Eg. `EEEE, DAYMTH yyyy`  (comma will automatically be added between month and year when needed)
	 *   - use string `HOUR` to automatically insert hour in 24-hour or 12-hour format. Eg. `HOUR:mm`
	 *   - use string `AMPM` to automatically insert am/pm marker if needed. Eg. `HOUR:mmAMPM`
	 * @param string $locale : ICU locale. Eg. `en_US`, `en-US`, `da_DK` or `nb_NO`
	 * @param string $options : Available options:
	 *   - `short_month`      : set true to use abbreviated month name (Intl automatically determines if dot should be added)    (only applicable if pattern `DAYMTH` is used in `$format`)
	 *   - `short_month_no_dot` : set true to use abbreviated month name instead of fully spelled out, and enforce no trailing dot (only applicable if pattern `DAYMTH` is used in `$format`)
	 *   - `skip_auto_comma_year` : set true to skip automatically handling comma between day and year
	 *   - `short_ampm` : use `a` and `p` instead of `am` and `pm` in times
	 *   - `country` : ISO-3166 alpha-2 country code which will in some cases help with determining formatting, eg. in local time
	 *   - `time_country` : ISO-3166 alpha-2 country code which will help determining formatting in local time but NOT in local date
	 *   - `force_clock` : set to either `12hr` or `24hr` to enforce using a specific 24/12-hour clock setting
	 */
	public static function format_local($datetime, $format, $locale = null, $options = []) {
		$locale = static::determine_locale($locale);

		if (strpos($format, 'DAYMTH') !== false) {
			$dayMonthFormat = static::day_month_local_format($locale, $options);
			$format = str_replace('DAYMTH', $dayMonthFormat, $format);
		}

		$possibly_ampm = false;
		if (strpos($format, 'HOUR') !== false || strpos($format, 'AMPM') !== false) {
			$timeFormat = static::time_local_format($locale, (strpos($format, 'HOUR2') !== false ? array_merge($options, ['twodigit_hour' => true]) : $options) );
			$format = str_replace('HOUR2', $timeFormat['hour'], $format);
			$format = str_replace('HOUR', $timeFormat['hour'], $format);
			$format = str_replace('AMPM', $timeFormat['ampm'], $format);
			$possibly_ampm = true;
		}

		// Automatically add comma between day and year, but not between month and year
		if (!@$options['skip_auto_comma_year'] && strpos($format, 'd yy') !== false) {
			$format = str_replace('d yy', 'd, yy', $format);
		}

		static::$_formatters[$locale]->setPattern($format);
		$output = static::$_formatters[$locale]->format($datetime);
		if (@$options['short_month_no_dot']) {
			$output = preg_replace("/([^0-9]{3,})\\./", '$1', $output);
		}
		if ($possibly_ampm) {
			$output = preg_replace_callback('/([^a-z])(AM|PM)\b/i', function($matches) use (&$options) {
				if (@$options['short_ampm']) {
					return $matches[1] . strtolower(substr($matches[2], 0, 1));
				} else {
					// just convert to lower case
					return $matches[1] . strtolower($matches[2]);
				}
			}, $output);
		}
		return $output;
	}

	/**
	 * Convert several date formats (except Unix) to MySQL format
	 *
	 * - a Javascript equivalent with the same name exists, it works exactly the same way
	 * - TODO: maybe make possible to input Unix timestamp (like set $dateformat and/or $timeformat to 'unix'... or just provide a numeric value for $datetime?)
	 *
	 * @param string $datetime : Date and/or time to convert
	 * @param string $dateformat : Format of the date to convert. Valid values are `dmy`, `mdy`, `ymd` added by a forth character which sets the delimiter which can be anything except a number
	 *   - set to false if `$datetime` only contains a time value
	 *   - see function convert_cc_exp_to_mysql_date() for converting credit card expiration dates
	 * @param string $timeformat : Format of the time to convert. Valid values are `hm`, `hms` added by a forth character which sets the delimiter which can be anything except a number
	 *   - if 12-clock it must be a cathe capitalized `H` (eg. `Hm` or `Hms`)
	 *   - examples:
	 *     - hh:mm            (24hr clock)
	 *     - hh:mm:ss         (24hr clock)
	 *     - hh:mm [am|pm]    (12hr clock, hh = (0)0-23, mm = (0)0-59, with or without space between time and am/pm)
	 *     - hh:mm:ss [am|pm] (12hr clock, hh = (0)1-12, mm = (0)0-59, with or without space between time and am/pm)
	 * @param string $returnonfail : String to return if conversion fails
	 *   - provide string `source` to return the untouched datetime value
	 *     - IMPORTANT: if this is used you MUST validate the datetime later on before using it (preferably on server-side)
	 *   - provide string `empty` to return an empty string
	 *
	 * @return string : MySQL formatted date and/or time
	 *   - or empty string if the values are empty
	 *   - or if conversion fails and $returnonfail is set, it's value is returned
	 */
	public static function to_mysql_datetime($datetime, $dateformat, $timeformat = false, $returnonfail = false) {
		if ($datetime) {
			$output = [];
			$datetime = trim($datetime);
			if ($returnonfail) {
				$skipfail = true;
				if ($returnonfail == 'source') {
					$returnonfail = $datetime;
				} elseif ($returnonfail == 'empty') {
					$returnonfail = '';
				}
			}
			while (strpos($datetime, '  ') !== false) {  //ensure no double spaces
				$datetime = str_replace('  ', ' ', trim($datetime));
			}
			$datetime = preg_replace('/\\s(am|pm)/i', '\\1', $datetime);
			if (strpos($datetime, ' ') && $dateformat != false) {
				//both date AND time
				$parts = explode(' ', $datetime);
				if (count($parts) != 2) {
					if ($skipfail) {
						return $returnonfail;
					} else {
						core::system_error('Invalid date/time to convert to MySQL format.', ['Value' => $datetime]);
					}
				} else {
					$date = $parts[0]; $time = $parts[1];
				}
			} else {
				//only date OR time
				if ($dateformat && $datedelimiter = substr($dateformat, 3, 1)) {
					if (strpos($datetime, $datedelimiter) !== false) {  // the value contains the date delimiter => must be a date
						$iswhat = 'date';
					} else {
						$iswhat = 'time';
					}
				} elseif ($timedelimiter = substr($timeformat, -1, 1)) {
					if (strpos($datetime, $timedelimiter) !== false) {  // the value contains the time delimiter => must be a time
						$iswhat = 'time';
					} else {
						$iswhat = 'date';
					}
				} else {
					if ($skipfail) {
						return $returnonfail;
					} else {
						core::system_error('Could not determine if value was a date or a time.', ['Value' => $datetime]);
					}
				}
				switch ($iswhat) {
					case 'date': $date = $datetime; $time = false; break;
					case 'time': $date = false; $time = $datetime; break;
				}
			}
			if ($date) {
				if (preg_match('/^(dmy|mdy|ymd)(\\W)$/', $dateformat, $match)) {
					$dateorder = $match[1];
					$datedelimiter = $match[2];
					$parts = explode($datedelimiter, $date);
					switch ($dateorder) {
					case 'dmy':
						$output[] = str_pad($parts[2], 4, '0', STR_PAD_LEFT) .'-'. str_pad($parts[1], 2, '0', STR_PAD_LEFT) .'-'. str_pad($parts[0], 2, '0', STR_PAD_LEFT);
						break;
					case 'mdy':
						$output[] = str_pad($parts[2], 4, '0', STR_PAD_LEFT) .'-'. str_pad($parts[0], 2, '0', STR_PAD_LEFT) .'-'. str_pad($parts[1], 2, '0', STR_PAD_LEFT);
						break;
					case 'ymd':
						$output[] = str_pad($parts[0], 4, '0', STR_PAD_LEFT) .'-'. str_pad($parts[1], 2, '0', STR_PAD_LEFT) .'-'. str_pad($parts[2], 2, '0', STR_PAD_LEFT);
						break;
					}
				} else {
					if ($skipfail) {
						return $returnonfail;
					} else {
						core::system_error('Invalid date format for converting to MySQL format.', ['Dateformat' => $dateformat]);
					}
				}
			}
			if ($time) {
				if (preg_match('/^([hH]ms?)(\\W)$/', $timeformat, $match)) {
					$is12hr = (strpos($timeformat, 'H') !== false ? true : false);
					$timeorder = strtolower($match[1]);  //is now either 'hm' or 'hms'
					$timedelimiter = $match[2];
					$time = str_replace(' ', '', strtolower($time));
					$ampm = (strpos($time, 'pm') !== false ? 'pm' : (strpos($time, 'am') !== false ? 'am' : ''));
					if ($ampm) $time = substr($time, 0, -2);
					switch ($timeorder) {
						case 'hm':  list($hours, $mins) = explode($timedelimiter, $time); $secs = '00'; break;
						case 'hms': list($hours, $mins, $secs) = explode($timedelimiter, $time); break;
					}
					if ($is12hr) {
						if ($ampm == 'pm' && $hours <= 11) {
							$hours += 12;
						} elseif ($ampm == 'am' && $hours == 12) {
							$hours = '00';
						}
					}
					$hours = str_pad($hours, 2, '0', STR_PAD_LEFT);  //NOTE: only necessary with hours (mins and secs should always be provided in two digits anyway)
					$output[] = $hours .':'. $mins .':'. $secs;
				} else {
					if ($skipfail) {
						return $returnonfail;
					} else {
						core::system_error('Invalid time format for converting to MySQL format.', ['Timeformat' => $timeformat]);
					}
				}
			}
			$output = implode(' ', $output);
			return addslashes($output);  //using addslashes() is a safety measure. In case the value is invalid (= input for this function is invalid) and we don't use mysqli_real_escape_string() or similar in SQL, the code will still not break.
		} else {
			return '';
		}
	}

	/**
	 * Textually write how long time ago a given timestamp was
	 *
	 * Very similar to [time_period_single_unit()] but always subtracts the timestamp from current time.
	 *
	 * @param string|integer $mysql_or_unix_timestamp : MySQL timestamp or Unix timestamp
	 *   - a MySQL timestamp is assumed to be in UTC unless otherwise specified like this: `2017-03-21 14:24:03 Europe/Copenhagen`
	 * @param array $options : Associative array with any of these options:
	 *   - `unit_names` : default `short`
	 *   - `decimals` : default 0
	 *   - `include_weeks` : default false
	 *   - `smart_general_guide` : for the general guide use expressions like `today` and `yesterday` instead of hours/days
	 *     - also adds the word `ago` after the units or `in` before if time is in the future
	 *   - `output_timezone` : time zone to use for determining `today` and `yesterday` when smart_general_guide is enabled
	 *   - `input_timezone` : time zone a MySQL timestamp in $mysql_or_unix_timestamp is in
	 *   - `hour_adjustment` : hours to add to MySQL date-only timestamps for making a general guide that feels more correct. Default is 12 (= make it noon)
	 *     - set to false to not apply any adjustment
	 *
	 * @return array : Identical to time_period_single_unit() or empty array if timestamp was empty/null/false
	 *   - if option smart_general_guide=true an additional key `relative_guide` is added
	 */
	public static function time_ago($mysql_or_unix_timestamp, $options = []) {
		$unit_names = 'short'; $decimals = 0; $include_weeks = false;
		if (@$options['unit_names']) {
			$unit_names = $options['unit_names'];
		}
		if (is_numeric(@$options['decimals'])) {
			$decimals = $options['decimals'];
		}
		if (@$options['include_weeks']) {
			$include_weeks = true;
		}

		if ($mysql_or_unix_timestamp) {
			if (is_numeric($mysql_or_unix_timestamp)) {
				$ts =& $mysql_or_unix_timestamp;
			} else {
				// MySQL timestamp
				if (@$options['smart_general_guide'] && strlen($mysql_or_unix_timestamp) <= 10 && @$options['hour_adjustment'] !== false) {
					$mysql_or_unix_timestamp .= ' '. ($options['hour_adjustment'] ? $options['hour_adjustment'] : 12) .':00:00';   //set a date like "2017-06-17" to be at eg. noon instead of midnight so that the general guide usually feels more correct
				}
				if (@$options['input_timezone']) {
					$mysql_or_unix_timestamp = $mysql_or_unix_timestamp .' '. $options['input_timezone'];
				} elseif (stripos($mysql_or_unix_timestamp, 'UTC') === false && strpos($mysql_or_unix_timestamp, '/') === false) {
					$mysql_or_unix_timestamp = $mysql_or_unix_timestamp .' UTC';
				}
				$ts = strtotime($mysql_or_unix_timestamp);
			}

			$now_ts = time();

			$return = static::time_period_single_unit($now_ts - $ts, $unit_names, $decimals, $include_weeks);

			if (@$options['smart_general_guide']) {
				$last_midnight = (new \DateTime('today midnight', new \DateTimeZone((@$options['output_timezone'] ? $options['output_timezone'] : 'UTC'))))->getTimestamp();
				if ($ts < $last_midnight - 24*3600) {
					$return['general_guide'] = $return['general_guide'] .' '. core::txt('ago', 'ago', '#');
					$return['relative_guide'] = 'ago';
				} elseif ($ts < $last_midnight) {
					$return['general_guide'] = core::txt('yesterday', 'yesterday', '#');
					$return['relative_guide'] = 'yesterday';
				} elseif ($ts < $last_midnight + 24*3600) {
					$return['general_guide'] = core::txt('today', 'today', '#');
					$return['relative_guide'] = 'today';
				} elseif ($ts < $last_midnight + 48*3600) {
					$return['general_guide'] = core::txt('tomorrow', 'tomorrow', '#');
					$return['relative_guide'] = 'tomorrow';
				} else {
					$return['general_guide'] = core::txt('in', 'in', '#') .' '. ltrim($return['general_guide'], '-');  //remove leading minus
					$return['relative_guide'] = 'in';
				}
			}
			return $return;
		} else {
			return [];
		}
	}

	/**
	 * Textually write a time period/duration, like in a countdown or "time since". Days, hours, minutes, and seconds
	 *
	 * This differs from time_period_single_unit() in that it outputs ALL units (eg. hours, minutes, and seconds) instead of only one unit (eg. minutes).
	 * This divides the duration up into eg. hours, minutes and seconds whereas time_period_single_unit() determines the whole period/duration in EITHER days, hours, minutes etc.
	 *
	 * @param integer $time : Duration/length in seconds (like unix timestamp)
	 * @param boolean $include_zeros : Whether or not values of null should be included in the `fulltext` output
	 *
	 * @return array : Associated array with days (`days`), hours (`hours`), minutes (`mins`), seconds (`secs`), and full textual representation (`fulltext`)
	 */
	public static function time_period_all_units($time, $include_zeros = false, $unit_names = 'long') {
		//determine unit names
		switch ($unit_names) {
		case 'ultrashort':
			$lbl_secs_one = 's'; $lbl_secs_more = 's';
			$lbl_mins_one = 'm'; $lbl_mins_more = 'm';
			$lbl_hours_one = 'h'; $lbl_hours_more = 'h';
			$lbl_days_one = 'd'; $lbl_days_more = 'd';
			$lbl_weeks_one = 'w'; $lbl_weeks_more = 'w';
			$lbl_mnths_one = 'mo'; $lbl_mnths_more = 'mos';
			$lbl_years_one = 'y'; $lbl_years_more = 'y';
			$spacing = '';
			break;
		case 'short':
			$lbl_secs_one = core::txt('second_short', 'sec.', '#'); $lbl_secs_more = core::txt('seconds_short', 'sec.', '#');
			$lbl_mins_one = core::txt('minute_short', 'min.', '#'); $lbl_mins_more = core::txt('minutes_short', 'min.', '#');
			$lbl_hours_one = core::txt('hour_short', 'hr', '#'); $lbl_hours_more = core::txt('hours_short', 'hrs', '#');
			$lbl_days_one = core::txt('day_short', 'day', '#'); $lbl_days_more = core::txt('days_short', 'days', '#');
			$lbl_weeks_one = core::txt('week_short', 'week', '#'); $lbl_weeks_more = core::txt('weeks_short', 'weeks', '#');
			$lbl_mnths_one = core::txt('month_short', 'month', '#'); $lbl_mnths_more = core::txt('months_short', 'months', '#');
			$lbl_years_one = core::txt('year_short', 'year', '#'); $lbl_years_more = core::txt('years_short', 'yrs', '#');
			$spacing = ' ';
			break;
		case 'long':
			$lbl_secs_one = core::txt('second', 'second', '#'); $lbl_secs_more = core::txt('seconds', 'seconds', '#');
			$lbl_mins_one = core::txt('minute', 'minute', '#'); $lbl_mins_more = core::txt('minutes', 'minutes', '#');
			$lbl_hours_one = core::txt('hour', 'hour', '#'); $lbl_hours_more = core::txt('hours', 'hours', '#');
			$lbl_days_one = core::txt('day', 'day', '#'); $lbl_days_more = core::txt('days', 'days', '#');
			$lbl_weeks_one = core::txt('week', 'week', '#'); $lbl_weeks_more = core::txt('weeks', 'weeks', '#');
			$lbl_mnths_one = core::txt('month', 'month', '#'); $lbl_mnths_more = core::txt('months', 'months', '#');
			$lbl_years_one = core::txt('year', 'year', '#'); $lbl_years_more = core::txt('years', 'years', '#');
			$spacing = ' ';
			break;
		default:
			core::system_error('Configuration error. Unit format not defined.', ['Unit name' => $unit_names]);
		}

		//calculate the different valus
		$days = ($time - ($time % 86400)) / 86400;
		$time = $time - ($days * 86400);
		$hours = ($time - ($time % 3600)) / 3600;
		$time = $time - ($hours * 3600);
		$mins = ($time - ($time % 60)) / 60;
		$time = $time - ($mins * 60);
		$secs = $time;
		//make the "fulltext" - a complete textual representation
		if ($include_zeros || (!$include_zeros && $days != 0)) {
			$fulltext  = ($days == 1) ? "1 ". $lbl_days_one .", " : $days ." ". $lbl_days_more .", ";
		}
		if ($include_zeros || (!$include_zeros && $hours != 0)) {
			$fulltext .= ($hours == 1) ? "1 ". $lbl_hours_one .", " : $hours ." ". $lbl_hours_more .", ";
		}
		if ($include_zeros || (!$include_zeros && $mins != 0)) {
			$fulltext .= ($mins == 1) ? "1 ". $lbl_mins_one .", " : $mins ." ". $lbl_mins_more .", ";
		}
		if ($include_zeros || (!$include_zeros && $secs != 0)) {
			$fulltext .= ($secs == 1) ? "1 ". $lbl_secs_one : $secs ." ". $lbl_secs_more;
		}
		if (substr($fulltext, strlen($fulltext)-2, 2) == ', ') $fulltext = substr($fulltext, 0, strlen($fulltext)-2);  //remove trailing comma and space if exists
		$fulltext = trim($fulltext);
		return [
			'days' => $days,
			'hours' => $hours,
			'mins' => $mins,
			'secs' => $secs,
			'fulltext' => $fulltext
		];
	}

	/**
	 * Textually write a time period/duration with a single unit, either days, hours, minutes, seconds - depending on how long the duration is
	 *
	 * The method also returns a value ('general_guide') where the appropriate unit is automatically determined, based on what is meaningful for the reader to know (this will of course not be the exact time, but only a general guide).
	 *
	 * This differs from [time_period_all_units()] in that this only returns a single unit, [time_period_all_units()] returns ALL units.
	 * See [time_period_all_units()] for further explanation.
	 *
	 * Maybe it would be useful even to make one a function returning two units - for better precision when you want it!
	 *
	 * @param integer $time : Duration/length in seconds (like unix timestamp)
	 * @param string $unit_names : Whether `short` (= abbreviated) or `long` unit names should be used in the `general_guide`
	 * @param integer $decimals : Number of decimals to use in the `general_guide` number
	 *   - can be used to obtain much greater precision
	 * @param boolean $include_weeks : Whether or not to use the time in weeks for the `general_guide`
	 *   - most often you would probably want to use the days up until you have one month
	 *
	 * @return array : Associative array. The unit for the `general_guide` is specified in `appropriate_unit`
	 */
	public static function time_period_single_unit($time, $unit_names = 'short', $decimals = 0, $include_weeks = false) {
		//determine unit names
		switch ($unit_names) {
		case 'ultrashort':
			$lbl_secs_one = 's'; $lbl_secs_more = 's';
			$lbl_mins_one = 'm'; $lbl_mins_more = 'm';
			$lbl_hours_one = 'h'; $lbl_hours_more = 'h';
			$lbl_days_one = 'd'; $lbl_days_more = 'd';
			$lbl_weeks_one = 'w'; $lbl_weeks_more = 'w';
			$lbl_mnths_one = 'mo'; $lbl_mnths_more = 'mos';
			$lbl_years_one = 'y'; $lbl_years_more = 'y';
			$spacing = '';
			break;
		case 'short':
			$lbl_secs_one = core::txt('second_short', 'sec.', '#'); $lbl_secs_more = core::txt('seconds_short', 'sec.', '#');
			$lbl_mins_one = core::txt('minute_short', 'min.', '#'); $lbl_mins_more = core::txt('minutes_short', 'min.', '#');
			$lbl_hours_one = core::txt('hour_short', 'hr', '#'); $lbl_hours_more = core::txt('hours_short', 'hrs', '#');
			$lbl_days_one = core::txt('day_short', 'day', '#'); $lbl_days_more = core::txt('days_short', 'days', '#');
			$lbl_weeks_one = core::txt('week_short', 'week', '#'); $lbl_weeks_more = core::txt('weeks_short', 'weeks', '#');
			$lbl_mnths_one = core::txt('month_short', 'month', '#'); $lbl_mnths_more = core::txt('months_short', 'months', '#');
			$lbl_years_one = core::txt('year_short', 'year', '#'); $lbl_years_more = core::txt('years_short', 'yrs', '#');
			$spacing = ' ';
			break;
		case 'long':
			$lbl_secs_one = core::txt('second', 'second', '#'); $lbl_secs_more = core::txt('seconds', 'seconds', '#');
			$lbl_mins_one = core::txt('minute', 'minute', '#'); $lbl_mins_more = core::txt('minutes', 'minutes', '#');
			$lbl_hours_one = core::txt('hour', 'hour', '#'); $lbl_hours_more = core::txt('hours', 'hours', '#');
			$lbl_days_one = core::txt('day', 'day', '#'); $lbl_days_more = core::txt('days', 'days', '#');
			$lbl_weeks_one = core::txt('week', 'week', '#'); $lbl_weeks_more = core::txt('weeks', 'weeks', '#');
			$lbl_mnths_one = core::txt('month', 'month', '#'); $lbl_mnths_more = core::txt('months', 'months', '#');
			$lbl_years_one = core::txt('year', 'year', '#'); $lbl_years_more = core::txt('years', 'years', '#');
			$spacing = ' ';
			break;
		default:
			core::system_error('Configuration error. Unit format not defined.', ['Unit name' => $unit_names]);
		}
		//calculate the time in the different units
		$times['seconds']  = $time;
		$times['minutes']  = $times['seconds'] / 60;
		$times['hours']    = $times['minutes'] / 60;
		$times['days']     = $times['hours'] / 24;
		$times['days_rounded'] = round($times['days'], $decimals);
		$times['weeks']    = $times['days'] / 7;
		$times['months']   = $times['days'] / 30;
		$times['years']    = $times['days'] / 365;
		if (abs($times['seconds']) < 60) {  //NOTE: use abs() so we also handle negative periods correctly
			$times['general_guide'] = ($times['seconds'] == 1 ? '1'. $spacing . $lbl_secs_one : $times['seconds'] . $spacing . $lbl_secs_more);
			$times['appropriate_unit'] = 'seconds';
		} elseif (round(abs($times['minutes']), $decimals) < 60) {
			$rounded = round($times['minutes'], $decimals);
			$times['general_guide'] = ($rounded == 1 ? '1'. $spacing . $lbl_mins_one : $rounded . $spacing . $lbl_mins_more);
			$times['appropriate_unit'] = 'minutes';
		} elseif (round(abs($times['hours']), $decimals) < 24) {
			$rounded = round($times['hours'], $decimals);
			$times['general_guide'] = ($rounded == 1 ? '1'. $spacing . $lbl_hours_one : $rounded . $spacing . $lbl_hours_more);
			$times['appropriate_unit'] = 'hours';
		} elseif ( (!$include_weeks && abs($times['days_rounded']) < 30)  ||  ($include_weeks && abs($times['days_rounded']) < 7) ) {  //if weeks are used, the period of using days is shorter
			$times['general_guide'] = ($times['days_rounded'] == 1 ? '1'. $spacing . $lbl_days_one : $times['days_rounded'] . $spacing . $lbl_days_more);
			$times['appropriate_unit'] = 'days';
		} elseif ($include_weeks && round(abs($times['weeks']), $decimals) < 5 && abs($times['days']) < 30) {  //skip to month if 30 or more days
			$rounded = round($times['weeks'], $decimals);
			$times['general_guide'] = ($rounded == 1 ? '1'. $spacing . $lbl_weeks_one : $rounded . $spacing . $lbl_weeks_more);
			$times['appropriate_unit'] = 'weeks';
		} elseif (round(abs($times['months']), $decimals) < 12) {
			$rounded = round($times['months'], $decimals);
			$times['general_guide'] = ($rounded == 1 ? '1'. $spacing . $lbl_mnths_one : $rounded . $spacing . $lbl_mnths_more);
			$times['appropriate_unit'] = 'months';
		} else {
			//else write in years
			$rounded = round(abs($times['years']), $decimals);
			$times['general_guide'] = ($rounded == 1 ? '1'. $spacing . $lbl_years_one : $rounded . $spacing . $lbl_years_more);
			$times['appropriate_unit'] = 'years';
		}
		return $times;
	}

	/**
	 * Textually write a time period/duration with a custom defined number of units
	 *
	 * @param integer $time : Duration/length in seconds (like unix timestamp)
	 * @param string $unit_names : Whether `short` (= abbreviated) or `long` unit names should be used in the `general_guide`
	 *   - OBS!! CURRENTLY THIS HAS NO EFFECT AS time_period_all_units() FIRST NEEDS TO HAVE THIS FEATURE IMPLEMENTED TOO
	 * @param integer $no_of_units : Number of units you want, the more units the more precise the presentation will be
	 *
	 * @return string : Eg. `2 days, 17 hours`, `15 minutes, 14 seconds`, `4 days, 6 hrs, 38 min.`
	 */
	public static function time_period_custom_units($time, $unit_names = 'short', $no_of_units = 2) {
		if (!is_numeric($no_of_units)) {
			core::system_error('Invalid number of units for writing a time period.');
		}
		$output = [];
		$allunits = static::time_period_all_units($time, false, 'short');
		$textparts = explode(',', $allunits['fulltext']);
		$textparts_count = count($textparts);
		for ($i = 1; $i <= $no_of_units && $i <= $textparts_count; $i++) {
			$output[] = trim($textparts[$i-1]);
		}
		return implode(', ', $output);
	}

	/**
	 * Formats a time period nicely
	 *
	 * @param integer|string $from_date : Date in Unix or MySQL format
	 * @param integer|string $to_date   : Date in Unix or MySQL format
	 * @param array $options : Associative array with any combination of these keys:
	 *   - `2digit_year` : set true to only show 2 digits in the year(s)
	 *   - `no_year` : set true to don't show year at all
	 *   - `always_abbrev_months` : set true to don't spell out fully the short months March, April, May, June and July
	 *   - `never_abbrev_months` : set true to always spell out fully the month names
	 *   - `no_dot_after_month` : set true to not show dot after abbreviated month name
	 *   - `input_timezone` : timezone of input when it is in MySQL format and it is not UTC
	 *   - `output_timezone` : timezone to use for the output. Defaults to system timezone.
	 *   - `short_months` : provide array with month numbers of month names that in the given locale (= language) are already short and do not need to be abbreviated unless specifically requested. Default is `[3, 4, 5, 6, 7]`.
	 *
	 * @return string : Eg. `Dec. 3-5, 2010` or `Nov. 30 - Dec. 4, 2010` or `Dec. 27, 2010 - Jan. 2, 2011`
	 */
	public static function format_timeperiod($from_date, $to_date, $options = []) {
		// Backward compatibility to when $options could be a string or null
		if (is_string($options)) {
			$newoptions = [];
			if (strpos($options, '2digit_year') !== false) $newoptions['2digit_year'] = true;
			if (strpos($options, 'noyear') !== false) $newoptions['no_year'] = true;
			if (strpos($options, 'always_abbrev_months') !== false) $newoptions['always_abbrev_months'] = true;
			if (strpos($options, 'never_abbrev_months') !== false) $newoptions['never_abbrev_months'] = true;
			$options = $newoptions;
		} elseif (!is_array($options)) {
			$options = [];
		}

		// Prepare for using a specific locale
		if (array_key_exists('_locale', $options)) {
			$locale = static::determine_locale($options['_locale']);
			$formatter =& static::$_formatters[$locale];

			$month_before_date = static::day_month_local_format($locale, ['return_boolean' => true]);

			$dot_after_date = false;
			$locales_countries = static::uses_dot_after_date();
			if (in_array($locale, $locales_countries, true) || (@$options['country'] && in_array($options['country'], $locales_countries, true))) {
				$dot_after_date = true;
			}
		} else {
			$locale = null;
		}

		// Streamline input into DateTime objects
		if (!is_numeric($from_date)) {
			if (@$options['input_timezone']) {
				$from_date = new \DateTime($from_date, new \DateTimeZone($options['input_timezone']));
			} else {
				$from_date = new \DateTime($from_date, new \DateTimeZone('UTC'));
			}
		} else {
			$from_date = new \DateTime($from_date);
		}
		if (!is_numeric($to_date)) {
			if (@$options['input_timezone']) {
				$to_date = new \DateTime($to_date, new \DateTimeZone($options['input_timezone']));
			} else {
				$to_date = new \DateTime($to_date, new \DateTimeZone('UTC'));
			}
		} else {
			$to_date = new \DateTime($to_date);
		}

		// Handle timezone options
		if (@$options['output_timezone']) {
			$timezone = new \DateTimeZone($options['output_timezone']);
			$from_date->setTimezone($timezone);
			$to_date->setTimezone($timezone);
			if ($locale) {
				$formatter->setTimeZone($timezone);
			}
		} else {
			// in this case only mess with timezone if an *input* timezone was specified, otherwise leave everything in the same timezone to "ignore" timezone handling
			if (@$options['input_timezone']) {
				$from_date->setTimezone(new \DateTimeZone(date_default_timezone_get()));
				$to_date->setTimezone(new \DateTimeZone(date_default_timezone_get()));
			}
		}

		$yr_mode = (@$options['2digit_year'] ? '2dig' : (@$options['no_year'] ? 'noyr' : '4dig'));
		$monthdot = (@$options['no_dot_after_month'] ? '' : '.');

		$conditional_comma = function($string) {
			// Only add comma before year if the part before ends on a digit
			if (in_array(substr($string, -1), ['0', '1', '2', '3', '4', '5', '6', '7', '8', '9'], true)) {
				return ',';
			} else {
				return '';
			}
		};

		// Determine "to" and "from" month names
		if (@$options['never_abbrev_months']) {
			if ($locale) {
				$formatter->setPattern('MMMM');
				$from_month = $formatter->format($from_date);
				$to_month   = $formatter->format($to_date);
			} else {
				$from_month = $from_date->format('F');
				$to_month   = $to_date->format('F');
			}
		} elseif (!@$options['always_abbrev_months']) {
			$short_months = (is_array(@$options['short_months']) ? $options['short_months'] : [3, 4, 5, 6, 7]);
			if (in_array($from_date->format('n'), $short_months)) {
				if ($locale) {
					$formatter->setPattern('MMMM');
					$from_month = $formatter->format($from_date);
				} else {
					$from_month = $from_date->format('F');
				}
			} else {
				if ($locale) {
					$formatter->setPattern('MMM');  //the formatter automatically adds month dot if needed, so no need to use $monthdot
					$from_month = $formatter->format($from_date);
					if (@$options['no_dot_after_month']) {  //remove what formatter automatically might have added
						$from_month = rtrim($from_month, '.');
					}
				} else {
					$from_month = $from_date->format('M'. $monthdot);
				}
			}
			if (in_array($to_date->format('n'), $short_months)) {
				if ($locale) {
					$formatter->setPattern('MMMM');
					$to_month = $formatter->format($to_date);
				} else {
					$to_month = $to_date->format('F');
				}
			} else {
				if ($locale) {
					$formatter->setPattern('MMM');  //the formatter automatically adds month dot if needed, so no need to use $monthdot
					$to_month = $formatter->format($to_date);
					if (@$options['no_dot_after_month']) {  //remove what formatter automatically might have added
						$to_month = rtrim($to_month, '.');
					}
				} else {
					$to_month = $to_date->format('M'. $monthdot);
				}
			}
		} else {
			// always_abbrev_months = true
			if ($locale) {
				$formatter->setPattern('MMM');
				$from_month = $formatter->format($from_date);
				$to_month   = $formatter->format($to_date);
				if (@$options['no_dot_after_month']) {  //remove what formatter automatically might have added
					$from_month = rtrim($from_month, '.');
					$to_month = rtrim($to_month, '.');
				}
			} else {
				$from_month = $from_date->format('M'. $monthdot);
				$to_month = $to_date->format('M'. $monthdot);
			}
		}

		if ($yr_mode !== 'noyr') {
			$from_year = $from_date->format('Y');
			$to_year = $to_date->format('Y');
		} else {
			$from_year = $to_year = '';
		}

		// Build the "from" part, eg. "Oct. 18", or "18" if month comes after date
		$from_month_cond = null;
		if (!$locale) {
			$output = $from_month .' '. $from_date->format('j');
		} else {
			$formatter->setPattern('d');
			if ($month_before_date) {
				$output = $from_month .' '. $formatter->format($from_date);
			} else {
				$output = $formatter->format($from_date);
				$from_month_cond = $from_month;  //must be added AFTER the ending date has been added below
			}
		}

		if ($from_date->format('Y-m-d') == $to_date->format('Y-m-d')) {
			// Only one day, don't write ending date, eg. "Oct. 18, 2020" or "18. okt. 2020"
			if ($locale && $from_month_cond) {
				if ($dot_after_date) {
					$output .= '.';
				}
				$output .= ' '. $from_month_cond;
			}
		} else {
			if ($from_month == $to_month && $from_year == $to_year) {
				// Month and year are the same, eg. "Oct. 18-22, 2020" or "18-22. okt. 2020"
				$output .= '-'. $to_date->format('j');
				if ($locale && $from_month_cond) {
					if ($dot_after_date) {
						$output .= '.';
					}
					$output .= ' '. $from_month_cond;
				}
			} else {
				// build "to" month and date when are the same for the remaining two scenarios below
				if (!$locale) {
					$to_month_and_date = $to_month .' '. $to_date->format('j');
				} else {
					$formatter->setPattern('d');
					if ($month_before_date) {
						$to_month_and_date = $to_month .' '. $formatter->format($to_date);
					} else {
						$to_month_and_date = $formatter->format($to_date);
						if ($dot_after_date) {
							$to_month_and_date .= '.';
						}
						$to_month_and_date .= ' '. $to_month;
					}
				}

				// also common for the two scenarios
				if ($locale && $from_month_cond) {
					if ($dot_after_date) {
						$output .= '.';
					}
					$output .= ' '. $from_month_cond;
				}

				// Remaining two possible scenarios...
				if ($from_year == $to_year) {
					// Months are not the same, eg. "Oct. 18 - Nov. 22, 2020" or "18. okt. - 22. nov. 2020"
					$output .= ' - '. $to_month_and_date;
				} else {
					// Years are not the same, eg. "June 18, 2020 - Nov. 22, 2021" or "18. juni 2020 - 22. nov. 2021"
					$output .= $conditional_comma($output) .' '. ($yr_mode == '2dig' ? "'". substr($from_year, 2) : $from_year) .' - '. $to_month_and_date;
				}
			}
		}
		// Add "to" year
		if ($yr_mode != 'noyr') {
			$output .= $conditional_comma($output) .' '. ($yr_mode == '2dig' ? "'". substr($to_year, 2) : $to_year);
		}

		return $output;
	}

	/**
	 * Formats a time period nicely according to a locale
	 *
	 * @param array $options : Same as for `format_timeperiod()` plus these:
	 *   - `country` : ISO-3166 alpha-2 country code which will in some cases help with determining formatting, eg. whether dot should be used after the date
	 */
	public static function format_timeperiod_local($from_date, $to_date, $locale = null, $options = []) {
		$options['_locale'] = $locale;
		return static::format_timeperiod($from_date, $to_date, $options);
	}

	/**
	 * Change the timezone of a given timestamp
	 *
	 * @param string $datetime : Timestamp to change. MySQL format: yyyy-mm-dd hh:mm:ss (or yyyy-mm-dd hh:mm, or any format accepted by the PHP DateTime constructor)
	 *   - yyyy-mm-dd can also be used but conversion will then always be based on midnight of that date and might therefore be incorrect
	 * @param string $curr_timezone : The current timezone of the timestamp, according to http://php.net/manual/en/timezones.php
	 * @param string $new_timezone : The timezone to convert the timestamp to, according to http://php.net/manual/en/timezones.php
	 * @param string $format : (opt.) Date format to return according to DateTime->format()
	 *
	 * @return string : MySQL formatted timestamp or according to $format if specified
	 */
	public static function change_timestamp_timezone($datetime, $curr_timezone, $new_timezone, $format = false) {
		if (!$format) {
			$format = 'Y-m-d H:i:s';
		}
		if ($datetime) {
			$timestamp = new \DateTime($datetime, new \DateTimeZone($curr_timezone));
			$timestamp->setTimezone(new \DateTimeZone($new_timezone));
			return $timestamp->format($format);
		} else {
			return '';
		}
	}

	/**
	 * Determine a person's age at a given date, when knowing their birth year and month
	 *
	 * @param string|integer $at_date : Date for which to calculate the person's age, MySQL format or Unix timestamp or anything that strtotime() parses
	 * @param string|integer $birthyear : Year person was born
	 *   - can also be set to a complete MySQL date (yyyy-mm-dd) or a Unix timestamp. Then set month and day to blank values ('', false or null)
	 * @param string|integer $birthmonth : Month person was born (numeric)
	 * @param string|integer $birthday : Day of month person was born, or one of these strings if unknown:
	 *   - `chance_of_being_older`   : results in a chance people being actually older   than calculated here
	 *   - `chance_of_being_younger` : results in a chance people being actually younger than calculated here
	 *
	 * @return integer : Age
	 */
	public static function calculate_age($at_date, $birthyear, $birthmonth, $birthday) {
		if ($birthday && !is_numeric($birthday) && $birthday != 'chance_of_being_older' && $birthday != 'chance_of_being_younger') {
			core::system_error('Invalid day of month for calculating age.');
		}

		if (!is_numeric($at_date)) {
			$at_date = strtotime($at_date);  //also come here for the MySQL format
		}
		$at_year = (int) date('Y', $at_date);
		$at_month = (int) date('n', $at_date);
		$at_day = (int) date('j', $at_date);

		// Option to provide a MySQL format as birth date (using the $birthyear argument)
		if (!is_numeric($birthyear) && preg_match("|^(\\d{4})-(\\d{1,2})-(\\d{1,2})$|", $birthyear, $parts)) {
			//is a MySQL date
			$birthday = (int) $parts[3];
			$birthmonth = (int) $parts[2];
			$birthyear = (int) $parts[1];
		} elseif ($birthyear > 9999) {
			//must be a Unix timestamp
			$birthday = (int) date('j', $birthyear);
			$birthmonth = (int) date('n', $birthyear);
			$birthyear = (int) date('Y', $birthyear);
		} else {
			//ensure each argument is an integer (due to mktime() )
			$birthday = (int) $birthday;
			$birthmonth = (int) $birthmonth;
			$birthyear = (int) $birthyear;
		}

		// Set birth date of month if not provided
		if ($birthday == 'chance_of_being_older') {
			// NOTE: assuming their birth date on the 31st on the month results in some people being actually older   than this calculated age
			$birthday = 31;
		} elseif ($birthday == 'chance_of_being_younger') {
			// NOTE: assuming their birth date on the  1st on the month results in some people being actually younger than this calculated age
			$birthday = 1;
		}

		$age = $at_year - $birthyear;  //basic calculation
		if (mktime(0,0,0, $at_month, $at_day, $at_year) >= mktime(0,0,0, $birthmonth, $birthday, $at_year)) {  //the equal sign results in the person being the "new" age on the day of his/her birthdate
			$had_birthday_in_year = true;
		} else {
			$had_birthday_in_year = false;
		}
		if (!$had_birthday_in_year) {  //a person having bith
			$age -= 1;  //person haven't had birth day yet in this year, subtract one
		}
		return $age;
	}

	/**
	 * Get the quarter of a given date
	 */
	public static function get_quarter($date = null) {
		if (!$date) {
			$date = new \DateTime('now');
		} elseif (is_string($date)) {
			$date = new \DateTime($date);
		}

		return ceil($date->format('n') / 3);
	}

	/**
	 * @param string $period : Currently only supporters `quarter`. TODO: implement `halfyear`
	 * @param DateTime $as_of_date : Specify as of which date if not current date/time
	 * @return array : Object with properties being a DateTime object (only the date is relevant, not the time)
	 */
	public static function get_previous_period_dates($period, ?\DateTime $as_of_date = null) {
		if (!$as_of_date) {
			$as_of_date = new \DateTimeImmutable('today');
		} elseif (!($as_of_date instanceof \DateTimeImmutable)) {
			$as_of_date = \DateTimeImmutable::createFromMutable($as_of_date);
		}

		if ($period == 'quarter') {
			$current_quarter = static::get_quarter($as_of_date);

			if ($current_quarter == 1) {
				// Previous quarter ends in December of the previous year
				$start = $as_of_date->modify('first day of October last year');
				$end = $as_of_date->modify('last day of December last year');
				$period_number  = 4;
			} else {
				// Previous quarter ends in the month before the current quarter's start month
				$month = $as_of_date->format('n');
				$months_since_previous_quarter = $month % 3;
				if ($months_since_previous_quarter == 0) $months_since_previous_quarter = 3;

				$start = $as_of_date->modify('first day of '. ($months_since_previous_quarter + 2) .' months ago');
				$end = $as_of_date->modify('last day of '. $months_since_previous_quarter .' months ago');
				$period_number = $current_quarter - 1;
			}
		} else {
			core::system_error('Calculating dates for this perioud has not yet been implemented.', ['Period' => $period]);
		}

		return (object) [
			'start_date' => $start,
			'end_date' => $end,
			'period_number' => $period_number,
		];
	}

	/**
	 * @param string $period : eg. `5min` or `6h` or `14d`
	 * @param array $options : Available options:
	 *   - `timezone` : set timezone, eg. `Europe/Copenhagen`. Defaults to system timezone.
	 *   - `null_on_fail` : set true to return null if `$period` doesn't hold the right format
	 */
	public static function period_to_datetime($period, $options = []) {
		if (preg_match('/^(\\d+)(h|d|min)$/i', $period, $match)) {
			if (@$options['timezone']) {
				$options['timezone'] = new \DateTimeZone($options['timezone']);
			}
			switch ($match[2]) {
			case 'h':
				return new \DateTime('+'. $match[1] .' hours', @$options['timezone']);
				break;
			case 'd':
				return new \DateTime('+'. $match[1] .' days', @$options['timezone']);
				break;
			case 'min':
				return new \DateTime('+'. $match[1] .' minutes', @$options['timezone']);
				break;
			default:
				core::system_error('Undefined unit for converting period to date/time.', ['Unit' => $unit]);
			}
		} else {
			if (@$options['null_on_fail']) {
				return null;
			} else {
				core::system_error('Incorrect format for converting period to date/time.', ['Period' => $period]);
			}
		}
	}

	public static function scripttimer_start() {
		static::$scripttimer_current_loop = [];

		static::$scripttimer_loop_start = microtime(true);
		if (!static::$scripttimer_start) {
			static::$scripttimer_start = static::$scripttimer_loop_start;
		}
		return static::$scripttimer_loop_start;
	}
	/**
	 * @param string|number $reference : Reference to the point in the code, usually line number using `__LINE__`
	 */
	public static function scripttimer_meantime($reference = null, $writetext = false) {
		if (!static::$scripttimer_loop_start) {
			$html = '<div style="color: orangered"><b>Timer was not started!</b></div>';
			if (PHP_SAPI == 'cli') {
				echo strip_tags($html);
			} else {
				echo $html;
			}
			return;
		}
		$scripttimer_meantime = microtime(true);
		$duration = number_format($scripttimer_meantime - static::$scripttimer_loop_start, 3);
		if ($writetext) {
			$backtrace = debug_backtrace();
			$html = '<div style="color: orangered" title="'. $backtrace[0]['file'] .':'. $backtrace[0]['line'] .'"><b>Meantime #'. ++static::$scripttimer_loop_meantimecount .', line '. $backtrace[0]['line'] .': '. $duration .''. (static::$scripttimer_loop_lastmeantime ? ' ('. number_format($scripttimer_meantime - static::$scripttimer_loop_lastmeantime, 3) .')' : '') .'</b></div>';
			if (PHP_SAPI == 'cli') {
				echo strip_tags($html);
			} else {
				echo $html;
			}
		}
		static::$scripttimer_loop_lastmeantime = $scripttimer_meantime;

		if (isset($reference)) {
			static::$scripttimer_current_loop[$reference] = $duration;
		} else {
			static::$scripttimer_current_loop[] = $duration;
		}

		return $duration;
	}
	public static function scripttimer_stop($writetext = false) {
		if (!static::$scripttimer_loop_start) {
			$html = '<div style="color: orangered"><b>Timer was not started!</b></div>';
			if (PHP_SAPI == 'cli') {
				echo strip_tags($html);
			} else {
				echo $html;
			}
			return null;
		}
		$scripttimer_end = microtime(true);
		$duration = number_format($scripttimer_end - static::$scripttimer_loop_start, 3);
		if ($writetext) {
			$backtrace = debug_backtrace();
			$html = '<div style="color: orangered" title="'. $backtrace[0]['file'] .':'. $backtrace[0]['line'] .'"><b>Full duration, line '. $backtrace[0]['line'] .': '. $duration .' seconds.'. (static::$scripttimer_loop_meantimecount ? ' Meantimes average: '. number_format($duration / static::$scripttimer_loop_meantimecount, 3) : '') .'</b></div>';
			if (PHP_SAPI == 'cli') {
				echo strip_tags($html);
			} else {
				echo $html;
			}
		}
		static::$scripttimer_loop_meantimecount = false;  //clear it
		static::$scripttimer_loop_lastmeantime = false;  //clear it

		static::$scripttimer_current_loop['End'] = $duration;
		static::$scripttimer_table[] = static::$scripttimer_current_loop;

		return $duration;
	}

	public static function scripttimer_report($write_to_file = null) {
		$total_duration = number_format(microtime(true) - static::$scripttimer_start, 3);
		ob_start();
?>
<h1>Performance Report</h1>
<?= date('Y-m-d H:i:s') ?>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<table class="table table-striped table-bordered table-sm">
<tr>
<?php
		$shortest_loop = null;
		$longest_loop = 0;
		foreach (current(static::$scripttimer_table) as $ref => $duration) {
?>
	<th><?= $ref ?></th>
<?php
			if ($duration > $longest_loop) {
				$longest_loop = $duration;
			}
			if ($shortest_loop === null || $duration < $shortest_loop) {
				$shortest_loop = $duration;
			}
		}
?>
</tr>
<?php
		$loop = 0;
		foreach (static::$scripttimer_table as $datapoints) {
			$loop++;
?>
<tr title="Loop #<?= $loop ?>">
<?php
			$prev_duration = 0;
			foreach ($datapoints as $notused => $duration) {
				$percentage = round(($duration - $prev_duration) / $longest_loop * 100);
?>
	<td title="Duration: <?= $duration - $prev_duration ?>"><?= $duration ?><br><div style="width: <?= $percentage ?>px; height: 8px; background-color: #ff9019; display: inline-block"></div></td>
<?php
				$prev_duration = $duration;
			}
?>
</tr>
<?php
		}
?>
</table>
<div>Shortest Loop Duration: <?= $shortest_loop ?> secs</div>
<div>Longest Loop Duration: <?= $longest_loop ?> secs</div>
<div>Total Duration: <?= $total_duration ?> secs</div>
<?php
		$html = ob_get_clean();
		if ($write_to_file) {
			file_put_contents($write_to_file, $html);
		}
		return $html;
	}


	/**
	 * Set default locale
	 *
	 * Uses the Intl extension.
	 *
	 * Works together with [format_local()]
	 *
	 * @param string $locale : ICU locale. Eg. `en_US`, `da_DK` or `nb_NO`
	 */
	public static function set_default_locale($locale) {
		static::$_defaultLocale = $locale;
		if (!array_key_exists($locale, static::$_formatters)) {
			static::$_formatters[$locale] = new \IntlDateFormatter($locale, \IntlDateFormatter::SHORT, \IntlDateFormatter::SHORT);
		}
	}

	/**
	 * Method used internally to determine the locale to work with
	 */
	public static function determine_locale($locale = null) {
		if ($locale) {
			// do nothing
		} elseif (static::$_defaultLocale) {
			$locale = static::$_defaultLocale;
		} else {
			$locale = setlocale(LC_TIME, 0);
		}

		$locale = static::clean_locale($locale);

		if (!array_key_exists($locale, static::$_formatters)) {
			try {
				static::$_formatters[$locale] = new \IntlDateFormatter(($locale === 'DONTUSE' ? null : $locale), \IntlDateFormatter::SHORT, \IntlDateFormatter::SHORT);
			} catch (\IntlException $e) {
				static::$_formatters[$locale] = new \IntlDateFormatter(null, \IntlDateFormatter::SHORT, \IntlDateFormatter::SHORT);
			}
		}
		return $locale;
	}

	/**
	 * Clean locale by converting eg. `en-US` to `en_US`
	 */
	public static function clean_locale($locale) {
		return str_replace('-', '_', $locale);
	}

	/**
	 * List of locales/countries that use a dot after the date, eg. `21. oktober`
	 *
	 * Deduced from Windows region formats.
	 *
	 * @return array : List of ICU locale and ISO-3166 alpha-2 countries
	 */
	public static function uses_dot_after_date() {
		return [
			'da_DK', 'da_GL', 'de_AT', 'de_BE', 'de_CH', 'cs-CZ', 'de_DE', 'de_IT', 'de_LI', 'de_LU', 'lb_LU', 'et_EE', 'fi_FI', 'fo_DK', 'fo_FO', 'hr_HR', 'hu_HU', 'is_IS', 'lv_LV', 'nb_NO', 'nn_NO', 'sk_SK', 'sl_SI',
			'CZ', 'DK', 'GL', 'AT', 'BA', 'DE', 'LI', 'EE', 'FO', 'HR', 'HU', 'IS', 'LV', 'NO', 'SK', 'SI', 'ME', 'RS', 'XK',   //when the whole (or all locales in the country) uses it they can be listed here as well
		];
	}

	/**
	 * Get the local format of writing day and month
	 *
	 * Wikipedia article about date formatting in different countries: https://en.wikipedia.org/wiki/Date_format_by_country
	 *
	 * @param string $locale : ICU locale. Eg. `en_US`, `en-US`, `da_DK` or `nb_NO`
	 * @param string $options : Available options:
	 *   - `short_month` : use abbreviated month name instead of fully spelled out (Intl extension automatically determines if dot should be added)  (the alternative `short_month_no_dot` is just for internal use through format_local() )
	 *   - `return_boolean` : return a boolean indicating whether month comes before the date
	 *
	 * @return string : Format that can be used with [format_local()] according to https://www.php.net/manual/en/intldateformatter.setpattern.php. Eg. `d. MMMM`
	 */
	public static function day_month_local_format($locale = null, $options = []) {
		if (!$locale) {
			$locale = static::$_defaultLocale;
		}

		if ($locale === 'en_US' || $locale === 'en-US') {
			if (@$options['return_boolean']) {
				return true;
			} elseif (@$options['short_month'] || @$options['short_month_no_dot']) {
				return 'MMM d';
			} else {
				return 'MMMM d';
			}
		} else {
			if (@$options['return_boolean']) {
				return false;
			} elseif (@$options['short_month'] || @$options['short_month_no_dot']) {
				return 'd. MMM';
			} else {
				return 'd. MMMM';
			}
		}
	}

	/**
	 * Get the local format of writing a time (24-hour or 12-hour format)
	 *
	 * Wikipedia article about countries using 12-hour clock: https://en.wikipedia.org/wiki/12-hour_clock
	 *
	 * @param string $locale : ICU locale. Eg. `en_US`, `en-US`, `da_DK` or `nb_NO`
	 * @param string $options : Available options:
	 *   - `country` : provide country (ISO-3166 alpha-2) to correctly determine when to use 12-hour clock when the locale is not specific enough. To only consider country set $locale to "DONTUSE"
	 *   - `time_country` : same as `country` but now $locale is not considered at all
	 *   - `force_clock` : set to either `12hr` or `24hr` to enforce using a specific 24/12-hour clock setting
	 *
	 * @return array : Array with `hour` and `ampm` designators that can be used with [format_local()] according to https://www.php.net/manual/en/intldateformatter.setpattern.php
	 */
	public static function time_local_format($locale = null, $options = []) {
		if (!$locale) {
			$locale = static::$_defaultLocale;
		}
		$locale = static::clean_locale($locale);

		$country = null;
		if (@$options['time_country']) {
			$country = $options['time_country'];
		} elseif (@$options['country']) {
			$country = $options['country'];
		}

		if (
			@$options['force_clock'] === '12hr'
				||
			(in_array($locale, ['en_US', 'en_GB', 'en_AU', 'hi_IN']) && !@$options['time_country'] && @$options['force_clock'] !== '24hr')
				||
			($country && in_array(strtoupper($country), ['US', 'GB', 'AU', 'IN', 'IE', 'CA', 'NZ', 'PK', 'BD', 'MY', 'MT', '12hr']) && @$options['force_clock'] !== '24hr')
		) {
			$hour_symbol = (@$options['twodigit_hour'] ? 'hh' : 'h');
			return ['hour' => $hour_symbol, 'ampm' => 'a', '12hour' => true];
		} else {
			$hour_symbol = (@$options['twodigit_hour'] ? 'HH' : 'H');
			return ['hour' => $hour_symbol, 'ampm' => '', '12hour' => false];
		}
	}

	/**
	 * Format dates and/or times according to locale settings
	 *
	 * @deprecated Use [format_local()] instead
	 *
	 * Based on strftime() but adjusted to work correctly
	 *
	 * @param string $format : According to strftime(), plus the following:  (literal % cannot be used)
	 *   - ¤A : weekday name with abbreviated to 3 characters
	 * @param integer $date_unix : UNIX timestamp of the date/time to be formatted
	 *
	 * @return string
	 */
	public static function format_datetime_local($format, $date_unix) {
		$output = $format;
		$working = ['a', 'A', 'B', 'c', 'C', 'd', 'D', 'g', 'h', 'H', 'I', 'j', 'm', 'n', 'p', 'r', 'R', 'S', 't', 'T', 'u', 'U', 'V', 'W', 'w', 'x', 'X', 'y', 'Y', 'Z'];
		// Do all the working values
		foreach ($working as $c) {
			$output = str_replace('%'.$c, strftime('%'.$c, $date_unix), $output);
		}
		// Make 3-letter weekday name
		if (strpos($output, '¤A') !== false) {
			$value = strftime('%A', $date_unix);
			$value = mb_substr($value, 0, 3);
			$output = str_replace('¤A', $value, $output);
		}
		// Fix non-working %b (some servers added "." after abbreviated month names (eg. domeneshop.no))
		if (strpos($output, '%b') !== false) {
			$value = rtrim(strftime('%b', $date_unix), '.');
			$output = str_replace('%b', $value, $output);
		}
		// Fix non-working %e (do not have space in front of single digits, even though specification says it should have - and it didn't work at all on my local dev machine)
		if (strpos($output, '%e') !== false) {
			$value = date('j', $date_unix);
			$output = str_replace('%e', $value, $output);
		}
		// Fix non-working %G (4-digit year doesn't work)
		if (strpos($output, '%G') !== false) {
			$value = date('Y', $date_unix);
			$output = str_replace('%G', $value, $output);
		}
		// Fix non-working %M (minute doesn't work at all)
		if (strpos($output, '%M') !== false) {
			$value = date('i', $date_unix);
			$output = str_replace('%M', $value, $output);
		}
		return $output;
	}

	/**
	 * Add or subtract a specified period from a date
	 *
	 * @deprecated Use PHP's native [[DateTime->add()]] instead
	 *
	 * This is better than just multiplying the timestamp because this takes daylight savings time into consideration
	 *
	 * @param integer $time : UNIX timestamp
	 * @param integer $adjust_by : The number, positive to add or negative to subtract, you want to adjust the time with
	 * @param string $interval : the unit for the $adjust_by number. Possible values are: `hour`, `minute`, `second`, `day`, `month`, `year`
	 * @return integer : UNIX timestamp
	 */
	public static function time_add($time, $adjust_by, $interval) {
		switch ($interval) {
		case 'hour':
		case 'hours':
		case 'hr':
		case 'hrs':
			$newtime = mktime(date('G',$time) + $adjust_by, date('i',$time), date('s',$time), date('m',$time), date('d',$time), date('Y',$time));
			break;
		case 'minute':
		case 'minutes':
		case 'min':
		case 'mins':
			$newtime = mktime(date('G',$time), date('i',$time) + $adjust_by, date('s',$time), date('m',$time), date('d',$time), date('Y',$time));
			break;
		case 'second':
		case 'seconds':
		case 'sec':
		case 'secs':
			$newtime = mktime(date('G',$time), date('i',$time), date('s',$time) + $adjust_by, date('m',$time), date('d',$time), date('Y',$time));
			break;
		case 'day':
		case 'days':
			$newtime = mktime(date('G',$time), date('i',$time), date('s',$time), date('m',$time), date('d',$time) + $adjust_by, date('Y',$time));
			break;
		case 'month':
		case 'months':
			$newtime = mktime(date('G',$time), date('i',$time), date('s',$time), date('m',$time) + $adjust_by, date('d',$time), date('Y',$time));
			break;
		case 'year':
		case 'years':
		case 'yr':
		case 'yrs':
			$newtime = mktime(date('G',$time), date('i',$time), date('s',$time), date('m',$time), date('d',$time), date('Y',$time) + $adjust_by);
			break;
		default:
			core::system_error('Configuration error. Interval not defined.', ['Function' => 'time_add()', 'Interval' => $interval]);
		}
		return $newtime;
	}

	/**
	 * Get the difference between two times expressed in a certain interval
	 *
	 * @deprecated Use PHP's native [[DateTime->diff()]] instead
	 *
	 * @param integer $time1 : first time in UNIX timestamp (beginning time)
	 * @param integer $time1 : second time in UNIX timestamp (ending time)
	 * @param string $interval : calculate the difference as months, weeks, days, hours, minutes, or seconds (see below for valid values)
	 *   - note that a month is calculated as 30 days
	 * @param boolean $return_absolute : return the absolute value? This means the order of times doesn't matter and result is always positive.
	 * @return number : Possibly with decimals
	 */
	public static function time_diff($time1, $time2, $interval = 'days', $return_absolute = false) {
		$difference_seconds = $time2 - $time1;
		if ($return_absolute) {
			$difference_seconds = abs($difference_seconds);
		}
		switch (strtolower($interval)) {
		case 'month':
		case 'months':
			$diff_target = $difference_seconds / 60 / 60 / 24 / 30;
			break;
		case 'week':
		case 'weeks':
		case 'wk':
		case 'wks':
			$diff_target = $difference_seconds / 60 / 60 / 24 / 7;
			break;
		case 'day':
		case 'days':
			$diff_target = $difference_seconds / 60 / 60 / 24;
			break;
		case 'hour':
		case 'hours':
		case 'hr':
		case 'hrs':
			$diff_target = $difference_seconds / 60 / 60;
			break;
		case 'minute':
		case 'minutes':
		case 'min':
		case 'mins':
			$diff_target = $difference_seconds / 60;
			break;
		case 'second':
		case 'seconds':
		case 'sec':
		case 'secs':
			$diff_target = $difference_seconds;
			break;
		default:
			core::system_error('Interval has not been defined for calculating time differences.');
		}
		return $diff_target;
	}
}
