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

		curl_setopt($this->curl, CURLOPT_URL, $this->auth_response['instance_url'] .'/services/data/'. $this->api_version .'/query?q=' . urlencode($SOQL));
		curl_setopt($this->curl, CURLOPT_HTTPHEADER, array('Authorization: OAuth '. $this->auth_response['access_token']));
		curl_setopt($this->curl, CURLOPT_POST, false);
		curl_setopt($this->curl, CURLOPT_CUSTOMREQUEST, null);
		
		$json_response = curl_exec($this->curl);

		$status = curl_getinfo($this->curl, CURLINFO_HTTP_CODE);
		if ($status != 200) {
			core::system_error('Salesforce SOQL execution failed.', ['Status' => $status, 'Response' => $json_response, 'cURL error' => curl_error($this->curl), 'cURL errno' => curl_errno($this->curl) ]);
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

		curl_setopt($this->curl, CURLOPT_URL, $this->auth_response['instance_url'] .'/services/data/'. $this->api_version .'/sobjects/'. $object_name .'/');
		curl_setopt($this->curl, CURLOPT_HTTPHEADER, array('Authorization: OAuth '. $this->auth_response['access_token'], 'Content-Type: application/json'));
		curl_setopt($this->curl, CURLOPT_POST, true);
		curl_setopt($this->curl, CURLOPT_POSTFIELDS, json_encode($fields));
		curl_setopt($this->curl, CURLOPT_CUSTOMREQUEST, null);

		$json_response = curl_exec($this->curl);

		$status = curl_getinfo($this->curl, CURLINFO_HTTP_CODE);
		if ($status != 200) {
			core::system_error('Creating Salesforce object failed.', ['Status' => $status, 'Response' => $json_response, 'cURL error' => curl_error($this->curl), 'cURL errno' => curl_errno($this->curl) ]);
		}

		return json_decode($json_response, true);
	}
}
