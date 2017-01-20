<?php
namespace winternet\jensenfw2;

class pdf {
	static public function convert_pdf_to_image($pdf_path, $image_path, $options = array() ) {
		/*
		DESCRIPTION:
		- convert a PDF to an image
		INPUT:
		- $pdf_path : full path and filename of the PDF to convert
		- $image_path : full path and filename of the image to write to
						If PDF has multiple pages insert %d where you want to insert page number into the destination filename
		- $options : see below (in the code)
		OUTPUT:
		- nothing
		*/
		$defaults = array(
			'output_format' => 'jpg',  //'jpg' or 'png'
			'resolution' => 150,
			'jpg_quality' => 90,
			'suffix_pattern' => '-%d',  //suffix added to file name for each page of the converted PDF (%d indicates page number)
			'ghostscript_path' => 'gs',
		);
		if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
			$defaults['ghostscript_path'] = 'c:/programs/ghostscript/bin/gswin64c.exe';
		}

		$options = array_merge($defaults, (array) $options);

		if ($options['output_format'] == 'jpg' || $options['output_format'] == 'jpeg') {
			$sdevice = 'jpeg';
		} elseif ($options['output_format'] == 'png') {
			$sdevice = 'pngalpha';
		} else {
			core::system_error('Invalid output format for converting PDF to image.', ['Format' => $options['output_format']]);
		}

		$cmd = $options['ghostscript_path'] .' -q -dQUIET -dSAFER -dBATCH -dNOPAUSE -dNOPROMPT -dMaxBitmap=500000000 -dAlignToPixels=0 -dGridFitTT=2 -sDEVICE='. $sdevice .' -dTextAlphaBits=4 -dGraphicsAlphaBits=4 -r'. $options['resolution'] . ($options['output_format'] == 'jpg' ? ' -dJPEGQ='. $options['jpg_quality'] : '') .' -sOutputFile='. $image_path .' '. $pdf_path .' 2>&1';
		exec($cmd, $coutput, $returncode);

		if (!empty($coutput)) {
			throw new \Exception("Error in ". __FILE__ .":". __LINE__ ." -- GhostScript command for converting PDF to image outputted unexpected data ((INTERNAL:". json_encode($coutput) ."))");
		} elseif ($returncode != 0) {
			throw new \Exception("Error in ". __FILE__ .":". __LINE__ ." -- GhostScript command for converting PDF to image returned ". $returncode);
		}

		// WORKS BUT NOT ANTI-ALISED! AND FAIRLY SMALL SIZE IMAGE
		// $imagick = new \Imagick();
		// $imagick->readImage($image_path);  //full path is required
		// $imagick->writeImage($image_path);  //full path is required

		// OTHER IMAGEMAGICK METHOD BUT SAME ISSUE
		// echo exec("convert.exe ". __DIR__ .'/AutomatedDesignSample.pdf' ." ". __DIR__ .'/AutomatedDesignSample.jpg' ." 2>&1"); // Does not work and gives below error
	}
}
