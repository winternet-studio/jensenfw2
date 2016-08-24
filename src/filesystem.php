<?php
/*
This file contains functions related to file system handling and manipulation
*/
namespace winternet\jensenfw2;

class filesystem {
	public static function get_folders($folder, $sorting_order = 0) {
		/*
		DESCRIPTION:
		- get a list of all folders in a given folder
		INPUT:
		- $folder : relative or absolute reference to folder
		- $sorting_order : see PHP documentation for scandir()
		OUTPUT:
		- array with file names
		- or false if folder does not exist
		*/
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

	public static function get_files($folder, $sorting_order = 0) {
		/*
		DESCRIPTION:
		- get all files in a folder
		INPUT:
		- $folder : relative or absolute reference to folder
		- $sorting_order : see PHP documentation for scandir()
		OUTPUT:
		- array with file names
		- or false if folder does not exist
		*/
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

	public static function file_put_contents_prepend($file, $contents, $trim_length = false) {
		/*
		DESCRIPTION:
		- add contents to file, but prepending instead of appending it
		INPUT:
		- $file
		- $contents : new content to add to the file
		- $trim_length (opt.) : after prepending contents truncate the file to this amount of bytes
		OUTPUT:
		- nothing, only modifies the file
		*/
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

	public static function file_info($file) {
		/*
		DESCRIPTION:
		- get all kinds of information about a file
		INPUT:
		- $file : can be just a file name, but if you want path data output too you must of course include that
		OUTPUT:
		- associative array
		*/
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

	public static function rename_move_file($old_filepath, $new_file, &$err_msg_var = null, $allow_overwrite = false) {
		/*
		DESCRIPTION:
		- renames a file and/or move a file
		INPUT:
		- $old_filepath : current file name including path to file
		- $new_file : new file name
			- if only rename : you can leave out the path
			- if rename and move : path must of course be included
		- $err_msg_var : if present any error message (associative array with 'code' and 'desc') will be written to this variable
		OUTPUT:
		- true on success, false on failed
		*/
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

	public static function delete_file($location, $trashcan_folder = false, &$err_msg_var = null) {
		/*
		DESCRIPTION:
		- delete a file, with optional trashcan feature
		INPUT:
		- $location : path and file name
		- $trashcan_folder : if a folder is specified the file will be moved to this folder instead of just being deleted (with or without trailing slash/backslash)
		- $err_msg_var : if present any error message (associative array with 'code' and 'desc') will be written to this variable
		*/
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

	public static function iterate_folder_tree($path, $callback_function, $_internal = false) {
		/*
		DESCRIPTION:
		- look through all files in a folder tree (recursively) and apply a callback function to each file
		- example:
			iterate_folder_tree($path, 'remove_old_file');
			public static function remove_old_file($fullpath, $filename) {
				unlink($fullpath);
			}
		INPUT:
		- $path : the path to the folder to start in
		- $callback_function : function to call for each file
			- is passed two arguments: 1) full path to the file incl. its name, 2) file name only
		OUTPUT:
		- nothing, but $GLOBALS['jfw_iterated_paths'] will be an array of paths we have gone through
		*/
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

	public static function copy_folder_tree($src, $dest, $arr_skip_matches = array() ) {
		/*
		DESCRIPTION:
		- copy all files and folders to another folder
		INPUT:
		- $src : source folder
		- $dest : destination folder
		- $arr_skip_matches : array of regular expressions which when matching a given full path should exclude that path
		OUTPUT:
		- if success : true
		- if failure : false
		*/
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

	public static function empty_folder($emptypath, $arr_skip_matches = array(), &$err_msg_var = null) {
		/*
		DESCRIPTION:
		- delete all files and folders within a given folder recursively
		- use delete_folder_tree() instead to also delete the folder itself
		INPUT:
		- $emptypath : complete absolute path to folder that should be emptied
		- $arr_skip_matches : array of regular expressions within the given folder which when matched should NOT be deleted
		- $err_msg_var : if present any error message (associative array with 'code' and 'desc') will be written to this variable
		OUTPUT:
		- if success : true
		- if failure : false
		*/
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

	public static function delete_folder_tree($dir, &$err_msg_var = null) {
		/*
		DESCRIPTION:
		- delete an entire folder structure with all its files and folders recursively
		- use empty_folder() instead to only delete its contents
		INPUT:
		- $folder : folder to delete
		- $err_msg_var : if present any error message (associative array with 'code' and 'desc') will be written to this variable
		OUTPUT:
		- if success : true
		- if failure : false
		*/
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

	public static function make_valid_filename($input, $skip_space_conversion = false) {
		/*
		DESCRIPTION:
		- use this function to convert any string to a valid file OR directory name, with special characters removed
		- less restrictive than make_valid_filename_strict()
		INPUT:
		- $skip_space_conversion (true|false) : set to true if spaces should NOT be converted to underscores (_)
		*/

		// Replace spaces
		if (!$skip_space_conversion) {
			$input = str_replace(' ', '_', $input);
		}

		// Remove invalid and odd characters
		$invalid_chars = array('\\', '/', ':', '*', '?', '"', '<', '>', '|',
			'&', '%', '¤', '#', '!', '§', '½', ';', '=', '`', '´', '^', '+'); //first row is directly invalid, the rest I just don't want in a file name
		$input = str_replace($invalid_chars, '', $input);

		return $input;
	}

	public static function make_valid_filename_strict($input, $skip_space_conversion = false) {
		/*
		DESCRIPTION:
		- use this function to convert any string to a valid file OR directory name, with special characters removed
		- very strict regarding which characters are allowed
		- extension is not touched
		INPUT:
		- $skip_space_conversion (true|false) : set to true if spaces should NOT be converted to underscores (_)
		*/

		// Get basename
		$fileinfo = pathinfo($input);
		$extension = $fileinfo['extension'];
		$basename = str_replace('.'.$extension, '', $fileinfo['basename']);  //my basename is NOT equal to PHP basename - I don't include the extension

		// Replace spaces
		if (!$skip_space_conversion) {
			$basename = str_replace(' ', '_', $basename);
		}

		// Replace special characters
		$search  = array('æ' , 'Æ' , 'ø' , 'Ø' , 'å' , 'Å' , 'à', 'á', 'â', 'ã', 'ä', 'è', 'é', 'ê', 'ë', 'ì', 'í', 'î', 'ï', 'ò', 'ó', 'ô', 'õ', 'ö', 'ù', 'ú', 'û', 'ü', 'ý', 'À', 'Á', 'Â', 'Ã', 'Ä', 'È', 'É', 'Ê', 'Ë', 'Ì', 'Í', 'Î', 'Ï', 'Ò', 'Ó', 'Ô', 'Õ', 'Ö', 'Ù', 'Ú', 'Û', 'Ü', 'Ý', 'ß' , '');
		$replace = array('ae', 'AE', 'oe', 'OE', 'aa', 'AA', 'a', 'a', 'a', 'a', 'a', 'e', 'e', 'e', 'e', 'i', 'i', 'i', 'i', 'o', 'o', 'o', 'o', 'o', 'u', 'u', 'u', 'u', 'y', 'A', 'A', 'A', 'A', 'A', 'E', 'E', 'E', 'E', 'I', 'I', 'I', 'I', 'O', 'O', 'O', 'O', 'O', 'U', 'U', 'U', 'U', 'Y', 'ss', "'");
		$basename = str_replace($search, $replace, $basename);
		$extension = str_replace($search, $replace, $extension);

		// Remove invalid and odd characters
		$invalid_chars = array('\\', '/', ':', '*', '?', '"', '<', '>', '|',
			'.', ',', '&', '%', '¤', '#', '!', '§', '½', ';', '(', ')', '=', '`', '´', '^', '+', '', ''); //de første er direkte ugyldige, resten er bare nogen jeg ikke vil have med
		$basename = str_replace($invalid_chars, '', $basename);
		$extension = str_replace($invalid_chars, '', $extension);

		// Remove any other non-ASCII characters
		$basename = preg_replace('/[^\x20-\x7E]/'.(mb_internal_encoding() == 'UTF-8' ? 'u' : ''),'', $basename);
		$extension = preg_replace('/[^\x20-\x7E]/'.(mb_internal_encoding() == 'UTF-8' ? 'u' : ''),'', $extension);

		if ($extension) {
			return $basename .'.'. $extension;
		} else {
			return $basename;
		}
	}

	public static function make_unique_filename($filename, $basefolder, $is_dir = false, $forcedigits = false, $digits = 2) {
		/*
		DESCRIPTION:
		- use this function to ensure that a file OR directory will be unique in a certain folder by adding a number after the name
		INPUT:
		- $filename : file name to check uniqueness of
		- $basefolder : in which folder to check (with or without trailing slash/backslash)
		- $is_dir: if $filename is a file or a directory
		- $forcedigits: whether or not to add a number even though the file would be unique without adding a number (good for making a series of files)
		- $digits: number of digits in the number that will be added
		TODO:
		- first put files into an array and then check against the array instead of going to the filesystem all the time
		*/
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

	public static function require_folder_exist($folder, $mode = false) {
		/*
		DESCRIPTION:
		- check existence of a folder and automatically tries to create it if not present
		- works recursively (=> ensures that parent folders also exist)
		- source: PHP documentation notes: acroyear@io.com (22-Jun-2003 05:38)
		- original function name: mkdirs()
		INPUT:
		- $folder : path to require existence of
		- $mode (opt.) : option to set specific permissions (ignored on Windows platforms)
		OUTPUT:
		- true or false
		*/
		if (is_dir($folder)) {
			return true;
		}
		$parent_folder = dirname($folder);
		if (!self::require_folder_exist($parent_folder, $mode)) {
			return false;
		}
		$mkdir_result = mkdir($folder);
		if (!$mkdir_result) {
			core::system_error('Folder did not exists and automatic creation failed.', ['Folder' => $folder]);
		} else {
			return true;
		}
	}

	public static function is_binary_file($file) {
		/*
		DESCRIPTION:
		- determine if a file is binary
		INPUT:
		- $file : file name including path
		OUTPUT:
		- true or false
		*/
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

	public static function cleanup_path($path) {
		/*
		DESCRIPTION:
		- cleanup a path (with or without filename) by using only forward slashes
		*/
		return str_replace('\\', '/', $path);
	}
}
