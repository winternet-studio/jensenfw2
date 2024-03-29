<?php
/**
 * Functions related to XML
 */

namespace winternet\jensenfw2;

class xml {
	/**
	 * Generate an XML element, eg.: <firstName>Regina</firstName>
	 *
	 * @param string $tag : Name of the XML tag/element
	 * @param string $content : Value for the given tag
	 * @return string
	 */
	public static function xml_tag($tag, $content, $options = []) {
		if (is_array($tag)) {
			$attribs = $tag[1];
			$tag = $tag[0];
			$tmp = [];
			foreach ($attribs as $name => $value) {
				if (!@$options['skip_entities']) {
					$tmp[] = $name .'="'. static::xml_entities($value) .'"';
				} else {
					$tmp[] = $name .'="'. $value .'"';
				}
			}
			$attribs = ' '. implode(' ', $tmp);
		}
		if ($content && @$options['cdata']) {
			return '<'. $tag . ($attribs ? $attribs : '') .'><![CDATA['. $content .']]></'. $tag .'>'."\r\n";
		} else {
			if (!@$options['skip_entities']) {
				$content = static::xml_entities($content);
			}
			return '<'. $tag . ($attribs ? $attribs : '') .'>'. $content .'</'. $tag .'>'."\r\n";
		}
	}

	/**
	 * Convert a string to use XML entities
	 *
	 * @param string $string
	 * @return string : Converted string
	 */
	public static function xml_entities($string) {
		$string = (string) $string;
		// Unlike HTML, XML supports only five "named character entities":
		$named_entities = [
			'&' => '&amp;', //ampersand
			'<' => '&lt;', //less-than
			'>' => '&gt;', //greater-than
			"'" => '&apos;', //apostrophe (single-quote)
			'"' => '&quot;', //quotation (double-quote)
		];
		/*
		FOR DEVELOPER:
		The five characters above are the only characters that require escaping in XML.
		All other characters can be entered directly in an editor that supports UTF-8.
		You can also use numeric character references that specify
		the Unicode for the character, for example:
			©	copyright sign				&#xA9;
			?	sound recording copyright	&#x2117;
			™	trade mark sign				&#x2122;

		For more documentation see http://www.xml.com/axml/target.html#sec-references
		*/
		foreach ($named_entities as $name => $entity) {
			if ($name == '&') {
				//replace & only if not followed by # (eg. &#539;)
				$string = preg_replace('/&(?!#)/', '&amp;', $string);
			} else {
				$string = str_replace($name, $entity, $string);
			}
		}
		return $string;
	}

	/**
	 * Parse an XML string into an array
	 *
	 * @param string $xml_string
	 * @param array $options : Associative array with any of these keys:
	 *   - `flatten_cdata` : set to true to flatten CDATA elements
	 *   - `use_objects` : set to true to parse into objects instead of associative arrays
	 *   - `convert_booleans` : set to true to cast string values 'true' and 'false' into booleans
	 * @return array : Associative array
	 */
	public static function parse_xml_into_array($xml_string, $options = []) {
		// Remove namespaces by replacing ":" with "_"
		if (preg_match_all("|</([\\w\\-]+):([\\w\\-]+)>|", $xml_string, $matches, PREG_SET_ORDER)) {
			foreach ($matches as $match) {
				$xml_string = str_replace('<'. $match[1] .':'. $match[2], '<'. $match[1] .'_'. $match[2], $xml_string);
				$xml_string = str_replace('</'. $match[1] .':'. $match[2], '</'. $match[1] .'_'. $match[2], $xml_string);
			}
		}

		$output = json_decode(json_encode(@simplexml_load_string($xml_string, 'SimpleXMLElement', (@$options['flatten_cdata'] ? LIBXML_NOCDATA : 0))), (@$options['use_objects'] ? false : true));

		// Cast string values "true" and "false" to booleans
		if (@$options['convert_booleans']) {
			$bool = function(&$item, $key) {
				if (in_array($item, ['true', 'TRUE', 'True'], true)) {
					$item = true;
				} elseif (in_array($item, ['false', 'FALSE', 'False'], true)) {
					$item = false;
				}
			};
			array_walk_recursive($output, $bool);
		}

		return $output;
	}

	/**
	 * Parse an XML string into an object
	 *
	 * @param array $options : See parse_xml_into_array()
	 */
	public static function parse_xml($xml_string, $options = []) {
		return static::parse_xml_into_array($xml_string, array_merge($options, ['use_objects' => true]));
	}

	public static function dump_xml($xml_string) {
		echo '<pre style="background-color: #feccfc; padding: 5px; border: 1px solid #e8aee6; border-radius: 3px; display: inline-block">';
		$dom = new \DOMDocument;
		$dom->preserveWhiteSpace = FALSE;
		$dom->loadXML($xml_string);
		$dom->formatOutput = TRUE;
		echo htmlentities($dom->saveXml());
		echo '</pre>';
	}
}
