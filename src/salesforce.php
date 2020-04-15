<?php
/*
This file contains functions related to Salesforce.com via REST API
*/
namespace winternet\jensenfw2;

class salesforce {
	// Config variables
	var $client_id;
	var $client_secret;
	var $username;
	var $password;
	var $security_token;
	var $login_uri;
	var $api_version;
	var $token_storage_instance;

	// Runtime variables
	var $is_authenticated = false;
	var $auth_response = null;
	var $curl = null;
	var $exec_curl_log_callback = null;

	/**
	 * Constructor
	 *
	 * @param string $client_id
	 * @param string $client_secret
	 * @param string $username
	 * @param string $password
	 * @param string $security_token
	 * @param string $login_uri
	 * @param string $api_version
	 * @param string $token_storage_instance : Class with these methods:
	 *  - `saveToken($access_token, $instance_url)` which returns nothing
	 *  - `getToken()` which returns eg. `array('access_token' => 'rELHinuBmp9i98HBV4h7mMWVh', 'instance_url' => 'https://na30.salesforce.com')`
	 */
	public function __construct($client_id, $client_secret, $username, $password, $security_token, $login_uri, $api_version, $token_storage_instance = null) {
		$this->client_id = $client_id;
		$this->client_secret = $client_secret;
		$this->username = $username;
		$this->password = $password;
		$this->security_token = $security_token;
		$this->login_uri = $login_uri;
		$this->api_version = $api_version;
		$this->token_storage_class = $token_storage_instance;

		if ($token_storage_instance !== null) {
			$token = $token_storage_instance->getToken();
			if (!empty($token)) {
				// assume that the token is valid
				$this->auth_response['access_token'] = $token['access_token'];
				$this->auth_response['instance_url'] = $token['instance_url'];
			}
		}

		$this->curl = curl_init();
		curl_setopt($this->curl, CURLOPT_HEADER, false);
		curl_setopt($this->curl, CURLOPT_RETURNTRANSFER, true);
	}

	public function authenticate($force_reauth = false, $callback = null) {
		// Authentication methods: http://salesforce.stackexchange.com/questions/785/authenticate-3rd-party-application-with-oauth2
		if (!$this->is_authenticated || $force_reauth) {
			$params = [
				'grant_type' => 'password',
				'client_id' => $this->client_id,
				'client_secret' => $this->client_secret,
				'username' => $this->username,
				'password' => $this->password . $this->security_token,
			];

			curl_setopt($this->curl, CURLOPT_URL, $this->login_uri .'/services/oauth2/token');
			curl_setopt($this->curl, CURLOPT_POST, true);
			curl_setopt($this->curl, CURLOPT_POSTFIELDS, http_build_query($params));
			curl_setopt($this->curl, CURLOPT_CUSTOMREQUEST, null);

			$json_response = $this->exec_curl('POST');

			$status = curl_getinfo($this->curl, CURLINFO_HTTP_CODE);
			if ($status != 200) {
				core::system_error('Salesforce authentication failed.', ['Status' => $status, 'Response' => $json_response, 'cURL error' => curl_error($this->curl), 'cURL errno' => curl_errno($this->curl) ]);
			}

			$response = json_decode($json_response, true);

			if ($response['access_token']) {
				$this->auth_response = $response;
				$this->is_authenticated = true;
			} else {
				core::system_error('Failed to authenticate to Salesforce.');
			}
		}
	}

	/**
	 * Run an SOQL query
	 *
	 * @param string $SOQL : Query in the format of Salesforce Object Query Language
	 * @return array
	 */
	public function execute_soql($SOQL) {
		$this->authenticate();

		$url = $this->auth_response['instance_url'] .'/services/data/'. $this->api_version .'/query?q=' . urlencode($SOQL);
		curl_setopt($this->curl, CURLOPT_URL, $url);
		curl_setopt($this->curl, CURLOPT_HTTPHEADER, ['Authorization: OAuth '. $this->auth_response['access_token']]);
		curl_setopt($this->curl, CURLOPT_POST, false);
		curl_setopt($this->curl, CURLOPT_CUSTOMREQUEST, null);

		$json_response = $this->exec_curl('GET');

		$status = curl_getinfo($this->curl, CURLINFO_HTTP_CODE);
		if ($status != 200) {
			core::system_error('Salesforce SOQL execution failed.', ['URL' => $url, 'SOQL' => $SOQL, 'Status' => $status, 'Response' => $json_response, 'cURL error' => curl_error($this->curl), 'cURL errno' => curl_errno($this->curl) ]);
		}

		return json_decode($json_response, true);
	}

	/**
	 * Create a single record
	 *
	 * @param string $object_name : String with name of object to create, eg. "Account", "Contact", etc.
	 * @param array $fields : Associative array with fieldname/value pairs
	 * @return array
	 */
	public function create($object_name, $fields = []) {
		$this->authenticate();

		$url = $this->auth_response['instance_url'] .'/services/data/'. $this->api_version .'/sobjects/'. $object_name .'/';
		curl_setopt($this->curl, CURLOPT_URL, $url);
		curl_setopt($this->curl, CURLOPT_HTTPHEADER, ['Authorization: OAuth '. $this->auth_response['access_token'], 'Content-Type: application/json']);
		curl_setopt($this->curl, CURLOPT_POST, true);
		curl_setopt($this->curl, CURLOPT_POSTFIELDS, json_encode($fields));
		curl_setopt($this->curl, CURLOPT_CUSTOMREQUEST, null);

		$json_response = $this->exec_curl('POST');

		$status = curl_getinfo($this->curl, CURLINFO_HTTP_CODE);
		if ($status != 201) {
			core::system_error('Creating Salesforce object failed.', ['URL' => $url, 'Fields' => $fields, 'Status' => $status, 'Response' => $json_response, 'cURL error' => curl_error($this->curl), 'cURL errno' => curl_errno($this->curl) ]);
		}

		return json_decode($json_response, true);
	}

	/**
	 * Create multiple records
	 *
	 * @param string $object_name : String with name of object to create, eg. "Account", "Contact", etc.
	 * @param array $array_of_fields : Array with associative subarrays with fieldname/value pairs
	 * @return array : Array from batch() method
	 */
	public function create_multiple($object_name, $array_of_fields = []) {
		$parms = [
			'haltOnError' => true,
			'batchRequests' => [],
		];

		foreach ($array_of_fields as $fields) {
			$parms['batchRequests'][] = [
				'method' => 'POST',
				'url' => $this->api_version .'/sobjects/'. $object_name,
				'richInput' => $fields,
			];
		}

		return $this->batch($parms);
	}

	/**
	 * Update a single record
	 *
	 * @param string $object_name : String with name of object to update, eg. "Account", "Contact", etc.
	 * @param string $id : ID of record to update
	 * @param array $fields : Associative array with fieldname/value pairs
	 * @return void : (Salesforce gives no response data on success)
	 */
	public function update($object_name, $id, $fields = []) {
		$this->authenticate();

		$url = $this->auth_response['instance_url'] .'/services/data/'. $this->api_version .'/sobjects/'. $object_name .'/'. $id;
		curl_setopt($this->curl, CURLOPT_URL, $url);
		curl_setopt($this->curl, CURLOPT_HTTPHEADER, ['Authorization: OAuth '. $this->auth_response['access_token'], 'Content-Type: application/json']);
		curl_setopt($this->curl, CURLOPT_POST, true);
		curl_setopt($this->curl, CURLOPT_POSTFIELDS, json_encode($fields));
		curl_setopt($this->curl, CURLOPT_CUSTOMREQUEST, 'PATCH');

		$json_response = $this->exec_curl('PATCH');

		$status = curl_getinfo($this->curl, CURLINFO_HTTP_CODE);
		if ($status != 204) {
			core::system_error('Updating Salesforce object failed.', ['URL' => $url, 'Fields' => $fields, 'Status' => $status, 'Response' => $json_response, 'cURL error' => curl_error($this->curl), 'cURL errno' => curl_errno($this->curl) ]);
		}
	}

	/**
	 * Update multiple records
	 *
	 * @param string $object_name : String with name of object to create, eg. "Account", "Contact", etc.
	 * @param array $array_of_records : Array with associative subarrays with these keys:
	 *  - `id` : ID of record to update
	 *  - `fields` : associative array with fieldname/value pairs
	 * @return array : Array from batch() method
	 */
	public function update_multiple($object_name, $array_of_records = []) {
		$parms = [
			'haltOnError' => true,
			'batchRequests' => [],
		];

		foreach ($array_of_records as $record) {
			$parms['batchRequests'][] = [
				'method' => 'PATCH',
				'url' => $this->api_version .'/sobjects/'. $object_name .'/'. $record['id'],
				'richInput' => $record['fields'],
			];
		}

		return $this->batch($parms);
	}

	/**
	 * Delete a single record
	 *
	 * Supports cascading deletions to ensure referential integrity - at least stated in SOAP doc, assume it's the same for REST:
	 * https://developer.salesforce.com/docs/atlas.en-us.api.meta/api/sforce_api_calls_delete.htm
	 * Except for Accounts that have Opportunities that are Closed Won. Those we manually delete before deleting the Account this function.
	 *
	 * @param string $object_name : String with name of object to delete, eg. "Account", "Contact", etc.
	 * @param string $id : ID of record to delete
	 * @return boolean : True if success, false if already previously deleted
	 **/
	public function delete($object_name, $id) {
		$this->authenticate();

		// For Accounts manually delete Opportunities since cascade deletions doesn't work for those that are Closed Won
		if ($object_name == 'Account') {
			$opportunities = $this->execute_soql("SELECT Id FROM Opportunity WHERE AccountId = '". str_replace("'", '', $id) ."'");
			if ($opportunities['totalSize'] > 0) {
				foreach ($opportunities['records'] as $opportunity) {
					$this->delete('Opportunity', $opportunity['Id']);
				}
			}
		}

		$url = $this->auth_response['instance_url'] .'/services/data/'. $this->api_version .'/sobjects/'. $object_name .'/'. $id;
		curl_setopt($this->curl, CURLOPT_URL, $url);
		curl_setopt($this->curl, CURLOPT_HTTPHEADER, ['Authorization: OAuth '. $this->auth_response['access_token']]);
		curl_setopt($this->curl, CURLOPT_POST, false);
		curl_setopt($this->curl, CURLOPT_POSTFIELDS, null);
		curl_setopt($this->curl, CURLOPT_CUSTOMREQUEST, 'DELETE');

		$json_response = $this->exec_curl('DELETE');

		$status = curl_getinfo($this->curl, CURLINFO_HTTP_CODE);
		if ($status == 404) {
			$result = json_decode($json_response, true);
			if ($result[0]['errorCode'] == 'ENTITY_IS_DELETED') {
				// entity has already been deleted
				return false;
			} else {
				// entity was not found
				core::system_error('Salesforce object to delete was not found.', ['Status' => $status, 'Response' => $json_response, 'cURL error' => curl_error($this->curl), 'cURL errno' => curl_errno($this->curl) ]);
			}
		} elseif ($status != 204) {
			core::system_error('Deleting Salesforce object failed.', ['URL' => $url, 'Status' => $status, 'Response' => $json_response, 'cURL error' => curl_error($this->curl), 'cURL errno' => curl_errno($this->curl) ]);
		} else {
			// $json_response will be empty when success
			return true;
		}
	}

	/**
	 * Delete multiple records
	 *
	 * @param string $object_name : String with name of object to create, eg. "Account", "Contact", etc.
	 * @param array $IDs : Array of record IDs to be deleted
	 * @return array : Array from batch() method
	 */
	public function delete_multiple($object_name, $IDs = []) {
		$parms = [
			'haltOnError' => true,
			'batchRequests' => [],
		];

		foreach ($IDs as $ID) {
			$parms['batchRequests'][] = [
				'method' => 'DELETE',
				'url' => $this->api_version .'/sobjects/'. $object_name .'/'. $ID,
			];
		}

		return $this->batch($parms);
	}

	/**
	 * Do batches of requests, eg. multiple insert/update/delete
	 *
	 * @param array $batch_requests : Array according to https://developer.salesforce.com/docs/atlas.en-us.api_rest.meta/api_rest/requests_composite_batch.htm
	 *  - see also: https://developer.salesforce.com/docs/atlas.en-us.api_rest.meta/api_rest/dome_composite_batch.htm#topic-title
	 *  - example (in JSON notation):
	 * ```
	 * 		{
	 * 			"haltOnError": true,
	 * 			"batchRequests": [
	 * 				{
	 * 					"method": "PATCH",
	 * 					"url": "v34.0/sobjects/Account/001D000000K0fXOIAZ",
	 * 					"richInput": {
	 * 						"Name": "NewName"
	 * 					}
	 * 				},
	 * 				{
	 * 					"method": "GET",
	 * 					"url": "v34.0/sobjects/Account/001D000000K0fXOIAZ?fields=Name,BillingPostalCode"
	 * 				}
	 * 			]
	 * 		}
	 * ```
	 *
	 * @return array : Sxample (in JSON notation):
	 * ```
	 * 		{
	 * 			"hasErrors": false,
	 * 			"results": [
	 * 				{
	 * 					"statusCode": 204,   //eg. in case of updating a record or successfully deleting a record
	 * 					"result": null
	 * 				},
	 * 				{
	 * 					"statusCode": 200,
	 * 					"result": {
	 * 						"attributes": {
	 * 							"type": "Account",
	 * 							"url": "/services/data/v34.0/sobjects/Account/001D000000K0fXOIAZ"
	 * 						},
	 * 						"Name": "NewName",
	 * 						"BillingPostalCode": "94105",
	 * 						"Id": "001D000000K0fXOIAZ"
	 * 					}
	 * 				}
	 * 			]
	 * 		}
	 * ```
	 */
	public function batch($batch_requests) {
		$this->authenticate();

		$url = $this->auth_response['instance_url'] .'/services/data/'. $this->api_version .'/composite/batch';
		curl_setopt($this->curl, CURLOPT_URL, $url);
		curl_setopt($this->curl, CURLOPT_HTTPHEADER, ['Authorization: OAuth '. $this->auth_response['access_token'], 'Content-Type: application/json']);
		curl_setopt($this->curl, CURLOPT_POST, true);
		curl_setopt($this->curl, CURLOPT_POSTFIELDS, json_encode($batch_requests));
		curl_setopt($this->curl, CURLOPT_CUSTOMREQUEST, null);

		$json_response = $this->exec_curl('POST');

		$status = curl_getinfo($this->curl, CURLINFO_HTTP_CODE);
		$result = json_decode($json_response, true);
		return $result;
	}

	/**
	 * Describe a Salesforce object (= get schema)
	 *
	 * @param string $object_name : String with name of object to update, eg. "Account", "Contact", etc.
	 * @return array
	 */
	public function describe_object($object_name) {
		$this->authenticate();

		$url = $this->auth_response['instance_url'] .'/services/data/'. $this->api_version .'/sobjects/'. $object_name .'/describe';
		curl_setopt($this->curl, CURLOPT_URL, $url);
		curl_setopt($this->curl, CURLOPT_HTTPHEADER, ['Authorization: OAuth '. $this->auth_response['access_token']]);
		curl_setopt($this->curl, CURLOPT_POST, false);
		curl_setopt($this->curl, CURLOPT_CUSTOMREQUEST, null);

		$json_response = $this->exec_curl('GET');

		$status = curl_getinfo($this->curl, CURLINFO_HTTP_CODE);
		if ($status != 200) {
			core::system_error('Describing Salesforce object failed.', ['URL' => $url,'Status' => $status, 'Response' => $json_response, 'cURL error' => curl_error($this->curl), 'cURL errno' => curl_errno($this->curl) ]);
		}

		$response = json_decode($json_response, true);

		if ($response === null) {
			core::system_error('Response for describing Salesforce object is invalid.');
		}

		return $response;
	}

	private function exec_curl($type = null) {
		if (is_callable($this->exec_curl_log_callback)) {
			$starttime = microtime(true);
		}

		$response = curl_exec($this->curl);

		if (is_callable($this->exec_curl_log_callback)) {
			$data = [
				'url' => curl_getinfo($this->curl, CURLINFO_EFFECTIVE_URL),
				'type' => $type,
				'duration' => round(microtime(true) - $starttime, 3),
				'http_code' => curl_getinfo($this->curl, CURLINFO_HTTP_CODE),
				'request_size' => curl_getinfo($this->curl, CURLINFO_REQUEST_SIZE),
				'response_size' => curl_getinfo($this->curl, CURLINFO_SIZE_DOWNLOAD),
				'source' => 'REST',
			];
			call_user_func($this->exec_curl_log_callback, $data);
		}

		return $response;
	}
}
