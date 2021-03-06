<?php
/**
 * Functions related to logging of data sync between Salesforce.com and another database
 */

namespace winternet\jensenfw2;

abstract class salesforce_sync_logging_abstract {
	abstract public function save($direction, $action, $table, $id, $fields = null);

	public function fields_to_string($fields) {
		if ($fields === null) {
			return null;
		} elseif (is_string($fields)) {
			return $fields;
		} else {
			return json_encode($fields);
		}
	}
}
