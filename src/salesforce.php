<?php
/*
This file contains functions related to Salesforce.com
*/
namespace winternet\jensenfw2;

class salesforce {
	var $client_id;
	var $client_secret;
	var $login_uri;
	var $username;
	var $password;
	var $token_storage_class;

	var $is_authenticated = false;
	var $authenticated_uid = null;


	public function __construct($client_id, $client_secret, $login_uri, $username, $password, $token_storage_class) {
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
		$this->login_uri = $login_uri;
		$this->username = $username;
		$this->password = $password;
		$this->token_storage_class = $token_storage_class;

		$token = $token_storage_class::getToken();
		if (!empty($token)) {
			// assume that the token is valid
			$this->is_authenticated = true;
		}
	}

	public function authenticate($force_reauth = false, $callback = null) {
		// Authentication methods: http://salesforce.stackexchange.com/questions/785/authenticate-3rd-party-application-with-oauth2
		if (!$this->is_authenticated || $force_reauth) {
			$params = array(
				'grant_type' => 'password',
				'client_id' => $this->client_id,
				'client_secret' => $this->client_secret,
				'username' => $this->username,
				'password' => $this->password,
			);

			$curl = curl_init($this->login_uri .'/services/oauth2/token');
			curl_setopt($curl, CURLOPT_HEADER, false);
			curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
			curl_setopt($curl, CURLOPT_POST, true);
			curl_setopt($curl, CURLOPT_POSTFIELDS, http_build_query($params));

			$json_response = curl_exec($curl);

			$status = curl_getinfo($curl, CURLINFO_HTTP_CODE);

			if ($status != 200) {
				core::system_error('Salesforce authentication failed.', ['Status' => $status, 'Token URL' => $token_url, 'Response' => $json_response, 'cURL error' => curl_error($curl), 'cURL errno' => curl_errno($curl) ]);
			}

			curl_close($curl);

			dump($json_response);

			if ($this->authenticated_uid) {
				$this->is_authenticated = true;
			} else {
				throw new \Exception('Failed to authenticate to Salesforce.');
			}
		}
	}

	public function create_invoice_draft($invoice_fields, $invoice_lines) {
		/*
		DESCRIPTION:
		- 
		INPUT:
		- $invoice_fields : associative array of fields on the invoice. Example:
			array(
				'partner_id' => 42,
				'account_id' => 1065,  //= accounts receivable
				'currency_id' => 3,
				'payment_term_id' => 5,
				'comment' => 'Some comment',
				// 'origin' => '', //= Source Document
				'name' => 'Order ID 15615-3',  //= Reference/Description
				'date_invoice' => '2015-12-01',
				'date' => '2015-12-01',  //accounting date (leave out to use invoice date)
			)
		- $invoice_lines : array of associative arrays of invoice lines. Example:
				array(
					array(
						'account_id' => 1074,   //from Chart of Accounts
						'quantity' => 1,
						'name' => 'Your description Line 1',
						// 'invoice_line_tax_ids' => array(array(4, 23, false)),  // http://stackoverflow.com/questions/32635670/odoo-v8-php-insert-one2many-or-many2many-field
						'price_unit' => 500.00,
					),
					array(
						'account_id' => 1074,
						'quantity' => 1,
						'name' => 'Your description Line 2',
						// 'invoice_line_tax_ids' => array(array(4, 23, false)),
						'price_unit' => 350.00,
					),
				)
		OUTPUT:
		- 
		*/
		$this->authenticate();
		$this->require_object_client();

		$invoice_id = $this->object_client->execute_kw($this->server_database, $this->authenticated_uid, $this->server_password, 'account.invoice', 'create', array($invoice_fields));

		if (!$invoice_id['faultString']) {
			// Calculate taxes if applicable
			$this->object_client->execute_kw($this->server_database, $this->authenticated_uid, $this->server_password, 'account.invoice', 'compute_taxes', $invoice_id);

			// Add the newly created invoice id to each line
			foreach ($invoice_lines as &$line) {
				$line['invoice_id'] = $invoice_id;
			}

			// Add invoice lines
			$successful_lines = $invoice_line_ids = array();
			foreach ($invoice_lines as $key => $invoice_line) {
				$result_invline = $this->object_client->execute_kw($this->server_database, $this->authenticated_uid, $this->server_password, 'account.invoice.line', 'create', array($invoice_line));
				if ($result_invline['faultString']) {
					throw new \Exception('Failed to create Odoo invoice lines: '. $result_invline['faultString'] .' ['. @base64_encode(@openssl_encrypt(json_encode(array($successful_lines, $invoice_line_ids)), 'AES-128-CBC', 'error96')) .']');
				} else {
					$invoice_line_ids[] = $result_invline;
					$successful_lines[] = $key;
				}
			}
		} else {
			throw new \Exception('Failed to create Odoo invoice: '. $invoice_id['faultString']);
		}

		return array(
			'invoice_id' => $invoice_id,
			'invoice_line_ids' => $invoice_line_ids,
		);
	}

	public function get_invoice($filters = array(), $options = array(), $meta_options = array() ) {
		/*
		DESCRIPTION:
		- get a one invoices
		INPUT:
		- $filters : array, eg.: array(array('partner_id', '=', 42), array('date_invoice', '=', '2015-12-01'))
		- $meta_options : associative array passed on to get_invoices(). The following keys are specific to this method:
			- 'retrieve_pdf' : set to true to also retrieve the PDF invoice (included in output as base64 in the key 'pdf_invoice')
			- 'custom_pdf_report' : to use the non-standard PDF report specify the report Template Name (find it at Settings > Technical > Actions > Reports)
		OUTPUT:
		- 
		*/
		$this->authenticate();

		$options['limit'] = 1;

		$invoices = $this->get_invoices($filters, $options, $meta_options);
		if ($invoices[0]) {
			// Also get PDF if requested
			if ($meta_options['retrieve_pdf']) {
				$this->require_report_client();

				$pdfresult = $this->report_client->render_report($this->server_database, $this->authenticated_uid, $this->server_password, ($meta_options['custom_pdf_report'] ? $meta_options['custom_pdf_report'] : 'account.report_invoice'), array($invoices[0]['id']));
				if ($pdfresult['faultString']) {
					throw new \Exception('Failed to generate PDF invoice report: '. $pdfresult['faultString']);
				} else {
					$invoices[0]['pdf_invoice'] = $pdfresult['result'];
				}
			}

			return $invoices[0];
		} else {
			if ($meta_options['soft_fail']) {
				return false;
			} else {
				throw new \Exception('Odoo invoice not found.');
			}
		}
	}

	public function get_invoices($filters = array(), $options = array(), $meta_options = array() ) {
		/*
		DESCRIPTION:
		- get a list of invoices
		INPUT:
		- $filters : array, eg.: array(array('partner_id', '=', 42), array('date_invoice', '=', '2015-12-01'))
		OUTPUT:
		- 
		*/
		$this->authenticate();
		$this->require_object_client();

		return $this->object_client->execute_kw($this->server_database, $this->authenticated_uid, $this->server_password, 'account.invoice', ($meta_options['search_only'] ? 'search' : 'search_read'), array($filters), $options);
	}

	public function validate_invoice($invoice_id) {
		/*
		DESCRIPTION:
		- 
		INPUT:
		- $invoice_id : Odoo's internal invoice ID (not the invoice number! It doesn't have one yet!)
		OUTPUT:
		- 
		*/
		$this->authenticate();
		$this->require_object_client();

		if (!is_numeric($invoice_id)) {
			throw new \Exception('Odoo invoice ID to be validated is not a number.');
		}

		$invoice = $this->object_client->execute_kw($this->server_database, $this->authenticated_uid, $this->server_password, 'account.invoice', 'read', array(array($invoice_id)));
		if ($invoice[0]['state'] != 'draft') {
			throw new \Exception('Odoo invoice to be validated is not a draft (it is '. $invoice[0]['state'] .').');
		}

		// Source: see "Workflow manipulations" at www.odoo.com/documentation/9.0/api_integration.html
		$this->object_client->exec_workflow($this->server_database, $this->authenticated_uid, $this->server_password, 'account.invoice', 'invoice_open', $invoice_id);
		// Seems to just return false (not sure what else it might return, so don't handle it for now)
	}

	public function change_invoice_number($invoice_id, $new_invoice_number) {
		/*
		DESCRIPTION:
		- 
		INPUT:
		- 
		OUTPUT:
		- 
		*/

		// TODO: finish making this method
		exit;

		$this->authenticate();
		$this->require_object_client();

		if (!is_numeric($invoice_id)) {
			throw new \Exception('Odoo invoice ID to change invoice number for is not a number.');
		}
		if (!$new_invoice_number) {
			throw new \Exception('Odoo new invoice number is missing.');
		}

		// Change the journal entries
		// TODO: find the journal entries 
		$move_ids = '????????';  //find these
		$done_IDs = array();
		foreach ($move_ids as $key => $move_id) {
			$result = $this->object_client->execute_kw($this->server_database, $this->authenticated_uid, $this->server_password, 'account.move', 'write', array(array($move_id), array('name' => $new_invoice_number)));
			if ($result['faultString']) {
				throw new \Exception('Failed to change invoice number: '. $result['faultString'] .' ['. @base64_encode(@openssl_encrypt(json_encode(array($move_id, $done_IDs)), 'AES-128-CBC', 'error96')) .']');
			} else {
				$done_IDs[] = $move_id;
			}
		}

		// Change the invoice
		// TODO: I think change the journal entries above automatically changes the invoice as well, but if not use the next line
		// $fields = $this->object_client->execute_kw($this->server_database, $this->authenticated_uid, $this->server_password, 'account.invoice', 'write', array(array($invoice_id), array('number' => $new_invoice_number)));
	}

	public function create_payment_draft($payment_fields) {
		/*
		DESCRIPTION:
		- 
		INPUT:
		- $invoice_fields : associative array of fields on the invoice. Example:
			array(
				'payment_type' => 'inbound',
				'partner_type' => 'customer',
				'partner_id' => 42,
				'journal_id' => 26,
				???NEEDED???'destination_journal_id' => false,
				'payment_method_id' => 1,
				'currency_id' => 15,
				'amount' => 530,
				'payment_date' => '2016-12-01',
				'communication' => 'Some comment',  //= Memo
				???'invoice_ids' => array(15, 16),
			)
		OUTPUT:
		- 
		*/
		$this->authenticate();
		$this->require_object_client();

		$payment_id = $this->object_client->execute_kw($this->server_database, $this->authenticated_uid, $this->server_password, 'account.payment', 'create', array($payment_fields));

		if (!$payment_id['faultString']) {
			return $payment_id;
		} else {
			throw new \Exception('Failed to create Odoo payment: '. $payment_id['faultString']);
		}

		return array(
			'payment_id' => $payment_id,
		);
	}

	public function validate_payment($payment_id) {
		throw new \Exception('Validating payment is not yet implemented.');
		return;

		$this->authenticate();
		$this->require_object_client();

		if (!is_numeric($payment_id)) {
			throw new \Exception('Odoo payment ID to be validated is not a number.');
		}

		$payment = $this->object_client->execute_kw($this->server_database, $this->authenticated_uid, $this->server_password, 'account.payment', 'read', array(array($payment_id)));
		if ($payment[0]['state'] != 'draft') {
			throw new \Exception('Odoo payment to be validated is not a draft (it is '. $payment[0]['state'] .').');
		}

echo '<pre style="background-color: gold; border: solid chocolate 1px; padding: 10px"><div style="color: chocolate"><b>VARIABLE DUMP</b> '. (__FILE__ ? __FILE__ : '') .' : <b>'. (__LINE__ ? __LINE__ : '') .'</b>'. (__FUNCTION__ ? ' '. __FUNCTION__ .'()' : '') .'</div><div style="color: blue"><b>'; echo '</b></div><hr>';
echo var_dump($payment);     echo '</pre>';
exit;
// TODO: CONTINUE: change 'invoice_open' to the correct one. See what the browser sends when you do it there
//       and then test the entire method
		$this->object_client->exec_workflow($this->server_database, $this->authenticated_uid, $this->server_password, 'account.invoice', 'invoice_open', $payment_id);
		// Seems to just return false (not sure what else it might return, so don't handle it for now)
	}

	public function get_currencies() {
		$this->authenticate();
		$this->require_object_client();

		$output = array();

		$currencies = $this->object_client->execute_kw($this->server_database, $this->authenticated_uid, $this->server_password, 'res.currency', 'search_read', array());

		foreach ($currencies as $currency) {
			$output[$currency['name']] = array(
				'rate' => $currency['rate'],
				'as_of' => $currency['date'],
				'currency_id' => $currency['id'],
			);
		}

		return $output;
	}

	public function update_exchange_rates() {
		$this->authenticate();
		$this->require_object_client();

		// Possible alternatives: https://github.com/yelizariev/addons-yelizariev/blob/8.0/currency_rate_update/currency_rate_update.py
		$sources = array(
			'ecb' => array(
				'url' => 'http://www.ecb.europa.eu/stats/eurofxref/eurofxref-daily.xml',
				'base_currency' => 'EUR',
			),
		);

		$eff_source = $sources['ecb'];

		$updated_currencies = array();

		$ch = curl_init();
		curl_setopt($ch, CURLOPT_URL, $eff_source['url']);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($ch, CURLOPT_TIMEOUT, 30);
		if (!ini_get('safe_mode') && !ini_get('open_basedir')) {  //CURLOPT_FOLLOWLOCATION is not allowed in safe mode and when open basedir is set
			curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);  //automatically follow redirects
		}

		$xml = curl_exec($ch);

		if ($xml) {
			$data = json_decode(json_encode(simplexml_load_string($xml)), true);
			
			if ($data) {
				$curr_rates = $this->object_client->execute_kw($this->server_database, $this->authenticated_uid, $this->server_password, 'res.currency', 'search_read', array());
				if ($curr_rates) {

					$rates_date = $data['Cube']['Cube']['@attributes']['time'];
					if (!$rates_date) {
						throw new \Exception('Did not found date of the retrieved exchange rates');
					}

					$new_rates = array($eff_source['base_currency'] => '1');
					foreach ($data['Cube']['Cube']['Cube'] as $rate) {
						$new_rates[$rate['@attributes']['currency']] = $rate['@attributes']['rate'];
					}


					foreach ($curr_rates as $curr_rate) {
						if ($new_rates[$curr_rate['name']]) {  //if we have a new exchange rate for this currency...
							if (!$curr_rate['date'] || strtotime($curr_rate['date']) < strtotime($rates_date) ) {
								// Update rate when we have a newer one
								$fields = array(
									'currency_id' => $curr_rate['id'],
									'name' => $rates_date .' 00:00:00',
									'rate' => $new_rates[$curr_rate['name']],
								);
								$result = $this->object_client->execute_kw($this->server_database, $this->authenticated_uid, $this->server_password, 'res.currency.rate', 'create', array($fields));
								if ($result['faultString']) {
									throw new \Exception('Failed to update Odoo exchange rate for '. $curr_rate['name'] .':'. $result['faultString']);
								} else {
									$updated_currencies[$curr_rate['name']] = $new_rates[$curr_rate['name']];
								}
							} else {
								// already up-to-date
							}
						}
					}

				}
			} else {
				throw new \Exception('Failed parsing the XML with exchange rates');
			}
		} else {
			throw new \Exception('Response with exchange rates was empty');
		}

		return $updated_currencies;
	}
}
