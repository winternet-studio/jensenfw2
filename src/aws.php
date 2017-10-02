<?php
namespace winternet\jensenfw2;

class aws {
	/**
	 * Calculate Amazon AWS ETag used on the S3 service
	 *
	 * @param string $filename : Path to file to check
	 * @param integer $chunksize : Chunk size in Megabytes
	 * @param string $expected : Verify calculated etag against this specified etag and return true or false instead
	 *	- if you make chunksize negative (eg. -8 instead of 8) the function will guess the chunksize by checking all possible sizes given the number of parts mentioned in $expected
	 * @return string|boolean : ETag, or boolean if $expected is set
	 */
	public static function calculate_etag($filename, $chunksize, $expected = false) {
		if ($chunksize < 0) {
			$do_guess = true;
			$chunksize = 0 - $chunksize;
		} else {
			$do_guess = false;
		}

		$chunkbytes = $chunksize*1024*1024;
		$filesize = filesize($filename);
		if ($filesize < $chunkbytes && (!$expected || !preg_match("/^\\w{32}-\\w+$/", $expected))) {
			$return = md5_file($filename);
			if ($expected) {
				$expected = strtolower($expected);
				return ($expected === $return ? true : false);
			} else {
				return $return;
			}
		} else {
			$md5s = array();
			$handle = fopen($filename, 'rb');
			if ($handle === false) {
				return false;
			}
			while (!feof($handle)) {
				$buffer = fread($handle, $chunkbytes);
				$md5s[] = md5($buffer);
				unset($buffer);
			}
			fclose($handle);

			$concat = '';
			foreach ($md5s as $indx => $md5) {
				$concat .= hex2bin($md5);
			}
			$return = md5($concat) .'-'. count($md5s);
			if ($expected) {
				$expected = strtolower($expected);
				$matches = ($expected === $return ? true : false);
				if ($matches || $do_guess == false || strlen($expected) == 32) {
					return $matches;
				} else {
					// Guess the chunk size
					preg_match("/-(\\d+)$/", $expected, $match);
					$parts = $match[1];
					$min_chunk = ceil($filesize / $parts /1024/1024);
					$max_chunk =  floor($filesize / ($parts-1) /1024/1024);
					$found_match = false;
					for ($i = $min_chunk; $i <= $max_chunk; $i++) {
						if (self::calculate_etag($filename, $i) === $expected) {
							$found_match = true;
							break;
						}
					}
					return $found_match;
				}
			} else {
				return $return;
			}
		}
	}
}
