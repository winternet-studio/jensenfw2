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
			throw new core\system_error('The extension Imagick is not installed. Required for converting RGB to CMYK.');
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
		$img = new Imagick($image_path);
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
