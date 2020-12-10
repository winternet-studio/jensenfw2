<?php
/**
 * Functions related to syncing data between Salesforce.com and another database
 *
 * NOTE: the term "our" refers to our own data and database whereas "their" or "sf" refers to Salesforce data and objects.
 *
 * Main methods to use are:
 * - send_to_salesforce()
 * - receive_from_salesforce()
 * - sync_entire_table_to_salesforce()
 */

namespace winternet\jensenfw2;

use winternet\jensenfw2\core;
use winternet\jensenfw2\datetime;
use winternet\jensenfw2\salesforce;

class salesforce_sync {
	// Config variables
	var $client_id;
	var $client_secret;
	var $username;
	var $password;
	var $security_token;
	var $login_uri;
	var $api_version;
	var $token_storage_instance;
	var $enterprise_wsdl_path;

	// Runtime variables
	var $debug = false;
	var $soap_connection = null;
	var $rest_connection = null;
	var $logging_instance = null;
	var $exec_curl_log_callback = null;
	var $cached_existing_records = [];

	/**
	 * @param string $token_storage_instance : Class with these methods:
	 *   - `saveToken($access_token, $instance_url)` which returns nothing
	 *   - `getToken()` which returns eg. `['access_token' => 'rELHinuBmp9i98HBV4h7mMWVh', 'instance_url' => 'https://na30.salesforce.com']`
	 */
	public function __construct($client_id, $client_secret, $username, $password, $security_token, $login_uri, $api_version, $enterprise_wsdl_path, $token_storage_instance = null) {
		$this->client_id = $client_id;
		$this->client_secret = $client_secret;
		$this->username = $username;
		$this->password = $password;
		$this->security_token = $security_token;
		$this->login_uri = $login_uri;
		$this->api_version = $api_version;
		$this->token_storage_instance = $token_storage_instance;
		$this->enterprise_wsdl_path = $enterprise_wsdl_path;

		if ($token_storage_instance !== null) {
			$token = $token_storage_instance->getToken();
			if (!empty($token)) {
				// assume that the token is valid
				$this->auth_response['access_token'] = $token['access_token'];
				$this->auth_response['instance_url'] = $token['instance_url'];
			}
		}
	}

	public function connect_salesforce_rest() {
		// Connect to Salesforce REST API
		if (!$this->rest_connection) {
			if ($this->debug == 2) {
				core::$is_dev = true;
			}
			$this->rest_connection = new salesforce($this->client_id, $this->client_secret, $this->username, $this->password, $this->security_token, $this->login_uri, $this->api_version, $this->token_storage_instance);
			if ($this->exec_curl_log_callback) {
				$this->rest_connection->exec_curl_log_callback = $this->exec_curl_log_callback;
			}
			$this->rest_connection->authenticate();
		}

		return $this->rest_connection;
	}

	public function connect_salesforce_soap() {
		// Connect to Salesforce SOAP API
		if (!$this->soap_connection) {
			if (!$this->username) {
				core::system_error('Salesforce credentials have not been defined.');
			}

			if (is_callable($this->exec_curl_log_callback)) {
				$starttime = microtime(true);
			}

			if (!class_exists('\Phpforce\SoapClient\ClientBuilder', true)) {
				system_error('Salesforce SOAP API Client is not available. Please install composer package phpforce/soap-client.', []);
			}

			$builder = new \Phpforce\SoapClient\ClientBuilder($this->enterprise_wsdl_path, $this->username, $this->password, $this->security_token);
			$this->soap_connection = $builder->build();
			$this->soap_connection->login($this->username, $this->password, $this->security_token);

			if (is_callable($this->exec_curl_log_callback)) {
				$data = [
					'url' => 'authenticate',
					// 'type' => null,
					'duration' => round(microtime(true) - $starttime, 3),
					// 'http_code' => null,
					// 'request_size' => strlen($this->soap_connection->soapClient->__getLastRequest()),   //CAN'T USE THIS UNTIL THE LIBRARY CHANGES soapClient TO PUBLIC INSTEAD OF PROTECTED
					// 'response_size' => strlen($this->soap_connection->soapClient->__getLastResponse()),   //CAN'T USE THIS UNTIL THE LIBRARY CHANGES soapClient TO PUBLIC INSTEAD OF PROTECTED
					'source' => 'SOAP',
				];
				call_user_func($this->exec_curl_log_callback, $data);
			}
		}

		return $this->soap_connection;
	}

	/**
	 * Send a single record to Salesforce to be added/updated/deleted there
	 *
	 *
	 * !!! IMPORTANT NOTE !!!
	 * Do not terminate script when using system_error() but make sure notification is sent to developer instead AND exit the function so that the rest of the code is not executed
	 * Since this is never a critical issue for our site, we don't want to terminate other actions following the call to this function
	 *
	 *
	 * @param string $action : Type of operation: `insert`, `update`, `delete` or `replace`. Replace will insert if record doesn't already exist, otherwise update.
	 * @param string $our_table : Full database table name (in our database) of record(s) to send to Salesforce
	 * @param integer $our_id : Our primary key value for the given record to send
	 * @param array $previous_values : Associative array with the previous record values, keys being our table column names and the values being their value
	 *   - set to null if the previous values are not known
	 *   - not necessary when action=delete
	 * @param array $new_values : Associative array with the new record values, keys being our table column names and the values being their value
	 *   - if $previous_values was provided only changed fields will be sent to salesforce
	 *   - not necessary when action=delete
	 */
	public function send_to_salesforce($config_instance, $action, $our_table, $our_id, $previous_values = [], $new_values = []) {
		if (!in_array($action, ['insert', 'update', 'delete', 'replace'], true)) {
			core::system_error('Invalid action for sending data to Salesforce.', false, ['xsilent' => true, 'xterminate' => false, 'xnotify' => 'developer', 'xsevere' => 'WARNING']);
			return;
		}

		$not_found = false;


		if ($action != 'delete') {
			if ($previous_values === null) {
				$fields = $new_values;
			} else {
				$fields = $this->fields_updated($config_instance, $our_table, $previous_values, $new_values);
				if (empty($fields)) {
					// No changes found in the fields that we are synchronizing with Salesforce => do nothing
					return;
				}
			}
		}


		$object_map = $config_instance->object_config();

		if (empty($object_map[$our_table])) {
			core::system_error('Our table not configured for sending data to Salesforce.', false, ['xsilent' => true, 'xterminate' => false, 'xnotify' => 'developer', 'xsevere' => 'WARNING']);
			return;
		}

		$sf = $this->connect_salesforce_rest();

		$is_contact = $sf_accountId = false;
		if ($object_map[$our_table]['sf_object'] == 'Contact') {
			$is_contact = true;
		}

		// Get the Salesforce ID of the record to deal with
		if ($action !== 'insert') {
			$result = $sf->execute_soql('SELECT Id'. ($is_contact ? ', AccountId' : '') .' FROM '. $object_map[$our_table]['sf_object'] .' WHERE '. $object_map[$our_table]['our_primkey_sf_field'] .' = '. (int) $our_id);
			if (empty($result)) {
				core::system_error('Failed to get Salesforce record ID when sending data to Salesforce.', ['SOQL result' => $result], ['xsilent' => true, 'xterminate' => false, 'xnotify' => 'developer', 'xsevere' => 'WARNING']);
				return;
			} elseif ((int) $result['totalSize'] == 0) {
				// NOTE: in case $action = 'delete' this error doesn't matter actually - could even skip raising it if we experience it more
				if ($action == 'replace') {
					$action = 'insert';
					$not_found = true;
				} else {
					core::system_error('Failed to get Salesforce record ID when sending data to Salesforce. No record having our primary key value was found.', ['SOQL result' => $result], ['xsilent' => true, 'xterminate' => false, 'xnotify' => 'developer', 'xsevere' => 'WARNING']);
					return;
				}
			} elseif ((int) $result['totalSize'] > 1) {
				core::system_error('Failed to get Salesforce record ID when sending data to Salesforce. Multiple records have our primary key value!', ['SOQL result' => $result], ['xsilent' => true, 'xterminate' => false, 'xnotify' => 'developer', 'xsevere' => 'WARNING']);
				return;
			}

			if ($action == 'replace') {  //if we get here and this condition is true it means that the record exists and we just need to do an update
				$action = 'update';
			}

			if (!$not_found) {
				$sf_id = $result['records'][0]['Id'];
				if ($is_contact) {
					$sf_accountId = $result['records'][0]['AccountId'];
				}
			}
		}

		if ($action != 'delete') {
			$fk_records = [];
			if (!empty($object_map[$our_table]['foreign_key_tables'])) {
				foreach ($object_map[$our_table]['foreign_key_tables'] as $fk_field => $fk_table) {
					$fk_records[$fk_table] = $this->get_existing_records($object_map[$fk_table]['sf_object'], $object_map[$fk_table]['our_primkey_sf_field'], $object_map[$fk_table]['lastmodified_sf_field'], $object_map[$fk_table]['our_timestamp_timezone'], [ $new_values[$fk_field] ]);
				}
			}

			$field_map = $config_instance->field_conversion_to_salesforce($this, $our_table, $fk_records);

			if ($this->debug) {
				file_put_contents('dump_sf_fields.txt', print_r($fields, true) ."\r\n--------------------- line ". __LINE__ ." in ". __FILE__ ." at ". date('Y-m-d H:i:s') ."\r\n\r\n\r\n", FILE_APPEND);
			}

			$sf_fields = [];
			foreach ($fields as $sh_colname => $field) {
				foreach ($field_map as $fld_cfg) {
					if ($fld_cfg['trigger_field'] == $sh_colname) {
						$conversion = $this->convert_value_to_salesforce($action, $fld_cfg, $fields);
						if ($conversion === '__skip_field') {
							continue;
						} elseif ($conversion === '__skip_record') {
							return;
						}
						$sf_fields[ $fld_cfg['sf_field'] ] = $conversion;
					} elseif (substr($fld_cfg['trigger_field'], 0, 1) === '*' && !$done_autofield[$fld_cfg['sf_field']]) {
						if (($fld_cfg['trigger_field'] === '*insert' && $action == 'insert') || ($fld_cfg['trigger_field'] === '*update' && $action == 'update') || $fld_cfg['trigger_field'] === '*') {
							$sf_fields[ $fld_cfg['sf_field'] ] = $this->convert_value_to_salesforce($action, $fld_cfg, $fields);
							$done_autofield[$fld_cfg['sf_field']] = true;
						}
					}
				}
			}

			if ($this->debug) {
				file_put_contents('dump_sf_fields.txt', print_r($fields, true) ."\r\n--------------------- line ". __LINE__ ." in ". __FILE__ ." at ". date('Y-m-d H:i:s') ."\r\n\r\n\r\n", FILE_APPEND);
			}
		}

		try {
			if ($action == 'insert') {
				$o = $sf->create($object_map[$our_table]['sf_object'], $sf_fields);
				// $o is associative array: ['id' => 'a153600000527ubAAA', 'success' => true, 'errors' => []]
				if ($this->logging_instance) {
					$sf_fields['___SALESFORCE_ID'] = $o['id'];
					$this->logging_instance->save('to_salesforce', 'insert', $our_table, $our_id, $sf_fields);
				}
			} elseif ($action == 'update') {
				if (!empty($sf_fields)) {  //in case we only modify fields that aren't replicated to Salesforce
					$o = $sf->update($object_map[$our_table]['sf_object'], $sf_id, $sf_fields);
					// $o is null (nothing is returned)
					if ($this->logging_instance) {
						$sf_fields['___SALESFORCE_ID'] = $sf_id;
						$this->logging_instance->save('to_salesforce', 'update', $our_table, $our_id, $sf_fields);
					}
				}
			} elseif ($action == 'delete') {
				$o = $sf->delete($object_map[$our_table]['sf_object'], $sf_id);
				// $o is a boolean
				if ($o) {
					// If deleting a Contact, also delete the Account if it has no other Contacts
					if ($is_contact && $sf_accountId) {
						$contacts_count = $sf->execute_soql("SELECT count() FROM Contact WHERE AccountId = '". $sf_accountId ."'");
						if ($contacts_count['totalSize'] == 0) {  //if no other Contacts...
							$o2 = $sf->delete('Account', $sf_accountId);
						}
					}

					if ($this->logging_instance) {
						$this->logging_instance->save('to_salesforce', 'delete', $our_table, $our_id, $sf_id);
					}
				}
			} else {
				core::system_error('Invalid action for sending data to Salesforce', false, ['xsilent' => true, 'xterminate' => false, 'xnotify' => 'developer', 'xsevere' => 'WARNING']);
				return;
			}
		} catch (\Exception $e) {
			core::system_error('Failed to send record to Salesforce.', ['Salesforce fields' => $sf_fields, 'Exception' => $e], ['xsilent' => true, 'xterminate' => false, 'xnotify' => 'developer', 'xsevere' => 'WARNING']);
			return;
		}
	}

	/**
	 * Receive a single record from Salesforce to be added/updated/deleted here
	 */
	public function receive_from_salesforce() {
		core::system_error('METHOD NOT YET IMPLEMENTED');
	}

	/**
	 * Synchronize an entire table of ours to Salesforce
	 *
	 * Must be run in CLI mode.
	 *
	 * @param string $our_table : Name of our database table in our database (req.)
	 * @param string $sf_object : Name of Salesforce object (req.)
	 * @param string $our_primkey : Name of our primary key field in our database (req.)
	 * @param array $field_map : Array where the values contain an associative array according to the argument $field_cfg of the function convert_value_to_salesforce() (req.)
	 * @param array $existing_records : Output from get_existing_records() (req.)
	 */
	public function sync_entire_table_to_salesforce($config_instance, $our_table, $field_map, $existing_records) {
		if (PHP_SAPI !== 'cli') {
			core::system_error('Salesforce table sync must be done from command line.');
		}


		$object_map = $config_instance->object_config();
		if (empty($object_map[$our_table])) {
			core::system_error('Our table not configured for syncing entire table to Salesforce.');
			return;
		}

		$sf_object = $object_map[$our_table]['sf_object'];
		$our_primkey = $object_map[$our_table]['our_primkey_our_table'];
		$our_lastmodified = $object_map[$our_table]['lastmodified_our_field'];  //name of field containing the "last modified" timestamp (if not provided all records will be updated)

		core::require_database();

		if (!is_array($existing_records)) {
			core::system_error('Existing records is not an array for synchronizing table to Salesforce.');
		}

		$max_records_per_request = 200;  //Source: http://www.salesforce.com/us/developer/docs/api/Content/sforce_api_calls_create.htm
		$sfrecords = [
			'add' => [],
			'update' => [],
		];

		$show_nonchanged = false;

		echo PHP_EOL . PHP_EOL ."Synchronizing ". $our_table . PHP_EOL;

		// Get all records from our database and check each one if they need to be updated or added
		$sql  = "SELECT * ";
		$sql .= "FROM ". $our_table ." ";
		$sql .= "ORDER BY ". $our_primkey ." ";
		$records =& core::database_query($sql, 'Database query failed for getting local records failed.');
		if (mysqli_num_rows($records) > 0) {

			$this->connect_salesforce_soap();

			$nonupdated_count = $updated_count = 0;
			while ($dbrecord = mysqli_fetch_assoc($records)) {

				$primkeyID = $dbrecord[$our_primkey];

				$log_msg = '';
				if (!empty($existing_records[$primkeyID])) {
					if (!$our_lastmodified) {
						$action = 'update';
						$log_msg = ' ALWAYS UPDATE';
					} elseif ($dbrecord[$our_lastmodified] != $existing_records[$primkeyID]['our_last_modified']) {
						$action = 'update';
						$log_msg = ' (Our:'. $dbrecord[$our_lastmodified] .' <> SF:'. $existing_records[$primkeyID]['our_last_modified'] .')';
					} else {
						$action = false;
						$log_msg = ' ('. $dbrecord[$our_lastmodified] .' = '. $existing_records[$primkeyID]['our_last_modified'] .')';
					}
				} else {
					$action = 'add';
				}

				if ($action) {
					$is_nonchanged = false;

					$recindex = count($sfrecords[$action]);  //because the count starts from 1 and indexes from 0 we don't have to add one to get the new index

					echo PHP_EOL . ucfirst($action) ."";
					if ($action == 'add') {
						echo '......';
					} elseif ($action == 'update') {
						echo '...';
					}
					echo ': '. $our_primkey .' '. $primkeyID .' ...';

					//NOTE: even set empty fields so that info is correctly removed when record is updated
					$sfrecords[$action][$recindex] = new \stdClass();
					if ($action == 'update') {
						$sfrecords[$action][$recindex]->Id = $existing_records[$primkeyID]['salesforce_id'];
					}

					foreach ($field_map as $fld_cfg) {
						$sf_field = $fld_cfg['sf_field'];
						$conversion = $this->convert_value_to_salesforce($action, $fld_cfg, $dbrecord);
						if ($conversion === '__skip_field') {
							continue;  // Salesforce does not allow trying to update foreign key fields (Master-Detail field) even if the value is the same
						} elseif ($conversion === '__skip_record') {
							echo ' Skipped due to receiving a "dont sync" flag';
							unset($sfrecords[$action][$recindex]);
							continue 2;
						}
						$sfrecords[$action][$recindex]->{$sf_field} = $conversion;
					}

					// Set which fields need to have their values removed (this must also be done when $action == 'add')
					$sfrecords[$action][$recindex]->fieldsToNull = [];
					foreach ($sfrecords[$action][$recindex] as $key => $d) {
						if ($d === null || $d === '') {
							$sfrecords[$action][$recindex]->fieldsToNull[] = $key;
/*
WHAT IS THIS ABOUT? The line below was uncommented when I started looking at this script again...
						// For some reason Email is "forwarded" to npe01__WorkEmail__c and that is the field that must be NULLed when value should be removed!
						if ($key == 'Email') {
	#						$sfrecords[$action][$recindex]->fieldsToNull[] = 'npe01__WorkEmail__c';
						}
*/
							unset($sfrecords[$action][$recindex]->$key);
						}
					}

					if (count($sfrecords[$action]) >= $max_records_per_request) {
						$this->do_salesforce_addupdate_request($action, $sf_object, $sfrecords[$action]);
						$sfrecords[$action] = [];  //reset for next batch
					}

					echo ' Done';
					$updated_count++;
				} else {
					$is_nonchanged = true;
					if ($show_nonchanged) {
						echo PHP_EOL ."No change for #". $primkeyID;
					}
					$nonupdated_count++;
				}
				if ($is_nonchanged && !$show_nonchanged) {
					// don't show anything
				} else {
					#echo ' -- '. str_pad($dbrecord['legal_firstname'] .' '. $dbrecord['legal_lastname'], 28);
					echo '  '. $log_msg;
				}

				if (isset($existing_records[$primkeyID])) {
					unset($existing_records[$primkeyID]);
				}
			}
		}

		// Do the remaining ones
		if (count($sfrecords['add']) > 0) {
			$this->do_salesforce_addupdate_request('add', $sf_object, $sfrecords['add']);
		}
		if (count($sfrecords['update']) > 0) {
			$this->do_salesforce_addupdate_request('update', $sf_object, $sfrecords['update']);
		}

		// Delete the contacts that are no longer found in our website database
		if (count($existing_records) > 0) {
			// TODO: don't we need to split it up into multiple requests if there are more than 200 records to be deleted?
			$delete_IDs = [];
			foreach ($existing_records as $curr_recordID => $c_data) {
				echo PHP_EOL ."Delete...";
				echo ": ". $our_primkey ." ". $curr_recordID ."   (Salesforce ID ". $c_data['salesforce_id'] .")";
				$delete_IDs[] = $c_data['salesforce_id'];
				$updated_count++;
			}

			if (is_callable($this->exec_curl_log_callback)) {
				$starttime = microtime(true);
			}
			$response = $this->soap_connection->delete($delete_IDs);
			if (is_callable($this->exec_curl_log_callback)) {
				$data = [
					'url' => 'delete-'. json_encode($delete_IDs),
					// 'type' => null,
					'duration' => round(microtime(true) - $starttime, 3),
					// 'http_code' => null,
					// 'request_size' => strlen($this->soap_connection->soapClient->__getLastRequest()),
					// 'response_size' => strlen($this->soap_connection->soapClient->__getLastResponse()),
					'source' => 'SOAP',
				];
				call_user_func($this->exec_curl_log_callback, $data);
			}

			foreach ($response as $result) {
				if ( ! $result->isSuccess()) {
					core::system_error('Failed to delete '. $sf_object .' from Salesforce.', ['Salesforce ID' => $result->id, 'Err msg' => print_r($result->errors, true) ]);
				}
			}
			echo ' Done with all.';
		}

		echo  PHP_EOL ."Updated records: ". $updated_count;
		echo  PHP_EOL ."Non-updated records: ". $nonupdated_count;
	}

	public function set_logging($logging_instance) {
		$this->logging_instance = $logging_instance;
	}

	private function do_salesforce_addupdate_request($action, $sf_object, $records) {
		if ($action == 'add') {
			// NOTE: enable this to debug the XML sent to Salesforce
			// try {

				if (is_callable($this->exec_curl_log_callback)) {
					$starttime = microtime(true);
				}
				$rsp = $this->soap_connection->create($records, $sf_object);
				if (is_callable($this->exec_curl_log_callback)) {
					$data = [
						'url' => 'create-'. $sf_object,
						// 'type' => null,
						'duration' => round(microtime(true) - $starttime, 3),
						// 'http_code' => null,
						// 'request_size' => strlen($this->soap_connection->soapClient->__getLastRequest()),
						// 'response_size' => strlen($this->soap_connection->soapClient->__getLastResponse()),
						'source' => 'SOAP',
					];
					call_user_func($this->exec_curl_log_callback, $data);
				}

			// } catch (\Exception $e) {
			// 	file_put_contents('dump.txt', print_r($this->soap_connection->soapClient->__getLastRequest(), true) . PHP_EOL ."--------------------- line ". __LINE__ ." in ". __FILE__ ." at ". date('Y-m-d H:i:s') . PHP_EOL . PHP_EOL . PHP_EOL, FILE_APPEND);
			// }
		} elseif ($action == 'update') {
			if (is_callable($this->exec_curl_log_callback)) {
				$starttime = microtime(true);
			}
			$rsp = $this->soap_connection->update($records, $sf_object);
			if (is_callable($this->exec_curl_log_callback)) {
				$data = [
					'url' => 'update-'. $sf_object,
					// 'type' => null,
					'duration' => round(microtime(true) - $starttime, 3),
					// 'http_code' => null,
					// 'request_size' => strlen($this->soap_connection->soapClient->__getLastRequest()),
					// 'response_size' => strlen($this->soap_connection->soapClient->__getLastResponse()),
					'source' => 'SOAP',
				];
				call_user_func($this->exec_curl_log_callback, $data);
			}
		}

		$success_count = 0;
		$errors = [];
		foreach ($rsp as $curr_record) {
			if ( ! $curr_record->isSuccess()) {
				$errors[] = ['msg' => $curr_record->errors];
			} else {
				$success_count++;
			}
		}
		if (count($errors) > 0) {
			core::system_error('Failed to add/update '. $sf_object .' in Salesforce.', ['Success count' => $success_count .' of '. count($rsp), 'Action' => $action, 'SF object' => $sf_object, 'Err msg' => print_r($errors, true) ]);
		}
	}

	/**
	 * Get existing records from Salesforce for a given object (= database table)
	 *
	 * @param string $sf_object : Salesforce object to retrieve existing records from
	 * @param string $sf_primkey : Salesforce field name holding the value of our own primary key (eg. `MyPhpSystem_Person_ID__c`)
	 * @param string $sf_lastmodified : Salesforce field name holding the value of our last modified timestamp (eg. `MyPhpSystem_LastModified__c`)
	 * @param string $our_timestamp_timezone : If our data is not in UTC provide the PHP timezone string that must be applied to convert the timestamps to UTC (otherwise set null or false)
	 */
	public function get_existing_records($sf_object, $sf_primkey, $sf_lastmodified, $our_timestamp_timezone, $onlyIDs = null) {
		// Field list is found in WSDL (or standard fields: https://na14.salesforce.com/p/setup/layout/LayoutFieldList?type=Contact&setupid=ContactFields&retURL=%2Fui%2Fsetup%2FSetup%3Fsetupid%3DContact)
		// REMEMBER! New custom fields must be added to the WSDL (= regenerated) before they are visible here!
		$query  = "SELECT Id, ". $sf_primkey;
		if ($sf_lastmodified) {
			$query .= ", ". $sf_lastmodified;
		}
		$query .= " FROM ". $sf_object;  // SF field: LastModifiedDate
		if ($onlyIDs !== null) {

			// Use cached data if available
			$cache_key = $sf_object .'--'. $sf_primkey .'--'. json_encode($onlyIDs);
			if ($this->cached_existing_records[$cache_key]) {
				return $this->cached_existing_records[$cache_key];
			}

			$query .= " WHERE";
			foreach ($onlyIDs as $id) {
				if (is_numeric($id)) {
					$query .= " ". $sf_primkey ." = ". sql_esc($id) ." OR";
				} else {
					$query .= " ". $sf_primkey ." = '". sql_esc($id) ."' OR";
				}
			}
			$query = substr($query, 0, -3);
		}

		$this->connect_salesforce_soap();

		if (is_callable($this->exec_curl_log_callback)) {
			$starttime = microtime(true);
		}

		$results = $this->soap_connection->query($query);
		if (is_callable($this->exec_curl_log_callback)) {
			$data = [
				'url' => 'query : '. $query,
				// 'type' => null,
				'duration' => round(microtime(true) - $starttime, 3),
				// 'http_code' => null,
				// 'request_size' => strlen($this->soap_connection->soapClient->__getLastRequest()),  //NOTE: \Phpforce\SoapClient\Client->soapClient must first be modified to make it public instead of protected
				// 'response_size' => strlen($this->soap_connection->soapClient->__getLastResponse()),
				'source' => 'SOAP',
			];
			call_user_func($this->exec_curl_log_callback, $data);
		}

		$existing = [];
		foreach ($results as $record) {  //the iterator automatically do additional calls to fetch all records when the total is more than 2000
			if (!property_exists($record, $sf_primkey)) {
				core::system_error('Property holding value of our primary key does not exist '. $sf_object .' in Salesforce.', ['PrimKey' => $sf_primkey, 'More' => 'Maybe we forgot to generate new WSDL?']);
			}
			if (is_numeric($record->{$sf_primkey})) {  //don't touch records in Salesforce that don't have an integer reference to the primary key in our own table
				if ($record->$sf_lastmodified) {
					$existing[$record->{$sf_primkey}] = [
						'our_last_modified' => ($our_timestamp_timezone != 'system' && $our_timestamp_timezone != date_default_timezone_get() ? datetime::change_timestamp_timezone($record->$sf_lastmodified->format('Y-m-d H:i:s'), date_default_timezone_get(), $our_timestamp_timezone) : $record->$sf_lastmodified->format('Y-m-d H:i:s')),   //get the timestamp in our own timezone (OBS!! Phpforce\SoapClient automatically converts timestamp to the system timezone!)
						'salesforce_id' => $record->Id,
					];
				} else {
					$existing[$record->{$sf_primkey}] = [
						'our_last_modified' => null,
						'salesforce_id' => $record->Id,
					];
				}
			}
		}

		if ($onlyIDs !== null) {  //didn't dare to try caching full table recordsets because don't they potentially get big?
			// Cache the result
			$this->cached_existing_records[$cache_key] = $existing;
		}

		return $existing;
	}

	public function fields_updated($config_instance, $our_table, $oldinfo, $newinfo) {
		$changes = [];

		$fields = $config_instance->fields_to_sync($our_table);
		foreach ($fields as $field) {
			if ($oldinfo === null || (string) $oldinfo[$field] !== (string) $newinfo[$field]) {
				$changes[$field] = $newinfo[$field];
			}
		}
		return $changes;
	}

	/**
	 * Find the Salesforce ID of a given record from a list of existing records
	 *
	 * @param array $existing_records : Output from get_existing_records()
	 * @param integer|string $our_ID : ID from our database that we need to find the Salesforce ID of
	 */
	public function get_salesforce_record_id($existing_records, $our_ID, $skip_if_not_found = false) {
		if (empty($our_ID)) {
			core::system_error('Missing our ID for finding Salesforce ID.'. json_encode($existing_records[0]));
		}

		foreach ($existing_records as $curr_our_ID => $r) {
			if ($curr_our_ID == $our_ID) {
				return $r['salesforce_id'];
				break;
			}
		}
		if ($skip_if_not_found) {
			return null;
		} else {
			core::system_error('Failed to find Salesforce ID');
		}
	}

	/**
	 * Convert a given field value of ours to SalesForce's field value
	 *
	 * @param string $action : `insert` or `update` or `delete`
	 * @param array $field_cfg : Array where the values contain an associative array with keys:
	 *   - `trigger_field` (req.) : name of our field that was changed and causes this Salesforce field to be updated
	 *     - set to `*insert` to always set this value when inserting a record
	 *     - set to `*update` to always set this value when updating a record
	 *     - set to `*` to always set this value when inserting or updating a record
	 *   - `sf_field` (req.) : name of field in Salesforce
	 *   - `our_field` (req.) : name of field in our database (can actually be left out if 'conversion' is a callback function or 'fixed_value' is set)
	 *     - if the same as trigger_field field it can be set to `>trigger`
	 *   - `fixed_value` (opt.) : a fixed value to be set
	 *   - `conversion` (opt.) : method of how to convert our value to a Salesforce valid value. Possible options:
	 *     - `null_if_empty_string` : convert empty string ("") to NULL, otherwise return original value
	 *     - `date_to_checkbox` : convert a timestamp to a checkbox value in Salesforce
	 *     - `date_to_iso8601` : convert timestamp field from our timezone to UTC ('timezone' must then be set)
	 *     - a callback function that receives one argument with the record in question and returns the value to be set in Salesforce
	 *         - to skip the record entirely return the string `__dont_sync_to_salesforce`
	 *   - `timezone` : PHP timezone value of this field or `system` to use server timezone (used by conversion=date_to_iso8601)
	 *   - `no_update` : set this on fields that are foreign keys to not update it's value and cause an error in Salesforce
	 * @param array $db_record : Associative array with our record, eg.: `['legal_firstname' => 'Allan', 'legal_lastname' => 'Jensen']`
	 *
	 * @return string : The new value OR string `__skip_field` OR string `__skip_record` which then needs to be processed appropriately
	 */
	public function convert_value_to_salesforce($action, $field_cfg, $db_record) {
		if ($action == 'update' && $field_cfg['no_update']) {
			return '__skip_field';
		}

		if ($field_cfg['our_field'] == '>trigger') {
			$field_cfg['our_field'] = $field_cfg['trigger_field'];
		}

		if ($field_cfg['conversion'] && is_callable($field_cfg['conversion'])) {
			$value = $field_cfg['conversion']($db_record);
			if ($value === '__dont_sync_to_salesforce') {
				return '__skip_record';
			}
		} elseif ($field_cfg['conversion'] == 'null_if_empty_string') {
			$value = ($db_record[ $field_cfg['our_field'] ] === '' ? null : $db_record[ $field_cfg['our_field'] ]);
		} elseif ($field_cfg['conversion'] == 'tinyint_checkbox') {  //conversion of 1 and 0 to true and false
			$value = ($db_record[ $field_cfg['our_field'] ] ? true : false);
		} elseif ($field_cfg['conversion'] == 'date_to_checkbox') {
			$value = ($db_record[ $field_cfg['our_field'] ] ? true : false);
		} elseif ($field_cfg['conversion'] == 'date_to_iso8601') {
			$value = $db_record[ $field_cfg['our_field'] ];

			// OBS!! Phpforce\SoapClient automatically convert timestamps to the system timezone!
			if ($field_cfg['timezone'] && $field_cfg['timezone'] != 'system' && $field_cfg['timezone'] != date_default_timezone_get()) {
				$value = datetime::change_timestamp_timezone($value, $field_cfg['timezone'], date_default_timezone_get(), 'c');
			} else {
				// only convert to ISO-8601
				$value = date(DATE_ATOM, strtotime($value));  // DATE_ATOM = 'c'
			}
		} elseif ($field_cfg['fixed_value']) {
			$value = $field_cfg['fixed_value'];
		} else {
			$value = $db_record[ $field_cfg['our_field'] ];
		}
		return $value;
	}

	public function convert_value_from_salesforce() {



	}

}
