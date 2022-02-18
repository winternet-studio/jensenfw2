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
	var $odoo_major_version;
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
	public function __construct($server_url, $server_database, $server_username, $server_password, $options = []) {
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

			$this->authenticated_uid = $this->common_client->authenticate($this->server_database, $this->server_username, $this->server_password, []);
			$this->handle_exception($this->authenticated_uid, 'Failed to authenticate.');

			if ($this->authenticated_uid) {
				$this->is_authenticated = true;
			} else {
				$this->error('Failed to authenticate to Odoo.');
			}
		}
	}

	public function change_active_company($companyID, $userID = null) {
		$this->authenticate();

		if (!$userID) {
			$userID = $this->authenticated_uid;
		}

		$write = $this->write('res.users', [[$userID], ['company_id' => $companyID]]);
		$this->handle_exception($write, 'Failed to change active company.');
	}

	public function execute_kw($model, $operation, $args, $context = null) {
		$this->authenticate();
		$this->require_object_client();
		return $this->object_client->execute_kw($this->server_database, $this->authenticated_uid, $this->server_password, $model, $operation, $args, $context);
	}

	/**
	 * Generic read method
	 *
	 * @param string $model : Example: `account.invoices`
	 * @param array $args
	 */
	public function read($model, $args) {
		$this->authenticate();
		$this->require_object_client();
		return $this->execute_kw($model, 'read', $args);
	}

	/**
	 * Generic search and read method
	 *
	 * @param string $model : Example: `account.invoices`
	 * @param array $args : Example: `[[['name', '=', 'base'], ['date', '>', '2019-01-01']]]`
	 */
	public function search_read($model, $args) {
		$this->authenticate();
		$this->require_object_client();
		return $this->execute_kw($model, 'search_read', $args);
	}

	/**
	 * Generic read and group method
	 *
	 * Source code for read_group: https://github.com/odoo/odoo/blob/d4d5181285196f6c3e311a6afdd356f4dc2851ef/openerp/models.py#L2027
	 *
	 * @param string $model : Example: `account.invoices`
	 * @param array $args
	 */
	public function read_group($model, $args) {
		$this->authenticate();
		$this->require_object_client();
		return $this->execute_kw($model, 'read_group', $args);
	}

	/**
	 * Generic update method
	 *
	 * @param string $model : Example: `account.invoices`
	 * @param array $args
	 */
	public function update($model, $args) {
		$this->authenticate();
		$this->require_object_client();
		return $this->execute_kw($model, 'update', $args);
	}

	/**
	 * Generic create method
	 *
	 * @param string $model : Example: `account.invoices`
	 * @param array $args
	 * @param array $context
	 */
	public function create($model, $args, $context = null) {
		$this->authenticate();
		$this->require_object_client();
		return $this->execute_kw($model, 'create', $args, $context);
	}

	/**
	 * Generic write method
	 *
	 * @param string $model : Example: `account.invoices`
	 * @param array $args
	 */
	public function write($model, $args) {
		$this->authenticate();
		$this->require_object_client();
		return $this->execute_kw($model, 'write', $args);
	}

	/**
	 * Generic unlink method
	 *
	 * @param string $model : Example: `account.invoices`
	 * @param array $args
	 */
	public function unlink($model, $args) {
		$this->authenticate();
		$this->require_object_client();
		return $this->execute_kw($model, 'unlink', $args);
	}

	/**
	 * @param array $params : Available parameters:
	 *   `filters` : Example: `[ ['account_id', '=', 7034], ['date', '>=', '2019-01-01'], ['date', '<=', '2019-12-31'] ]`
	 *   `fields` : Example: `['display_name', 'contact_address', 'credit']`
	 *   `offset` :
	 *   `limit` :
	 *   `order` : Example: `date, move_id` or `account_id, date` or `account_id, date DESC`
	 */
	public function search_parameters($params = []) {
		return [$params['filters'], $params['fields'], $params['offset'], $params['limit'], $params['order']];
	}

	/**
	 * Get Odoo version
	 *
	 * @param string $flag : `major` to only retrieve major version number (integer), or `base` to retrieve version of the base module, or `modules` to retrieve versions of all modules (usually only admin is allowed to do these two last ones)
	 * @return mixed : Examples:
	 *   - if no flag : array. Example: `['server_serie' => '10.0', 'server_version_info' => [10, 0, 0, 'final', 0, ''), 'server_version' => '10.0', 'protocol_version' => 1]`
	 *   - if flag `major` : `10`
	 *   - if flag `base` : `10.0.1.3`
	 *   - if flag `modules` : array
	 */
	public function get_version($flag = null) {
		if ($flag === 'base' || $flag === 'modules') {
			if ($flag === 'base') {
				$result = $this->search_read('ir.module.module', [[['name', '=', 'base']]]);
				return $result[0]['installed_version'];
			} elseif ($flag === 'modules') {
				$result = $this->search_read('ir.module.module', []);
				return $result;
			}
		} else {
			$this->require_common_client();
			if ($flag === 'major' || $flag === 'majorVersion' /*deprecated*/) {
				if (!$this->odoo_major_version) {
					$this->odoo_major_version = (int) $this->common_client->version()['server_version_info'][0];
				}
				return $this->odoo_major_version;
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
		return $this->search_read('ir.model', []);
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
	public function create_journal_entry($journal_details, $lines, $options = []) {
		// Create journal
		$move_id = $this->create('account.move', [$journal_details]);
		$this->handle_exception($move_id, 'Failed to create journal entry.');

		// Create journal items
		$lines_count = count($lines);
		$counter = 0;
		$line_ids = [];
		foreach ($lines as $line) {
			$counter++;

			$line['move_id'] = $move_id;

			if ($counter < $lines_count) {
				$context = ['context' => ['check_move_validity' => false]];
			} else {
				$context = ['context' => ['check_move_validity' => true]];
			}
			$item_id = $this->create('account.move.line', [$line], $context);
			$this->handle_exception($item_id, 'Failed to create journal item.');

			$line_ids[] = $item_id;
		}

		// Validate it
		if (!$options['skip_validate']) {
			$this->object_client->execute($this->server_database, $this->authenticated_uid, $this->server_password, 'account.move', 'post', [$move_id]);
		}

		return [
			'move_id' => $move_id,
			'line_ids' => $line_ids,
		];
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
		// TODO: option to specify currency_id with the 3-letter currency code instead (eg. 'USD')

		$invoice_id = $this->create('account.invoice', [$invoice_fields]);
		$this->handle_exception($invoice_id, 'Failed to create invoice.');

		// Calculate taxes if applicable
		$this->object_client->execute_kw($this->server_database, $this->authenticated_uid, $this->server_password, 'account.invoice', 'compute_taxes', $invoice_id);

		// Add the newly created invoice id to each line
		foreach ($invoice_lines as &$line) {
			$line['invoice_id'] = $invoice_id;
		}

		// Add invoice lines
		$successful_lines = $invoice_line_ids = [];
		foreach ($invoice_lines as $key => $invoice_line) {
			$result_invline = $this->create('account.invoice.line', [$invoice_line]);
			$this->handle_exception($result_invline, 'Failed to create Odoo invoice line.');
			// throw new \Exception(': '. $result_invline['faultString'] .' ['. @base64_encode(@openssl_encrypt(json_encode(array($successful_lines, $invoice_line_ids)), 'AES-128-CBC', 'error96')) .']');

			$invoice_line_ids[] = $result_invline;
			$successful_lines[] = $key;
		}

		return [
			'invoice_id' => $invoice_id,
			'invoice_line_ids' => $invoice_line_ids,
		];
	}

	/**
	 * Get a one invoices
	 *
	 * @param array $filters : For example `array(array('partner_id', '=', 42), array('date_invoice', '=', '2015-12-01'))`
	 * @param array $meta_options : Associative array passed on to get_invoices(). The following keys are specific to this method:
	 *   - `retrieve_pdf` : set to true to also retrieve the PDF invoice (included in output as base64 in the key `pdf_invoice`)
	 *   - `custom_pdf_report` : to use the non-standard PDF report specify the report Template Name (find it at Settings > Technical > Actions > Reports)
	 *   - `incl_lines` : set to true to also include the invoice lines (automatically included when PDF is generated)
	 */
	public function get_invoice($filters = [], $options = [], $meta_options = []) {
		$this->authenticate();

		$options['limit'] = 1;

		$invoices = $this->get_invoices($filters, $options, $meta_options);
		if ($invoices[0]) {
			// Also get PDF if requested
			if ($meta_options['retrieve_pdf']) {
				$this->require_report_client();

				$pdfresult = $this->report_client->render_report($this->server_database, $this->authenticated_uid, $this->server_password, ($meta_options['custom_pdf_report'] ? $meta_options['custom_pdf_report'] : 'account.report_invoice'), [$invoices[0]['id']]);
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
	 * @param array $filters : For example `array(array('partner_id', '=', 42), array('invoice_date', '=', '2015-12-01'))`
	 * @param array $meta_options : Associative array passed on to get_invoices(). The following keys are specific to this method:
	 *   - `incl_lines` : set to true to also include the invoice lines (automatically included when PDF is generated)
	 */
	public function get_invoices($filters = [], $options = [], $meta_options = []) {
		$this->authenticate();
		$this->require_object_client();

		if (!is_array($options)) $options = [];

		if ($this->get_version() >= 13) {
			$model = 'account.move';
			$filters[] = ['move_type', '=', 'out_invoice'];
			foreach ($filters as $filter) {
				if ($filter[0] === 'date_invoice') {  //for backward compatibility
					$filter[0] = 'invoice_date';  //name of field has been changed
				}
				if ($filter[0] === 'number') {  //for backward compatibility
					$filter[0] = 'sequence_number';  //name of field has been changed
				}
			}
		} else {
			$model = 'account.invoice';
		}

		$result = $this->object_client->execute_kw($this->server_database, $this->authenticated_uid, $this->server_password, $model, ($meta_options['search_only'] ? 'search' : 'search_read'), [$filters], $options);
		$this->handle_exception($result, 'Failed to get invoices.');

		if ($meta_options['incl_lines'] && !$meta_options['search_only']) {
			foreach ($result as &$invoice) {
				if ($this->get_version() >= 13) {
					$invoice['_invoice_line_items'] = $this->search_read('account.move.line', [[['move_id', '=', $invoice['id']], ['exclude_from_invoice_tab', '=', false]]]);
				} else {
					$invoice['_invoice_line_items'] = $this->search_read('account.invoice.line', [[['invoice_id', '=', $invoice['id']]]]);
				}
			}
		}

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
		if (!is_numeric($invoice_id)) {
			$this->error('Odoo invoice ID to be validated is not a number.');
		}

		$invoice = $this->read('account.invoice', [[$invoice_id]]);
		if ($invoice[0]['state'] != 'draft') {
			$this->error('Odoo invoice to be validated is not a draft (it is '. $invoice[0]['state'] .').');
		}

		if ($this->get_version('majorVersion') >= 10) {
			// From Odoo v10.0

			// References:
			// - https://stackoverflow.com/questions/53130518/odoo-10-invoice-validation/53304701#53304701
			// - https://www.odoo.com/nl_NL/forum/help-1/question/odoo10-sending-invoice-email-via-xmlrpc-118915#post_reply
			// - https://bloopark.de/en_US/blog/the-bloopark-times-english-2/post/odoo-10-workflows-partial-removal-265#blog_content
			// - https://supportuae.wordpress.com/tag/odoo-validate-invoice-from-code/
			return $this->object_client->execute($this->server_database, $this->authenticated_uid, $this->server_password, 'account.invoice', 'action_invoice_open', [$invoice_id]);

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

		if (!is_numeric($invoice_id)) {
			$this->error('Odoo invoice ID to change invoice number for is not a number.');
		}
		if (!$new_invoice_number) {
			$this->error('Odoo new invoice number is missing.');
		}

		// Change the journal entries
		// TODO: find the journal entries
		$move_ids = '????????';  //find these
		$done_IDs = [];
		foreach ($move_ids as $key => $move_id) {
			$result = $this->write('account.move', [[$move_id], ['name' => $new_invoice_number]]);
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
		// Is this of any interest? https://www.odoo.com/fr_FR/forum/aide-1/question/how-to-apply-payment-to-invoice-via-xml-rpc-37795

		$payment_id = $this->create('account.payment', [$payment_fields]);
		$this->handle_exception($payment_id, 'Failed to create payment draft.');

		return $payment_id;
	}

	public function validate_payment($payment_id) {
		throw new \Exception('Validating payment is not yet implemented.');
		return;

		if (!is_numeric($payment_id)) {
			$this->error('Odoo payment ID to be validated is not a number.');
		}

		$payment = $this->read('account.payment', [[$payment_id]]);
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
	 * Get all journals
	 */
	public function get_journals() {
		$this->authenticate();
		$this->require_object_client();

		$journals = $this->search_read('account.journal', [ [] ]);
		$this->handle_exception($journals, 'Failed to get journals.');

		return $journals;
	}

	/**
	 * @param string $journal_name : Example: `Credit Card - Stripe`
	 */
	public function get_journal($journal_name) {
		$this->authenticate();
		$this->require_object_client();

		$journal = $this->search_read('account.journal', [[['name', '=', $journal_name]]]);
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
		$account = $this->search_read('account.account', [[[ ($by_name ? 'name' : 'code'), '=', (string) $account_code]]]);
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
		$accounts = $this->search_read('account.account', $this->search_parameters(['order' => 'code']));
		$this->handle_exception($accounts, 'Failed to get accounts.');

		return $accounts;
	}

	/**
	 * @param stringer|integer $id : Example: `7052`, or `WinterNet Studio` if $by_name=true
	 * @param boolean $by_name : Whether to search by name instead of partner ID. Default false.
	 */
	public function get_partner($id, $by_name = false) {
		$partner = $this->search_read('res.partner', [[[ ($by_name ? 'name' : 'id'), '=', $id]]]);
		$this->handle_exception($partner, 'Failed to get partner.');

		if (empty($partner)) {
			$this->error('Partner not found.');
		}

		return $partner[0];
	}

	public function get_currencies() {
		$this->authenticate();
		$this->require_object_client();

		$output = [];

		$currencies = $this->search_read('res.currency', []);
		$this->handle_exception($currencies, 'Failed to get currencies.');

		foreach ($currencies as $currency) {
			$output[$currency['name']] = [
				'rate' => $currency['rate'],
				'as_of' => $currency['date'],
				'currency_id' => $currency['id'],
			];
		}

		return $output;
	}

	/**
	 * @param string $currency : 3-letter currency, eg. `USD`
	 */
	public function get_currency($currency) {
		$this->authenticate();
		$this->require_object_client();

		$currency = $this->search_read('res.currency', [[['name', '=', $currency]]]);
		$this->handle_exception($currency, 'Failed to get currency.');

		if (empty($currency)) {
			$this->error('Currency not found.');
		}

		return $currency[0];
	}

	/**
	 * @param integer|array $accounts : Single account ID or array of account IDs (not the account codes)
	 * @param string $type : `alltime` or `year`
	 * @param string $until_date : date you want opening balance as of. Eg. `2019-05-01` will give you opening balance on the morning of that date.
	 * @param array $options : Available options:
	 *   - `rawResult` : set true to return the raw result from Odoo
	 * @return float|array : Only the balance number when single account is requested, or array with keys being account ID and value being the balance when multiple accounts were requested, eg.:
	 * ```
	 * [
	 * 	"6105": -615.81,
	 * 	"6165": -2381.79
	 * ]
	 * ```
	 *
	 * Example if rawResult option is set:  (not sure if there would ever be a difference between `balance` and `balance2`...)
	 *
	 * ```
	 * [
	 * 	{
	 * 		"account_id": [
	 * 			6105,
	 * 			"1011 Salg af Layout til Danmark"
	 * 		],
	 * 		"account_id_count": 4,
	 * 		"balance2": 615.81,
	 * 		"balance": -615.81
	 * 	},
	 * 	{
	 * 		"account_id": [
	 * 			6165,
	 * 			"7825 Bank, Nykredit"
	 * 		],
	 * 		"account_id_count": 9,
	 * 		"balance2": 2381.79,
	 * 		"balance": -2381.79
	 * 	}
	 * ]
	 * ```
	 */
	public function get_account_opening_balance($accounts, $type, $until_date, $options = []) {
		if (!preg_match("/^(\\d{4})-(\\d{1,2})-(\\d{1,2})$/", $until_date, $match)) {
			$this->error('Invalid date for getting opening balance.');
		}

		if (!is_array($accounts)) {
			$accounts = [$accounts];
		}

		$year = $match[1];
		$month = $match[2];
		$day = $match[3];

		$domain = [];
		if ($type === 'year') {
			$domain[] = ['year', '=', $year];
		}
		// $domain[] = array('year', '<', $year);
		// $domain[] = array('month', '<', $month);
		$domain[] = ['date', '<', $until_date];
		$domain[] = ['account_id', 'in', $accounts];
		$fields = ['account_id', 'date', 'balance', 'balance2'];
		$groupby = ['account_id'];

		$data = $this->read_group('account.budget.report', [$domain, $fields, $groupby]);
		$this->handle_exception($data, 'Failed to get opening balance.');

		if (!$options['rawResult']) {
			if (count($accounts) === 1) {
				return $data[0]['balance'];
			} else {
				$output = [];
				foreach ($data as $acc) {
					$output[ $acc['account_id'][0] ] = $acc['balance'];
				}
				return $output;
			}
		}

		return $data;
	}

	/**
	 * @param array $accounts : Account codes
	 * @param string $from : From date in MySQL format: yyyy-mm-dd
	 * @param string $to : To date in MySQL format: yyyy-mm-dd
	 */
	public function get_general_ledger($accounts = [], $from = null, $to = null, $order = null) {
		if ($from && !preg_match("/^\\d+-\\d+-\\d+$/", $from)) {
			$this->error('Invalid starting date when getting general ledger.', null, ['Date' => $from]);
		}
		if ($to && !preg_match("/^\\d+-\\d+-\\d+$/", $to)) {
			$this->error('Invalid ending date when getting general ledger.', null, ['Date' => $to]);
		}

		$filters = [];
		if (!empty($accounts)) {
			$accountIDs = [];
			foreach ($accounts as $curr_account) {
				$accountIDs[] = $this->get_account($curr_account)['id'];
			}
			$filters[] = ['account_id', 'in', $accountIDs];
		}
		if ($from) {
			$filters[] = ['date', '>=', $from];
		}
		if ($to) {
			$filters[] = ['date', '<=', $to];
		}
		if ($order === 'date') {
			$order = 'date, move_id';
		} elseif ($order === 'account') {
			$order = 'account_id, date';  //order descendingly: eg. 'date DESC'
		}
		$params = $this->search_parameters([
			'filters' => $filters,
			'order' => $order,
		]);

		$data = $this->search_read('account.move.line', $params);
		$this->handle_exception($data, 'Failed to get general ledger data.');

		return $data;
	}

	/**
	 * @param array $data : Output from get_general_ledger()
	 * @param string $from : From date in MySQL format: yyyy-mm-dd
	 * @param string $to : To date in MySQL format: yyyy-mm-dd
	 */
	public function get_general_ledger_html($data, $accounts = [], $from = null, $to = null, $orderby = null, $options = []) {
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
		if (!is_numeric($year)) {
			$year = date('Y');
		}

		$this->search_parameters([
			'filters' => [
				['date', '>=', $year .'-01-01'], ['date', '<=', $year .'-12-31']
			],
		]);

		$data = $this->search_read('res.currency', $params);
		$this->handle_exception($data, 'Failed to get profit and loss data.');

		return $data;
	}

	public function update_exchange_rates() {
		// Possible alternatives: https://github.com/yelizariev/addons-yelizariev/blob/8.0/currency_rate_update/currency_rate_update.py
		$sources = [
			'ecb' => [
				'url' => 'http://www.ecb.europa.eu/stats/eurofxref/eurofxref-daily.xml',
				'base_currency' => 'EUR',
			],
		];

		$eff_source = $sources['ecb'];

		$updated_currencies = [];

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
				$curr_rates = $this->search_read('res.currency', []);
				$this->handle_exception($curr_rates, 'Failed to get currencies.');
				if ($curr_rates) {

					$rates_date = $data['Cube']['Cube']['@attributes']['time'];
					if (!$rates_date) {
						$this->error('Did not found date of the retrieved exchange rates.');
					}

					$new_rates = [$eff_source['base_currency'] => '1'];
					foreach ($data['Cube']['Cube']['Cube'] as $rate) {
						$new_rates[$rate['@attributes']['currency']] = $rate['@attributes']['rate'];
					}

					foreach ($curr_rates as $curr_rate) {
						if ($new_rates[$curr_rate['name']]) {  //if we have a new exchange rate for this currency...
							if (!$curr_rate['date'] || strtotime($curr_rate['date']) < strtotime($rates_date) ) {
								// Update rate when we have a newer one
								$fields = [
									'currency_id' => $curr_rate['id'],
									'name' => $rates_date .' 00:00:00',
									'rate' => $new_rates[$curr_rate['name']],
								];
								$result = $this->create('res.currency.rate', [$fields]);
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
