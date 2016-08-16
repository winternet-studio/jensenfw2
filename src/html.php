<?php
namespace winternet\jensenfw2;

class html {
	static public function parse_to_flat_array($html) {
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
					if (in_array($a, array('h1', 'h2', 'h3', 'h4', 'h5', 'h6'))) {
						$is_new_block_element = true;
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
					$the_output[$output_index] = array('text' => $a, 'optlist' => array_values($the_optlist));  //use array_values to get rid of the keys consisting of the ID, which we don't need for anything (I think!)

					// If the optlist indicates that this text is a new block element (= that text starts in a new paragraph) then rtrim() any trailing spaces from the previous text so that they are not converted to blank lines and thereby making extra space between the paragraphs
					if ($is_new_block_element && $output_index >= 1) {
						$prev_output_index = $output_index - 1;
						$the_output[$prev_output_index]['text'] = rtrim($the_output[$prev_output_index]['text']);
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

	static public function parse_style_css($css) {
		// Source: http://stackoverflow.com/questions/4432334/parse-inline-css-values-with-regex
		$output = array();
		preg_match_all("/([\w-]+)\s*:\s*([^;]+)\s*;?/", $css, $matches, PREG_SET_ORDER);
		foreach ($matches as $match) {
			$output[$match[1]] = $match[2];
		}
		return $output;
	}
}
