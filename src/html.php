<?php
/**
 * Functions related to HTML and CSS
 */

namespace winternet\jensenfw2;

class html {
	/**
	 * Convert HTML string to a PHP array
	 *
	 * @link http://stackoverflow.com/questions/23062537/how-to-convert-html-to-json-using-php
	 *
	 * @param string $html : HTML code
	 * @return array
	 */
	public static function parse_to_array($html) {
		$dom = new \DOMDocument();
		$dom->loadHTML($html);

		$element_to_obj = function($element) use (&$element_to_obj) {
			$obj = [ 'tag' => $element->tagName ];
			foreach ($element->attributes as $attribute) {
				$obj[$attribute->name] = $attribute->value;
			}
			foreach ($element->childNodes as $subElement) {
				if ($subElement->nodeType == XML_TEXT_NODE) {
					$obj['html'] = $subElement->wholeText;
				} else {
					$obj['children'][] = $element_to_obj($subElement);
				}
			}
			return $obj;
		};

		return $element_to_obj($dom->documentElement);
	}

	/**
	 * Convert HTML string to a "flat" PHP array (flat meaning the hierarchy of the HTML structure has been removed)
	 *
	 * @param string $html : HTML code
	 * @param array $options : Associative array with any of these options:
	 *   - `optlist_unique_tag_keys` : set to true to make the tag IDs unique only within the optlist in its context
	 *   - `error_callback` : pass a function that is called in case there are errors or warnings, eg. about invalid HTML (instead of outputting them to screen)
	 *       - it is passed an array with 'load_result' and 'errors' (array of LibXMLError objects)
	 *       - sample error:
	 *           LibXMLError Object (
	 *               [level] => 2
	 *               [code] => 68
	 *               [column] => 6893
	 *               [message] => htmlParseEntityRef: no name
	 *               [file] =>
	 *               [line] => 1
	 *           )
	 *       - see also http://php.net/libxml_get_errors
	 *       - the function will have to raise an exception if script should be terminated
	 *
	 * @return array
	 */
	public static function parse_to_flat_array($html, $options = []) {
		$options = (array) $options;

		$element_to_obj = function($element) use (&$element_to_obj) {
			$obj = [ 'tag' => $element->tagName ];
			foreach ($element->attributes as $attribute) {
				$obj[$attribute->name] = $attribute->value;
			}
			$counter = -1;
			foreach ($element->childNodes as $subElement) {
				$counter++;
				if ($subElement->nodeType == XML_TEXT_NODE) {
					$obj[$counter] = $subElement->wholeText;
				} else {
					$obj[$counter]['children'][] = $element_to_obj($subElement);
				}
			}
			return $obj;
		};

		$html_to_obj = function($html) use (&$element_to_obj, &$options) {
			if (is_callable(@$options['error_callback'])) {
				libxml_use_internal_errors(true);
			}
			$dom = new \DOMDocument();
			$res = $dom->loadHTML('<?xml encoding="UTF-8">'. $html);
			// IGNORE ERRORS: see http://stackoverflow.com/a/12328343/2404541 - espacially the comment I upvoted
			if (is_callable(@$options['error_callback'])) {
				$errors = [];
				foreach (libxml_get_errors() as $error) {  //NOTE: $res doesn't necessarily have to evaluate to false
					$errors[] = $error;
				}
				libxml_clear_errors();

				call_user_func($options['error_callback'], ['load_result' => $res, 'errors' => $errors]);
			}
			$obj = $element_to_obj($dom->documentElement);
			$return = $obj[0]['children'][0][0]['children'][0];
			unset($return['tag']);  //skip the <html> and <body> tags and remove the first tag that surrounds the entire text
			return $return;
		};

		$the_output = [];
		$the_optlist = [];
		$tag_counter = [];
		$output_index = -1;

		$process_array = function($array, &$the_output, &$the_optlist, $level = 0) use (&$process_array, &$output_index, &$options, &$tag_counter) {
			foreach ($array as $key => $a) {
				if ($key === 'tag') {
					// Starting a new tag
					$id = '{'. $a .'}';

					// Look for attributes of this tag
					$attributes = [];
					foreach ($array as $otherkey => $b) {
						if (is_string($otherkey) && $otherkey !== 'tag') {
							$attributes[$otherkey] =  $b;
						}
					}

					if (!@$options['optlist_unique_tag_keys']) {
						// Ensure globally unique ID
						$tag_counter[$a]++;
						$id = $id . $tag_counter[$a];
					} else {
						// Ensure unique ID with in the current optlist
						$occur_counter = 1;
						while ($the_optlist[$id . $occur_counter]) {
							$occur_counter++;
						}
						$id = $id . $occur_counter;  //the counter actually becomes an indicator of how many nested occurences we haveof this given tag (always starts at 1. The first nested tag will have number 2)
					}

					$the_optlist[$id]['_level'] = $level;
					$the_optlist[$id]['tag'] = $a;
					$the_optlist[$id]['just_started'] = true;
					$the_optlist[$id]['is_ending'] = false;

					/*
					Misc sources:
					http://www.htmlhelp.com/reference/html40/block.html
					http://itman.in/en/html5-block-level-elements/
					http://www.htmlhelp.com/reference/html40/block.html
					https://stackoverflow.com/questions/21840505/td-element-is-a-inline-element-or-block-level-element#21840575
					*/
					if (in_array($a, ['h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'div', 'pre', 'blockquote', 'li', 'p', 'nav', 'section', 'table'])) {
						$the_optlist[$id]['tagtype'] = 'block';
					} elseif (in_array($a, ['ol', 'ul'])) {  //these do cause a new block though but so does <li> - but they don't cause two blocks in their own right, they together cause a block. They are rather "block-with-block-children". Maybe we need a separate array entry for marking that?
						$the_optlist[$id]['tagtype'] = 'block';
					} elseif (in_array($a, ['td', 'th', 'thead', 'tbody', 'tfoot'])) {  // I'm not quite sure how to classify these (maybe block-children-of-block?!) so set them to something unspecific until we one day figure out they need a classification
						$the_optlist[$id]['tagtype'] = '_unknown_';
					} else {
						$the_optlist[$id]['tagtype'] = 'inline';
					}

					if (!empty($attributes)) {  //no reason to add it if it is empty
						$the_optlist[$id]['attribs'] = $attributes;
						if ($the_optlist[$id]['attribs']['style']) {
							$the_optlist[$id]['attribs']['style_parsed'] = self::parse_style_css($the_optlist[$id]['attribs']['style']);
						}
					}
				} elseif (is_array($a) && array_key_exists('children', $a)) {
					$process_array($a['children'], $the_output, $the_optlist, ++$level);
				} elseif (is_array($a)) {
					$process_array($a, $the_output, $the_optlist, ++$level);
				} elseif (is_numeric($key) && is_string($a)) {
					$output_index++;

					$the_output[$output_index] = ['text' => $a, 'optlist' => $the_optlist, 'block_started' => false /*default*/];

					// Reset just_started for the next use of the contents in $the_optlist (since $the_optlist is reused for next fragment)
					$the_optlist = array_map(function($item) use (&$the_output, &$output_index) {
						// ...and at the same time regsiter if any of the options indicate that this text is a block element that was just started
						if ($item['tagtype'] == 'block' && $item['just_started']) {
							$the_output[$output_index]['block_started'] = true;
						}

						$item['just_started'] = false;
						return $item;
					}, $the_optlist);

					// If the optlist indicates that this text is a new block element (= that text starts in a new paragraph) then trim the last trailing line-break (if any) from the previous text so that they are not converted to blank lines and thereby making extra space between the paragraphs (only trim one space since multiple line-breaks do need to make extra space between the paragraphs)
					if ($the_output[$output_index]['block_started'] && $output_index >= 1) {
						$prev_output_index = $output_index - 1;
						$the_output[$prev_output_index]['text'] = preg_replace("/\\n$/", '', $the_output[$prev_output_index]['text'], 1);
					}
				}
			}

			//remove options as we move higher up in the tree than at the level where they were set
			foreach ($the_optlist as $tag => $tagdata) {
				if ($level <= $tagdata['_level']) {
					//record tags that are closing after this fragment
					$the_output[$output_index]['optlist'][$tag]['is_ending'] = true;

					unset($the_optlist[$tag]);
				}
			}
		};

		$html = preg_replace("/&(?![A-Za-z#]+;)/", '&amp;', $html);  //ensure entities are written correctly, otherwise failure on at least Windows, not sure about Linux
		$dom = $html_to_obj('<div class="jfw_autoadded">'. $html .'</div>');  //class only added so that we have a reference to where it was set just in case it shows up anywhere! Normally it will just be removed by the DOM parsing function.

		$process_array($dom, $the_output, $the_optlist);
		return $the_output;
	}

	/**
	 * Parse the CSS in a style attribute
	 *
	 * @link http://stackoverflow.com/questions/4432334/parse-inline-css-values-with-regex
	 */
	public static function parse_style_css($css) {
		$output = [];
		preg_match_all("/([\w-]+)\s*:\s*([^;]+)\s*;?/", $css, $matches, PREG_SET_ORDER);
		foreach ($matches as $match) {
			$output[$match[1]] = $match[2];
		}
		return $output;
	}

	/**
	 * Parse the CSS shorthand syntax for providing one to four values
	 *
	 * @param string $css_value : CSS property value, eg. `4px`, `2px 5px`, `2px 7px 5px`, or `2px 7px 3px 7px`
	 * @return array : Associative array with keys `top`, `left`, `bottom`, and `right`
	 */
	public static function parse_css_shorthand($css_value) {
		$css_value = trim($css_value);
		if (strpos($css_value, ' ') !== false) {
			$parts = preg_split("/[\\s]+/", $css_value);
			$partscount = count($parts);
			if ($partscount == 2) {
				$output['top'] = $parts[0];
				$output['bottom'] = $parts[0];
				$output['left'] = $parts[1];
				$output['right'] = $parts[1];
			} elseif ($partscount == 3) {
				$output['top'] = $parts[0];
				$output['left'] = $parts[1];
				$output['right'] = $parts[1];
				$output['bottom'] = $parts[2];
			} elseif ($partscount == 4) {
				$output['top'] = $parts[0];
				$output['right'] = $parts[1];
				$output['bottom'] = $parts[2];
				$output['left'] = $parts[3];
			} else {
				core::system_error('Invalid CSS property shorthand to parse.', ['Value' => $css_value]);
			}
		} else {
			// single value
			$output = [
				'top' => $css_value,
				'left' => $css_value,
				'bottom' => $css_value,
				'right' => $css_value,
			];
		}
		return $output;
	}

	/**
	 * Find and extract data from JSON-LD script tags in an HTML document
	 */
	public static function extract_json_ld($html) {
		$dom = new \DOMDocument;
		libxml_use_internal_errors(true);

		$dom->loadHTML($html);

		$xpath = new \DOMXPath($dom);
		$scriptNodes = $xpath->query('//script[@type="application/ld+json"]');

		$output = [];
		if ($scriptNodes->length > 0) {
			for ($i = 0; $i < $scriptNodes->length; $i++) {
				$output[] = json_decode(trim($scriptNodes->item($i)->textContent));
			}
		}
		return $output;
	}

	/**
	 * Format the standard result/output from a function with 'status', 'result_msg', and 'err_msg' keys in an array
	 *
	 * @param array $arr_result : The array that was returned by the function
	 * @param string $ok_messageHTML : Message to write to the user when the operation succeeds (normally one sentence, eg. 'The event has been deleted.')
	 * @param string $error_messageHTML : Message to write to the user when the operation fails (eg. 'Sorry, the event could not be deleted because:')
	 * @return string : HTML code
	 */
	public static function format_standard_function_result($arr_result, $ok_messageHTML, $error_messageHTML) {
		$html = '';
		if ($arr_result['status'] == 'ok') {
			$html = '<div class="std-func-result ok">'. $ok_messageHTML;
			if (count($arr_result['result_msg']) > 0) {
				$html .= ' Please note:';
				$html .= '<ul>';
				foreach ($arr_result['result_msg'] as $curr_msg) {
					$html .= '<li>'. $curr_msg .'</li>';
				}
				$html .= '</ul>';
			}
			$html .= '</div>';
		} else {
			$html  = '<div class="std-func-result error">'. $error_messageHTML;
			$html .= '<ul>';
			foreach ($arr_result['err_msg'] as $curr_msg) {
				$html .= '<li>'. $curr_msg .'</li>';
			}
			$html .= '</ul>';
			$html .= '</div>';
		}
		return $html;
	}
}
