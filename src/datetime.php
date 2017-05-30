<?php
/*
This file contains functions related to date and time handling
*/
namespace winternet\jensenfw2;

class datetime {
	public var $scripttimer_start = null;
	public var $scripttimer_meantimecount = null;
	public var $scripttimer_lastmeantime = null;

	function format_datetime_local($format, $date_unix) {
		/*
		DESCRIPTION:
		- format dates and/or times according to locale settings (currently just according to strftime() )
		- based on strftime() but adjusted to work correctly
		INPUT:
		- $format : according to strftime(), plus the following:  (literal % cannot be used)
			- ¤A : weekday name with abbreviated to 3 characters
		- $date_unix : UNIX timestamp of the date/time to be formatted
		OUTPUT:
		- string
		*/
		$output = $format;
		$working = array('a', 'A', 'B', 'c', 'C', 'd', 'D', 'g', 'h', 'H', 'I', 'j', 'm', 'n', 'p', 'r', 'R', 'S', 't', 'T', 'u', 'U', 'V', 'W', 'w', 'x', 'X', 'y', 'Y', 'Z');
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

	function to_mysql_datetime($datetime, $dateformat, $timeformat = false, $returnonfail = false) {
		/*
		DESCRIPTION:
		- convert several date formats (except Unix) to MySQL format
		- use validate_date(), validate_time(), and validate_datetime() to ensure correct date/time values
		- a Javascript equivalent with the same name exists, it works exactly the same way
		- TODO: maybe make possible to input Unix timestamp (like set $dateformat and/or $timeformat to 'unix'... or just provide a numeric value for $datetime?)
		INPUT:
		- $date : date and/or time to convert
		- $dateformat : format of the date to convert. Valid values are 'dmy', 'mdy', 'ymd' added by a forth character which sets the delimiter which can be anything except a number
			- set to false if $datetime only contains a time value
			- see function convert_cc_exp_to_mysql_date() for converting credit card expiration dates
		- $timeformat : format of the time to convert. Valid values are 'hm', 'hms' added by a forth character which sets the delimiter which can be anything except a number
			- if 12-clock it must be a cathe capitalized 'H' (eg. 'Hm' or 'Hms')
			- examples:
				- hh:mm            (24hr clock)
				- hh:mm:ss         (24hr clock)
				- hh:mm [am|pm]    (12hr clock, hh = (0)0-23, mm = (0)0-59, with or without space between time and am/pm)
				- hh:mm:ss [am|pm] (12hr clock, hh = (0)1-12, mm = (0)0-59, with or without space between time and am/pm)
		- $returnonfail : string to return if conversion fails
			- provide string 'source' to return the untouched datetime value
				- IMPORTANT: if this is used you MUST validate the datetime later on before using it (preferably on server-side)
			- provide string 'empty' to return an empty string
		OUTPUT:
		- MySQL formatted date and/or time
		- or empty string if the values are empty
		- or if conversion fails and $returnonfail is set, it's value is returned
		*/
		if ($datetime) {
			$output = array();
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
						core::system_error('Invalid date/time to convert to MySQL format.', array('Value' => $datetime) );
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
						core::system_error('Could not determine if value was a date or a time.', array('Value' => $datetime) );
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
						core::system_error('Invalid date format for converting to MySQL format.', array('Dateformat' => $dateformat) );
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
						core::system_error('Invalid time format for converting to MySQL format.', array('Timeformat' => $timeformat) );
					}
				}
			}
			$output = implode(' ', $output);
			return addslashes($output);  //using addslashes() is a safety measure. In case the value is invalid (= input for this function is invalid) and we don't use mysqli_real_escape_string() or similar in SQL, the code will still not break.
		} else {
			return '';
		}
	}

	function time_add($time, $adjust_by, $interval) {
		/*
		DESCRIPTION:
		- add or subtract a specified period from a date
		- this is better than just multiplying the timestamp because this takes daylight savings time into consideration
		INPUT:
		- $time : UNIX timestamp
		- $adjust_by : the number, positive to add or negative to subtract, you want to adjust the time with
		- $interval : the unit for the $adjust_by number. Possible values are:  hour|minute|second|day|month|year
		OUTPUT:
		- UNIX timestamp
		*/
		switch ($interval) {
		case 'hour':
		case 'hours':
		case 'hr':
		case 'hrs':
			$newtime = mktime(date("G",$time) + $adjust_by, date("i",$time), date("s",$time), date("m",$time), date("d",$time), date("Y",$time));
			break;
		case 'minute':
		case 'minutes':
		case 'min':
		case 'mins':
			$newtime = mktime(date("G",$time), date("i",$time) + $adjust_by, date("s",$time), date("m",$time), date("d",$time), date("Y",$time));
			break;
		case 'second':
		case 'seconds':
		case 'sec':
		case 'secs':
			$newtime = mktime(date("G",$time), date("i",$time), date("s",$time) + $adjust_by, date("m",$time), date("d",$time), date("Y",$time));
			break;
		case 'day':
		case 'days':
			$newtime = mktime(date("G",$time), date("i",$time), date("s",$time), date("m",$time), date("d",$time) + $adjust_by, date("Y",$time));
			break;
		case 'month':
		case 'months':
			$newtime = mktime(date("G",$time), date("i",$time), date("s",$time), date("m",$time) + $adjust_by, date("d",$time), date("Y",$time));
			break;
		case 'year':
		case 'years':
		case 'yr':
		case 'yrs':
			$newtime = mktime(date("G",$time), date("i",$time), date("s",$time), date("m",$time), date("d",$time), date("Y",$time) + $adjust_by);
			break;
		default:
			core::system_error('Configuration error. Interval not defined.', array('Function' => 'time_add()', 'Interval' => $interval));
		}
		return $newtime;
	}

	function time_diff($time1, $time2, $interval = 'days', $return_absolute = false) {
		/*
		DESCRIPTION:
		- get the difference between two times expressed in a certain interval
		INPUT:
		- $time1 : first time in UNIX timestamp (beginning time)
		- $time1 : second time in UNIX timestamp (ending time)
		- $interval : calculate the difference as months, weeks, days, hours, minutes, or seconds (see below for valid values)
			- note that a month is calculated as 30 days
		- $return_absolute : return the absolute value? This means the order of times doesn't matter and result is always positive.
		OUTPUT:
		- number, possible with decimals
		*/
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

	function time_period_all_units($time, $include_zeros = false) {
		/*
		DESCRIPTION:
		- textually write a time period/duration, like in a countdown or "time since". Days, hours, minutes, and seconds
		- this differs from time_period_single_unit() in that it outputs ALL units (eg. hours, minutes, and seconds) instead of only one unit (eg. minutes)
			- this divides the duration up into eg. hours, minutes and seconds whereas time_period_single_unit() determines the whole period/duration in EITHER days, hours, minutes etc.
		INPUT:
		- $time : duration/length in seconds (like unix timestamp)
		- $include_zeros = true|false : whether or not values of null should be included in the "fulltext"
		OUTPUT:
		- associated array with days ('days'), hours ('hours'), minutes ('mins'), seconds ('secs'), and full textual representation ('fulltext')
		*/
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
			$fulltext  = ($days == 1) ? "1 day, " : $days ." days, ";
		}
		if ($include_zeros || (!$include_zeros && $hours != 0)) {
			$fulltext .= ($hours == 1) ? "1 hour, " : $hours ." hours, ";
		}
		if ($include_zeros || (!$include_zeros && $mins != 0)) {
			$fulltext .= ($mins == 1) ? "1 minute, " : $mins ." minutes, ";
		}
		if ($include_zeros || (!$include_zeros && $secs != 0)) {
			$fulltext .= ($secs == 1) ? "1 second" : $secs ." seconds";
		}
		if (substr($fulltext, strlen($fulltext)-2, 2) == ', ') $fulltext = substr($fulltext, 0, strlen($fulltext)-2);  //remove trailing comma and space if exists
		$fulltext = trim($fulltext);
		return array(
			'days' => $days,
			'hours' => $hours,
			'mins' => $mins,
			'secs' => $secs,
			'fulltext' => $fulltext
		);
	}

	function time_period_single_unit($time, $unit_names = 'short', $decimals = 0, $include_weeks = false) {
		/*
		DESCRIPTION:
		- textually write a time period/duration with a single unit, either days, hours, minutes, seconds - depending on how long the duration is
		- the function also returns a value ('general_guide') where the appropiate unit is automatically determined, based on what is meaningful for the reader to know (this will of course not be the exact time, but only a general guide)
		- this differs from time_period_all_units() in that this only returns a single unit, time_period_all_units() returns ALL units
			- see time_period_all_units() for further explanation
		- maybe it would be useful even to make one a function returning two units - for better precision when you want it!
		INPUT:
		- $time : duration/length in seconds (like unix timestamp)
		- $unit_names ('short'|'long') : whether short (= abbreviated) or long unit names should be unised in the 'general_guide'
		- $decimals : number of decimals to use in the 'general_guide' number
			- can be used to obtain much greater precision
		- $include_weeks (true|false) : whether or not to use the time in weeks for the 'general_guide'
			- most often you would probably want to use the days up until you have one month
		OUTPUT:
		- associative array: see below
		- the unit for the 'general_guide' is specified in 'appropiate_unit'
		*/
		//determine unit names
		switch ($unit_names) {
		case 'short':
			$lbl_secs_one = txt('second_short', 'sec.', '#'); $lbl_secs_more = txt('seconds_short', 'sec.', '#');
			$lbl_mins_one = txt('minute_short', 'min.', '#'); $lbl_mins_more = txt('minutes_short', 'min.', '#');
			$lbl_hours_one = txt('hour_short', 'hr', '#'); $lbl_hours_more = txt('hours_short', 'hrs', '#');
			$lbl_days_one = txt('day_short', 'day', '#'); $lbl_days_more = txt('days_short', 'days', '#');
			$lbl_weeks_one = txt('week_short', 'week', '#'); $lbl_weeks_more = txt('weeks_short', 'weeks', '#');
			$lbl_mnths_one = txt('month_short', 'month', '#'); $lbl_mnths_more = txt('months_short', 'months', '#');
			$lbl_years_one = txt('year_short', 'year', '#'); $lbl_years_more = txt('years_short', 'yrs', '#');
			break;
		case 'long':
			$lbl_secs_one = txt('second', 'second', '#'); $lbl_secs_more = txt('seconds', 'seconds', '#');
			$lbl_mins_one = txt('minute', 'minute', '#'); $lbl_mins_more = txt('minutes', 'minutes', '#');
			$lbl_hours_one = txt('hour', 'hour', '#'); $lbl_hours_more = txt('hours', 'hours', '#');
			$lbl_days_one = txt('day', 'day', '#'); $lbl_days_more = txt('days', 'days', '#');
			$lbl_weeks_one = txt('week', 'week', '#'); $lbl_weeks_more = txt('weeks', 'weeks', '#');
			$lbl_mnths_one = txt('month', 'month', '#'); $lbl_mnths_more = txt('months', 'months', '#');
			$lbl_years_one = txt('year', 'year', '#'); $lbl_years_more = txt('years', 'years', '#');
			break;
		default:
			core::system_error('Configuration error. Unit format not defined.', array('Unit name' => $unit_names) );
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
			$times['general_guide'] = ($times['seconds'] == 1 ? '1 '. $lbl_secs_one : $times['seconds'] .' '. $lbl_secs_more);
			$times['appropiate_unit'] = 'seconds';
		} elseif (round(abs($times['minutes']), $decimals) < 60) {
			$rounded = round($times['minutes'], $decimals);
			$times['general_guide'] = ($rounded == 1 ? '1 '. $lbl_mins_one : $rounded .' '. $lbl_mins_more);
			$times['appropiate_unit'] = 'minutes';
		} elseif (round(abs($times['hours']), $decimals) < 24) {
			$rounded = round($times['hours'], $decimals);
			$times['general_guide'] = ($rounded == 1 ? '1 '. $lbl_hours_one : $rounded .' '. $lbl_hours_more);
			$times['appropiate_unit'] = 'hours';
		} elseif ( (!$include_weeks && abs($times['days_rounded']) < 30)  ||  ($include_weeks && abs($times['days_rounded']) < 7) ) {  //if weeks are used, the period of using days is shorter
			$times['general_guide'] = ($times['days_rounded'] == 1 ? '1 '. $lbl_days_one : $times['days_rounded'] .' '. $lbl_days_more);
			$times['appropiate_unit'] = 'days';
		} elseif ($include_weeks && round(abs($times['weeks']), $decimals) < 5 && abs($times['days']) < 30) {  //skip to month if 30 or more days
			$rounded = round($times['weeks'], $decimals);
			$times['general_guide'] = ($rounded == 1 ? '1 '. $lbl_weeks_one : $rounded .' '. $lbl_weeks_more);
			$times['appropiate_unit'] = 'weeks';
		} elseif (round(abs($times['months']), $decimals) < 12) {
			$rounded = round($times['months'], $decimals);
			$times['general_guide'] = ($rounded == 1 ? '1 '. $lbl_mnths_one : $rounded .' '. $lbl_mnths_more);
			$times['appropiate_unit'] = 'months';
		} else {
			//else write in years
			$rounded = round(abs($times['years']), $decimals);
			$times['general_guide'] = ($rounded == 1 ? '1 '. $lbl_years_one : $rounded .' '. $lbl_years_more);
			$times['appropiate_unit'] = 'years';
		}
		return $times;
	}

	function time_period_custom_units($time, $unit_names = 'short', $no_of_units = 2) {
		/*
		DESCRIPTION:
		- textually write a time period/duration with a custom defined number of units
		INPUT:
		- $time : duration/length in seconds (like unix timestamp)
		- $unit_names ('short'|'long') : whether short (= abbreviated) or long unit names should be unised in the 'general_guide'
			- OBS!! CURRENTLY THIS HAS NO EFFECT AS time_period_all_units() FIRST NEEDS TO HAVE THIS FEATURE IMPLEMENTED TOO
		- $no_of_units : number of units you want, the more units the more precise the presentation will be
		OUTPUT:
		- string
		- examples: 2 days, 17 hours  -or-  15 minutes, 14 seconds  -or--  4 days, 6 hrs, 38 min.
		*/
		if (!is_numeric($no_of_units)) {
			core::system_error('Invalid number of units for writing a time period.');
		}
		$output = array();
		$allunits = time_period_all_units($time, false);
		$textparts = explode(',', $allunits['fulltext']);
		$textparts_count = count($textparts);
		for ($i = 1; $i <= $no_of_units && $i <= $textparts_count; $i++) {
			$output[] = trim($textparts[$i-1]);
		}
		return implode(', ', $output);
	}

	function format_timeperiod($fromdate, $todate, $flags = false) {
		/*
		DESCRIPTION:
		- formats a time period nicely
		INPUT:
		- $fromdate : date in Unix or MySQL format
		- $todate   : date in Unix or MySQL format
		- $flags : string with any combination of these flags:
			- '2digit_year' : only show 2 digits in the year(s)
			- 'noyear' : don't show year at all
			- 'always_abbrev_months' : don't spell out fully the short months March, April, May, June and July
			- 'never_abbrev_months' : always spell out fully the month names
		OUTPUT:
		- string
		- eg. "Dec. 3-5, 2010" or "Nov. 30 - Dec. 4, 2010" or "Dec. 27, 2010 - Jan. 2, 2011"
		*/
		if (!is_numeric($fromdate)) {
			$fromdate = strtotime($fromdate);
		}
		if (!is_numeric($todate)) {
			$todate = strtotime($todate);
		}
		$flags = (string) $flags;
		$yrmode = (strpos($flags, '2digit_year') !== false ? '2dig' : (strpos($flags, 'noyear') !== false ? 'noyr' : '4dig'));

		if (strpos($flags, 'never_abbrev_months') !== false) {
			$frommonth = date('F', $fromdate);
			$tomonth = date('F', $todate);
		} elseif (strpos($flags, 'always_abbrev_months') === false) {
			$shortmonths = array(3, 4, 5, 6, 7);
			if (in_array(date('n', $fromdate), $shortmonths)) {
				$frommonth = date('F', $fromdate);
			} else {
				$frommonth = date('M.', $fromdate);
			}
			if (in_array(date('n', $todate), $shortmonths)) {
				$tomonth = date('F', $todate);
			} else {
				$tomonth = date('M.', $todate);
			}
		} else {
			$frommonth = date('M.', $fromdate);
			$tomonth = date('M.', $todate);
		}

		if ($yrmode !== 'noyr') {
			$fromyear = date('Y', $fromdate);
			$toyear = date('Y', $todate);
		} else {
			$fromyear = $toyear = '';
		}
		$output = $frommonth .' '. date('j', $fromdate);
		if ($frommonth == $tomonth && $fromyear == $toyear) {
			$output .= '-'. date('j', $todate);
		} elseif ($fromyear == $toyear) {
			//months are not the same
			$output .= ' - '. $tomonth .' '. date('j', $todate);
		} else {
			//years are not the same
			$output .= ', '. ($yrmode == '2dig' ? "'". substr($fromyear, 2) : $fromyear) .' - '. $tomonth .' '. date('j', $todate);
		}
		if ($yrmode != 'noyr') {
			$output .= ', '. ($yrmode == '2dig' ? "'". substr($toyear, 2) : $toyear);
		}

		return $output;
	}

	function change_timestamp_timezone($datetime, $curr_timezone, $new_timezone, $format = false) {
		/*
		DESCRIPTION:
		- change the timezone of a given timestamp
		INPUT:
		- $datetime : timestamp to change. MySQL format: yyyy-mm-dd hh:mm:ss (or yyyy-mm-dd hh:mm, or any format accepted by the PHP DateTime constructor)
			- yyyy-mm-dd can also be used but conversion will then always be based on midnight of that date and might therefore be incorrect
		- $curr_timezone : the current timezone of the timestamp, according to http://php.net/manual/en/timezones.php
		- $new_timezone : the timezone to convert the timestamp to, according to http://php.net/manual/en/timezones.php
		- $format (opt.) : date format to return according to DateTime->format()
		OUTPUT:
		- MySQL formatted timestamp or according to $format if specified
		*/
		if (!$format) {
			if (preg_match("|^\\d{4}-\\d{1,2}-\\d{1,2}$|", $datetime, $match)) {
				$format = 'Y-m-d';
			} else {
				$format = 'Y-m-d H:i:s';
			}
		}
		if ($datetime) {
			$timestamp = new DateTime($datetime, new DateTimeZone($curr_timezone));
			$timestamp->setTimezone(new DateTimeZone($new_timezone));
			return $timestamp->format($format);
		} else {
			return '';
		}
	}

	function calculate_age($at_date, $birthyear, $birthmonth, $birthday) {
		/*
		DESCRIPTION:
		- determine a person's age at a given date, when knowing their birth year and month
		INPUT:
		- $at_date : date for which to calculate the person's age, MySQL format or Unix timestamp or anything that strtotime() parses
		- $birthyear (req.) : year person was born
			- can also be set to a complete MySQL date (yyyy-mm-dd) or a Unix timestamp. Then set mont and day to blank values ('', false or null)
		- $birthmonth (req.) : month person was born
		- $birthday (req.) : day of month person was born, or one of these strings if unknown:
			- 'chance_of_being_older'   : results in a chance people being actually older   than calculated here
			- 'chance_of_being_younger' : results in a chance people being actually younger than calculated here
		OUTPUT:
		- age (integer)
		*/
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

	function scripttimer_start() {
		self::$scripttimer_start = microtime(true); 
		return self::$scripttimer_start;
	}
	function scripttimer_meantime($writetext = false) {
		if (!self::$scripttimer_start) {
			echo '<div style="color: orangered"><b>Timer was not started!</b></div>';
			return;
		}
		$scripttimer_meantime = microtime(true); 
		$duration = number_format($scripttimer_meantime - self::$scripttimer_start, 3);
		if ($writetext) {
			echo '<div style="color: orangered"><b>Meantime #'. ++self::$scripttimer_meantimecount .': '. $duration .''. (self::$scripttimer_lastmeantime ? ' ('. number_format($scripttimer_meantime - self::$scripttimer_lastmeantime, 3) .')' : '') .'</b></div>';
		}
		self::$scripttimer_lastmeantime = $scripttimer_meantime;
		return $duration;
	}
	function scripttimer_stop($writetext = false) {
		if (!self::$scripttimer_start) {
			echo '<div style="color: orangered"><b>Timer was not started!</b></div>';
			return null;
		}
		$scripttimer_end = microtime(true); 
		$duration = number_format($scripttimer_end - self::$scripttimer_start, 3);
		if ($writetext) {
			echo '<div style="color: orangered"><b>Duration: '. $duration .' seconds.'. (self::$scripttimer_meantimecount ? ' Meantimes average: '. number_format($duration / self::$scripttimer_meantimecount, 3) : '') .'</b></div>';
		}
		self::$scripttimer_meantimecount = false;  //clear it
		self::$scripttimer_lastmeantime = false;  //clear it
		return $duration;
	}
}