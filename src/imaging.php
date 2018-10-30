<?php
namespace winternet\jensenfw2;

use \Imagick;

class imaging {
	/**
	 * Get information about an image
	 *
	 * @param string $input_filepath
	 *
	 * @return array : Associative array:
	 *   - `w` (number) : width in pixels
	 *   - `h` (number) : height in pixels
	 *   - `ratio` (number) : aspect ratio (width divided by height)
	 *   - `is_vertical` (boolean) : is image vertical/portrait or horizontal/landscape
	 */
	static public function image_info($input_filepath) {
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

	/**
	 * Detect if a PNG has alpha channel (transparency)
	 *
	 * Source: https://stackoverflow.com/a/43996262/2404541
	 *
	 * @param string $filepath
	 * @return boolean
	 */
	static public function png_has_transparency($filepath) {
		if (!file_exists($filepath)) {
			core::system_error('File to check transparency for does not exist.');
		}

		//32-bit pngs
		//4 checks for "greyscale + alpha" and "RGB + alpha"
		if ((ord(file_get_contents($filepath, false, null, 25, 1)) & 4) > 0) {  //check if 25th byte being "4" or "6" (according to https://stackoverflow.com/a/8750947/2404541)
			return true;
		}

		//8 bit pngs
		$fd = fopen($filepath, 'r');
		$continue = true;
		$plte = $trns = $idat = false;
		$line = '';
		while ($continue === true) {
			$continue = false;
			$line .= fread($fd, 1024);
			if ($plte === false) {
				$plte = (stripos($line, 'PLTE') !== false);
			}
			if ($trns === false) {
				$trns = (stripos($line, 'tRNS') !== false);
			}
			if ($idat === false) {
				$idat = (stripos($line, 'IDAT') !== false);
			}
			if ($idat === false and !($plte === true and $trns === true)) {
				$continue = true;
			}
		}
		fclose($fd);
		return ($plte === true and $trns === true);
	}

	/**
	 * Calculate the portion of a source image that must fit into a specific width and height
	 *
	 * If changing proportion of the image is necessary the exceeding image material is cropped away.
	 * The cropped image will be a centered part of the source.
	 *
	 * @return array : Associative array with 3 elements:
	 *   - `w` is the width we want to use of the source
	 *   - `h` is the height we want to use of the source
	 *   - `x` is the x coordinate on the source image that will be the upper left corner in the new image
	 *   - `y` is the y coordinate on the source image that will be the upper left corner in the new image
	 *   - `cuttingboundary` tells whether the width or the height has been cut in order to obtain the new image size and proportions
	 */
	static public function resize_and_crop_calc($input_width, $input_height, $target_width, $target_height) {
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

	/**
	 * Calculate the new size for a image to fit into a max width and height
	 *
	 * @return array : Associative array with 3 elements:
	 *   - `w` is the new width
	 *   - `h` is the new height
	 *   - `is_resized` is true/false depending on if new size was calculated at all (it is not calculated is source image is within the allowed size)
	 */
	static public function resize_calc($curr_width, $curr_height, $max_width, $max_height) {
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
			- 'compress_png' : set to true to compress PNG images (using pngquant)
			- 'calc_png_compression_savings' : set to true to calculate how much space we save by compressing PNG files
			- 'add_elements' : array with any number of elements to add (see code), or string with text to write in lower left corner of picture
				- ARRAY METHOD NOT FULLY IMPLEMENTED
			- 'quality' : set output quality. Has different meaning depending on image type:
				- jpg (0-99) : amount of compression resulting in different file sizes and image qualities. Default 90
				- png (0-9)  : amount of compression resulting in different file sizes and amount of time required (png is always lossless). Default 9
			- 'fix_gamma' (boolean) : fix gamma issue in GD2 when resizing (see https://web.archive.org/web/20120208120928/http://www.4p8.com/eric.brasseur/gamma.html#PHP)
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
		if ($src_ext == 'tiff') $src_ext = 'tif';
		if (!in_array($src_ext, array('jpg', 'png', 'tif'))) {
			$err_msg[] = 'Input file extension '. $src_ext .' is not supported.';
		}
		if ($src_ext == 'tif') {
			if (!extension_loaded('imagick')) {
				core::system_error('The extension Imagick is not installed. Required for using resize_save() with TIFF files.');
			}
			if ($options['src_x'] || $options['src_y']) {
				core::system_error('Options src_x and src_y for TIFF images is not yet supported by resize_save().');
			}
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
			if ($src_ext == 'tif') {
				$img_src = new \Imagick($inputfilepath);
			} elseif ($src_ext == 'jpg') {
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
			if ($src_ext == 'tif') {
				$result = $img_src->resizeImage($new_width, $new_height, \Imagick::FILTER_GAUSSIAN, 1);
			} else {
				$img_dst = imagecreatetruecolor($new_width, $new_height);
				if ($has_transparency) {
					imagealphablending($img_dst, false);
					imagesavealpha($img_dst, true);
				}
				$result = imagecopyresampled($img_dst, $img_src, 0, 0, $options['src_x'], $options['src_y'], $new_width, $new_height, $curr_width, $curr_height);
			}
			if ($result == false) {
				$err_msg[] = 'Failed to create destination image.';
			}
		}

		if (count($err_msg) == 0 && $src_ext != 'tif') {
			if ($options['fix_gamma']) {
				imagegammacorrect($img_dst, 2.2, 1.0);  //see https://web.archive.org/web/20120208120928/http://www.4p8.com/eric.brasseur/gamma.html#PHP
			}
		}

		if (count($err_msg) == 0) {
			// Write text on image
			if (is_string($options['add_elements']) && $options['add_elements']) {
				if ($src_ext == 'tif') {
					core::system_error('Writing text on the TIFF images is not yet supported by resize_save().');
				} else {
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

			if ($src_ext == 'tif') {
				$img_src->writeImage($outputfilepath);
				$img_src->clear();
				$img_src->destroy();
			} else {
				if (in_array($dest_ext, array('jpg', 'jpeg'))) {
					if (!imagejpeg($img_dst, $outputfilepath, $options['quality'])) {
						$err_msg[] = 'Failed to write JPG file.';
					}
				} elseif ($dest_ext == 'png') {
					if (!imagepng($img_dst, $outputfilepath, $options['quality'])) {
						$err_msg[] = 'Failed to write PNG file.';
					} else {
						if ($options['compress_png']) {
							if ($options['calc_png_compression_savings']) {
								$size_before = filesize($outputfilepath);
							}
							self::compress_png($outputfilepath, ['save_to_file' => $outputfilepath, 'allow_overwrite' => true, 'ignore_exitcodes' => [99 /*ignore if compression fails due to minimum quality not being met*/]]);
							if ($options['calc_png_compression_savings']) {
								clearstatcache();
								$size_after = filesize($outputfilepath);
							}
						}
					}
				} else {
					$err_msg[] = 'Output file extension '. $dest_ext .' is not supported.';
				}
				imagedestroy($img_src);
				imagedestroy($img_dst);
			}
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
		if ($dest_ext == 'png' && $options['calc_png_compression_savings'] && $size_before) {
			$result['png_compression_savings'] = $size_before - $size_after;
			$result['png_compression_savings_perc'] = $result['png_compression_savings'] / $size_before * 100;
		}
		return $result;
	}

	/**
	 * Convert RGB image to CMYK
	 *
	 * Requires Imagick extension
	 *
	 * @param string $image_path_rgb : Full path required (if $use_image_own_profile = true: is only used if image doesn't contain it's own profile)
	 * @param string $image_path_cmyk : Full path required
	 */
	static public function convert_rgb_to_cmyk($image_path_rgb, $image_path_cmyk, $options = array() ) {
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

		$use_image_own_profile = true;

		if ($use_image_own_profile) {
			$profiles = $img->getImageProfiles('*', false); //possibly contains: icc, iptc, exif, xmp, 8bim
		}

		$img->setImageColorspace(Imagick::COLORSPACE_SRGB);
		if ($use_image_own_profile && in_array('icc', $profiles)) {  //use image's own ICC profile if it has one
			$img->profileImage('icc', $img->getImageProfile('icc'));
		} else {
			// TODO: use sRGB as default instead - Lars says almost all photos use this instead of the Adobe RGB (Allowed to use? c:\Program Files (x86)\Common Files\Adobe\Color\Profiles\Recommended\sRGB Color Space Profile.icm)
			// 											Otherwise maybe use the new v4 found online?
			$icc_rgb = file_get_contents($options['rgb_icc_profile_path']);
			$img->profileImage('icc', $icc_rgb);
		}
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

			/*
			On Allan's developer machine (Windows 10) the Imagick module crashes when file name contains special letters like æøå,
			even when I downloaded the latest php_imagick.dll from https://pecl.php.net/package/imagick/3.4.3/windows (php_imagick-3.4.3-7.0-ts-vc14-x64.zip)
			So let Imagick do the work on a temporary file instead.
			*/
			if (PHP_OS == 'WINNT') {
				$path = pathinfo($image_path, PATHINFO_DIRNAME);
				$ext = pathinfo($image_path, PATHINFO_EXTENSION);
				$temp_file = $path .'/'. rand(10000000, 99999999) . microtime(true) .'.'. $ext;  //Imagick crashes if full path is not given
				copy($image_path, $temp_file);
				$image_path = $temp_file;
			}

			$img = new Imagick($image_path);

			if (PHP_OS == 'WINNT') {
				unlink($temp_file);
			}

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

	/**
	 * Convert RGB color values to CMYK and vice versa
	 *
	 * Requires LittleCMS (http://www.littlecms.com/)
	 *
	 * Install LittleCMS on Debian:
	 * ```
	 * apt install gcc build-essential
	 * apt install liblcms2-2
	 * git clone https://github.com/mm2/Little-CMS.git
	 * cd Little-CMS
	 * ./configure
	 * make
	 * make check
	 * make install
	 * ```
	 *
	 * @param string $from_colorspace : `rgb` or `cmyk`
	 * @param string $to_colorspace : `rgb` or `cmyk`
	 * @param array $color_value : array with "from" colorspace values. RGB sample: `['r' => 10, 'g' => 248, 'b' => 0]`. CMYK sample: `['c' => 100, 'm' => 50, 'y' => 0, 'k' => 0]`
	 * @param string $from_icc : full path to ICC profile of "from" colorspace
	 * @param string $to_icc : full path to ICC profile of "to" colorspace
	 * @return array : Formatted as $color_value
	 */
	static public function convert_colorspace($from_colorspace, $to_colorspace, $color_value, $from_icc, $to_icc) {
		// Sample command lines:
		// echo -e "100\n0\n0\n0\n" | transicc -i ./icc_profiles/ISOcoated_v2_300_eci.icc -o ./icc_profiles/AdobeRGB1998.icc -n
		// echo -e "73\n34\n0\n0\n" | transicc -i ./icc_profiles/ISOcoated_v2_300_eci.icc -o ./icc_profiles/AdobeRGB1998.icc -n
		// echo -e "208\n68\n117\n" | transicc -i ./icc_profiles/AdobeRGB1998.icc -o ./icc_profiles/ISOcoated_v2_300_eci.icc -n

		if ($from_colorspace == 'rgb') {
			if (!is_numeric($color_value['r']) || !is_numeric($color_value['g']) || !is_numeric($color_value['g'])) {
				core::system_error('At least one RGB input value is not numeric when converting colorspace.', ['Values' => $color_value]);
			}
			$color_string = (int) $color_value['r'] .'\n'. (int) $color_value['g'] .'\n'. (int) $color_value['b'] .'\n';
		} else {
			if (!is_numeric($color_value['c']) || !is_numeric($color_value['m']) || !is_numeric($color_value['y']) || !is_numeric($color_value['k'])) {
				core::system_error('At least one CMYK input value is not numeric when converting colorspace.', ['Values' => $color_value]);
			}
			$color_string = (int) $color_value['c'] .'\n'. (int) $color_value['m'] .'\n'. (int) $color_value['y'] .'\n'. (int) $color_value['k'] .'\n';
		}

		$output = [];
		exec('echo -e "'. $color_string .'" | transicc -i '. escapeshellarg($from_icc) .' -o '. escapeshellarg($to_icc) .' -n 2>/dev/null', $output);
		if ($output) {
			$output = explode(' ', $output[0]);
		} else {
			core::system_error('File to check transparency for does not exist. Maybe LittleCMS is not installed.');
		}

		if ($to_colorspace == 'rgb') {
			if (!is_numeric($output[0]) || !is_numeric($output[1]) || !is_numeric($output[2])) {
				core::system_error('At least one RGB output value is not numeric when converting colorspace. Maybe LittleCMS is not installed.', ['Values' => $output]);
			}
			return ['r' => $output[0], 'g' => $output[1], 'b' => $output[2]];
		} else {
			if (!is_numeric($output[0]) || !is_numeric($output[1]) || !is_numeric($output[2]) || !is_numeric($output[3])) {
				core::system_error('At least one CMYK output value is not numeric when converting colorspace. Maybe LittleCMS is not installed.', ['Values' => $output]);
			}
			return ['c' => $output[0], 'm' => $output[1], 'y' => $output[2], 'k' => $output[3]];
		}
	}

	/**
	 * Optimizes PNG file with pngquant 1.8 or later
	 *
	 * Reduces file size of 24-bit/32-bit PNG images.
	 * You need to install pngquant 1.8 on the server (ancient version 1.0 won't work).
	 * There's package for Debian/Ubuntu and RPM for other distributions on http://pngquant.org
	 *
	 * Source: https://pngquant.org/php.html
	 *
	 * @param string $input_file_png : Path to a PNG file
	 * @param array $options : Associative array with keys according to code (see $defaults)
	 *
	 * @return mixed : Depending on options: either nothing when it writes output to file, or a string with content of PNG file after conversion
	 */
	static public function compress_png($input_file_png, $options = []) {
		$defaults = [
			'min_quality' => 60,  //guarantee that quality won't be worse than that.
			'max_quality' => 90,  //conversion quality, useful values from 60 to 100 (smaller number = smaller file)
			'save_to_file' => false,  //path to file where the new PNG will be saved to (if not set the function will return the file content)
			'allow_overwrite' => false,  //allow output file path to be the same as the input file path?
			'ignore_exitcodes' => [],  //array of exit codes for which we don't want to raise an error (or make first entry '*' to ignore all)
		];
		$options = array_merge($defaults, $options);

		if (!file_exists($input_file_png)) {
			core::system_error('PNG file to compress does not exist.', ['File' => $input_file_png]);
		}

		// '-' makes it use stdout, required to save to $compressed_png_content variable
		// '<' makes it read from the given file path
		// escapeshellarg() makes this safe to use with any path
		if ($options['save_to_file']) {
			$cmd = 'pngquant --quality='. $options['min_quality'] .'-'. $options['max_quality'] .' --output='. escapeshellarg($options['save_to_file']) . ($options['allow_overwrite'] ? ' --force' : '') .' '. escapeshellarg($input_file_png) .' 2>&1';  //redirect stderr to stdout in order to see error messages
			$output = array();
			$exitcode = null;
			exec($cmd, $output, $exitcode);

			if ($exitcode != 0 && !in_array($exitcode, $options['ignore_exitcodes']) && $options['ignore_exitcodes'][0] !== '*') {
				core::system_error('Conversion to compressed PNG failed. Is pngquant 1.8+ installed?', ['File' => $input_file_png, 'Exit code' => $exitcode, 'Output' => $output]);
			}
		} else {
			$cmd = 'pngquant --quality='. $options['min_quality'] .'-'. $options['max_quality'] .' - < '. escapeshellarg($input_file_png);
			$compressed_png_content = shell_exec($cmd);

			if (!$compressed_png_content) {
				core::system_error('Conversion to compressed PNG failed. Is pngquant 1.8+ installed?', ['File' => $input_file_png]);
			}

			return $compressed_png_content;
		}
	}
}
