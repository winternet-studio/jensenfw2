<?php
/**
 * Methods related to logging
 */

namespace winternet\jensenfw2;

class logging {
	public static function class_defaults() {
		$cfg = [];

		$corecfg = core::get_class_defaults('core');
		$cfg['log_actions_db_name'] = $corecfg['databases'][1]['db_name'];
		$cfg['log_actions_db_table'] = 'log_actions';

		return $cfg;
	}

	/**
	 * Log an action that happens in the system (eg. by a user or by some automated process)
	 *
	 * Example: `logging::log_action('', false, array('for_name' => '', 'for_userID' => 1), array('' => 1)  );`
	 *
	 * Requires the table 'log_actions' in the database:  (table name can be changed in config)
	 * ```
	 * CREATE TABLE `log_actions` (
	 *   `log_actionID` INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
	 *   `log_timestamp` DATETIME NULL DEFAULT NULL,
	 *   `log_by_userID` INT(10) UNSIGNED NULL DEFAULT NULL,
	 *   `log_by_name` VARCHAR(100) NULL DEFAULT NULL,
	 *   `log_emulated_by` VARCHAR(100) NULL DEFAULT NULL,
	 *   `log_action` VARCHAR(30) NULL DEFAULT NULL,
	 *   `log_subaction` VARCHAR(30) NULL DEFAULT NULL,
	 *   `log_for_userID` INT(10) UNSIGNED NULL DEFAULT NULL,
	 *   `log_for_name` VARCHAR(100) NULL DEFAULT NULL,
	 *   `log_parameters` MEDIUMTEXT NULL,
	 *   `log_ip` VARCHAR(50) NULL DEFAULT NULL,
	 *   `log_visitID` INT(10) UNSIGNED NULL DEFAULT NULL,
	 *   PRIMARY KEY (`log_actionID`),
	 *   INDEX `tempindx` (`log_action`, `log_for_userID`, `log_subaction`)
	 * );
	 * ```
	 *
	 * @param string $action : Main action keyword
	 * @param string $subaction : (opt.) Sub action keyword
	 * @param array $primary_parms : (opt.) An array with keys matching a table column name except the 'log_' prefix. Available by default are:
	 *   - `by_name` (text)
	 *   - `by_userID` (integer)
	 *   - `emulated_by` (text)
	 *   - `for_name` (text)
	 *   - `for_userID` (integer)
	 *   - `visitID` (integer)
	 * @param array|string $secondary_parms : Either an array or a string:
	 *   - array: have any keys and values, and will be formatted before they are all put into the parameters database field
	 *   - string: will be put directly into the parameters field
	 * @param array $options : Available options:
	 *   - `duplicate_window` : set this to the number of seconds within which to not register the logentry if it with already registered previously
	 *   - `reset_elapsed_time` : set this to true to reset the remaining time before registering the same logentry again when a duplicate entry was detected
	 *   - `visitID` : visitID to set in the log_visitID column
	 * @return integer|boolean : The operationID of the log entry that was created, or false if a duplicate entry was detected
	 */
	public static function log_action($action, $subaction = false, $primary_parms = [], $secondary_parms = false, $options = []) {
		// Don't register duplicate entries if requested
		if (is_numeric($options['duplicate_window'])) {
			if (@constant('YII_BEGIN_TIME') && PHP_SAPI != 'cli') {
				\Yii::$app->session->open();  //ensure session has been started
			}
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

		$logSQL_vars = [];

		$logSQL = "INSERT INTO `". $cfg['log_actions_db_name'] ."`.`". $cfg['log_actions_db_table'] ."` SET ";
		$logSQL .= "log_timestamp = '". gmdate('Y-m-d H:i:s') ."', ";
		$counter = 0;
		foreach ($primary_parms as $key => $value) {
			if ($value !== '' && $value !== null && $value !== false) {
				if (!preg_match("/^[a-z0-9_]+$/i", $key)) {
					core::system_error('Invalid primary parameter name for logging action.', ['Name' => $key]);
				} else {
					$counter++;
					$logSQL .= "`log_". $key ."` = :prim". $counter .", ";
					$logSQL_vars['prim'. $counter] = $value;
				}
			}
		}
		$logSQL .= "log_ip = :ip, ";
		$logSQL_vars['ip'] = ($_SERVER['HTTP_X_FORWARDED_FOR'] ? $_SERVER['HTTP_X_FORWARDED_FOR'] : $_SERVER['REMOTE_ADDR']);
		// Sometimes HTTP_X_FORWARDED_FOR can contain multiple addresses, eg. `212.98.86.98, 165.225.64.70`. Use the left-most one (according to wikipedia - I hope that's correct...!). See https://en.wikipedia.org/wiki/X-Forwarded-For#Format
		if (strpos($logSQL_vars['ip'], ',') !== false) {
			$logSQL_vars['ip'] = explode(',', $logSQL_vars['ip']);
			$logSQL_vars['ip'] = trim($logSQL_vars['ip'][0]);
		}
		$logSQL .= "log_action = :action, ";
		$logSQL_vars['action'] = $action;
		if ($subaction) {
			$logSQL .= "log_subaction = :subaction, ";
			$logSQL_vars['subaction'] = $subaction;
		}
		// Prepare secondary parameters for database
		if (is_array($secondary_parms)) {  //transform array into plain text
			if (count($secondary_parms) > 0) {
				$logSQL .= "log_parameters = :parms, ";
				$logSQL_vars['parms'] = json_encode($secondary_parms);
			}
		} elseif ($secondary_parms) {  //insert as plain text
			$logSQL .= "log_parameters = :parms, ";
			$logSQL_vars['parms'] = $secondary_parms;
		}
		$logSQL = substr($logSQL, 0, strlen($logSQL)-2);

		if (@constant('YII_BEGIN_TIME')) {
			// Using Yii framework
			\Yii::$app->db->createCommand($logSQL, $logSQL_vars)->execute();
			$new_operationID = \Yii::$app->db->getLastInsertID();
		} else {
			// Not using Yii framework
			core::require_database($cfg['db_server_id']);
			$logSQL = preg_replace("/ = :\\b/", ' = ?', $logSQL);
			$logSQL = core::prepare_sql($logSQL, $logSQL_vars);
			$new_operationID = core::database_result(['server_id' => $cfg['db_server_id'], $logSQL], false, 'Database query failed for making a log entry.');
		}

		return $new_operationID;
	}
}
