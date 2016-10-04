<?php
/*
This file contains functions related to Salesforce.com
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
	var $token_storage_class;

	// Runtime variables
	var $is_authenticated = false;
	var $auth_response = null;
	var $curl = null;

	public function __construct($client_id, $client_secret, $username, $password, $security_token, $login_uri, $api_version, $token_storage_class = null) {
		/*
		DESCRIPTION:
		- 
		INPUT:
		- $token_storage_class : class with these static methods:
			- saveToken($access_token, $instance_url) which returns nothing
			- getToken() which returns eg. array('access_token' => 'rELHinuBmp9i98HBV4h7mMWVh', 'instance_url' => 'https://na30.salesforce.com')
		OUTPUT:
		- 
		*/
		$this->client_id = $client_id;
		$this->client_secret = $client_secret;
		$this->username = $username;
		$this->password = $password;
		$this->security_token = $security_token;
		$this->login_uri = $login_uri;
		$this->api_version = $api_version;
		$this->token_storage_class = $token_storage_class;

		if ($token_storage_class !== null) {
			$token = $token_storage_class::getToken();
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
			$params = array(
				'grant_type' => 'password',
				'client_id' => $this->client_id,
				'client_secret' => $this->client_secret,
				'username' => $this->username,
				'password' => $this->password . $this->security_token,
			);

			curl_setopt($this->curl, CURLOPT_URL, $this->login_uri .'/services/oauth2/token');
			curl_setopt($this->curl, CURLOPT_POST, true);
			curl_setopt($this->curl, CURLOPT_POSTFIELDS, http_build_query($params));
			curl_setopt($this->curl, CURLOPT_CUSTOMREQUEST, null);

			$json_response = curl_exec($this->curl);

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

	public function execute_soql($SOQL) {
		/*
		DESCRIPTION:
		- run an SOQL query
		INPUT:
		- $SOQL : string with a query in the format of Salesforce Object Query Language
		OUTPUT:
		- array
		*/
		$this->authenticate();

		$url = $this->auth_response['instance_url'] .'/services/data/'. $this->api_version .'/query?q=' . urlencode($SOQL);
		curl_setopt($this->curl, CURLOPT_URL, $url);
		curl_setopt($this->curl, CURLOPT_HTTPHEADER, array('Authorization: OAuth '. $this->auth_response['access_token']));
		curl_setopt($this->curl, CURLOPT_POST, false);
		curl_setopt($this->curl, CURLOPT_CUSTOMREQUEST, null);
		
		$json_response = curl_exec($this->curl);

		$status = curl_getinfo($this->curl, CURLINFO_HTTP_CODE);
		if ($status != 200) {
			core::system_error('Salesforce SOQL execution failed.', ['URL' => $url, 'Status' => $status, 'Response' => $json_response, 'cURL error' => curl_error($this->curl), 'cURL errno' => curl_errno($this->curl) ]);
		}

		return json_decode($json_response, true);
	}

	public function create($object_name, $fields = array() ) {
		/*
		DESCRIPTION:
		- create a single record
		INPUT:
		- $object_name : string with name of object to create, eg. "Account", "Contact", etc.
		- $fields : associative array with fieldname/value pairs
		OUTPUT:
		- array
		*/
		$this->authenticate();

		$url = $this->auth_response['instance_url'] .'/services/data/'. $this->api_version .'/sobjects/'. $object_name .'/';
		curl_setopt($this->curl, CURLOPT_URL, $url);
		curl_setopt($this->curl, CURLOPT_HTTPHEADER, array('Authorization: OAuth '. $this->auth_response['access_token'], 'Content-Type: application/json'));
		curl_setopt($this->curl, CURLOPT_POST, true);
		curl_setopt($this->curl, CURLOPT_POSTFIELDS, json_encode($fields));
		curl_setopt($this->curl, CURLOPT_CUSTOMREQUEST, null);

		$json_response = curl_exec($this->curl);

		$status = curl_getinfo($this->curl, CURLINFO_HTTP_CODE);
		if ($status != 201) {
			core::system_error('Creating Salesforce object failed.', ['URL' => $url, 'Status' => $status, 'Response' => $json_response, 'cURL error' => curl_error($this->curl), 'cURL errno' => curl_errno($this->curl) ]);
		}

		return json_decode($json_response, true);
	}

	public function create_multiple($object_name, $array_of_fields = array() ) {
		/*
		DESCRIPTION:
		- create multiple records
		INPUT:
		- $object_name : string with name of object to create, eg. "Account", "Contact", etc.
		- $array_of_fields : array with associative subarrays with fieldname/value pairs
		OUTPUT:
		- array from batch() method
		*/
		$parms = array(
			'haltOnError' => true,
			'batchRequests' => array(),
		);

		foreach ($array_of_fields as $fields) {
			$parms['batchRequests'][] = array(
				'method' => 'POST',
				'url' => $this->api_version .'/sobjects/'. $object_name,
				'richInput' => $fields,
			);
		}

		return $this->batch($parms);
	}

	public function update($object_name, $id, $fields = array() ) {
		/*
		DESCRIPTION:
		- update a single record
		INPUT:
		- $object_name : string with name of object to update, eg. "Account", "Contact", etc.
		- $id : ID of record to update
		- $fields : associative array with fieldname/value pairs
		OUTPUT:
		- array
		*/
		$this->authenticate();

		$url = $this->auth_response['instance_url'] .'/services/data/'. $this->api_version .'/sobjects/'. $object_name .'/'. $id;
		curl_setopt($this->curl, CURLOPT_URL, $url);
		curl_setopt($this->curl, CURLOPT_HTTPHEADER, array('Authorization: OAuth '. $this->auth_response['access_token'], 'Content-Type: application/json'));
		curl_setopt($this->curl, CURLOPT_POST, true);
		curl_setopt($this->curl, CURLOPT_POSTFIELDS, json_encode($fields));
		curl_setopt($this->curl, CURLOPT_CUSTOMREQUEST, null);

		$json_response = curl_exec($this->curl);

		$status = curl_getinfo($this->curl, CURLINFO_HTTP_CODE);
		if ($status != 201) {
			core::system_error('Updating Salesforce object failed.', ['URL' => $url, 'Status' => $status, 'Response' => $json_response, 'cURL error' => curl_error($this->curl), 'cURL errno' => curl_errno($this->curl) ]);
		}

		return json_decode($json_response, true);
	}

	public function update_multiple($object_name, $array_of_records = array() ) {
		/*
		DESCRIPTION:
		- update multiple records
		INPUT:
		- $object_name : string with name of object to create, eg. "Account", "Contact", etc.
		- $array_of_records : array with associative subarrays with these keys:
			- 'id' : ID of record to update
			- 'fields' : associative array with fieldname/value pairs
		OUTPUT:
		- array from batch() method
		*/
		$parms = array(
			'haltOnError' => true,
			'batchRequests' => array(),
		);

		foreach ($array_of_records as $record) {
			$parms['batchRequests'][] = array(
				'method' => 'PATCH',
				'url' => $this->api_version .'/sobjects/'. $object_name .'/'. $record['id'],
				'richInput' => $record['fields'],
			);
		}

		return $this->batch($parms);
	}

	public function delete($object_name, $id) {
		/*
		DESCRIPTION:
		- delete a single record
		INPUT:
		- $object_name : string with name of object to delete, eg. "Account", "Contact", etc.
		- $id : ID of record to delete
		OUTPUT:
		- success : true
		- already previously deleted : false
		*/
		$this->authenticate();

		$url = $this->auth_response['instance_url'] .'/services/data/'. $this->api_version .'/sobjects/'. $object_name .'/'. $id;
		curl_setopt($this->curl, CURLOPT_URL, $url);
		curl_setopt($this->curl, CURLOPT_HTTPHEADER, array('Authorization: OAuth '. $this->auth_response['access_token']));
		curl_setopt($this->curl, CURLOPT_POST, false);
		curl_setopt($this->curl, CURLOPT_POSTFIELDS, null);
		curl_setopt($this->curl, CURLOPT_CUSTOMREQUEST, 'DELETE');

		$json_response = curl_exec($this->curl);

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

	public function delete_multiple($object_name, $IDs = array() ) {
		/*
		DESCRIPTION:
		- delete multiple records
		INPUT:
		- $object_name : string with name of object to create, eg. "Account", "Contact", etc.
		- $IDs : array of record IDs to be deleted
		OUTPUT:
		- array from batch() method
		*/
		$parms = array(
			'haltOnError' => true,
			'batchRequests' => array(),
		);

		foreach ($IDs as $ID) {
			$parms['batchRequests'][] = array(
				'method' => 'DELETE',
				'url' => $this->api_version .'/sobjects/'. $object_name .'/'. $ID,
			);
		}

		return $this->batch($parms);
	}

	public function batch($batch_requests) {
		/*
		DESCRIPTION:
		- do batches of requests, eg. multiple insert/update/delete
		INPUT:
		- $batch_requests : array according to https://developer.salesforce.com/docs/atlas.en-us.api_rest.meta/api_rest/requests_composite_batch.htm
			- see also: https://developer.salesforce.com/docs/atlas.en-us.api_rest.meta/api_rest/dome_composite_batch.htm#topic-title
			- example (in JSON notation):
				{
					"haltOnError": true,
					"batchRequests": [
						{
							"method": "PATCH",
							"url": "v34.0/sobjects/Account/001D000000K0fXOIAZ",
							"richInput": {
								"Name": "NewName"
							}
						},
						{
							"method": "GET",
							"url": "v34.0/sobjects/Account/001D000000K0fXOIAZ?fields=Name,BillingPostalCode"
						}
					]
				}
		OUTPUT:
		- array
		- example (in JSON notation):
				{
					"hasErrors": false,
					"results": [
						{
							"statusCode": 204,   //eg. in case of updating a record or successfully deleting a record
							"result": null
						},
						{
							"statusCode": 200,
							"result": {
								"attributes": {
									"type": "Account",
									"url": "/services/data/v34.0/sobjects/Account/001D000000K0fXOIAZ"
								},
								"Name": "NewName",
								"BillingPostalCode": "94105",
								"Id": "001D000000K0fXOIAZ"
							}
						}
					]
				}
		*/
		$this->authenticate();

		$url = $this->auth_response['instance_url'] .'/services/data/'. $this->api_version .'/composite/batch';
		curl_setopt($this->curl, CURLOPT_URL, $url);
		curl_setopt($this->curl, CURLOPT_HTTPHEADER, array('Authorization: OAuth '. $this->auth_response['access_token'], 'Content-Type: application/json'));
		curl_setopt($this->curl, CURLOPT_POST, true);
		curl_setopt($this->curl, CURLOPT_POSTFIELDS, json_encode($batch_requests));
		curl_setopt($this->curl, CURLOPT_CUSTOMREQUEST, null);

		$json_response = curl_exec($this->curl);

		$status = curl_getinfo($this->curl, CURLINFO_HTTP_CODE);
		$result = json_decode($json_response, true);
		return $result;
	}
}
