<?php
/**
 * Class for dealing with OpenStreetMap
 *
 * See also https://towardsdatascience.com/beginner-guide-to-download-the-openstreetmap-gis-data-24bbbba22a38
 */

namespace winternet\jensenfw2;

class openstreetmap {

	/**
	 * Download data directly from OpenStreetMap
	 *
	 * Only for small-sized GIS datasets (max 50,000 nodes).
	 */
	public function download_data($min_lng, $min_lat, $max_lng, $max_lat) {
		$data_xml = network::http_request('GET', 'https://api.openstreetmap.org/api/0.6/map?bbox='. $min_lng .','. $min_lat .','. $max_lng .','. $max_lat);

		$data = \winternet\jensenfw2\xml::parse_xml_into_array($data_xml);  //should be refactored to use the plain SimpleXML extension for better performance

		return [
			'nodes' => static::get_nodes($data),
			'ways' => static::get_ways($data),
		];
	}

	/**
	 * Download data from OpenStreetMap via the Overpass API
	 *
	 * @param string $query : The Overpass query, see examples at https://wiki.openstreetmap.org/wiki/Overpass_API/Overpass_API_by_Example
	 * @return mixed : By default returns the raw response string, but if it is detected the query asks for JSON it automatically decodes it and returns an object
	 */
	public function overpass_query($query) {
		return network::http_request('POST', 'https://overpass-api.de/api/interpreter', ['data' => $query], ['parse_json' => (strpos($query, 'out:json') !== false ? 'object' : false)]);
	}

	/**
	 * Internal method for extracting nodes
	 */
	public function get_nodes(&$data) {
		$output = [];
		if (!empty($data['node'])) {
			foreach ($data['node'] as $node) {
				$tags = [];
				if ($node['tag']) {
					foreach ($node['tag'] as $tag) {
						if ($tag['@attributes']) {
							$tags[ $tag['@attributes']['k'] ] = $tag['@attributes']['v'];
						} else {
							// when it only has one tag
							$tags[ $tag['k'] ] = $tag['v'];
						}
					}
					$output[ $node['@attributes']['id'] ] = [
						'lat' => $node['@attributes']['lat'],
						'lon' => $node['@attributes']['lon'],
						'timestamp' => $node['@attributes']['timestamp'],
						'changeset' => $node['@attributes']['changeset'],
						'version' => $node['@attributes']['version'],
						// 'visible' => $node['@attributes']['visible'],
						'userid' => $node['@attributes']['uid'],
						'user' => $node['@attributes']['user'],
						'tags' => $tags,
					];
				}
			}
		}
		return $output;
	}

	/**
	 * Internal method for extracting ways
	 */
	public function get_ways(&$data) {
		$output = [];
		if (!empty($data['way'])) {
			foreach ($data['way'] as $way) {
				$tags = [];
				foreach ($way['tag'] as $tag) {
					if ($tag['@attributes']) {
						$tags[ $tag['@attributes']['k'] ] = $tag['@attributes']['v'];
					} else {
						// when it only has one tag
						$tags[ $tag['k'] ] = $tag['v'];
					}
				}
				$nodes = [];
				foreach ($way['nd'] as $node) {
					$nodes[] = (int) $node['@attributes']['ref'];
				}
				$output[ $way['@attributes']['id'] ] = [
					'timestamp' => $way['@attributes']['timestamp'],
					'changeset' => $way['@attributes']['changeset'],
					'version' => $way['@attributes']['version'],
					// 'visible' => $way['@attributes']['visible'],
					'userid' => $way['@attributes']['uid'],
					'user' => $way['@attributes']['user'],
					'tags' => $tags,
					'nodes' => $nodes,
				];
			}
		}
		return $output;
	}

}
