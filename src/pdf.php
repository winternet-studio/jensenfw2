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
			'single_page' => false,  //can be set to true when xpdf is used in order to automatically remove the "-000001.png" that it automatically adds to the file name
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
			$coutput = array();
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
			$coutput = array();
			exec($cmd, $coutput, $returncode);

			if (!empty($coutput)) {
				throw new \Exception('Ghostscript command for converting PDF to image outputted unexpected data ((INTERNAL:'. json_encode($coutput) .'))');
			} elseif ($returncode != 0) {
				throw new \Exception('Ghostscript command for converting PDF to image returned '. $returncode);
			}
		}

		if ($options['engine'] === 'xpdf' && ($options['output_format'] === 'jpg' || $options['use_box'] === 'ArtBox')) {

			// xpdf doesn't support only extracting the ArtBox so do this fill-in by using pdfinfo to get the details and crop the image
			if ($options['use_box'] === 'ArtBox') {
				// Get the info about boxes as well as page size
				if (!$options['xpdf_info_path']) {  //if the unofficial option has not been passed then just derive it from the `pdftopng` path
					$options['xpdf_info_path'] = str_replace('pdftopng', 'pdfinfo', $options['xpdf_path']);
				}
				$cmd = $options['xpdf_info_path'] .' -box '. escapeshellarg($pdf_path) .' 2>&1';
				$coutput = array();
				exec($cmd, $coutput, $returncode);

				if (empty($coutput)) {
					throw new \Exception('xpdf pdfinfo command for getting PDF boxes outputted unexpected data ((INTERNAL:'. json_encode($coutput) .'))');
				} elseif ($returncode != 0) {
					throw new \Exception('xpdf pdfinfo command for getting PDF boxes returned '. $returncode);
				}

				$pagesize_info = $artbox_info = null;
				foreach ($coutput as $cline) {
					if (strtolower(substr($cline, 0, 10)) == 'page size:') {  //pdfinfo documentation: https://www.xpdfreader.com/pdfinfo-man.html
						$pagesize_info = $cline;
					} elseif (strtolower(substr($cline, 0, 7)) == 'artbox:') {  //pdfinfo documentation: https://www.xpdfreader.com/pdfinfo-man.html
						$artbox_info = $cline;
					}
					if ($pagesize_info && $artbox_info) break;
				}
				if (!$pagesize_info) throw new \Exception('xpdf pdfinfo command for getting PDF boxes failed to find page size line.');
				if (!$artbox_info) throw new \Exception('xpdf pdfinfo command for getting PDF boxes failed to find ArtBox line.');

				if (!preg_match("/([\\d\\.]+)\\s*x\\s*([\\d\\.]+)\\s*pts/", $pagesize_info, $pagesize_match)) {
					throw new \Exception('xpdf pdfinfo command for getting PDF boxes failed to parse page size details.');
				}
				if (!preg_match("/([\\d\\.]+)\\s+([\\d\\.]+)\\s+([\\d\\.]+)\\s+([\\d\\.]+)/", $artbox_info, $artbox_match)) {// NOTE: the format is "llx lly urx ury" according to https://forum.xpdfreader.com/viewtopic.php?f=3&t=41207
					throw new \Exception('xpdf pdfinfo command for getting PDF boxes failed to parse ArtBox details.');
				}
				$pagesize_info = array(
					'width' => round($pagesize_match[1], 2),
					'height' => round($pagesize_match[2], 2),
				);
				$artbox_info = array(
					'll_x' => round($artbox_match[1], 2),
					'll_y' => round($artbox_match[2], 2),
					'ur_x' => round($artbox_match[3], 2),
					'ur_y' => round($artbox_match[4], 2),
				);

				// Check if ArtBox is different than page size and if so calculate the x and y offset and width and height cropping parameters on the image that will match the ArtBox
				if ($artbox_info['ll_x'] != 0 || $artbox_info['ll_y'] != 0 || $artbox_info['ur_x'] != $pagesize_info['width'] || $artbox_info['ur_y'] != $pagesize_info['height']) {
					$crop_to_artbox = true;

					$multiply_ratio = $options['resolution'] / 72;   //from points to pixels: 841.89 pts (A4 height) / 72 points per inch * 150 dpi = 1754 px

					$px_ll_x = round($artbox_info['ll_x'] * $multiply_ratio);
					$px_ll_y = round($artbox_info['ll_y'] * $multiply_ratio);
					$px_width = round(($artbox_info['ur_x'] - $artbox_info['ll_x']) * $multiply_ratio);
					$px_height = round(($artbox_info['ur_y'] - $artbox_info['ll_y']) * $multiply_ratio);
				} else {
					$crop_to_artbox = false;
				}
			}


			clearstatcache();
			for ($i = 1; $i < 1000; $i++) {
				$filename_src  = sprintf($image_path .'-%1$06d.png', $i);

				// Exit loop when no more files exist
				if (!file_exists($filename_src)) {
					break;
				}

				if ($options['output_format'] === 'jpg') {
					$filename_dest = sprintf($image_path .'-%1$06d.jpg', $i);
				} else {
					$filename_dest = $filename_src;
				}

				$convert_options = array();
				// Crop the image to the ArtBox
				if ($crop_to_artbox) {
					$convert_options[] = '-gravity SouthWest -crop '. $px_width .'x'. $px_height .'+'. $px_ll_x .'+'. $px_ll_y;
				}

				// xpdf doesn't support output in jpg so in that case we do this fill-in using ImageMagick to support that
				if ($options['output_format'] === 'jpg') {
					$convert_options[] = '-alpha remove -quality '. $options['jpg_quality'];  //about alpha remove: You should use -alpha remove rather than mis-use -flatten. It has the same effect, but the alpha on is faster and more memory efficient. It also works with "mogrify", where -flatten will not. Source: https://www.imagemagick.org/discourse-server/viewtopic.php?t=24048
				}

				if (!empty($convert_options)) {
					$cmd = 'convert '. escapeshellarg($filename_src) .' '. implode(' ', $convert_options) .' '. escapeshellarg($filename_dest) .' 2>&1';
					$coutput = array();
					exec($cmd, $coutput, $returncode);

					if (!empty($coutput)) {
						throw new \Exception('ImageMagick command for cropping image to ArtBox and/or converting to JPG outputted unexpected data ((INTERNAL:'. json_encode($coutput) .'))');
					} elseif ($returncode != 0) {
						throw new \Exception('ImageMagick command for cropping image to ArtBox and/or converting to JPG returned '. $returncode);
					}
				}
			}
		}

		// WORKS BUT NOT ANTI-ALISED! AND FAIRLY SMALL SIZE IMAGE - AND PROBABLY SLOWER AND LESS EFFICIENT THAN COMMAND LINE
		// $imagick = new \Imagick();
		// $imagick->readImage($image_path);  //full path is required
		// $imagick->writeImage($image_path);  //full path is required

		// OTHER IMAGEMAGICK METHOD BUT SAME ISSUE
		// echo exec("convert.exe ". __DIR__ .'/AutomatedDesignSample.pdf' ." ". __DIR__ .'/AutomatedDesignSample.jpg' ." 2>&1"); // Does not work and gives below error

		// Remove the "-000001.png" that xpdf automatically adds to the file name when PDF is only a single page (Ghostscript doesn't automatically add the number, it only uses the %d parameter)
		if ($options['engine'] === 'xpdf' && $options['single_page']) {
			if ($options['output_format'] === 'jpg') {
				$src = sprintf($image_path .'-%1$06d.jpg', 1);
				$dst = $image_path;
			} else {
				$src = sprintf($image_path .'-%1$06d.png', 1);
				$dst = $image_path;
			}
			rename($src, $dst);
		}
	}
}
