<?php
/*
This file contains functions related to XML
*/
namespace winternet\jensenfw2;

class xml {
	function xml_tag($tag, $content, $flags = '') {
		/*
		DESCRIPTION:
		- generate an XML element, eg.: <firstName>Regina</firstName>
		INPUT:
		- $tag : name of the XML tag/element
		- $content : value for the given tag
		OUTPUT:
		- string
		*/
		if (is_array($tag)) {
			$attribs = $tag[1];
			$tag = $tag[0];
			$tmp = array();
			foreach ($attribs as $name => $value) {
				if (strpos($eff_flags, 'skip-entities') === false) {
					$tmp[] = $name .'="'. xml_entities($value) .'"';
				} else {
					$tmp[] = $name .'="'. $value .'"';
				}
			}
			$attribs = ' '. implode(' ', $tmp);
		}
		if ($content && strpos($eff_flags, 'cdata') !== false) {
			return '<'. $tag . ($attribs ? $attribs : '') .'><![CDATA['. $content .']]></'. $tag .'>'."\r\n";
		} else {
			if (strpos($eff_flags, 'skip-entities') === false) {
				$content = self::xml_entities($content);
			}
			return '<'. $tag . ($attribs ? $attribs : '') .'>'. $content .'</'. $tag .'>'."\r\n";
		}
	}

	function xml_entities($string) {
		/*
		DESCRIPTION:
		- convert a string to use XML entities
		INPUT:
		- $string
		OUTPUT:
		- converted string
		*/
		$string = (string) $string;
		// Unlike HTML, XML supports only five "named character entities":
		$named_entities = array(
			'&' => '&amp;', //ampersand
			'<' => '&lt;', //less-than
			'>' => '&gt;', //greater-than
			"'" => '&apos;', //apostrophe (single-quote)
			'"' => '&quot;', //quotation (double-quote)
		);
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

	function parse_xml_into_array($xml_string, $options = []) {
		/*
		DESCRIPTION:
		- parse an XML string into an array
		INPUT:
		- $xml_string
		- $options : associative array with any of these keys:
			- 'flatten_cdata' : set to true to flatten CDATA elements
			- 'use_objects' : set to true to parse into objects instead of associative arrays
			- 'convert_booleans' : set to true to cast string values 'true' and 'false' into booleans
		OUTPUT:
		- associative array
		*/

		// Remove namespaces by replacing ":" with "_"
		if (preg_match_all("|</([\\w\\-]+):([\\w\\-]+)>|", $xml_string, $matches, PREG_SET_ORDER)) {
			foreach ($matches as $match) {
				$xml_string = str_replace('<'. $match[1] .':'. $match[2], '<'. $match[1] .'_'. $match[2], $xml_string);
				$xml_string = str_replace('</'. $match[1] .':'. $match[2], '</'. $match[1] .'_'. $match[2], $xml_string);
			}
		}

		$output = json_decode(json_encode(@simplexml_load_string($xml_string, 'SimpleXMLElement', ($options['flatten_cdata'] ? LIBXML_NOCDATA : 0))), ($options['use_objects'] ? false : true));

		// Cast string values "true" and "false" to booleans
		if ($options['convert_booleans']) {
			$bool = function(&$item, $key) {
				if (in_array($item, array('true', 'TRUE', 'True'), true)) {
					$item = true;
				} elseif (in_array($item, array('false', 'FALSE', 'False'), true)) {
					$item = false;
				}
			};
			array_walk_recursive($output, $bool);
		}

		return $output;
	}

	public static function dump_xml($xml_string) {
		echo '<pre style="background-color: #feccfc; padding: 5px; border: 1px solid #e8aee6; border-radius: 3px; display: inline-block">';
		$dom = new DOMDocument;
		$dom->preserveWhiteSpace = FALSE;
		$dom->loadXML($xml_string);
		$dom->formatOutput = TRUE;
		echo htmlentities($dom->saveXml());
		echo '</pre>';
	}
}
