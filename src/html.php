<?php
namespace winternet\jensenfw2;

class html {
	public static function parse_to_array($html) {
		/*
		DESCRIPTION:
		- convert HTML string to a PHP array
		- source: http://stackoverflow.com/questions/23062537/how-to-convert-html-to-json-using-php
		INPUT:
		- $html : string with HTML code
		OUTPUT:
		- array
		*/
		$dom = new \DOMDocument();
		$dom->loadHTML($html);

		$element_to_obj = function($element) use (&$element_to_obj) {
			$obj = array( 'tag' => $element->tagName );
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

	public static function parse_to_flat_array($html) {
		/*
		DESCRIPTION:
		- convert HTML string to a "flat" PHP array (flat meaning the hierarchy of the HTML structure has been removed)
		INPUT:
		- $html : string with HTML code
		OUTPUT:
		- array
		*/
		$element_to_obj = function($element) use (&$element_to_obj) {
			$obj = array( 'tag' => $element->tagName );
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

		$html_to_obj = function($html) use (&$element_to_obj) {
			$dom = new \DOMDocument();
			$dom->loadHTML('<?xml encoding="UTF-8">'. $html);
			// IGNORE ERRORS: @$dom->loadHTML($html);
			$obj = $element_to_obj($dom->documentElement);
			$return = $obj[0]['children'][0][0]['children'][0];
			unset($return['tag']);  //skip the <html> and <body> tags and remove the first tag that surrounds the entire text
			return $return;
		};

		$the_output = array();
		$the_optlist = array();
		$output_index = -1;

		$process_array = function($array, &$the_output, &$the_optlist, $level = 0) use (&$process_array, &$output_index) {
			foreach ($array as $key => $a) {
				if ($key === 'tag') {
					$id = '{'. $a .'}';
					//look for attributes of this tag
					$attributes = array();
					foreach ($array as $otherkey => $b) {
						if (is_string($otherkey) && $otherkey !== 'tag') {
							$id .= '('. $otherkey .'='. $b .')';
							$attributes[$otherkey] =  $b;
						}
					}

					$the_optlist[$id]['_level'] = $level;
					$the_optlist[$id]['tag'] = $a;
					if (in_array($a, array('h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'div', 'pre', 'blockquote', 'li', 'ol', 'ul', 'p', 'nav', 'section'))) {
						$is_new_block_element = true;
					}
					$the_optlist[$id]['tagtype'] = ($is_new_block_element ? 'block' : 'inline');
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
					$the_output[$output_index] = array('text' => $a, 'optlist' => array_values($the_optlist));  //use array_values to get rid of the keys consisting of the ID, which we don't need for anything (I think!)

					// If the optlist indicates that this text is a new block element (= that text starts in a new paragraph) then trim the last trailing line-break (if any) from the previous text so that they are not converted to blank lines and thereby making extra space between the paragraphs (only trim one space since multiple line-breaks do need to make extra space between the paragraphs)
					if ($is_new_block_element && $output_index >= 1) {
						$prev_output_index = $output_index - 1;
						$the_output[$prev_output_index]['text'] = preg_replace("/\\n$/", '', $the_output[$prev_output_index]['text'], 1);
					}

					$is_new_block_element = false;
				}
			}

			//remove options as we move higher up in the tree than at the level where they were set
			foreach ($the_optlist as $tag => $tagdata) {
				if ($level <= $tagdata['_level']) {
					unset($the_optlist[$tag]);
				}
			}
		};

		$dom = $html_to_obj('<div class="jfw_autoadded">'. $html .'</div>');  //class only added so that we have a reference to where it was set just in case it shows up anywhere! Normally it will just be removed by the DOM parsing function.

		$process_array($dom, $the_output, $the_optlist);
		return $the_output;
	}

	public static function parse_style_css($css) {
		// Source: http://stackoverflow.com/questions/4432334/parse-inline-css-values-with-regex
		$output = array();
		preg_match_all("/([\w-]+)\s*:\s*([^;]+)\s*;?/", $css, $matches, PREG_SET_ORDER);
		foreach ($matches as $match) {
			$output[$match[1]] = $match[2];
		}
		return $output;
	}

	public static function format_standard_function_result($arr_result, $ok_messageHTML, $error_messageHTML) {
		/*
		DESCRIPTION:
		- format the standard result/output from a function with 'status', 'result_msg', and 'err_msg' keys in an array
		INPUT:
		- $arr_result : the array that was returned by the function
		- $ok_messageHTML : message to write to the user when the operation succeeds (normally one sentence, eg. 'The event has been deleted.')
		- $error_messageHTML : message to write to the user when the operation fails (eg. 'Sorry, the event could not be deleted because:')
		OUTPUT:
		- HTML code
		*/
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
