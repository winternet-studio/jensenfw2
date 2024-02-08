<?php
/**
 * Methods related to geography
 *
 * Latitude/longitude is specified in WGS84 (also named EPSG:4326).
 *
 * @see https://github.com/proj4php/proj4php (another library that can convert between datums/projections)
 * @see https://github.com/proj4js/proj4js
 *
 * TESTS:
 * ```
 * $longitude_x = 37.6;  // x-coordinate of the point to test
 * $latitude_y = -77.446;    // y-coordinate of the point to test
 *
 * $polygon = array(array(37.628134,-77.458334), array(37.629867,-77.449021), array(37.62324,-77.445416), array(37.622424,-77.457819));
 *
 * if (geography::is_in_polygon($polygon, $longitude_x, $latitude_y)) {
 * 	echo 'Is inside polygon';
 * } else {
 * 	echo 'Is outside polygon';
 * }
 * ```
 */

namespace winternet\jensenfw2;

class geography {
	/**
	 * Calculate the distance between two latitude/longitude coordinates
	 *
	 * Example: distance(32.9697, -96.80322, 29.46786, -98.53506, 'km')
	 *
	 * Source: http://www.zipcodeworld.com/samples/distance.php.html (did minor adjustments)
	 *
	 * @param float $lat1 : Latitude  of 1st coordinate
	 * @param float $lng1 : Longitude of 1st coordinate
	 * @param float $lat2 : Latitude  of 2nd coordinate
	 * @param float $lng2 : Longitude of 2nd coordinate
	 * @param string $unit : Unit to return the results in. Options are:
	 * 	- `km`  : kilometers
	 * 	- `m`   : meters
	 * 	- `mi`  : statute miles (most common in US) (default)
	 * 	- `nmi` : nautical miles
	 *
	 * @return float : Distance in the given unit
	 */
	public static function distance($lat1, $lng1, $lat2, $lng2, $unit) {
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

	/**
	 * Calculate the great-circle bearing (follow earth's curvature), in degrees, from starting Point A to remote Point B
	 *
	 * The bearing varies as you move towards Point B, so for navigation you would have to recalcuate this from time to time
	 *
	 * Source: http://webcache.googleusercontent.com/search?q=cache:o-7HcMkYfh0J:https://www.dougv.com/2009/07/13/calculating-the-bearing-and-compass-rose-direction-between-two-latitude-longitude-coordinates-in-php/+&cd=2&hl=no&ct=clnk
	 *
	 * @param float $lat1 : Latitude of Point A
	 * @param float $lng1 : Longitude of Point A
	 * @param float $lat2 : Latitude of Point B
	 * @param float $lng2 : Longitude of Point B
	 *
	 * @return float : Bearing
	 */
	public static function bearing_greatcircle($lat1, $lng1, $lat2, $lng2) {
		return (rad2deg(atan2(sin(deg2rad($lng2) - deg2rad($lng1)) * cos(deg2rad($lat2)), cos(deg2rad($lat1)) * sin(deg2rad($lat2)) - sin(deg2rad($lat1)) * cos(deg2rad($lat2)) * cos(deg2rad($lng2) - deg2rad($lng1)))) + 360) % 360;
	}

	/**
	 * Calculate the bearing of a rhumb-line (straight line on map), in degrees, from starting Point A to remote Point B
	 *
	 * The bearing for a rhumb-line is constant all the way
	 *
	 * Source: http://webcache.googleusercontent.com/search?q=cache:o-7HcMkYfh0J:https://www.dougv.com/2009/07/13/calculating-the-bearing-and-compass-rose-direction-between-two-latitude-longitude-coordinates-in-php/+&cd=2&hl=no&ct=clnk
	 *
	 * @param float $lat1 : Latitude of Point A
	 * @param float $lng1 : Longitude of Point A
	 * @param float $lat2 : Latitude of Point B
	 * @param float $lng2 : Longitude of Point B
	 *
	 * @return float : Bearing
	 */
	public static function bearing_rhumbline($lat1, $lng1, $lat2, $lng2) {
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

	/**
	 * Get the cardinal direction of a bearing divided into 8 different directions
	 *
	 * @param float $bearing : Number between 0 and 359
	 * @return array : Associative array with keys `short` and `long`
	 */
	public static function get_compass_rose_direction_8($bearing) {
		if ($bearing <= 22.5 || $bearing > 337.5) {
			return ['short' => 'N', 'long' => 'North'];
		} elseif ($bearing <= 67.5) {
			return ['short' => 'NE', 'long' => 'North East'];
		} elseif ($bearing <= 112.5) {
			return ['short' => 'E', 'long' => 'East'];
		} elseif ($bearing <= 157.5) {
			return ['short' => 'SE', 'long' => 'South East'];
		} elseif ($bearing <= 202.5) {
			return ['short' => 'S', 'long' => 'South'];
		} elseif ($bearing <= 247.5) {
			return ['short' => 'SW', 'long' => 'South West'];
		} elseif ($bearing <= 292.5) {
			return ['short' => 'W', 'long' => 'West'];
		} else { // $bearing <= 337.5
			return ['short' => 'NW', 'long' => 'North West'];
		}
	}

	/**
	 * Calculate the point (latitude/longitude) given a point of origin, a bearing, and a distance
	 *
	 * Source: http://www.etechpulse.com/2014/02/calculate-latitude-and-longitude-based.html
	 *
	 * @param float $lat : Origin point latitude
	 * @param float $lng : Origin point longitude
	 * @param float $angle : Bearing in degrees (0-360)
	 * @param float $distance : Distance from the point in kilometers
	 *
	 * @return array : First entry is the new latitude, second entry the new longitude, eg.: array(60.6793281, 8.6953779)
	 */
	public static function point_from_bearing_distance($lat, $lng, $angle, $distance) {
		$new_latlng = [];
		$distance = $distance / 6371;
		$angle = deg2rad($angle);

		$lat1 = deg2rad($lat);
		$lng1 = deg2rad($lng);

		$new_lat = asin(sin($lat1) * cos($distance) +
					  cos($lat1) * sin($distance) * cos($angle));

		$new_lng = $lng1 + atan2(sin($angle) * sin($distance) * cos($lat1),
							  cos($distance) - sin($lat1) * sin($new_lat));

		if (is_nan($new_lat) || is_nan($new_lng)) {
			return null;
		}

		$new_latlng[0] = rad2deg($new_lat);
		$new_latlng[1] = rad2deg($new_lng);

		return $new_latlng;
	}

	/**
	 * Calculate the coordinates for a square around a center point
	 *
	 * @see latlng_to_tile_map_bbox()
	 *
	 * @param float $lat : Center point latitude
	 * @param float $lng : Center point longitude
	 * @param float $distance : Distance from the center point to the edge in kilometers
	 *
	 * @return array : Array with keys `min_lng`, `min_lat`, `max_lng`, `max_lat`
	 */
	public static function square_around_point($lat, $lng, $distance) {
		return [
			'min_lng' => static::point_from_bearing_distance($lat, $lng, 270, $distance)[1],
			'min_lat' => static::point_from_bearing_distance($lat, $lng, 180, $distance)[0],
			'max_lng' => static::point_from_bearing_distance($lat, $lng, 90, $distance)[1],
			'max_lat' => static::point_from_bearing_distance($lat, $lng, 0, $distance)[0],
		];
	}

	/**
	 * Convert a latitude or longitude from Degrees Minutes Seconds to Decimal Degrees
	 *
	 * Eg. N 55° 40' 49.872", E 12° 33' 44.028"  ===>  55.68052, 12.56223
	 *
	 * Source: https://www.dougv.com/2012/03/07/converting-latitude-and-longitude-coordinates-between-decimal-and-degrees-minutes-seconds/
	 *
	 * @param float $degrees
	 * @param float $minutes
	 * @param float $seconds
	 * @param string $cardinal_direction : Possible values: `N`, `S`, `E`, `W` (case-insensitive)
	 *
	 * @return float : The decimal degrees
	 */
	public static function convert_coordinate_dms_to_decimal($degrees, $minutes, $seconds, $cardinal_direction) {
		$d = strtoupper($cardinal_direction);
		$ok = ['N', 'S', 'E', 'W'];

		//degrees must be integer between 0 and 180
		if (!is_numeric($degrees) || $degrees < 0 || $degrees > 180) {
			core::system_error('Invalid degress for converting to decimal.');
		} elseif(!is_numeric($minutes) || $minutes < 0 || $minutes > 59) {  //minutes must be integer or float between 0 and 59
			core::system_error('Invalid minutes for converting to decimal.');
		} elseif (!is_numeric($seconds) || $seconds < 0 || $seconds > 59) {  //seconds must be integer or float between 0 and 59
			core::system_error('Invalid seconds for converting to decimal.');
		} elseif (!in_array($d, $ok)) {
			core::system_error('Invalid direction for converting to decimal.');
		} else {
			$decimal = $degrees + ($minutes / 60) + ($seconds / 3600);

			//reverse for south or west coordinates; north is assumed
			if ($d == 'S' || $d == 'W') {
				$decimal *= -1;
			}
		}

		return $decimal;
	}

	/**
	 * Convert a latitude or longitude from Decimal Degrees to Degrees Minutes Seconds
	 *
	 * Eg. 55.68052, 12.56223  ===>  N 55° 40' 49.872", E 12° 33' 44.028"
	 *
	 * Source: https://www.dougv.com/2012/03/07/converting-latitude-and-longitude-coordinates-between-decimal-and-degrees-minutes-seconds/
	 *
	 * @param float $decimal : The decimal degrees, eg. `59.7682642` or `-122.4726193`
	 * @param string $type : Whether the decimal degrees is for a latitude or longitude: `lat` or `lng`
	 * @return array : Array with keys `degrees`, `minutes`, `seconds`, `direction`
	 */
	public static function convert_coordinate_decimal_to_dms($decimal, $type) {
		if (!is_numeric($decimal) || abs($decimal) > 180 || !in_array($type, ['lat', 'lng'])) {
			core::system_error('Invalid parameters for converting decimal degrees.', ['Decimal' => $decimal, 'Type' => $type]);
		}

		if ($type == 'lat' && $decimal < 0) {
			$cardinal_direction = 'S';
		} elseif ($type == 'lng' && $decimal < 0) {
			$cardinal_direction = 'W';
		} elseif ($type == 'lng') {
			$cardinal_direction = 'E';
		} else {
			$cardinal_direction = 'N';
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
		$seconds = round($seconds - ($minutes * 60), 5);

		return [
			'degrees' => $degrees,
			'minutes' => $minutes,
			'seconds' => $seconds,
			'direction' => $cardinal_direction,
			'textual' => $cardinal_direction .' '. $degrees .'° '. $minutes ."' ". $seconds .'"',
		];
	}

	/**
	 * Convert a latitude or longitude from Decimal Degrees to Degrees Decimal Minutes
	 *
	 * Eg. 55.68052, 12.56223  ===>  N 55° 40.8312', E 12° 33.7338'
	 *
	 * @param float $decimal : The decimal degrees, eg. `59.7682642` or `-122.4726193`
	 * @param string $type : Whether the decimal degrees is for a latitude or longitude: `lat` or `lng`
	 * @return array : Array with keys `degrees`, `minutes`, `direction`
	 */
	public static function convert_coordinate_decimal_to_ddm($decimal, $type) {
		$output = static::convert_coordinate_decimal_to_dms($decimal, $type);

		$output['minutes'] = $output['minutes'] + round($output['seconds'] / 60, 5);
		unset($output['seconds']);

		return $output;
	}

	/**
	 * Determine if a given latitude/longitude point is within a given polygon
	 *
	 * Source: http://stackoverflow.com/questions/5065039/find-point-in-polygon-php
	 *
	 * @param array $polygon : Array with subarray of points in the polygon, where first value is the longitude (decimal), second value is the latitude (decimal)
	 * @param float $longitude_x : Longitude of point
	 * @param float $latitude_y : Latitude of point
	 *
	 * @return mixed : True or false (or 1 and 0)
	 */
	public static function is_in_polygon($polygon, $longitude_x, $latitude_y) {
		$vertices_x = [];
		$vertices_y = [];
		foreach ($polygon as $p) {
			$vertices_x[] = $p[0];
			$vertices_y[] = $p[1];
		}
		$points_polygon = count($vertices_x) - 1;  // number vertices - zero-based array

		return static::_is_in_polygon($points_polygon, $vertices_x, $vertices_y, $longitude_x, $latitude_y);
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

	/**
	 * Convert X/Y/Zoom from slippy map tile names to latitude/longitude
	 *
	 * @link https://gis.stackexchange.com/questions/109095/converting-xyz-tile-request-to-wms-request
	 * @link https://wiki.openstreetmap.org/wiki/Slippy_map_tilenames
	 */
	public static function convert_xyzoom_to_latlng($xtile, $ytile, $zoom) {
		$n = pow(2, $zoom);
		$lon_deg = $xtile / $n * 360.0 - 180.0;
		$lat_deg = rad2deg(atan(sinh(pi() * (1 - 2 * $ytile / $n))));
		return [
			'lng' => $lon_deg,
			'lat' => $lat_deg,
		];
	}

	/**
	 * Convert latitude/longitude to X/Y/Zoom from slippy map tile names
	 */
	public static function convert_latlng_to_xyzoom($lat, $lng, $zoom) {
		$xtile = floor((($lng + 180) / 360) * pow(2, $zoom));
		$ytile = floor((1 - log(tan(deg2rad($lat)) + 1 / cos(deg2rad($lat))) / pi()) /2 * pow(2, $zoom));
		return [
			'x' => $xtile,
			'y' => $ytile,
		];
	}

	/**
	 * Convert X/Y/Zoom from slippy map tile names to TMS tiles, or vice versa
	 *
	 * HAS NOT BEEN TESTED!
	 *
	 * @see https://gis.stackexchange.com/questions/132242/what-are-the-differences-between-tms-xyz-wmts
	 *
	 * @source https://gist.github.com/tmcw/4954720
	 */
	public static function convert_xyzoom_tofrom_tms($xtile, $ytile, $zoom) {
		$ytile = pow(2, $zoom) - $ytile - 1;
		return [
			'x' => $xtile,
			'y' => $ytile,
			'zoom' => $zoom,
		];
	}

	/**
	 * Convert latitude/longitude to a bounding box
	 *
	 * @link https://wiki.openstreetmap.org/wiki/Slippy_map_tilenames#Lon..2Flat._to_bbox
	 *
	 * @see square_around_point()
	 *
	 * @param string $anchor_point : `center` or `top-left`
	 */
	public static function latlng_to_tile_map_bbox($lat, $lng, $zoom, $map_width = 1000, $map_height = 1000, $anchor_point = 'center') {
		$tile_size = 256;

		list('x' => $xtile, 'y' => $ytile) = static::convert_latlng_to_xyzoom($lat, $lng, $zoom);

		if ($anchor_point === 'center') {
			$xtile_s = ($xtile * $tile_size - $map_width/2) / $tile_size;
			$ytile_s = ($ytile * $tile_size - $map_height/2) / $tile_size;
			$xtile_e = ($xtile * $tile_size + $map_width/2) / $tile_size;
			$ytile_e = ($ytile * $tile_size + $map_height/2) / $tile_size;
		} elseif ($anchor_point === 'top-left') {
			$xtile_s = ($xtile * $tile_size ) / $tile_size;
			$ytile_s = ($ytile * $tile_size ) / $tile_size;
			$xtile_e = ($xtile * $tile_size + $map_width) / $tile_size;
			$ytile_e = ($ytile * $tile_size + $map_height) / $tile_size;
		} else {
			core::system_error('Invalid anchor point for converting latitude/longitude to a bounding box.', ['Anchor point' => $anchor_point]);
		}

		list('lng' => $lng_s, 'lat' => $lat_s) = static::convert_xyzoom_to_latlng($xtile_s, $ytile_s, $zoom);
		list('lng' => $lng_e, 'lat' => $lat_e) = static::convert_xyzoom_to_latlng($xtile_e, $ytile_e, $zoom);

		return [
			'min_lng' => $lng_s,  //west
			'max_lng' => $lng_e,  //east
			'min_lat' => $lat_e,  //south
			'max_lat' => $lat_s,  //north
		];
	}

	/**
	 * Convert X/Y/Zoom to a latitude/longitude bounding box, which can be used in a WMS service
	 */
	public static function xyzoom_to_bbox($xtile, $ytile, $zoom, $tile_size = 256) {
		$coord = static::convert_xyzoom_to_latlng($xtile, $ytile, $zoom);
		return static::latlng_to_tile_map_bbox($coord['lat'], $coord['lng'], $zoom, $tile_size, $tile_size, 'top-left');  //default tile size is from https://tile.openstreetmap.org/16/34317/18715.png
	}
}
