<?php
/**
 * Class for accessing the accounting software Odoo
 *
 * Dependent on https://github.com/DarkaOnLine/Ripcord
 */

namespace winternet\jensenfw2;

use Ripcord\Ripcord;
use Ripcord\Client\Transport\Stream;

class odoo {
	var $server_url;
	var $server_username;
	var $server_password;
	var $server_database;
	var $options;

	var $common_client;
	var $object_client;
	var $report_client;

	var $is_authenticated = false;
	var $authenticated_uid = null;
	var $last_fault_string = null;

	/**
	 * @param array $options : Available options:
	 *   - `skip_verify_certificates` : set true to disable certificate verification
	 *   - `odoo_version` : set the version of Odoo, eg. `9` or `10`. Defaults to 10.
	 */
	public function __construct($server_url, $server_database, $server_username, $server_password, $options = array()) {
		$this->server_url = $server_url;
		$this->server_database = $server_database;
		$this->server_username = $server_username;
		$this->server_password = $server_password;

		if (!array_key_exists('odoo_version', $options)) {
			$options['odoo_version'] = 10;
		}

		$this->options = $options;
	}

	public function require_common_client() {
		if (!$this->common_client) {
			$this->common_client = Ripcord::client($this->server_url . '/xmlrpc/2/common', null, $this->create_stream());
			if (!$this->common_client) {
				$this->throw_exception('Failed to create Odoo common client.');
			}
		}
	}

	public function require_object_client() {
		if (!$this->object_client) {
			$this->object_client = Ripcord::client($this->server_url . '/xmlrpc/2/object', null, $this->create_stream());
			if (!$this->object_client) {
				$this->throw_exception('Failed to create Odoo object client.');
			}
		}
	}

	public function require_report_client() {
		if (!$this->report_client) {
			$this->report_client = Ripcord::client($this->server_url . '/xmlrpc/2/report', null, $this->create_stream());
			if (!$this->report_client) {
				$this->throw_exception('Failed to create Odoo report client.');
			}
		}
	}

	public function create_stream() {
		if ($this->options['skip_verify_certificates']) {
			return new Stream([
				'ssl' => [
					// set some SSL/TLS specific options
					'verify_peer' => false,
					'verify_peer_name' => false,
					'allow_self_signed' => true,
				],
			]);
		} else {
			return new Stream();
		}
	}

	public function authenticate() {
		if (!$this->is_authenticated) {
			$this->require_common_client();
			$this->authenticated_uid = $this->common_client->authenticate($this->server_database, $this->server_username, $this->server_password, array());
			if ($this->authenticated_uid) {
				$this->is_authenticated = true;
			} else {
				$this->throw_exception('Failed to authenticate to Odoo.');
			}
		}
	}

	/**
	 * Create journal entry
	 *
	 * Inspiration: https://stackoverflow.com/questions/38794533/how-to-create-journal-entries-and-items-in-odoo-using-xmlrpc-in-php
	 *
	 * @param array $journal_details : Example:
	 * ```
	 * array(
	 *   'journal_id' => 137,
	 *   'ref' => 'Your reference',
	 * )
	 * ```
	 *
	 * @param array $lines : Example:
	 * ```
	 * $lines = array(
	 *   array(
	 *     'name' => 'Paper clips',
	 *     'account_id' => 5265,
	 *     'debit' => 10.50,
	 *     'credit' => 0.00,
	 *     'amount_currency' => 1.50,
	 *     'currency_id' => 3,
	 *     'date_created' => '2019-05-12',
	 *     'date' => '2019-05-12',
	 *   ),
	 *   array(
	 *     'name' => 'VISA card',
	 *     'account_id' => 5240,
	 *     'debit' => 0.00,
	 *     'credit' => 10.50,
	 *     'amount_currency' => -1.50,
	 *     'currency_id' => 3,
	 *     'date_created' => '2019-05-12',
	 *     'date' => '2019-05-12',
	 *   ),
	 * )
	 * ```
	 */
	public function create_journal_entry($journal_details, $lines) {
		$this->authenticate();
		$this->require_object_client();

		// Create journal
		$move_id = $this->object_client->execute_kw($this->server_database, $this->authenticated_uid, $this->server_password, 'account.move', 'create', array($journal_details));
		$this->handle_exception($move_id, 'Failed to create journal entry.');

		// Create journal items
		$lines_count = count($lines);
		$counter = 0;
		$line_ids = array();
		foreach ($lines as $line) {
			$counter++;

			$line['move_id'] = $move_id;

			if ($counter < $lines_count) {
				$context = array('context' => array('check_move_validity' => false));
			} else {
				$context = array('context' => array('check_move_validity' => true));
			}
			$item_id = $this->object_client->execute_kw($this->server_database, $this->authenticated_uid, $this->server_password, 'account.move.line', 'create', array($line), $context);
			$this->handle_exception($item_id, 'Failed to create journal item.');

			$line_ids[] = $item_id;
		}

		// Validate it
		$this->object_client->execute($this->server_database, $this->authenticated_uid, $this->server_password, 'account.move', 'post', array($move_id));

		return array(
			'move_id' => $move_id,
			'line_ids' => $line_ids,
		);
	}

	/**
	 *
	 * @param array $invoice_fields : Associative array of fields on the invoice. Example:
	 * ```
	 * 	array(
	 * 		'partner_id' => 42,
	 * 		'account_id' => 1065,  //= accounts receivable
	 * 		'currency_id' => 3,
	 * 		'payment_term_id' => 5,
	 * 		'comment' => 'Some comment',
	 * 		// 'origin' => '', //= Source Document
	 * 		'name' => 'Order ID 15615-3',  //= Reference/Description
	 * 		'date_invoice' => '2015-12-01',
	 * 		'date' => '2015-12-01',  //accounting date (leave out to use invoice date)
	 * 	)
	 * ```
	 * @param array $invoice_lines : Array of associative arrays of invoice lines. Example:
	 * ```
	 * 		array(
	 * 			array(
	 * 				'account_id' => 1074,   //from Chart of Accounts
	 * 				'quantity' => 1,
	 * 				'name' => 'Your description Line 1',
	 * 				// 'invoice_line_tax_ids' => array(array(4, 23, false)),  // http://stackoverflow.com/questions/32635670/odoo-v8-php-insert-one2many-or-many2many-field
	 * 				'price_unit' => 500.00,
	 * 			),
	 * 			array(
	 * 				'account_id' => 1074,
	 * 				'quantity' => 1,
	 * 				'name' => 'Your description Line 2',
	 * 				// 'invoice_line_tax_ids' => array(array(4, 23, false)),
	 * 				'price_unit' => 350.00,
	 * 			),
	 * 		)
	 * ```
	 */
	public function create_invoice_draft($invoice_fields, $invoice_lines) {
		$this->authenticate();
		$this->require_object_client();

		// TODO: option to specify currency_id with the 3-letter currency code instead (eg. 'USD')

		$invoice_id = $this->object_client->execute_kw($this->server_database, $this->authenticated_uid, $this->server_password, 'account.invoice', 'create', array($invoice_fields));
		$this->handle_exception($invoice_id, 'Failed to create invoice.');

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
			$this->handle_exception($result_invline, 'Failed to create Odoo invoice line.');
			// throw new \Exception(': '. $result_invline['faultString'] .' ['. @base64_encode(@openssl_encrypt(json_encode(array($successful_lines, $invoice_line_ids)), 'AES-128-CBC', 'error96')) .']');

			$invoice_line_ids[] = $result_invline;
			$successful_lines[] = $key;
		}

		return array(
			'invoice_id' => $invoice_id,
			'invoice_line_ids' => $invoice_line_ids,
		);
	}

	/**
	 * Get a one invoices
	 *
	 * @param array $filters : For example `array(array('partner_id', '=', 42), array('date_invoice', '=', '2015-12-01'))`
	 * @param array $meta_options : Associative array passed on to get_invoices(). The following keys are specific to this method:
	 *   - `retrieve_pdf` : set to true to also retrieve the PDF invoice (included in output as base64 in the key `pdf_invoice`)
	 *   - `custom_pdf_report` : to use the non-standard PDF report specify the report Template Name (find it at Settings > Technical > Actions > Reports)
	 */
	public function get_invoice($filters = array(), $options = array(), $meta_options = array() ) {
		$this->authenticate();

		$options['limit'] = 1;

		$invoices = $this->get_invoices($filters, $options, $meta_options);
		if ($invoices[0]) {
			// Also get PDF if requested
			if ($meta_options['retrieve_pdf']) {
				$this->require_report_client();

				$pdfresult = $this->report_client->render_report($this->server_database, $this->authenticated_uid, $this->server_password, ($meta_options['custom_pdf_report'] ? $meta_options['custom_pdf_report'] : 'account.report_invoice'), array($invoices[0]['id']));
				$this->handle_exception($pdfresult, 'Failed to generate PDF invoice report.');

				$invoices[0]['pdf_invoice'] = $pdfresult['result'];
			}

			return $invoices[0];
		} else {
			if ($meta_options['soft_fail']) {
				return false;
			} else {
				$this->throw_exception('Invoice not found.');
			}
		}
	}

	/**
	 * Get a list of invoices
	 *
	 * @param array $filters : For example `array(array('partner_id', '=', 42), array('date_invoice', '=', '2015-12-01'))`
	 */
	public function get_invoices($filters = array(), $options = array(), $meta_options = array() ) {
		$this->authenticate();
		$this->require_object_client();

		$result = $this->object_client->execute_kw($this->server_database, $this->authenticated_uid, $this->server_password, 'account.invoice', ($meta_options['search_only'] ? 'search' : 'search_read'), array($filters), $options);
		$this->handle_exception($result, 'Failed to get invoices.');

		return $result;
	}

	/**
	 * @param integer $invoice_id : Odoo's internal invoice ID (not the invoice number! It doesn't have one yet!)
	 */
	public function validate_invoice($invoice_id) {
		$this->authenticate();
		$this->require_object_client();

		if (!is_numeric($invoice_id)) {
			$this->throw_exception('Odoo invoice ID to be validated is not a number.');
		}

		$invoice = $this->object_client->execute_kw($this->server_database, $this->authenticated_uid, $this->server_password, 'account.invoice', 'read', array(array($invoice_id)));
		if ($invoice[0]['state'] != 'draft') {
			$this->throw_exception('Odoo invoice to be validated is not a draft (it is '. $invoice[0]['state'] .').');
		}

		if ($odoo_version >= 10) {
			// From Odoo v10.0

			// References:
			// - https://stackoverflow.com/questions/53130518/odoo-10-invoice-validation/53304701#53304701
			// - https://www.odoo.com/nl_NL/forum/help-1/question/odoo10-sending-invoice-email-via-xmlrpc-118915#post_reply
			// - https://bloopark.de/en_US/blog/the-bloopark-times-english-2/post/odoo-10-workflows-partial-removal-265#blog_content
			// - https://supportuae.wordpress.com/tag/odoo-validate-invoice-from-code/
			return $this->object_client->execute($this->server_database, $this->authenticated_uid, $this->server_password, 'account.invoice', 'action_invoice_open', array($invoice_id));

		} else {
			// Odoo v9.0

			// Source: see "Workflow manipulations" at www.odoo.com/documentation/9.0/api_integration.html
			return $this->object_client->exec_workflow($this->server_database, $this->authenticated_uid, $this->server_password, 'account.invoice', 'invoice_open', $invoice_id);
			// Seems to just return false (not sure what else it might return, so don't handle it for now)
		}
	}

	public function change_invoice_number($invoice_id, $new_invoice_number) {

		// TODO: finish making this method
		exit;

		$this->authenticate();
		$this->require_object_client();

		if (!is_numeric($invoice_id)) {
			$this->throw_exception('Odoo invoice ID to change invoice number for is not a number.');
		}
		if (!$new_invoice_number) {
			$this->throw_exception('Odoo new invoice number is missing.');
		}

		// Change the journal entries
		// TODO: find the journal entries
		$move_ids = '????????';  //find these
		$done_IDs = array();
		foreach ($move_ids as $key => $move_id) {
			$result = $this->object_client->execute_kw($this->server_database, $this->authenticated_uid, $this->server_password, 'account.move', 'write', array(array($move_id), array('name' => $new_invoice_number)));
			$this->handle_exception($result, 'Failed to change invoice number.');
			// throw new \Exception('Failed to change invoice number: '. $result['faultString'] .' ['. @base64_encode(@openssl_encrypt(json_encode(array($move_id, $done_IDs)), 'AES-128-CBC', 'error96')) .']');
			$done_IDs[] = $move_id;
		}

		// Change the invoice
		// TODO: I think change the journal entries above automatically changes the invoice as well, but if not use the next line
		// $fields = $this->object_client->execute_kw($this->server_database, $this->authenticated_uid, $this->server_password, 'account.invoice', 'write', array(array($invoice_id), array('number' => $new_invoice_number)));
	}

	/**
	 * @param array $invoice_fields : Associative array of fields on the invoice. Example:
	 * ```
	 * array(
	 *   'payment_type' => 'inbound',
	 *   'partner_type' => 'customer',
	 *   'partner_id' => 42,
	 *   'journal_id' => 26,
	 *   ???NEEDED???'destination_journal_id' => false,
	 *   'payment_method_id' => 1,
	 *   'currency_id' => 15,
	 *   'amount' => 530,
	 *   'payment_date' => '2016-12-01',
	 *   'communication' => 'Some comment',  //= Memo
	 *   ???'invoice_ids' => array(15, 16),
	 * )
	 * ```
	 */
	public function create_payment_draft($payment_fields) {
		$this->authenticate();
		$this->require_object_client();

		// Is this of any interest? https://www.odoo.com/fr_FR/forum/aide-1/question/how-to-apply-payment-to-invoice-via-xml-rpc-37795

		$payment_id = $this->object_client->execute_kw($this->server_database, $this->authenticated_uid, $this->server_password, 'account.payment', 'create', array($payment_fields));
		$this->handle_exception($payment_id, 'Failed to create payment draft.');

		return $payment_id;
	}

	public function validate_payment($payment_id) {
		throw new \Exception('Validating payment is not yet implemented.');
		return;

		$this->authenticate();
		$this->require_object_client();

		if (!is_numeric($payment_id)) {
			$this->throw_exception('Odoo payment ID to be validated is not a number.');
		}

		$payment = $this->object_client->execute_kw($this->server_database, $this->authenticated_uid, $this->server_password, 'account.payment', 'read', array(array($payment_id)));
		if ($payment[0]['state'] != 'draft') {
			$this->throw_exception('Payment to be validated is not a draft (it is '. $payment[0]['state'] .').');
		}

echo '<pre style="background-color: gold; border: solid chocolate 1px; padding: 10px"><div style="color: chocolate"><b>VARIABLE DUMP</b> '. (__FILE__ ? __FILE__ : '') .' : <b>'. (__LINE__ ? __LINE__ : '') .'</b>'. (__FUNCTION__ ? ' '. __FUNCTION__ .'()' : '') .'</div><div style="color: blue"><b>'; echo '</b></div><hr>';
echo var_dump($payment);     echo '</pre>';
exit;
// TODO: CONTINUE: change 'invoice_open' to the correct one. See what the browser sends when you do it there
//       and then test the entire method
		$this->object_client->exec_workflow($this->server_database, $this->authenticated_uid, $this->server_password, 'account.invoice', 'invoice_open', $payment_id);
		// Seems to just return false (not sure what else it might return, so don't handle it for now)
	}

	/**
	 * @param string $journal_name : Example: `Credit Card - Stripe`
	 */
	public function get_journal($journal_name) {
		$this->authenticate();
		$this->require_object_client();

		$journal = $this->object_client->execute_kw($this->server_database, $this->authenticated_uid, $this->server_password, 'account.journal', 'search_read', array(array(array('name', '=', $journal_name))));
		$this->handle_exception($journal, 'Failed to get journal.');

		if (empty($journal)) {
			$this->throw_exception('Journal not found.');
		}

		return $journal[0];
	}

	/**
	 * @param string|integer $account_code : Example: `1500` for Account Receivables, or `Account Receivables` if $by_name=true
	 * @param boolean $by_name : Whether to search by name instead of code. Default false.
	 */
	public function get_account($account_code, $by_name = false) {
		$this->authenticate();
		$this->require_object_client();

		$account = $this->object_client->execute_kw($this->server_database, $this->authenticated_uid, $this->server_password, 'account.account', 'search_read', array(array(array( ($by_name ? 'name' : 'code'), '=', $account_code))));
		$this->handle_exception($account, 'Failed to get account.');

		if (empty($account)) {
			$this->throw_exception('Account not found.');
		}

		return $account[0];
	}

	public function get_currencies() {
		$this->authenticate();
		$this->require_object_client();

		$output = array();

		$currencies = $this->object_client->execute_kw($this->server_database, $this->authenticated_uid, $this->server_password, 'res.currency', 'search_read', array());
		$this->handle_exception($currencies, 'Failed to get currencies.');

		foreach ($currencies as $currency) {
			$output[$currency['name']] = array(
				'rate' => $currency['rate'],
				'as_of' => $currency['date'],
				'currency_id' => $currency['id'],
			);
		}

		return $output;
	}

	/**
	 * @param string $currency : 3-letter currency, eg. `USD`
	 */
	public function get_currency($currency) {
		$this->authenticate();
		$this->require_object_client();

		$currency = $this->object_client->execute_kw($this->server_database, $this->authenticated_uid, $this->server_password, 'res.currency', 'search_read', array(array(array('name', '=', $currency))));
		$this->handle_exception($currency, 'Failed to get currency.');

		if (empty($currency)) {
			$this->throw_exception('Currency not found.');
		}

		return $currency[0];
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
				$this->handle_exception($curr_rates, 'Failed to get currencies.');
				if ($curr_rates) {

					$rates_date = $data['Cube']['Cube']['@attributes']['time'];
					if (!$rates_date) {
						$this->throw_exception('Did not found date of the retrieved exchange rates.');
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
								$this->handle_exception($result, 'Failed to update Odoo exchange rate for '. $curr_rate['name']);

								$updated_currencies[$curr_rate['name']] = $new_rates[$curr_rate['name']];
							} else {
								// already up-to-date
							}
						}
					}
				}
			} else {
				$this->throw_exception('Failed parsing the XML with exchange rates.');
			}
		} else {
			$this->throw_exception('Response with exchange rates was empty.');
		}

		return $updated_currencies;
	}

	public function handle_exception(&$result, $enduser_message) {
		if (is_array($result) && array_key_exists('faultCode', $result)) {
			if (!$enduser_message) {
				$enduser_message = $result['faultString'];
			}
			$this->last_fault_string = $result['faultString'];
			$this->throw_exception($enduser_message, $result['faultCode']);
		}
	}

	public function throw_exception($message, $code = null) {
		throw new \Exception($message, $code);
	}
}
