<?php
/*
This file contains functions related to file system handling and manipulation
*/
namespace winternet\jensenfw2;

class filesystem {
	/**
	 * Get a list of all folders in a given folder
	 *
	 * @param string $folder : Relative or absolute reference to folder
	 * @param integer $sorting_order : See PHP documentation for scandir()
	 * @return array|false : Array with file names, or false if folder does not exist
	 */
	public static function get_folders($folder, $sorting_order = 0) {
		if (file_exists($folder)) {
			$folders = scandir($folder, $sorting_order);
			foreach ($folders as $key => $file) {
			    if (!is_dir($folder.'/'.$file) || $file == '.' || $file == '..') {
					unset($folders[$key]);
					$modified = true;
				}
			}
			if ($modified) $folders = array_values($folders);
			return $folders;
		} else {
			return false;
		}
	}

	/**
	 * Get all files in a folder
	 *
	 * @param string $folder : Relative or absolute reference to folder
	 * @param integer $sorting_order : See PHP documentation for scandir()
	 * @return array|false : Array with file names, or false if folder does not exist
	 */
	public static function get_files($folder, $sorting_order = 0) {
		if (file_exists($folder)) {
			$files = scandir($folder, $sorting_order);
			foreach ($files as $key => $file) {
			    if (!is_file($folder.'/'.$file) || $file == '.' || $file == '..') {
					unset($files[$key]);
					$modified = true;
				}
			}
			if ($modified) $files = array_values($files);
			return $files;
		} else {
			return false;
		}
	}

	/**
	 * Add contents to file, but prepending instead of appending it
	 *
	 * @param string $file
	 * @param string $contents : New content to add to the file
	 * @param integer $trim_length (opt.) : After prepending contents truncate the file to this amount of bytes
	 * @return void : Only modifies the file
	 */
	public static function file_put_contents_prepend($file, $contents, $trim_length = false) {
		$contents = (string) $contents;
		$len = strlen($contents);
		if ($len == 0) {
			//nothing to prepend, do nothing
		} elseif (!file_exists($file)) {
			file_put_contents($file, $contents);
		} else {
			$handle = fopen($file, 'r+');
			$final_len = filesize($file) + $len;
			$cache_old = fread($handle, $len);
			rewind($handle);
			$i = 1;
			while (ftell($handle) < $final_len) {
				fwrite($handle, $contents);
				$contents = $cache_old;
				$cache_old = fread($handle, $len);
				fseek($handle, $i * $len);
				$i++;
			}
			if (is_numeric($trim_length) && $trim_length < $final_len) {
				ftruncate($handle, $trim_length);
			}
			fclose($handle);
		}
	}

	/**
	 * Get all kinds of information about a file
	 *
	 * @param string $file : Can be just a file name, but if you want path data output too you must of course include that
	 * @return array
	 */
	public static function file_info($file) {
		$pathinfo = pathinfo($file);
		$nameonly = substr($pathinfo['basename'], 0, strlen($pathinfo['basename']) - strlen($pathinfo['extension']) - 1);  //remove extension and dot
		$file = str_replace("\\", "/", $file);  //unify input data
		if (strpos($file, '/') === false) {
			$fileinfo['full_filepath'] = false;
			$fileinfo['path'] = false;
		} else {
			$fileinfo['full_filepath'] = $file;
			$fileinfo['path'] = $pathinfo['dirname'] .'/';
		}
		$fileinfo['name'] = $pathinfo['basename'];
		$fileinfo['nameonly'] = $nameonly;
		$fileinfo['extension'] = $pathinfo['extension'];
		$fileinfo['size'] = filesize($file);
		$fileinfo['size_kb'] = round($fileinfo['size']    / 1024);
		$fileinfo['size_mb'] = round($fileinfo['size_kb'] / 1024);
		$fileinfo['date_modified_unix'] = filemtime($file);
		$fileinfo['date_modified_mysql'] = date('Y-m-d H:i:s', $fileinfo['date_modified_unix']);
		return $fileinfo;
	}

	/**
	 * Renames a file and/or move a file
	 *
	 * @param string $old_filepath : Current file name including path to file
	 * @param string $new_file : New file name
	 *   - if only rename : you can leave out the path
	 *   - if rename and move : path must of course be included
	 * @param string  $err_msg_var : If present any error message (associative array with `code` and `desc`) will be written to this variable
	 * @return boolean : True on success, false on failed
	 */
	public static function rename_move_file($old_filepath, $new_file, &$err_msg_var = null, $allow_overwrite = false) {
		// Unify input data
		$old_filepath = str_replace('\\', '/', $old_filepath);
		$new_file     = str_replace('\\', '/', $new_file);

		if (file_exists($old_filepath)) {
			$fileinfo = pathinfo($old_filepath);
			if (strpos($new_file, '/') === false) {
				//destination folder was NOT specified, use source folder
				$new_filepath = $fileinfo['dirname'] .'/'. $new_file;
			} else {
				//destination folder was specified
				$new_filepath = $new_file;
			}

			// Check existence of destination folder
			$new_filepath_info = pathinfo($new_filepath);
			if (!is_dir($new_filepath_info['dirname'])) {
				$err_msg_var = array('code' => 'dest_folder_nonexist', 'desc' => 'Destination folder to move file to does not exist.');
				return false;
			}
			if (file_exists($new_filepath)) {
				//Target filename already exists
				//NOTE: rename() does not give you the option of overwriting existing files (at least not under Windows - see manual notes for more info) but copy() does, therefore we use this instead when overwrite was set as allowed
				if ($allow_overwrite) {
					//Overwrite IS allowed
					if (copy($old_filepath, $new_filepath)) {
						if (unlink($old_filepath)) {
							return true;
						} else {
							$err_msg_var = array('code' => 'unlink_returned_false', 'desc' => 'File was copied to new name but old file could not be deleted for unknown reason.');
							return false;
						}
					} else {
						$err_msg_var = array('code' => 'copy_returned_false', 'desc' => 'File could not be renamed/moved (using copy) for unknown reason.');
						return false;
					}
				} else {
					//Overwrite is NOT allowed
					$err_msg_var = array('code' => 'dest_file_exist', 'desc' => 'File could not be renamed/moved because destination file already exists.');
					return false;
				}
			} else {
				if (rename($old_filepath, $new_filepath)) {
					return true;
				} else {
					$err_msg_var = array('code' => 'rename_returned_false', 'desc' => 'File could not be renamed/moved for unknown reason.');
					return false;
				}
			}
		} else {
			$err_msg_var = array('code' => 'src_file_nonexist', 'desc' => 'File to rename/move does not exist.');
			return false;
		}
	}

	/**
	 * Copy a file
	 *
	 * @param string $source : Source file according to copy()
	 * @param string $destination : Destination file according to copy()
	 * @param array $options : Any of these options:
	 *   - `keep_modification_time` : Retain modification time of the source file in the destination file
	 */
	public static function copy_file($source, $destination, $options = []) {
		$result = copy($source, $destination);
		if ($result) {
			if ($options['keep_modification_time']) {  //source: https://stackoverflow.com/questions/4898534/php-copy-file-without-changing-the-last-modified-date#17893031
				$dt = filemtime($source);
				if ($dt === false)  {
					core::system_error('Failed to get source timestamp when copying file.', ['Source' => $source, 'Destination' => $destination]);
				} else {
					if (!touch($destination, $dt)) {
						core::system_error('Failed to set destination timestamp when copying file.', ['Source' => $source, 'Destination' => $destination]);
					}
				}
			}
		}
		return $result;
	}

	/**
	 * Delete a file, with optional trashcan feature
	 *
	 * @param string $location : Path and file name
	 * @param string $trashcan_folder : If a folder is specified the file will be moved to this folder instead of just being deleted (with or without trailing slash/backslash)
	 * @param string $err_msg_var : If present any error message (associative array with `code` and `desc`) will be written to this variable
	 * @return boolean
	 */
	public static function delete_file($location, $trashcan_folder = false, &$err_msg_var = null) {
		if (file_exists($location)) {
			if ($trashcan_folder) {
				// Using trashcan feature
				$trashcan_folder = self::cleanup_path($trashcan_folder);
				if (is_dir($trashcan_folder) || true) {
					$pathinfo = pathinfo($location);
					$dest_filename = self::make_unique_filename($pathinfo['basename'], $trashcan_folder);
					$dest_filepath = $trashcan_folder .'/'. $dest_filename;
					$dest_filepath = str_replace('//', '/', $dest_filepath);
					if (self::rename_move_file($location, $dest_filepath, $move_errmsg)) {
						return true;
					} else {
						$err_msg_var = array('code' => 'move_to_trash_failed', 'desc' => 'File could not be deleted to trashcan.', 'parent_error' => $move_errmsg);
						return false;
					}
				} else {
					$err_msg_var = array('code' => 'trash_nonexist', 'desc' => 'Trashcan folder does not exist.');
					return false;
				}
			} else {
				// NOT using trashcan feature, just delete the file
				if (unlink($location)) {
					return true;
				} else {
					$err_msg_var = array('code' => 'unlink_returned_false', 'desc' => 'File could not be deleted.');
					return false;
				}
			}
		} else {
			$err_msg_var = array('code' => 'src_file_nonexist', 'desc' => 'File to delete does not exist.');
			return false;
		}
	}

	/**
	 * Look through all files in a folder tree (recursively) and apply a callback function to each file
	 *
	 * Example:
	 * ```
	 * iterate_folder_tree($path, 'remove_old_file');
	 * public static function remove_old_file($fullpath, $filename) {
	 *   unlink($fullpath);
	 * }
	 * ```
	 *
	 * @param string $path : The path to the folder to start in
	 * @param callable $callback_function : Function to call for each file
	 *   - is passed two arguments: 1) full path to the file incl. its name, 2) file name only
	 * @return void : But `$GLOBALS['jfw_iterated_paths']` will be an array of paths we have gone through
	 */
	public static function iterate_folder_tree($path, $callback_function, $_internal = false) {
		if (!$_internal) $GLOBALS['jfw_iterated_paths'] = array();
		foreach (scandir($path) as $file) {
			$pathfile = rtrim($path, '/') .'/'. $file;
			if (!is_readable($pathfile)) {
				continue;
			}
			if ($file != '.' && $file != '..') {
				if (is_dir($pathfile)) {
					self::iterate_folder_tree($pathfile, $callback_function, true);
					$GLOBALS['jfw_iterated_paths'][] = $pathfile;
				} else {
					$callback_function($pathfile, $file);
				}
			}
		}
	}

	/**
	 * Copy all files and folders to another folder
	 *
	 * @param string $src : Source folder
	 * @param string $dest : Destination folder
	 * @param array $arr_skip_matches : Array of regular expressions which when matching a given full path should exclude that path
	 * @return boolean : True if success, false if failure
	 */
	public static function copy_folder_tree($src, $dest, $arr_skip_matches = array() ) {
		if (!is_dir($src)) {
			return false;
		}
		foreach (scandir($src) as $file) {
			if ($file != '.' && $file != '..') {
				$srcfile = rtrim($src, '/') .'/'. $file;
				$destfile = rtrim($dest, '/') .'/'. $file;
				if (!empty($arr_skip_matches)) {
					foreach ($arr_skip_matches as $cstring) {
						if (preg_match($cstring, $srcfile)) {
							continue 2;  //skip this $file
						}
					}
				}
				if (!is_readable($srcfile)) {
					continue;
				}
				if (is_dir($srcfile)) {
					if (!file_exists($destfile)) {
						if (!mkdir($destfile)) {
							return false;
						}
					}
					$r = self::copy_folder_tree($srcfile, $destfile, $arr_skip_matches);
					if (!$r) {
						return false;
					}
				} else {
					if (!copy($srcfile, $destfile)) {
						return false;
					}
				}
			}
		}
		return true;
	}

	/**
	 * Get the most recent files in a folder tree
	 *
	 * @param string $path : Path to check recursively for most recent files
	 * @param integer $number_of_files : Number of recent files to get
	 * @param array $options : Available options:
	 *   - `unix_timestamps` : use Unix timestamps in the output - instead of MySQL formatted timestamps as yyyy-mm-dd hh:mm:ss in UTC
	 *   - `min_size` : set minimum file size for the file to be considered (in bytes)
	 * @return array : Keys being the file timestamp and the value the full path. Sorted by keys descendingly.
	 */
	public static function most_recent_files($path, $number_of_files = 10, $options = []) {
		$latest_files = [];

		self::iterate_folder_tree($path, function($fullpath, $filename) use (&$latest_files, &$number_of_files, &$trim_array) {
			if ($options['min_size'] && filesize($fullpath) < $options['min_size']) {
				return;
			}
			$timestamp = filemtime($fullpath);

			if (empty($latest_files)) {
				if ($options['unix_timestamps']) {
					$latest_files[$fullpath] = $timestamp;
				} else {
					$latest_files[$fullpath] = gmdate('Y-m-d H:i:s', $timestamp);
				}
			} else {
				if ($timestamp > current($latest_files)) {
					if ($options['unix_timestamps']) {
						$latest_files[$fullpath] = $timestamp;
					} else {
						$latest_files[$fullpath] = gmdate('Y-m-d H:i:s', $timestamp);
					}
					arsort($latest_files);

					if (count($latest_files) > $number_of_files) {
						array_pop($latest_files);  //remove the last one since we now have too many files
					}
				}
			}
		});

		return $latest_files;
	}

	/**
	 * Delete all files and folders within a given folder recursively
	 *
	 * Use delete_folder_tree() instead to also delete the folder itself.
	 *
	 * @param string $emptypath : Complete absolute path to folder that should be emptied
	 * @param array $arr_skip_matches : Array of regular expressions within the given folder which when matched should NOT be deleted
	 * @param string $err_msg_var : If present any error message (associative array with `code` and `desc`) will be written to this variable
	 * @return boolean : True if success, false if failure
	 */
	public static function empty_folder($emptypath, $arr_skip_matches = array(), &$err_msg_var = null) {
		if (mb_strlen($emptypath) < 7 || substr($emptypath, -1) != '/') {
			die('Error in argument for emptying folder: '. $emptypath);
		}
		if (!is_dir($emptypath)) {
			$err_msg_var = array('code' => 'folder_nonexist', 'desc' => 'Folder to empty does not exist.', 'path' => $emptypath);
			return false;
		}
		$files = glob($emptypath .'{,.}*', GLOB_BRACE);  //constant needed in order to remove 'hidden' files like .htaccess
		foreach ($files as $file) {
			if (!empty($arr_skip_matches)) {
				foreach ($arr_skip_matches as $cstring) {
					if (preg_match($cstring, $file) !== false) {
						continue 2;  //skip this $file
					}
				}
			}
			if (in_array(basename($file), array('.', '..'))) {
				continue;
			} elseif (is_file($file)) {
				if (!unlink($file)) {
					$err_msg_var = array('code' => 'unlink_returned_false', 'desc' => 'Could not delete file.', 'path' => $file);
					return false;
				}
			} elseif (is_dir($file)) {
				$r = self::delete_folder_tree($file, array(), $del_errmsg);
				if (!$r) {
					$err_msg_var = array('code' => 'delete_folder_tree_failed', 'desc' => 'Could not delete child folder tree.', 'parent_error' => $del_errmsg, 'path' => $file);
					return false;
				}
			}
		}
		return true;
	}

	/**
	 * Delete an entire folder structure with all its files and folders recursively
	 *
	 * Use empty_folder() instead to only delete its contents.
	 *
	 * @param string $folder : Folder to delete
	 * @param string $err_msg_var : If present any error message (associative array with `code` and `desc`) will be written to this variable
	 * @return boolean : True if success, false if failure
	 */
	public static function delete_folder_tree($folder, &$err_msg_var = null) {
		if (!is_dir($folder)) {
			$err_msg_var = array('code' => 'folder_nonexist', 'desc' => 'Folder to delete does not exist.', 'path' => $folder);
			return false;
		}
		$objects = scandir($folder);
		foreach ($objects as $object) {
			$folderobject = rtrim($folder, '/') .'/'. $object;
			if ($object != '.' && $object != '..') {
				if (filetype($folderobject) == 'dir') {
					$r = self::delete_folder_tree($folderobject, $del_errmsg);
					if (!$r) {
						$err_msg_var = array('code' => 'folder_nonexist', 'desc' => 'Could not delete child folder tree.', 'parent_error' => $del_errmsg, 'path' => $folderobject);
						return false;
					}
				} else {
					if (!unlink($folderobject)) {
						$err_msg_var = array('code' => 'unlink_returned_false', 'desc' => 'Could not delete a file.', 'path' => $folderobject);
						return false;
					}
				}
			}
		}
		reset($objects);
		if (!rmdir($folder)) {
			$err_msg_var = array('code' => 'rmdir_returned_false', 'desc' => 'Could not delete the top folder.', 'path' => $folder);
			return false;
		}
		return true;
	}

	/**
	 * Convert any string to a valid file OR directory name, with special characters removed
	 *
	 * Less restrictive than make_valid_filename_strict().
	 *
	 * @param array $options : Associative array with any of these keys:
	 *   - `replace_space_with` (string) : set character to replace spaces with instead of underscores (_)
	 *   - `skip_space_conversion` (boolean) : set to true if spaces should NOT be converted
	 * @return string
	 */
	public static function make_valid_filename($input, $options = array() ) {
		// Replace spaces
		if (!$options['skip_space_conversion']) {
			if ($options['replace_space_with']) {
				$input = str_replace(' ', $options['replace_space_with'], $input);
			} else {
				$input = str_replace(' ', '_', $input);
			}
		}

		// Remove invalid and odd characters
		$invalid_chars = array('\\', '/', ':', '*', '?', '"', '<', '>', '|',
			'&', '%', '¤', '#', '!', '§', '½', ';', '=', '`', '´', '^', '+');  //first row is truly invalid, the rest just some we don't want
		$input = str_replace($invalid_chars, '', $input);

		return $input;
	}

	/**
	 * Convert any string to a valid file OR directory name, with special characters removed
	 *
	 * Very strict regarding which characters are allowed.
	 *
	 * @param string $input : File name
	 * @param array $options : Associative array with any of these keys:
	 *   - `replace_space_with` (string) : set character to replace spaces with instead of underscores (_)
	 *   - `skip_space_conversion` (boolean) : set to true if spaces should NOT be converted
	 *   - `skip_special_char_conversion` (boolean) : set to true if special characters should NOT be converted
	 *   - `skip_removing_nonascii` (boolean) : set to true if unknown non-ASCII characters should NOT be removed
	 *   - `allow_characters` (string) : string with characters that should be allowed even though they are listed below
	 *   - `assume_filename_only` (boolean) : set to true assuming that $input is only a file name and not a full path
	 * @return string
	 */
	public static function make_valid_filename_strict($input, $options = array() ) {
		$options['allow_characters'] = (string) $options['allow_characters'];

		// Get basename
		$fileinfo = pathinfo($input);
		if (!$options['assume_filename_only']) {
			$extension = $fileinfo['extension'];
			$basename = str_replace('.'.$extension, '', $fileinfo['basename']);  //my basename is NOT equal to PHP basename - I don't include the extension
		} else {
			$extension = $fileinfo['extension'];
			$basename = str_replace('.'.$extension, '', $input);
		}

		// Replace spaces
		if (!$options['skip_space_conversion']) {
			if ($options['replace_space_with']) {
				$basename = str_replace(' ', $options['replace_space_with'], $basename);
			} else {
				$basename = str_replace(' ', '_', $basename);
			}
		}

		// Replace special characters
		$search  = array('æ' , 'Æ' , 'ø' , 'Ø' , 'å' , 'Å' , 'à', 'á', 'â', 'ã', 'ä', 'è', 'é', 'ê', 'ë', 'ì', 'í', 'î', 'ï', 'ò', 'ó', 'ô', 'õ', 'ö', 'ù', 'ú', 'û', 'ü', 'ý', 'À', 'Á', 'Â', 'Ã', 'Ä', 'È', 'É', 'Ê', 'Ë', 'Ì', 'Í', 'Î', 'Ï', 'Ò', 'Ó', 'Ô', 'Õ', 'Ö', 'Ù', 'Ú', 'Û', 'Ü', 'Ý', 'ß' , '');
		$replace = array('ae', 'AE', 'oe', 'OE', 'aa', 'AA', 'a', 'a', 'a', 'a', 'a', 'e', 'e', 'e', 'e', 'i', 'i', 'i', 'i', 'o', 'o', 'o', 'o', 'o', 'u', 'u', 'u', 'u', 'y', 'A', 'A', 'A', 'A', 'A', 'E', 'E', 'E', 'E', 'I', 'I', 'I', 'I', 'O', 'O', 'O', 'O', 'O', 'U', 'U', 'U', 'U', 'Y', 'ss', "'");
		if (!$options['skip_special_char_conversion']) {
			$basename = str_replace($search, $replace, $basename);
			$extension = str_replace($search, $replace, $extension);

			$excl_regex = '';
		} else {
			$excl_regex = implode('', $search);
		}

		// Remove invalid and odd characters
		$invalid_chars = array('\\', '/', ':', '*', '?', '"', '<', '>', '|',
			'.', ',', '&', '%', '¤', '#', '!', '§', '½', ';', '(', ')', '=', '`', '´', '^', '+');  //first row is truly invalid, the rest just some we don't want

		if ($options['allow_characters']) {
			for ($i = 0; $i < mb_strlen($options['allow_characters']); $i++) {
				$key = array_search(mb_substr($options['allow_characters'], $i, 1), $invalid_chars);
				if ($key !== false) {
					unset($invalid_chars[$key]);
				}
			}
		}

		$basename = str_replace($invalid_chars, '', $basename);
		$extension = str_replace($invalid_chars, '', $extension);

		// Remove any other unknown non-ASCII characters
		if (!$options['skip_removing_nonascii']) {
			$basename = preg_replace('/[^\x20-\x7E'. str_replace('/', "\\/", preg_quote($excl_regex . $options['allow_characters'])) .']/'.(mb_internal_encoding() == 'UTF-8' ? 'u' : ''),'', $basename);
			$extension = preg_replace('/[^\x20-\x7E'. str_replace('/', "\\/", preg_quote($excl_regex . $options['allow_characters'])) .']/'.(mb_internal_encoding() == 'UTF-8' ? 'u' : ''),'', $extension);
		}

		if ($extension) {
			return $basename .'.'. $extension;
		} else {
			return $basename;
		}
	}

	/**
	 * Check if a file name is strictly valid
	 *
	 * @param string $filename
	 * @return boolean
	 */
	public static function is_valid_filename($filename) {
		return (preg_match("#[". preg_quote("\\/:*?\"<>|") ."]#", $filename) ? false : true);
	}

	/**
	 * Check if a full path to a file is valid
	 *
	 * Eg.: `/var/www/myfile.php` or `c:\www-root\document.pdf`
	 *
	 * @param aray $options : Associative array with any of these keys:
	 * 	- `valid_folder_separators` (string) : list of valid folder separator characters. Default is slash and backslash (\ and /)
	 * 	- `allow_characters` (string) : other allowed characters in the folder and file names that would normally be disallowed
	 * 	- `skip_special_char_conversion` (boolean) : set to true to allow special characters according to make_valid_filename_strict() in the path
	 * @return boolean
	 */
	public static function is_valid_filepath($filepath, $options = array() ) {
		$defaults = [
			'valid_folder_separators' => "\\/",
			'allow_characters' => '',
			'skip_special_char_conversion' => false,
		];
		$options = array_merge($defaults, (array) $options);

		$filepath = preg_replace("/^([a-z]):([\\/])/i", '$1$2', $filepath);  //convert c:/folder/... to c/folder/...

		$clean = self::make_valid_filename_strict($filepath, ['skip_space_conversion' => true, 'skip_special_char_conversion' => $options['skip_special_char_conversion'], 'allow_characters' => $options['allow_characters'] . $options['valid_folder_separators'], 'assume_filename_only' => true]);
		return ($clean === $filepath ? true : false);
	}

	/**
	 * Ensure that a file OR directory will be unique in a certain folder by adding a number after the name
	 *
	 * @param string $filename : File name to check uniqueness of
	 * @param string $basefolder : In which folder to check (with or without trailing slash/backslash)
	 * @param boolean $is_dir : If $filename is a file or a directory
	 * @param boolean $forcedigits : Whether or not to add a number even though the file would be unique without adding a number (good for making a series of files)
	 * @param integer $digits : Number of digits in the number that will be added
	 * @return string
	 */
	public static function make_unique_filename($filename, $basefolder, $is_dir = false, $forcedigits = false, $digits = 2) {
		$basefolder = self::cleanup_path($basefolder);
		if ($basefolder[strlen($basefolder)-1] != '/') {  //add trailing slash if not present
			$basefolder .= '/';
		}
		if ($is_dir) {
			$basename = $filename;
		} else {
			$fileinfo = pathinfo($basefolder . $filename);
			$extension = $fileinfo['extension'];
			$basename = str_replace('.'.$extension, '', $fileinfo['basename']);  //my basename is NOT equal to PHP basename - I don't include the extension
		}
		if ($forcedigits) {
			$temp_basename = $basename .'_'. str_pad(1, $digits, '0', STR_PAD_LEFT);
			$counter = 1;  //next number to try will be 2
		} else {
			$temp_basename = $basename;
			$counter = 0;  //next number to try will be 1
		}
		while (file_exists($basefolder . $temp_basename . ($is_dir ? '' : '.'. $extension))) {
			$counter++;
			$temp_basename = $basename .'_'. str_pad($counter, $digits, '0', STR_PAD_LEFT);
		}
		$basename = $temp_basename;
		if ($is_dir) {
			return $basename;
		} else {
			return $basename .'.'. strtolower($extension);
		}
	}

	/**
	 * Check existence of a folder and automatically try to create it if not present
	 *
	 * Works recursively (=> ensures that parent folders also exist)
	 *
	 * Source: PHP documentation notes: acroyear@io.com (22-Jun-2003 05:38)
	 * Original function name: mkdirs()
	 *
	 * @param string $folder : Path to require existence of
	 * @param integer $mode : (opt.) Option to set specific permissions (ignored on Windows platforms)
	 * @return boolean
	 */
	public static function require_folder_exist($folder, $mode = null) {
		if (is_dir($folder)) {
			return true;
		}
		$parent_folder = dirname($folder);
		if (!self::require_folder_exist($parent_folder, $mode)) {
			return false;
		}
		if ($mode) {
			$mkdir_result = mkdir($folder, $mode);
		} else {
		$mkdir_result = mkdir($folder);
		}
		if (!$mkdir_result) {
			core::system_error('Folder did not exists and automatic creation failed.', ['Folder' => $folder]);
		} else {
			return true;
		}
	}

	/**
	 * Get the MIME type of a file by analyzing its contents
	 *
	 * Works only on Linux
	 *
	 * @param string $filepath : Path to file
	 * @return array|string : If found an associative array with keys `mimetype` and `charset`, if not found string `unknown`
	 */
	public static function get_mime_type($filepath) {
		if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
			throw new \Exception('The method get_mime_type() is not yet supported on Windows.');
		}
		if (!file_exists($filepath)) {  //crucial for security!
			throw new \Exception('File to get MIME type for does not exist.');
		}
		if (!self::is_valid_filepath($filepath)) {  //crucial for security!
			throw new \Exception('File path contains invalid characters.');
		}

		$output = []; $exitcode = null;
		exec('file -ib '. str_replace(' ', "\\ ", $filepath), $output, $exitcode);

		if ($exitcode > 0) {
			throw new \Exception('Failed to check MIME type of file.', ['File' => $filepath, 'Exit code' => $exitcode, 'Output' => $output]);
		} else {
			list($mimetype, $charset) = explode(';', $output[0]);
			$mimetype = trim($mimetype);
			if (!$mimetype) {
				return 'unknown';
			} else {
				return [
					'mimetype' => trim($mimetype),
					'charset' => trim(str_replace('charset=', '', $charset)),
				];
			}
		}
	}

	/**
	 * Check if a file has the correct extension relative to it's MIME type/file signature
	 *
	 * Works only on Linux.
	 *
	 * Usually used after is_signature_valid() has returned false.
	 *
	 * @return boolean|array : Returns `true` if correct or assumed correct. If we know wrong extension is being used return array with keys `actual` and `correct`.
	 */
	public static function check_correct_extension($filepath, $actual_extension = null) {
		if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
			throw new \Exception('The method check_wrong_extension() is not yet supported on Windows.');
		}

		$fileinfo = self::get_mime_type($filepath);

		if ($fileinfo === 'unknown') {
			// unknown file, assume it's correct
			return true;
		}

		if (!$actual_extension) {
			$actual_extension = strtolower(pathinfo($filepath, PATHINFO_EXTENSION));
		}
		$actual_extension = strtolower($actual_extension);
		$correct_extension = self::map_mime_type_to_extension()[$fileinfo['mimetype']];

		if (!$correct_extension) {
			// don't know what extension it should be according to its signature, so just assume it's correct
			return true;
		}
		if (!in_array($actual_extension, $correct_extension)) {
			return ['actual' => $actual_extension, 'correct' => $correct_extension[0]];
		}
	}

	public static function map_mime_type_to_extension() {
		return [
			'image/jpeg'  => ['jpg'],
			'image/pjpeg' => ['jpg'],
			'image/png' => ['png'],
			'image/tiff' => ['tif', 'tiff'],
			'image/x-tiff' => ['tif', 'tiff'],
			'application/pdf' => ['pdf'],
			'application/vnd.ms-opentype' => ['otf'],
			'application/x-font-opentype' => ['otf'],
			'application/x-font-ttf' => ['ttf'],
			'application/x-font-truetype' => ['ttf'],
			'application/x-indesign' => ['indd'],
		];
	}

	/**
	 * Check if a file matches its extension or MIME type by checking its header bytes
	 *
	 * Sources:
	 * - https://en.wikipedia.org/wiki/List_of_file_signatures
	 * - http://www.garykessler.net/library/file_sigs.html
	 * - https://www.sitepoint.com/mime-types-complete-list/
	 * - http://filesignatures.net/index.php?page=all&order=SIGNATURE&sort=DESC&alpha=All
	 *
	 * Software developer Allan Jensen (www.winternet.no) has started building a collection of sample files with the different signatures
	 *
	 * Hex to decimal converter: https://duckduckgo.com/?q=hex+to+decimal&atb=v26_k&ia=textconverter
	 *
	 * @param string $filepath : Path to file
	 * @param string $ext_or_mime : (opt.) Extension of the file, or MIME type. If not provided it is auto-detected.
	 *   - if MIME type was determined by reading the file itself there is no reason to using this method - unless you are cynical ;)
	 * @return boolean|string : Possible return values:
	 *   - true : valid
	 *   - false : not valid
	 *   - `unknown` : don't know the file type's or MIME type's signature
	 *   - `nosig` : has no signature
	 */
	public static function is_signature_valid($filepath, $ext_or_mime = false, $options = []) {
		$defaults = [
			'is_mime' => false,
		];
		$options = array_merge($defaults, (array) $options);

		if (!$ext_or_mime) {
			$ext_or_mime = strtolower(pathinfo($filepath, PATHINFO_EXTENSION));
			$options['is_mime'] = false;
		} else {
			$ext_or_mime = strtolower($ext_or_mime);
		}

		// NOTES:
		// - if multiple arrays (= "patterns") are given for a file type they must ALWAYS have equal length (requirement could actually be removed if we just find the longest one before reading from file)
		//    - if patterns do have different length put shortest patterns first and fill with '*'
		// - if a given byte in the sequence is not part of the signature use '*' to indicate that any byte is allowed

		$headers = null;

		if ((!$options['is_mime'] && in_array($ext_or_mime, ['jpg', 'jpeg'], true)) || ($options['is_mime'] && in_array($ext_or_mime, ['image/jpeg', 'image/pjpeg'], true))) {

			$headers = array(
				[255, 216, 255, 219, '*', '*', '*', '*', '*', '*', '*', '*'],  //JPEG raw - hex: FF D8 FF DB
				[255, 216, 255, 224, '*', '*',  74,  70,  73,  70,   0,   1],  //JFIF - hex: FF D8 FF E0 nn nn 4A 46 49 46 00 01
				[255, 216, 255, 225, '*', '*', '*', '*', '*', '*', '*', '*'],  //Exif - hex: FF D8 FF E1
				[255, 216, 255, 226, '*', '*', '*', '*', '*', '*', '*', '*'],  //Canon EOS JPEG - hex: FF D8 FF E2
				[255, 216, 255, 237, '*', '*', '*', '*', '*', '*', '*', '*'],  //Mixed Raster Content (MRC) - hex: FF D8 FF ED (source: http://fileformats.archiveteam.org/wiki/Mixed_Raster_Content)
			);

		} elseif ((!$options['is_mime'] && $ext_or_mime === 'png') || ($options['is_mime'] && $ext_or_mime === 'image/png')) {

			$headers = array([137, 80, 78, 71, 13, 10, 26, 10]);  //hex: 89 50 4E 47 0D 0A 1A 0A

		} elseif ((!$options['is_mime'] && in_array($ext_or_mime, ['tif', 'tiff'], true)) || ($options['is_mime'] && in_array($ext_or_mime, ['image/tiff', 'image/x-tiff'], true))) {

			$headers = array(
				[73, 73, 42, 0],  //little endian       - hex: 49 49 2A 00
				[77, 77, 0, 42],  //big endian          - hex: 4D 4D 00 2A
				[77, 77, 0, 43],  //BigTIFF files >4 GB - hex: 4D 4D 00 2B
				[73, 32, 73, '*'], //                   - hex: 49 20 49
			);

		} elseif ((!$options['is_mime'] && $ext_or_mime === 'pdf') || ($options['is_mime'] && in_array($ext_or_mime, ['application/pdf'], true))) {

			$headers = array([37, 80, 68, 70]);  //hex: 25 50 44 46

		} elseif ((!$options['is_mime'] && $ext_or_mime === 'otf') || ($options['is_mime'] && in_array($ext_or_mime, ['application/x-font-opentype', 'application/vnd.ms-opentype'], true))) {

			$headers = array([79, 84, 84, 79, 0]);  //hex: 4F 54 54 4F 00

		} elseif ((!$options['is_mime'] && $ext_or_mime === 'ttf') || ($options['is_mime'] && in_array($ext_or_mime, ['application/x-font-ttf', 'application/x-font-truetype'], true))) {

			$headers = array([0, 1, 0, 0]);  //hex: 00 01 00 00 00

		} elseif ((!$options['is_mime'] && $ext_or_mime === 'indd') || ($options['is_mime'] && in_array($ext_or_mime, ['application/x-indesign', 'application/octet-stream' /*on Swiftlayout server it gave this type for .indd files*/], true))) {

			$headers = array([6, 6, 237, 245, 216, 29, 70, 229, 189, 49, 239, 231, 254, 116, 183, 29]);  //hex: 06 06 ED F5 D8 1D 46 e5 BD 31 EF E7 FE 74 B7 1D - followed by an 8-byte file type specifier: "DOCUMENT" (even when saved as template), "BOOKBOOK", or "LIBRARY4", which need the proper extensions .indd, .indb, .indl. (source: https://forums.adobe.com/thread/705908)

		} elseif ((!$options['is_mime'] && in_array($ext_or_mime, ['txt', 'js', 'css'], true)) || ($options['is_mime'] && $ext_or_mime === 'text/plain')) {

			return 'nosig';

		}


		if ($headers !== null) {
	 		if (!file_exists($filepath)) {
				core::system_error('File to check signature on does not exist.', ['Folder' => $filepath]);
	 		}

			$f = fopen($filepath, 'r');

			$bytes = [];
			// inspiration: http://www.codeaid.net/php/check-if-the-file-is-a-png-image-file-by-reading-its-signature
			for ($byteindx = 0; $byteindx < count($headers[0]); $byteindx++) {
				$bytes[] = ord(fread($f, 1));  // convert current byte to its ASCII value
			}
			fclose($f);

			$found_valid_header = false;
			$header_count = count($headers);
			foreach ($headers as $header) {
				$failed_match = false;
				foreach ($bytes as $indx => $ascii) {
					if ($header[$indx] !== '*' && $ascii !== $header[$indx]) {
						$failed_match = true;
						if ($header_count == 1) {  //no alternatives to check, provide result immediately
							return false;
						} else {
							break;  //check no further bytes for this header alternative
						}
					}
				}

				if (!$failed_match) {
					$found_valid_header = true;
					break;  //check no further as we have found a valid signature
				}
			}

			if (!$found_valid_header) {
				return false;
			} else {
				return true;
			}
		}

		return 'unknown';
	}

	/**
	 * Determine if a file is binary
	 *
	 * @param string $file : File name including path
	 * @return boolean
	 */
	public static function is_binary_file($file) {
		$fp = fopen($file, 'r');
		$d = fread($fp, 1000);
		if (preg_match("|\\x00|", $d) || !preg_match('/\\.(php|phtml|js|txt|css|htm|html|xhtml|xml|xsl|xsd|txt|ini|sql|json|htaccess|htpasswd|asp|cgi|bat|log|vbs|csv|m3u)$/i', $file)) {  //NOTE: the first condition was not enough in case of a given PDF file I tried to upload
			return true;
		} else {
			return false;
		}
	}

	/*
	public static function folder_size($dir) {
	  //This function doesn't work on Windows as stat->blocks is not set.
	  //Otherwise this function is better than the other one because it counts the actual bytes the files occupy considering block size
	  $s = stat($dir);
	  $space = $s["blocks"]*512;
	  if (is_dir($dir)) {
	    $dh = opendir($dir);
	    while (($file = readdir($dh)) !== false)
	      if ($file != "." and $file != "..")
	        $space += folder_size($dir."/".$file);
	    closedir($dh);
	  }
	  return $space;
	}
	*/

	public static function folder_size($dir) {
		//This function does not consider block size
		//If folder does not exist false is returned
		if (is_dir($dir)) {
			$dh = opendir($dir);
			$size = 0;
			while (($file = readdir($dh)) !== false)
			    if ($file != "." and $file != "..") {
			        $path = $dir."/".$file;
			        if (is_dir($path))
			            $size += folder_size($path);
			        elseif (is_file($path))
			            $size += filesize($path);
			    }
			closedir($dh);
			return $size;
		} else {
			return false;
		}
	}

	/**
	 * Cleanup a path (with or without filename) by using only forward slashes
	 */
	public static function cleanup_path($path) {
		return str_replace('\\', '/', $path);
	}

	/**
	 * Concatenate path and filename, or two paths, making sure there is a slash and only one slash between them
	 */
	public static function concat_path($path1, $path2) {
		// TODO: make it possible to use endless number of arguments that will be concatenated
		$path1 = rtrim($path1, '/\\');
		$path2 = ltrim($path2, '/\\');
		return $path1 .'/'. $path2;
	}
}
