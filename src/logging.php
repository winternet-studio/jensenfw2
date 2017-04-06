<?php
/*
This file contains functions related to logging
*/
namespace winternet\jensenfw2;

class logging {
	public static function class_defaults() {
		$cfg = array();

		$corecfg = core::get_class_defaults('core');
		$cfg['log_actions_db_name'] = $corecfg['databases'][1]['db_name'];
		$cfg['log_actions_db_table'] = 'log_actions';

		return $cfg;
	}

	public static function log_action($action, $subaction = false, $primary_parms = false, $secondary_parms = false, $options = array() ) {
		/*
		DESCRIPTION:
		- log an action that happens in the system (eg. by a user or by some automated process)
		- example: logging::log_action('', false, array('for_name' => '', 'for_userID' => 1), array('' => 1)  );
		- requires the table 'log_actions' in the database:  (table name can be changed in config)
			CREATE TABLE `log_actions` (
				`log_actionID` INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
				`log_timestamp` DATETIME NULL DEFAULT NULL,
				`log_by_userID` INT(10) UNSIGNED NULL DEFAULT NULL,
				`log_by_name` VARCHAR(100) NULL DEFAULT NULL,
				`log_emulated_by` VARCHAR(100) NULL DEFAULT NULL,
				`log_action` VARCHAR(30) NULL DEFAULT NULL,
				`log_subaction` VARCHAR(30) NULL DEFAULT NULL,
				`log_for_userID` INT(10) UNSIGNED NULL DEFAULT NULL,
				`log_for_name` VARCHAR(100) NULL DEFAULT NULL,
				`log_parameters` MEDIUMTEXT NULL,
				`log_ip` VARCHAR(50) NULL DEFAULT NULL,
				`log_visitID` INT(10) UNSIGNED NULL DEFAULT NULL,
				PRIMARY KEY (`log_actionID`),
				INDEX `tempindx` (`log_action`, `log_for_userID`, `log_subaction`)
			);
		INPUT:
		- $action : main action keyword
		- $subaction (opt.) : sub action keyword
		- $primary_parms (opt.) : an array keys matching a table column name except the 'log_' prefix. Available by default are:
			- 'by_name' (text)
			- 'by_userID' (integer)
			- 'emulated_by' (text)
			- 'for_name' (text)
			- 'for_userID' (integer)
			- 'visitID' (integer)
		- $secondary_parms : either an array or a string:
			- array: have any keys and values, and will be formatted before they are all put into the parameters database field
			- string: will be put directly into the parameters field
		- $options : array with any of these keys:
			- 'duplicate_window' : set this to the number of seconds within which to not register the logentry if it with already registered previously
			- 'reset_elapsed_time' : set this to true to reset the remaining time before registering the same logentry again when a duplicate entry was detected
			- 'visitID' : visitID to set in the log_visitID column
		OUTPUT:
		- the operationID of the log entry that was created
		- or false if a duplicate entry was detected
		*/

		// Don't register duplicate entries if requested
		if (is_numeric($options['duplicate_window'])) {
			$session_varname = '_logentry_dedupe_'. md5($action .'-'. $subaction .'-'. json_encode($primary_parms) .'-'. json_encode($secondary_parms));
			if (!$_SESSION[$session_varname] || time()-$options['duplicate_window'] > $_SESSION[$session_varname]) {
				// Do register the log entry => continue
				$_SESSION[$session_varname] = time();
			} elseif ($_SESSION[$session_varname]) {
				// Do NOT register the log entry
				if ($options['reset_elapsed_time']) {
					// If logged again within the time window => update the timestamp to postpone another X seconds before registering again
					$_SESSION[$session_varname] = time();
				}
				return false;
			}
		}

		$cfg = core::get_class_defaults(__CLASS__);
		core::require_database();

		$logSQL = "INSERT INTO `". $cfg['log_actions_db_name'] ."`.`". $cfg['log_actions_db_table'] ."` SET ";
		$logSQL .= "log_timestamp = '". gmdate('Y-m-d H:i:s') ."', ";
		foreach ($primary_parms as $key => $value) {
			if ($value !== '' && $value !== null && $value !== false) {
				$logSQL .= "`log_". $key ."` = '". core::sql_esc($value) ."', ";
			}
		}
		$logSQL .= "log_ip = '". core::sql_esc($_SERVER['REMOTE_ADDR']) ."', ";
		$logSQL .= "log_action = '". core::sql_esc($action) ."', ";
		if ($subaction) {
			$logSQL .= "log_subaction = '". core::sql_esc($subaction) ."', ";
		}
		// Prepare secondary parameters for database
		if (is_array($secondary_parms)) {  //transform array into plain text
			if (count($secondary_parms) > 0) {
				$logSQL .= "log_parameters = '". core::sql_esc(json_encode($secondary_parms)) ."', ";
			}
		} elseif ($secondary_parms) {  //insert as plain text
			$logSQL .= "log_parameters = '". core::sql_esc($secondary_parms) ."', ";
		}
		$logSQL = substr($logSQL, 0, strlen($logSQL)-2);

		$new_operationID = core::database_result($logSQL, false, 'Database query failed for making a log entry.');
		return $new_operationID;
	}
}
