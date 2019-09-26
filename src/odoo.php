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
	 */
	public function __construct($server_url, $server_database, $server_username, $server_password, $options = array()) {
		$this->server_url = $server_url;
		$this->server_database = $server_database;
		$this->server_username = $server_username;
		$this->server_password = $server_password;
		$this->options = $options;
	}

	public function require_common_client() {
		if (!$this->common_client) {
			$this->common_client = Ripcord::client($this->server_url . '/xmlrpc/2/common', null, $this->create_stream());
			if (!$this->common_client) {
				$this->error('Failed to create Odoo common client.');
			}
		}
	}

	public function require_object_client() {
		if (!$this->object_client) {
			$this->object_client = Ripcord::client($this->server_url . '/xmlrpc/2/object', null, $this->create_stream());
			if (!$this->object_client) {
				$this->error('Failed to create Odoo object client.');
			}
		}
	}

	public function require_report_client() {
		if (!$this->report_client) {
			$this->report_client = Ripcord::client($this->server_url . '/xmlrpc/2/report', null, $this->create_stream());
			if (!$this->report_client) {
				$this->error('Failed to create Odoo report client.');
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
				$this->error('Failed to authenticate to Odoo.');
			}
		}
	}

	/**
	 * Get Odoo version
	 *
	 * @param string $flag : `cleanMajor` to only retrieve major version number (integer), or `base` to retrieve version of the base module, or `modules` to retrieve versions of all modules (usually only admin is allowed to do these two last ones)
	 * @return mixed : Examples:
	 *   - if no flag : array. Example: `['server_serie' => '10.0', 'server_version_info' => [10, 0, 0, 'final', 0, ''), 'server_version' => '10.0', 'protocol_version' => 1]`
	 *   - if flag `cleanMajor` : `10`
	 *   - if flag `base` : `10.0.1.3`
	 *   - if flag `modules` : array
	 */
	public function get_version($flag = null) {
		if ($flag === 'base' || $flag === 'modules') {
			$this->authenticate();
			$this->require_object_client();
			if ($flag === 'base') {
				$result = $this->object_client->execute_kw($this->server_database, $this->authenticated_uid, $this->server_password, 'ir.module.module', 'search_read', array(array(array('name', '=', 'base'))) );
				return $result[0]['installed_version'];
			} elseif ($flag === 'modules') {
				$result = $this->object_client->execute_kw($this->server_database, $this->authenticated_uid, $this->server_password, 'ir.module.module', 'search_read', array() );
				return $result;
			}
		} else {
			$this->require_common_client();
			if ($flag === 'cleanMajor') {
				return (int) $this->common_client->version()['server_version_info'][0];
			} else {
				return $this->common_client->version();
			}
		}
	}

	/**
	 * Get all API models
	 *
	 * Only admin is usually allowed to do this.
	 */
	public function get_models() {
		$this->authenticate();
		$this->require_object_client();
		return $this->object_client->execute_kw($this->server_database, $this->authenticated_uid, $this->server_password, 'ir.model', 'search_read', array() );
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
	 * @param array $options : Available options:
	 *   - `skip_validate` : set true to skip validating the journal entry, only create draft
	 */
	public function create_journal_entry($journal_details, $lines, $options = array()) {
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
		if (!$options['skip_validate']) {
			$this->object_client->execute($this->server_database, $this->authenticated_uid, $this->server_password, 'account.move', 'post', array($move_id));
		}

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
				$this->error('Invoice not found.');
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
	 * Validate/finalize an invoice
	 *
	 * WARNING: Once an invoice has been validated and assigned an invoice number it can never be permanently deleted again.
	 * At best you can cancel it and revert back to draft or canceled status - but not permanently deleted it.
	 * 
	 * @param integer $invoice_id : Odoo's internal invoice ID (not the invoice number! It doesn't have one yet!)
	 */
	public function validate_invoice($invoice_id) {
		$this->authenticate();
		$this->require_object_client();

		if (!is_numeric($invoice_id)) {
			$this->error('Odoo invoice ID to be validated is not a number.');
		}

		$invoice = $this->object_client->execute_kw($this->server_database, $this->authenticated_uid, $this->server_password, 'account.invoice', 'read', array(array($invoice_id)));
		if ($invoice[0]['state'] != 'draft') {
			$this->error('Odoo invoice to be validated is not a draft (it is '. $invoice[0]['state'] .').');
		}

		if ($this->get_version('cleanMajor') >= 10) {
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
			$this->error('Odoo invoice ID to change invoice number for is not a number.');
		}
		if (!$new_invoice_number) {
			$this->error('Odoo new invoice number is missing.');
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
			$this->error('Odoo payment ID to be validated is not a number.');
		}

		$payment = $this->object_client->execute_kw($this->server_database, $this->authenticated_uid, $this->server_password, 'account.payment', 'read', array(array($payment_id)));
		if ($payment[0]['state'] != 'draft') {
			$this->error('Payment to be validated is not a draft (it is '. $payment[0]['state'] .').');
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
			$this->error('Journal not found.');
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

		$account = $this->object_client->execute_kw($this->server_database, $this->authenticated_uid, $this->server_password, 'account.account', 'search_read', array(array(array( ($by_name ? 'name' : 'code'), '=', (string) $account_code))));
		$this->handle_exception($account, 'Failed to get account.');

		if (empty($account)) {
			$this->error('Account not found.');
		}

		return $account[0];
	}

	/**
	 * Get all accounts
	 */
	public function get_accounts() {
		$this->authenticate();
		$this->require_object_client();

		$accounts = $this->object_client->execute_kw($this->server_database, $this->authenticated_uid, $this->server_password, 'account.account', 'search_read', $this->odoo_search_parameters(['order' => 'code']));
		$this->handle_exception($accounts, 'Failed to get accounts.');

		return $accounts;
	}

	/**
	 * @param stringer|integer $id : Example: `7052`, or `WinterNet Studio` if $by_name=true
	 * @param boolean $by_name : Whether to search by name instead of partner ID. Default false.
	 */
	public function get_partner($id, $by_name = false) {
		$this->authenticate();
		$this->require_object_client();

		$partner = $this->object_client->execute_kw($this->server_database, $this->authenticated_uid, $this->server_password, 'res.partner', 'search_read', array(array(array( ($by_name ? 'name' : 'id'), '=', $id))));
		$this->handle_exception($partner, 'Failed to get partner.');

		if (empty($partner)) {
			$this->error('Partner not found.');
		}

		return $partner[0];
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
			$this->error('Currency not found.');
		}

		return $currency[0];
	}

	public function get_account_opening_balance() {
		// TODO: both for all time and for a given year
		$this->error('get_account_opening_balance is not yet implemented.');
	}

	/**
	 * @param array $params : Available parameters:
	 *   `filters` : Example: `[ ['account_id', '=', 7034], ['date', '>=', '2019-01-01'], ['date', '<=', '2019-12-31'] ]`
	 *   `fields` : Example: `['display_name', 'contact_address', 'credit']`
	 *   `offset` : 
	 *   `limit` : 
	 *   `order` : Example: `date, move_id` or `account_id, date` or `account_id, date DESC`
	 */
	public function odoo_search_parameters($params = array()) {
		return array($params['filters'], $params['fields'], $params['offset'], $params['limit'], $params['order']);
	}

	/**
	 * @param array $accounts : Account codes
	 * @param string $from : From date in MySQL format: yyyy-mm-dd
	 * @param string $to : To date in MySQL format: yyyy-mm-dd
	 */
	public function get_general_ledger($accounts = array(), $from = null, $to = null, $order = null) {
		$this->authenticate();
		$this->require_object_client();

		if ($from && !preg_match("/^\\d+-\\d+-\\d+$/", $from)) {
			$this->error('Invalid starting date when getting general ledger.', null, ['Date' => $from]);
		}
		if ($to && !preg_match("/^\\d+-\\d+-\\d+$/", $to)) {
			$this->error('Invalid ending date when getting general ledger.', null, ['Date' => $to]);
		}

		$filters = [];
		if (!empty($accounts)) {
			if (count($accounts) === 1) {
				$filters[] = array('account_id', '=', $this->get_account(current($accounts))['id']);
			} else {
				// NOTE: tried to understand how to write "logical OR" but didn't quite get it: https://www.odoo.com/th_TH/forum/help-1/question/how-to-use-logical-or-operator-with-xml-rpc-25694
				// So instead we just filter it manually below!
				$manual_account_filter = true;
				// Protect the regular expression further below by checking numeric values
				foreach ($accounts as $curr_account) {
					if (!is_numeric($curr_account)) {
						$this->error('Invalid account code when getting general ledger.', null, ['Accounts' => $accounts, 'Curr account' => $curr_account]);
					}
				}
			}
		}
		if ($from) {
			$filters[] = array('date', '>=', $from);
		}
		if ($to) {
			$filters[] = array('date', '<=', $to);
		}
		if ($order === 'date') {
			$order = 'date, move_id';
		} elseif ($order === 'account') {
			$order = 'account_id, date';  //order descendingly: eg. 'date DESC'
		}
		$params = $this->odoo_search_parameters(array(
			'filters' => $filters,
			'order' => $order,
		));

		$data = $this->object_client->execute_kw($this->server_database, $this->authenticated_uid, $this->server_password, 'account.move.line', 'search_read', $params);
		$this->handle_exception($data, 'Failed to get general ledger data.');

		if ($manual_account_filter && !empty($data)) {
			$filtered = false;
			foreach ($data as $curr_key => $curr_item) {
				if (!preg_match("/^(". implode('|', $accounts) .") /", $curr_item['account_id'][1])) {
					unset($data[$curr_key]);
					$filtered = false;
				}
			}
			if ($filtered) {
				// Reset keys so they are sequential
				$data = array_values($data);
			}
		}

		return $data;
	}

	/**
	 * @param array $data : Output from get_general_ledger()
	 * @param string $from : From date in MySQL format: yyyy-mm-dd
	 * @param string $to : To date in MySQL format: yyyy-mm-dd
	 */
	public function get_general_ledger_html($data, $accounts = array(), $from = null, $to = null, $orderby = null, $options = array()) {
		ob_start();

		// About getting account's initial balance: https://stackoverflow.com/questions/57231479/how-can-i-get-opening-and-closing-balance-using-function-for-partner-ledger-repo

		if ($orderby === 'date') {
			$title = 'Transaction List by Date';
		} elseif ($orderby === 'account') {
			$title = 'Transaction List by Account';
		} elseif ($orderby) {
			$title = 'Transaction List by '. $orderby;
		} else {
			$title = 'Transaction List';
		}
?>
<h3 class="text-center"><?= $title ?></h3>
<?php
		if ($from || $to) {
?>
<div class="text-center">Period: <b><?= $from ?> - <?= $to ?></b></div>
<?php
		}
		if (!empty($accounts)) {
?>
<div class="text-center"><?= (count($accounts) === 1 ? 'Account' : 'Accounts') ?>: <b><?= implode(', ', $accounts) ?></b></div>
<?php
}
?>
<div class="text-right"><small>Report generated: <?= (new \DateTime(null, new \DateTimeZone(($options['timezone'] ? $options['timezone'] : 'Europe/Copenhapen'))))->format('Y-m-d H:i:s') ?></small></div>
<table class="table table-condensed table-striped table-hover">
<?php
		if ($orderby === 'date') {
?>
<tr>
	<th class="dont-print">ID</th>
	<th>Date</th>
	<th>Account</th>
	<th>Partner</th>
	<th>Description</th>
	<th class="text-right">Currency</th>
	<th class="text-right">Debit</th>
	<th class="text-right">Credit</th>
	<th class="text-right">
<?php
			if (count($accounts) === 1) {
				echo 'Balance';
			}
?>
	</th>
</tr>
<?php
		}
		$count = 0;
		foreach ($data as $line) {
			if ($orderby !== 'date' && $last_account !== $line['account_id'][1]) {
?>
<tr>
	<th colspan="8" class="account-header"><h4 style="padding-top: 15px; padding-bottom: 0"><strong><?= $line['account_id'][1] ?></strong></h4></th>
</tr>
<tr>
	<th class="dont-print">ID</th>
	<th>Date</th>
	<th>Partner</th>
	<th>Description</th>
	<th class="text-right">Currency</th>
	<th class="text-right">Debit</th>
	<th class="text-right">Credit</th>
	<th class="text-right">Balance</th>
</tr>
<?php
				$last_account = $line['account_id'][1];
				$total = 0;
			}

			$total += $line['debit'] - $line['credit'];
			$total = round($total, 2);

			if ($options['enable_date_warnings']) {
				// Gør opmærksom på årets postering bogført i tidligere år - vil sandsynligvis være en fejl!
				$next_year = substr($line['date'], 0, 4) + 1;
			}

			$count++;
?>
<tr>
	<td class="dont-print">
<?php
			if ($options['odoo_url']) {
?>
	<a href="<?= $options['odoo_url'] ?>/web#id=<?= $line['move_id'][0] ?>&view_type=form&model=account.move" rel="noopener noreferrer" target="_blank"><?= $line['id'] ?></a>
<?php
			} else {
				echo $line['id'];
			}
?>
	</td>
	<td nowrap><?= $line['date'] ?> <?php
if ($options['enable_date_warnings']) {
	echo (strtotime($line['create_date']) > strtotime($next_year .'-07-01') ? ' <span style="font-weight: bold; color: red" title="Possibly used wrong date!">Created '. $line['create_date'] .'!</span>' : '');
}
?></td>
<?php
			if ($orderby === 'date') {
?>
	<td><?= $line['account_id'][1] ?></td>
<?php
			}
?>
	<td><?= $line['partner_id'][1] . ($line['invoice_id'][1] ? ', '. $line['invoice_id'][1] : '') ?></td>
	<td><?= trim($line['name'], '/') . ($line['ref'] ? '<br><small>'. $line['ref'] .'</small>' : '') ?></td>
	<td class="text-right" nowrap><?= ($line['amount_currency'] ? number_format($line['amount_currency'], 2, ',', '.') .' '. $line['currency_id'][1] : '') ?></td>
	<td class="text-right" nowrap><?= (0 == $line['debit'] ? '' : number_format($line['debit'], 2, ',', '.')) ?></td>
	<td class="text-right" nowrap><?= (0 == $line['credit'] ? '' : number_format($line['credit'], 2, ',', '.')) ?></td>
	<td class="text-right" nowrap><?= ($orderby !== 'date' || count($accounts) === 1 ? number_format($total, 2, ',', '.') : '') ?></td>
<?php
// echo '<td><pre style="background-color: gold; border: solid chocolate 1px; padding: 10px"><div style="color: chocolate"><b>VARIABLE DUMP</b> '. (__FILE__ ? __FILE__ : '') .' : <b>'. (__LINE__ ? __LINE__ : '') .'</b>'. (__FUNCTION__ ? ' '. __FUNCTION__ .'()' : '') .'</div><div style="color: blue"><b>'; echo '</b></div><hr>';
// var_dump($line);     echo '</pre></td>';
?>
</tr>
<?php	
		}
?>
</table>
<div class="small">Transactions: <?= $count ?></div>
<?php
		return ob_get_clean();
	}

	public function get_profit_and_loss_data($year = null) {
		$this->authenticate();
		$this->require_object_client();

		if (!is_numeric($year)) {
			$year = date('Y');
		}

		$this->odoo_search_parameters(array(
			'filters' => array(
				array('date', '>=', $year .'-01-01'), array('date', '<=', $year .'-12-31')
			),
		));

		$data = $this->object_client->execute_kw($this->server_database, $this->authenticated_uid, $this->server_password, 'res.currency', 'search_read', $params);
		$this->handle_exception($data, 'Failed to get profit and loss data.');

		return $data;
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
						$this->error('Did not found date of the retrieved exchange rates.');
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
				$this->error('Failed parsing the XML with exchange rates.');
			}
		} else {
			$this->error('Response with exchange rates was empty.');
		}

		return $updated_currencies;
	}

	public function handle_exception(&$result, $enduser_message) {
		if (is_array($result) && array_key_exists('faultCode', $result)) {
			if (!$enduser_message) {
				$enduser_message = $result['faultString'];
			}
			$this->last_fault_string = $result['faultString'];
			$this->error(rtrim($enduser_message, '.') .'. Please check odoo->last_fault_string for details.', $result['faultCode'], ['FaultString' => $result['faultString']]);
		}
	}

	/**
	 * @param string $message : Error message for the end-user
	 */
	public function error($message, $code = null, $details = []) {
		core::system_error($message .' (Code '. $code .')', $details);
	}
}
