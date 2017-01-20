<?php
namespace winternet\jensenfw2;

use \Imagick;

class imaging {
	static public function image_info($input_filepath) {
		/*
		DESCRIPTION:
		- get information about an image
		INPUT:
		- $input_filepath
		OUTPUT:
		- associative array:
			- 'w' (number) : width in pixels
			- 'h' (number) : height in pixels
			- 'ratio' (number) : aspect ratio (width divided by height)
			- 'is_vertical' (boolean) : is image vertical/portrait or horizontal/landscape
		*/
		$size = getimagesize($input_filepath);
		if ($size == false) {
			return false;
		}
		$w = $size[0];  //width
		$h = $size[1];  //height
		$aspect_ratio = $w / $h; //calculate aspect ratio
		$is_vertical = ($aspect_ratio < 1 ? true : false);  //determine if the image is vertical
		return array(
			'w' => $w,
			'h' => $h,
			'ratio' => $aspect_ratio,
			'is_vertical' => $is_vertical,
		);
	}

	static public function png_has_transparency($filepath) {
		/*
		DESCRIPTION:
		- detect if a PNG has alpha channel (transparency)
		- source: http://stackoverflow.com/a/8750947/2404541
		INPUT:
		- $filepath
		OUTPUT:
		- boolean
		*/
		if (!file_exists($filepath)) {
			core::system_error('File to check transparency for does not exist.');
		}
		$byte = ord(file_get_contents($filepath, null, null, 25, 1));
		return ($byte == 6 || $byte == 4 ? true : false);
	}

	static public function resize_and_crop_calc($input_width, $input_height, $target_width, $target_height) {
		/*
		DESCRIPTION:
		- calculate the portion of a source image that must fit into a specific width and height
		- if changing proportion of the image is necessary the exceeding image material is cropped away
		- the cropped image will be a centered part of the source
		INPUT:
		- see arguments above
		OUTPUT:
		- associative array with 3 elements:
			- 'w' is the width we want to use of the source
			- 'h' is the height we want to use of the source
			- 'x' is the x coordinate on the source image that will be the upper left corner in the new image
			- 'y' is the y coordinate on the source image that will be the upper left corner in the new image
			- 'cuttingboundary' tells whether the width or the height has been cut in order to obtain the new image size and proportions
		*/
		$input_ratio = $input_width / $input_height;
		$target_ratio = $target_width / $target_height;
		//determine which length to cut
		if ($input_ratio < $target_ratio) {
			$cutwhat = 'height';
		} else {
			$cutwhat = 'width';
		}
		//determine portion of source image to use
		switch ($cutwhat) {
		case 'width':
			//determine size of the source to use in the new image
			$src_width = round($input_height * $target_ratio);
			$src_height = $input_height;
			//determine x coordinate of where to cut
			$src_x = round(($input_width - $src_width) / 2);  //subtract the the length portion from the origianl image length and divide equal space on both sides of portion
			$src_y = 0;
			break;
		case 'height':
			//determine size of the source to use in the new image
			$src_width = $input_width;
			$src_height = round($input_width / $target_ratio);
			//determine y coordinate of where to cut
			$src_x = 0;
			$src_y = round(($input_height - $src_height) / 2);  //subtract the the length portion from the origianl image length and divide equal space on both sides of portion
			break;
		}
		return array('w' => $src_width, 'h' => $src_height, 'x' => $src_x, 'y' => $src_y, 'cuttingboundary' => $cutwhat);
	}

	static public function resize_calc($curr_width, $curr_height, $max_width, $max_height) {
		/*
		DESCRIPTION:
		- this function calculates the new size for a image to fit into a max width and height
		INPUT:
		- see arguments above
		OUTPUT:
		- array with 3 elements:
			- 'w' is the new width
			- 'h' is the new height
			- 'is_resized' is true/false depending on if new size was calculated at all (it is not calculated is source image is within the allowed size)
		*/
		if ($curr_width > $max_width || $curr_height > $max_height) {  //only do calculation if image is actually bigger than allowed
			//determine if width or height is the one being the max size
			$curr_ratio = $curr_width / $curr_height;
			$max_ratio = $max_width / $max_height;
			if ($curr_ratio < $max_ratio) {
				$maxboundary = 'height';
			} else {
				$maxboundary = 'width';
			}
			//calculate new size
			switch ($maxboundary) {
			case 'width':
				$new_width = $max_width;
				$new_height = $new_width / $curr_width * $curr_height;
				break;
			case 'height':
				$new_height = $max_height;
				$new_width = $new_height / $curr_height * $curr_width;
				break;
			}
			//round off
			$new_width = round($new_width);
			$new_height = round($new_height);
			return array('w' => $new_width, 'h' => $new_height, 'is_resized' => true);
		} else {
			return array('w' => $curr_width, 'h' => $curr_height, 'is_resized' => false);
		}
	}

	static public function resize_save($inputfilepath, $outputfilepath, $curr_width, $curr_height, $new_width, $new_height, $options = array() ) {
		/*
		DESCRIPTION:
		- resize a image file and save it to another file
		- handles JPG and PNG
		INPUT:
		- $inputfilepath (req.)
		- $outputfilepath (req.)
		- $curr_width (req.)
		- $curr_height (req.)
		- $new_width (req.)
		- $new_height (req.)
		- $options (opt.) : associative array with any of the following keys:
			- 'autodetect_transparency' : set to true to auto-detect transparency for PNG images. Needed if you want to retain transparency in the resized image. (can be set for JPG images as well but will have no effect)
			- 'add_elements' : array with any number of elements to add (see code), or string with text to write in lower left corner of picture
				- ARRAY METHOD NOT FULLY IMPLEMENTED
			- 'quality' : set output quality. Has different meaning depending on image type:
				- jpg (0-99) : amount of compression resulting in different file sizes and image qualities. Default 90
				- png (0-9)  : amount of compression resulting in different file sizes and amount of time required (png is always lossless). Default 9
			- 'src_x' : according to imagecopyresampled()
			- 'src_y' : according to imagecopyresampled()
		OUTPUT:
		- associative array with these values (= keys):
			- 'status' ('ok'|'error') : whether the operation was successful or not
			- 'err_msg' : array with error messages that arose which prohibited us from doing the operation
			- 'result_msg' : array with informational messages that arose from completing the operation
		*/
		$err_msg = array();
		$result_msg = array();
		$has_transparency = false;

		$src_ext = strtolower(pathinfo($inputfilepath, PATHINFO_EXTENSION));
		if ($src_ext == 'jpeg') $src_ext = 'jpg';
		if ($src_ext != 'jpg' && $src_ext != 'png') {
			$err_msg[] = 'Input file extension '. $src_ext .' is not supported.';
		}

		if (!is_numeric($options['src_x'])) {
			$options['src_x'] = 0;
		} else {
			$options['src_x'] = (int) $options['src_x'];  //ensure it's an integer
		}
		if (!is_numeric($options['src_y'])) {
			$options['src_y'] = 0;
		} else {
			$options['src_y'] = (int) $options['src_y'];  //ensure it's an integer
		}

		if (count($err_msg) == 0) {
			if ($src_ext == 'jpg') {
				$img_src = imagecreatefromjpeg($inputfilepath);
				if (!is_numeric($options['quality'])) {
					$options['quality'] = 90;
				} elseif ($options['quality'] > 99) {
					$err_msg[] = 'JPG quality must be between 0 and 99.';
				}
			} else {
				// is png
				$img_src = imagecreatefrompng($inputfilepath);
				if ($options['autodetect_transparency']) {
					$has_transparency = self::png_has_transparency($inputfilepath);
					if ($has_transparency) {  //source: http://stackoverflow.com/a/313103/2404541
						imagealphablending($img_src, true);
					}
				}
				if (!is_numeric($options['quality'])) {
					$options['quality'] = -1;
				} elseif ($options['quality'] > 9) {
					$err_msg[] = 'PNG quality must be between 0 and 9.';
				}
			}

			if ($img_src == false) {
				$err_msg[] = 'Failed to read source image.';
			}
		}

		if (count($err_msg) == 0) {
			$img_dst = imagecreatetruecolor($new_width, $new_height);
			if ($has_transparency) {
				imagealphablending($img_dst, false);
				imagesavealpha($img_dst, true);
			}
			$result = imagecopyresampled($img_dst, $img_src, 0, 0, $options['src_x'], $options['src_y'], $new_width, $new_height, $curr_width, $curr_height);
			if ($result == false) {
				$err_msg[] = 'Failed to create destination image.';
			}
		}

		if (count($err_msg) == 0) {
			// Write text on image
			if (is_string($options['add_elements']) && $options['add_elements']) {
				$options['add_elements'] = array(
					array(
						'type' => 'text',
						'add_transp_bg' => true,
						'position' => 'bottom_left',
						'writetext' => $options['add_elements'],
						'fontsize' => 10,
						'angle' => 0,
					)
				);
			}
			if (!is_array($options['add_elements'])) {
				$options['add_elements'] = array();
			}
			foreach ($options['add_elements'] as $elem) {
				if ($elem['type'] == 'text') {
					//make transparent box as background
					if ($elem['add_transp_bg']) {
						$white_trans = imagecolorallocatealpha($img_dst, 0, 0, 0, 80);
						if ($elem['position'] == 'bottom_left') {
							imagefilledrectangle($img_dst, 0, $new_height-19, $new_width, $new_height, $white_trans);
						}
					}

					// write text
					$black = imagecolorallocate($img_dst, 255,255,255);
					if ($elem['position'] == 'bottom_left') {
						imagettftext($img_dst, (array_key_exists('fontsize', $elem) ? $elem['fontsize'] : 10), (array_key_exists('angle', $elem) ? $elem['angle'] : 0), 4, $new_height-4, $black, ($elem['fontfile'] ? $elem['fontfile'] : './arial.ttf'), $elem['writetext']);
					} else {
						$err_msg[] = 'Text position is not among implemented values.';
					}
				}
			}
		}

		// Write to file
		if (count($err_msg) == 0) {
			$dest_ext = strtolower(pathinfo($outputfilepath, PATHINFO_EXTENSION));

			if (in_array($dest_ext), array('jpg', 'jpeg')) {
				if (!imagejpeg($img_dst, $outputfilepath, $options['quality'])) {
					$err_msg[] = 'Failed to write JPG file.';
				}
			} elseif ($dest_ext == 'png') {
				if (!imagepng($img_dst, $outputfilepath, $options['quality'])) {
					$err_msg[] = 'Failed to write PNG file.';
				}
			} else {
				$err_msg[] = 'Output file extension '. $dest_ext .' is not supported.';
			}
			imagedestroy($img_src);
			imagedestroy($img_dst);
		}

		if (count($err_msg) > 0) {
			$result = array(
				'status' => 'error',
				'err_msg' => $err_msg,
				'result_msg' => array(),
			);
		} else {
			$result = array(
				'status' => 'ok',
				'err_msg' => array(),
				'result_msg' => $result_msg,
			);
		}
		return $result;
	}

	static public function convert_rgb_to_cmyk($image_path_rgb, $image_path_cmyk, $options = array() ) {
		/*
		DESCRIPTION:
		- convert RGB image to CMYK
		- requires Imagick extension
		INPUT:
		- $image_path_rgb : full path required
		- $image_path_cmyk : full path required
		OUTPUT:
		-
		*/
		$defaults = array(
			'rgb_icc_profile_path' => '',
			'cmyk_icc_profile_path' => '',
			'force_overwrite' => false,
			'jpg_compression_quality' => 80,
		);

		if (!extension_loaded('imagick')) {
			core::system_error('The extension Imagick is not installed. Required for converting RGB to CMYK.');
		}

		// TODO: check *_icc_profile_path has been set, check input file exists, check output file written

		$options = array_merge($defaults, (array) $options);

		if (!$options['force_overwrite'] && file_exists($image_path_cmyk)) {
			return;
		}

		$img = new Imagick($image_path_rgb);

		$img->setImageColorspace(Imagick::COLORSPACE_SRGB);
		$icc_rgb = file_get_contents($options['rgb_icc_profile_path']);
		$img->profileImage('icc', $icc_rgb);
		unset($icc_rgb);

		$icc_cmyk = file_get_contents($options['cmyk_icc_profile_path']);
		$img->profileImage('icc', $icc_cmyk);
		unset($icc_cmyk);
		$img->setImageColorspace(Imagick::COLORSPACE_CMYK);

		$img->stripImage();

		$img->setImageCompression(Imagick::COMPRESSION_JPEG);
		$img->setImageCompressionQuality($options['jpg_compression_quality']);
		$img->setImageFormat('jpg');   // (png doesn't support CMYK)

		$img->writeImage($image_path_cmyk);
		$img->clear();
		$img->destroy();
		$img = null;
	}

	static public function get_colorspace($image_path) {
		if (!extension_loaded('imagick')) {
			core::system_error('The extension Imagick is not installed. Required for getting colorspace of image.');
		}

		if (file_exists($image_path)) {
			$img = new Imagick($image_path);
		} else {
			core::system_error('File to get colorspace for does not exist.', ['Image path' => $image_path]);
		}

		$int = $img->getImageColorspace();

		// Source: http://php.net/manual/en/imagick.setimagecolorspace.php (comment by "jdstraughan dot com at gmail dot com")
		if ($int == 0) {
			return 'undefined';
		} elseif ($int == 1) {
			return 'RGB';
		} elseif ($int == 2) {
			return 'GRAY';
		} elseif ($int == 3) {
			return 'Transparent';
		} elseif ($int == 4) {
			return 'OHTA';
		} elseif ($int == 5) {
			return 'LAB';
		} elseif ($int == 6) {
			return 'XYZ';
		} elseif ($int == 7) {
			return 'YCbCr';
		} elseif ($int == 8) {
			return 'YCC';
		} elseif ($int == 9) {
			return 'YIQ';
		} elseif ($int == 10) {
			return 'YPbPr';
		} elseif ($int == 11) {
			return 'YUV';
		} elseif ($int == 12) {
			return 'CMYK';
		} elseif ($int == 13) {
			return 'sRGB';
		} elseif ($int == 14) {
			return 'HSB';
		} elseif ($int == 15) {
			return 'HSL';
		} elseif ($int == 16) {
			return 'HWB';
		} else {
			return $int;
		}
	}
}
