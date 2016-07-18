<?php
namespace winternet\jensenfw2;

use \Imagick;

class imaging {
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
			'jpg_compression_quality' => 96,
		);

		if (!extension_loaded('imagick')) {
			throw new Exception('The extension Imagick is not installed. Required for converting RGB to CMYK.');
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

	public function get_colorspace($image_path) {
		// TODO: change to return an understandable string instead, like 'rgb' / 'cmyk'
		$img = new Imagick($image_path_rgb);
		return $img->getImageColorspace();
	}
}
