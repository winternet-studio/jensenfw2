<?php
/*
This file contains functions related to logging of data sync between Salesforce.com and another database
*/

namespace winternet\jensenfw2;

abstract class salesforce_sync_logging_abstract {
	abstract public static function save($direction, $action, $table, $id, $fields = null);

	public static function fields_to_string($fields) {
		return json_encode($fields);
	}
}
