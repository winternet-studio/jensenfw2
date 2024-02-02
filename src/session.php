<?php
/**
 * Functions related to sessions
 */

namespace winternet\jensenfw2;

class session {

	/**
	 * Easily set/unset different settings via the GET and POST methods
	 *
	 * - you can access the session varible using only the name (eg. `$_SESSION['myname']`)
	 *
	 * @param string $name : Name of the GET or POST variable that may contain the new value. If the found value is `reset` the variable will be "cleared" and set to null.
	 *   - can also be an array:
	 *     - first entry will be the primary name, which means that will be the variable name
	 *     - additional entries will only act as other possible options for naming the GET and POST variables, eg. so you can make a shorter name that you use when passing it on through web forms
	 *     - this actually gives you the option of using different variable names for GET and POST on _different_ pages which might help to avoid conflicts!
	 * @param mixed $default_value : Set default value the very first time we deal with this variable (opt.)
	 * @param boolean $prioritize_get : Normally a value sent by POST is preferred over GET, unless you set this to true (opt.)
	 */
	public static function setting($name, $default_value = false, $prioritize_get = false) {
		// This is a rewrite of the old session_setting() from the old JensenFW

		// Get primary name (variable name) and make array holding all possible GET and POST names
		if (is_array($name)) {
			$primary_name = $name[0];
			$getpost_names = $name;
		} else {
			$primary_name = $name;
			$getpost_names = [ $name ];
		}

		// Check if a new value exists
		$new_value = '-no-new-value-';  //first assume that there is no new value
		foreach ($getpost_names as $curr_name) {  //loop through each possible name
			$get_length  = strlen($_GET[$curr_name]);
			$post_length = strlen($_POST[$curr_name]);
			if ($prioritize_get) {  //determine new value
				if ($get_length > 0) {
					$new_value = $_GET[$curr_name];
				} elseif ($post_length > 0) {
					$new_value = $_POST[$curr_name];
				}
			} else {
				if ($post_length > 0) {
					$new_value = $_POST[$curr_name];
				} elseif ($get_length > 0) {
					$new_value = $_GET[$curr_name];
				}
			}
			if ($new_value != '-no-new-value-') {  //if we now have a new value, break the loop and skip checking the rest of the names
				break;
			}
		}

		// Determine if variable is currently set (used to determine if we should assign default value)
		if (@defined('YII_BEGIN_TIME') && PHP_SAPI !== 'cli' && \Yii::$app->session) {
			$session = \Yii::$app->session;
		}
		if (isset($_SESSION[$primary_name])) {
			$is_set = true;
		} else {
			$is_set = false;
		}

		// Assign the value to the variable (if any)
		if ($new_value === 'reset') {  //reset the value
			$_SESSION[$primary_name] = null;
		} elseif ($new_value != '-no-new-value-') {  //assign the new value
			$_SESSION[$primary_name] = $new_value;
		} elseif (strlen($default_value) > 0 && !$is_set) {  //set default value the very first time we deal with this variable
			$_SESSION[$primary_name] = $default_value;
		} else {
			//no new value and no default value has been set, make no changes
		}

		return $_SESSION[$primary_name];
	}

}
