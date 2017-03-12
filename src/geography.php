<?php
/*
This file contains functions related to geography
*/
namespace winternet\jensenfw2;

/*
TESTS:
$longitude_x = 37.6;  // x-coordinate of the point to test
$latitude_y = -77.446;    // y-coordinate of the point to test

$polygon = array(array(37.628134,-77.458334), array(37.629867,-77.449021), array(37.62324,-77.445416), array(37.622424,-77.457819));

if (is_in_polygon($polygon, $longitude_x, $latitude_y)) {
	echo "Is in polygon!";
} else {
	echo "Is not in polygon";
}
*/

class geography {
	public static function distance($lat1, $lng1, $lat2, $lng2, $unit) {
		/*
		DESCRIPTION:
		- calculate the distance between two latitude/longitude coordinates
		- example: distance(32.9697, -96.80322, 29.46786, -98.53506, 'km')
		- source: http://www.zipcodeworld.com/samples/distance.php.html (did minor adjustments)
		INPUT:
		- $lat1 : latitude  of 1st coordinate
		- $lng1 : longitude of 1st coordinate
		- $lat2 : latitude  of 2nd coordinate
		- $lng2 : longitude of 2nd coordinate
		- $unit : the unit to return the results in. Options are:
			- 'km'  : kilometers
			- 'm'   : meters
			- 'mi'  : statute miles (most common in US) (default)
			- 'nmi' : nautical miles
		OUTPUT:
		- number
		*/
		//:: ORIGINAL COMMENTS:
		//::  this routine calculates the distance between two points (given the
		//::  latitude/longitude of those points). it is being used to calculate
		//::  the distance between two zip codes or postal codes using our
		//::  zipcodeworld(tm) and postalcodeworld(tm) products.
		//::
		//::  definitions:
		//::    south latitudes are negative, east longitudes are positive
		//::
		//::  passed to function:
		//::    lat1, lng1 = latitude and longitude of point 1 (in decimal degrees)
		//::    lat2, lng2 = latitude and longitude of point 2 (in decimal degrees)
		//::    unit = the unit you desire for results
		//::		 where: 'mi' is statute miles
		//::			   'km' is kilometers
		//::			   'nmi' is nautical miles
		//::  united states zip code/ canadian postal code databases with latitude &
		//::  longitude are available at http://www.zipcodeworld.com
		//::
		//::  For enquiries, please contact sales@zipcodeworld.com
		//::
		//::  official web site: http://www.zipcodeworld.com
		//::
		//::  hexa software development center © all rights reserved 2004

		$theta = $lng1 - $lng2;
		$dist = sin(deg2rad($lat1)) * sin(deg2rad($lat2)) +  cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * cos(deg2rad($theta));
		$dist = acos($dist);
		$dist = rad2deg($dist);
		$miles = $dist * 60 * 1.1515;
		$unit = strtolower($unit);
		switch ($unit) {
			case 'km':  return ($miles * 1.609344); break;
			case 'm':   return ($miles * 1609.344); break;
			case 'nmi': return ($miles * 0.8684); break;
			default:    return $miles;  //=mi
		}
	}

	public static function point_from_bearing_distance($lat, $lng, $angle, $distance) {
		/*
		DESCRIPTION:
		- calculate the point (latitude/longitude) given a point of origin, a bearing, and a distance
		- source: http://www.etechpulse.com/2014/02/calculate-latitude-and-longitude-based.html
		INPUT:
		- $lat
		- $lng
		- $angle : bearing in degrees (0-360)
		- $distance : distance from the point in kilometers
		OUTPUT:
		- array where first entry is the new latitude, second entry the new longitude
		- example: array(60.6793281, 8.6953779)
		*/
		$new_latlng = [];
		$distance = $distance / 6371;
		$angle = self::ToRadians($angle);

		$lat1 = self::ToRadians($lat);
		$lng1 = self::ToRadians($lng);

		$new_lat = asin(sin($lat1) * cos($distance) +
					  cos($lat1) * sin($distance) * cos($angle));

		$new_lng = $lng1 + atan2(sin($angle) * sin($distance) * cos($lat1),
							  cos($distance) - sin($lat1) * sin($new_lat));

		if (is_nan($new_lat) || is_nan($new_lng)) {
			return null;
		}

		$new_latlng[0] = self::ToDegrees($new_lat);
		$new_latlng[1] = self::ToDegrees($new_lng);

		return $new_latlng;
	}
	public static function ToRadians($input) {
		return $input * pi() / 180;
	}
	public static function ToDegrees($input) {
		return $input * 180 / pi();
	}

	public static function bearing_greatcircle($lat1, $lng1, $lat2, $lng2) {
		/*
		DESCRIPTION:
		- calculate the great-circle bearing (follow earth's curvature), in degrees, from starting Point A to remote Point B
		- the bearing varies as you move towards Point B, so for navigation you would have to recalcuate this from time to time
		- source: http://webcache.googleusercontent.com/search?q=cache:o-7HcMkYfh0J:https://www.dougv.com/2009/07/13/calculating-the-bearing-and-compass-rose-direction-between-two-latitude-longitude-coordinates-in-php/+&cd=2&hl=no&ct=clnk
		INPUT:
		- $lat1 : latitude of Point A
		- $lng1 : longitude of Point A
		- $lat2 : latitude of Point B
		- $lng2 : longitude of Point B
		OUTPUT:
		- bearing (number)
		*/
		return (rad2deg(atan2(sin(deg2rad($lng2) - deg2rad($lng1)) * cos(deg2rad($lat2)), cos(deg2rad($lat1)) * sin(deg2rad($lat2)) - sin(deg2rad($lat1)) * cos(deg2rad($lat2)) * cos(deg2rad($lng2) - deg2rad($lng1)))) + 360) % 360;
	}

	public static function bearing_rhumbline($lat1, $lng1, $lat2, $lng2) {
		/*
		DESCRIPTION:
		- calculate the bearing of a rhumb-line (straight line on map), in degrees, from starting Point A to remote Point B
		- the bearing for a rhumb-line is constant all the way
		- source: http://webcache.googleusercontent.com/search?q=cache:o-7HcMkYfh0J:https://www.dougv.com/2009/07/13/calculating-the-bearing-and-compass-rose-direction-between-two-latitude-longitude-coordinates-in-php/+&cd=2&hl=no&ct=clnk
		INPUT:
		- $lat1 : latitude of Point A
		- $lng1 : longitude of Point A
		- $lat2 : latitude of Point B
		- $lng2 : longitude of Point B
		OUTPUT:
		- bearing (number)
		*/

		//difference in longitudinal coordinates
		$dLon = deg2rad($lng2) - deg2rad($lng1);

		//difference in the phi of latitudinal coordinates
		$dPhi = log(tan(deg2rad($lat2) / 2 + pi() / 4) / tan(deg2rad($lat1) / 2 + pi() / 4));

		//we need to recalculate $dLon if it is greater than pi
		if (abs($dLon) > pi()) {
			if ($dLon > 0) {
				$dLon = (2 * pi() - $dLon) * -1;
			} else {
				$dLon = 2 * pi() + $dLon;
			}
		}

		//return the angle, normalized
		return (rad2deg(atan2($dLon, $dPhi)) + 360) % 360;
	}

	public static function get_compass_rose_direction_8($bearing) {
		/*
		DESCRIPTION:
		- get the cardinal direction of a bearing divided into 8 different directions
		INPUT:
		- $bearing : number between 0 and 359
		OUTPUT:
		- associative array with keys 'short' and 'long'
		*/
		if ($bearing <= 22.5 || $bearing > 337.5) {
			return array('short' => 'N', 'long' => 'North');
		} elseif ($bearing <= 67.5) {
			return array('short' => 'NE', 'long' => 'North East');
		} elseif ($bearing <= 112.5) {
			return array('short' => 'E', 'long' => 'East');
		} elseif ($bearing <= 157.5) {
			return array('short' => 'SE', 'long' => 'South East');
		} elseif ($bearing <= 202.5) {
			return array('short' => 'S', 'long' => 'South');
		} elseif ($bearing <= 247.5) {
			return array('short' => 'SW', 'long' => 'South West');
		} elseif ($bearing <= 292.5) {
			return array('short' => 'W', 'long' => 'West');
		} else { // $bearing <= 337.5
			return array('short' => 'NW', 'long' => 'North West');
		}
	}

	public static function convert_coordinate_dms_to_decimal($degrees, $minutes, $seconds, $direction) {
		/*
		DESCRIPTION:
		- convert a latitude or longitude from Degrees Minutes Seconds to Decimal Degrees
		- source: https://www.dougv.com/2012/03/07/converting-latitude-and-longitude-coordinates-between-decimal-and-degrees-minutes-seconds/
		INPUT:
		- $degrees : number
		- $minutes : number
		- $seconds : number
		- $direction ('N', 'S', 'E', 'W') : (is case-insensitive)
		OUTPUT:
		- success: decimal
		- failure: false
		*/
		$d = strtolower($direction);
		$ok = array('n', 's', 'e', 'w');

		//degrees must be integer between 0 and 180
		if (!is_numeric($degrees) || $degrees < 0 || $degrees > 180) {
			$decimal = false;
		} elseif(!is_numeric($minutes) || $minutes < 0 || $minutes > 59) {  //minutes must be integer or float between 0 and 59
			$decimal = false;
		} elseif (!is_numeric($seconds) || $seconds < 0 || $seconds > 59) {  //seconds must be integer or float between 0 and 59
			$decimal = false;
		} elseif (!in_array($d, $ok)) {
			$decimal = false;
		} else {
			//inputs clean, calculate
			$decimal = $degrees + ($minutes / 60) + ($seconds / 3600);

			//reverse for south or west coordinates; north is assumed
			if ($d == 's' || $d == 'w') {
				$decimal *= -1;
			}
		}

		return $decimal;
	}

	public static function convert_coordinate_decimal_to_dms($decimal, $type) {
		/*
		DESCRIPTION:
		- convert a latitude or longitude from Decimal Degrees to Degrees Minutes Seconds
		- source: https://www.dougv.com/2012/03/07/converting-latitude-and-longitude-coordinates-between-decimal-and-degrees-minutes-seconds/
		INPUT:
		- $decimal : the decimal degrees
		- $type ('lat|lng') : whether the decimal degrees is for a latitude or longitude
		OUTPUT:
		- associative array with keys 'degrees', 'minutes', 'seconds', 'direction'
		*/

		//set default values for variables passed by reference
		$degrees = 0;
		$minutes = 0;
		$seconds = 0;
		$direction = 'X';

		//decimal must be integer or float no larger than 180;
		//type must be Boolean
		if (!is_numeric($decimal) || abs($decimal) > 180 || !in_array($type, array('lat', 'lng', 'lon', 'long'))) {
			return false;
		}

		//inputs OK, proceed
		//type is latitude when true, longitude when false

		//set direction; north assumed
		if ($type && $decimal < 0) {
			$direction = 'S';
		} elseif (!$type && $decimal < 0) {
			$direction = 'W';
		} elseif (!$type) {
			$direction = 'E';
		} else {
			$direction = 'N';
		}

		//get absolute value of decimal
		$d = abs($decimal);

		//get degrees
		$degrees = floor($d);

		//get seconds
		$seconds = ($d - $degrees) * 3600;

		//get minutes
		$minutes = floor($seconds / 60);

		//reset seconds
		$seconds = floor($seconds - ($minutes * 60));

		return array(
			'degrees' => $degrees,
			'minutes' => $minutes,
			'seconds' => $seconds,
			'direction' => $direction,
		);
	}

	public static function is_in_polygon($polygon, $longitude_x, $latitude_y) {
		/*
		DESCRIPTION:
		- determine if a given latitude/longitude point is within a given polygon
		- source: http://stackoverflow.com/questions/5065039/find-point-in-polygon-php
		INPUT:
		- $polygon : array with subarray of points in the polygon, where first value is the longitude (decimal), second value is the latitude (decimal)
		- $longitude_x : longitude of point (decimal)
		- $latitude_y : latitude of point (decimal)
		OUTPUT:
		- true or false (or 1 and 0)
		*/
		$vertices_x = array();
		$vertices_y = array();
		foreach ($polygon as $p) {
			$vertices_x[] = $p[0];
			$vertices_y[] = $p[1];
		}
		$points_polygon = count($vertices_x) - 1;  // number vertices - zero-based array

		return self::_is_in_polygon($points_polygon, $vertices_x, $vertices_y, $longitude_x, $latitude_y);
	}
	private static function _is_in_polygon(&$points_polygon, &$vertices_x, &$vertices_y, &$longitude_x, &$latitude_y) {
		$i = $j = $c = 0;
		for ($i = 0, $j = $points_polygon ; $i < $points_polygon; $j = $i++) {
			if ( (($vertices_y[$i]  >  $latitude_y != ($vertices_y[$j] > $latitude_y)) &&
			($longitude_x < ($vertices_x[$j] - $vertices_x[$i]) * ($latitude_y - $vertices_y[$i]) / ($vertices_y[$j] - $vertices_y[$i]) + $vertices_x[$i]) ) )
				$c = !$c;
		}
		return $c;
	}
}
