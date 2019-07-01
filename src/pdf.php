<?php
namespace winternet\jensenfw2;

class pdf {
	/**
	 * Convert a PDF to an image
	 *
	 * xpdf seems to perform just as well as Ghostscript and maybe even a bit faster.
	 *
	 * Direct Ghostscript is faster than using ImageMagick: http://bertanguven.com/faster-conversions-from-pdf-to-pngjpeg-imagemagick-vs-ghostscript
	 *
	 * @param string $pdf_path : Full path and filename of the PDF to convert
	 * @param string $image_path : Full path and filename of the image to write to
	 * 				xpdf: This is the root path eg. `/myfolder/file` which will result in `/myfolder/file-000001.png`, `/myfolder/file-000002.png`, etc depending on how many pages there are
	 *                    See also https://www.xpdfreader.com/pdftopng-man.html
	 * 				Ghostscript: If PDF has multiple pages insert %d where you want to insert page number into the destination filename
	 * @param array $options : See below (in the code)
	 * @return void
	 */
	static public function convert_pdf_to_image($pdf_path, $image_path, $options = array() ) {
		$defaults = array(
			'engine' => 'xpdf',  //'xpdf' or 'ghostscript'
			'output_format' => 'jpg',  //'jpg' or 'png' (xpdf actually only supports png but we use ImageMagick as a fill-in solution)
			'resolution' => 150,
			'jpg_quality' => 90,
			'xpdf_path' => '/usr/local/bin/pdftopng',  //full path to pdftopng
			'ghostscript_path' => 'gs',
			'transparency' => true,  //should transparency be maintained?
			'use_box' => null,  //crop image to a specific box ('ArtBox', 'TrimBox', 'CropBox')
			'TextAlphaBits' => 4,  //set these two to 1 to disable anti-aliasing (Ghostscript only)
			'GraphicsAlphaBits' => 4,  // (Ghostscript only)
		);
		$options = array_merge($defaults, (array) $options);

		if ($options['engine'] === 'xpdf') {
			$cmd = $options['xpdf_path'];
			if ($options['transparency']) {
				$cmd .= ' -alpha';
			}
			$cmd .= ' -r '. $options['resolution'];
			$cmd .= ' '. escapeshellarg($pdf_path) .' '. escapeshellarg($image_path) .' 2>&1';
			exec($cmd, $coutput, $returncode);  //exit codes: https://www.xpdfreader.com/pdftopng-man.html

			if (!empty($coutput)) {
				throw new \Exception('xpdf command for converting PDF to image outputted unexpected data ((INTERNAL:'. json_encode($coutput) .'))');
			} elseif ($returncode != 0) {
				throw new \Exception('xpdf command for converting PDF to image returned '. $returncode);
			}

		} elseif ($options['engine'] === 'ghostscript') {
			if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
				$defaults['ghostscript_path'] = 'c:/programs/ghostscript/bin/gswin64c.exe';
			}

			if ($options['output_format'] == 'jpg' || $options['output_format'] == 'jpeg') {
				$sdevice = 'jpeg';
			} elseif ($options['output_format'] == 'png') {
				if ($options['transparency']) {
					$sdevice = 'pngalpha';
				} else {
					$sdevice = 'png16m';
				}
			} else {
				core::system_error('Invalid output format for converting PDF to image.', ['Format' => $options['output_format']]);
			}

			$additional_options = '';
			if ($options['use_box'] && in_array($options['use_box'], ['ArtBox', 'TrimBox', 'CropBox'])) {
				$additional_options .= '-dUse'. $options['use_box'] .' ';  //=> -dUseArtbox, -dUseTrimBox, -dUseCropBox
			}

			$image_path_safe = str_replace('%d', '-PERCENTAGE-d', $image_path);  //on Windows escapeshellarg() replaces percent sign with space, so temporarily remove it
			$image_path_safe = escapeshellarg($image_path_safe);
			$image_path_safe = str_replace('-PERCENTAGE-d', '%d', $image_path_safe);
			$cmd = $options['ghostscript_path'] .' -q -dQUIET -dSAFER -dBATCH -dNOPAUSE -dNOPROMPT '. $additional_options .' -dMaxBitmap=500000000 -dAlignToPixels=0 -dGridFitTT=2 -sDEVICE='. $sdevice .' -dTextAlphaBits='. $options['TextAlphaBits'] .' -dGraphicsAlphaBits='. $options['GraphicsAlphaBits'] .' -r'. $options['resolution'] . ($options['output_format'] == 'jpg' ? ' -dJPEGQ='. $options['jpg_quality'] : '') .' -sOutputFile='. $image_path_safe .' '. escapeshellarg($pdf_path) .' 2>&1';
			exec($cmd, $coutput, $returncode);

			if (!empty($coutput)) {
				throw new \Exception('Ghostscript command for converting PDF to image outputted unexpected data ((INTERNAL:'. json_encode($coutput) .'))');
			} elseif ($returncode != 0) {
				throw new \Exception('Ghostscript command for converting PDF to image returned '. $returncode);
			}
		}

		if ($options['engine'] === 'xpdf' && $options['output_format'] == 'jpg') {
			// xpdf doesn't support output in jpg so in that case we do this fill-in using ImageMagick to support that
			clearstatcache();
			for ($i = 1; $i < 1000; $i++) {
				$filename_src  = sprintf($image_path .'-%1$06d.png', $i);
				$filename_dest = sprintf($image_path .'-%1$06d.jpg', $i);
				if (file_exists($filename_src)) {
					$coutput = array(); $returncode = 0;
					$cmd = 'convert '. escapeshellarg($filename_src) .' -alpha remove -quality '. $options['jpg_quality'] .' '. escapeshellarg($filename_dest) .' 2>&1';  //about alpha remove: You should use -alpha remove rather than mis-use -flatten. It has the same effect, but the alpha on is faster and more memory efficient. It also works with "mogrify", where -flatten will not. Source: https://www.imagemagick.org/discourse-server/viewtopic.php?t=24048
					exec($cmd, $coutput, $returncode);

					if (!empty($coutput)) {
						throw new \Exception('ImageMagick command for converting PNG to JPG outputted unexpected data ((INTERNAL:'. json_encode($coutput) .'))');
					} elseif ($returncode != 0) {
						throw new \Exception('ImageMagick command for converting PNG to JPG returned '. $returncode);
					}
				} else {
					break;
				}
			}
		}

		// WORKS BUT NOT ANTI-ALISED! AND FAIRLY SMALL SIZE IMAGE - AND PROBABLY SLOWER AND LESS EFFICIENT THAN COMMAND LINE
		// $imagick = new \Imagick();
		// $imagick->readImage($image_path);  //full path is required
		// $imagick->writeImage($image_path);  //full path is required

		// OTHER IMAGEMAGICK METHOD BUT SAME ISSUE
		// echo exec("convert.exe ". __DIR__ .'/AutomatedDesignSample.pdf' ." ". __DIR__ .'/AutomatedDesignSample.jpg' ." 2>&1"); // Does not work and gives below error
	}
}
