<?php
/**
 * Functions related to the dealing with databases
 */

namespace winternet\jensenfw2;

class database {

	/**
	 * @param string $table : Name of table
	 * @param array $options : Available options:
	 *   - `information_schema_output` : Output from "SELECT * FROM information_schema.columns WHERE table_schema = 'databasename' AND table_name = 'tablename'". If not provided it's determined automatically.
	 *   - `database` : Name of database (use if it's not the default one)
	 *   - `server_id` : Database server ID if not 0
	 *   - `ignore_unknown_types` : Set true to ignore unknown column types
	 */
	public static function get_mysql_table_schema($table, $options = []) {
		$schema = (object) [
			'database' => null,
			'primary_keys' => [],
			'columns' => [],
		];

		if ($information_schema_output) {
			$columns = $information_schema_output;
		} else {
			core::require_database($options['server_id'] ?? 0);
			if (empty($options['database'])) {
				$options['database'] = core::get_default_database_name($options['server_id'] ?? 0);
			}
			$columns = core::database_result(core::prepare_sql("SELECT * FROM information_schema.columns WHERE table_schema = ?dbname AND table_name = ?tblname", ['dbname' => $options['database'], 'tblname' => $table]));
			// $columns = core::database_result("DESCRIBE `". str_replace('`', '', $table) ."`");  //doesn't provide enough details (eg. missing comment - but is also harder to parse)
		}

		foreach ($columns as $column) {
			$is_primkey = false;
			if ($column['COLUMN_KEY'] === 'PRI') {
				$schema->primary_keys[] = $column['COLUMN_NAME'];
				$is_primkey = true;
			}

			$length = $unsigned = null;
			$is_text = $is_datetime = $is_number = false;
			$type = $column['DATA_TYPE'];
			if ($type === 'timestamp') {  //don't distinguish between timestamp and datetime
				$type = 'datetime';
			}
			if (in_array($column['DATA_TYPE'], ['varchar', 'char', 'tinytext', 'text', 'mediumtext', 'longtext'], true)) {
				$is_text = true;
				$length = $column['CHARACTER_MAXIMUM_LENGTH'];
			} elseif (in_array($column['DATA_TYPE'], ['datetime', 'date', 'time', 'timestamp'], true)) {
				$is_datetime = true;
			} elseif (in_array($column['DATA_TYPE'], ['tinyint', 'smallint', 'mediumint', 'int', 'bigint', 'float', 'double', 'decimal'], true)) {
				$is_number = true;
				if (strpos($column['COLUMN_TYPE'], 'unsigned') !== false) {
					$unsigned = true;
				} else {
					$unsigned = false;
				}
			} elseif ($column['DATA_TYPE'] === 'enum') {
					$is_text = true;
				if (preg_match("/^enum\\('(.*)'\\)$/", $column['COLUMN_TYPE'], $match)) {
					$values = explode("','", $match[1]);
					$values = array_map(function($item) {
						return str_replace("''", "'", $item);
					}, $values);
				} else {
					core::system_error('Failed to read possible enum values of column '. $column['COLUMN_NAME'] .' in table '. $table .' is not yet supported.');
				}
					} else {
				if (empty($options['ignore_unknown_types'])) {
					core::system_error('Database column '. $column['COLUMN_NAME'] .' of type '. $column['DATA_TYPE'] .' in table '. $table .' is not yet supported.');
				}
			}

			$default = $column['COLUMN_DEFAULT'];
			if ($default === 'NULL') {
				$default = null;
			}
			if ($is_text && $default !== null) {
				$default = trim($default, "'");
			}

			$schema->columns[ $column['COLUMN_NAME'] ] = (object) [
				'name' => $column['COLUMN_NAME'],  // string
				'type' => $type,  // 'tinytext', 'text', 'mediumtext', 'longtext', 'datetime', 'date', 'time', 'float', 'double', 'varchar', 'char', 'tinyint', 'smallint', 'mediumint', 'int', 'bigint', 'decimal' or 'enum'
				'raw_type' => $column['DATA_TYPE'],  // eg. retains the value `timestamp` instead of converting it to `datetime`
				'length' => $length,  // int or null
				'is_text' => $is_text,  // boolean
				'is_number' => $is_number,  // boolean
				'is_unsigned' => $unsigned,  // boolean for number columns only, otherwise null
				'is_datetime' => $is_datetime,  // boolean
				'is_primkey' => $is_primkey,  // boolean
				'is_autoincrement' => ($column['EXTRA'] && strpos($column['EXTRA'], 'auto_increment') !== false ? true : false),  // boolean
				'default' => $default,
				'raw_default' => $column['COLUMN_DEFAULT'],  // might contain the string `NULL` and will have single qoutes around the value when field is a text field
				'allow_null' => ($column['IS_NULLABLE'] === 'YES' ? true : false),  // boolean
				'allowed_values' => ($type === 'enum' ? $values : null),  // null or array
				'comment' => $column['COLUMN_COMMENT'],  // string
			];

			$schema->database = $options['database'];
		}
		return $schema;
	}

}
