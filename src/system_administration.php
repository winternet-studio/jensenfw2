<?php
/*
This file contains functions related to system/server administration
*/
namespace winternet\jensenfw2;

class system_administration {
	public $system_name = null;
	public $email_sender_address = null;

	private $_sent_error_notif = false;

	public function __construct($config = array()) {
		$config_vars = array('system_name', 'email_sender_address');

		foreach ($config_vars as $var) {
			if ($config[$var]) {
				$this->{$var} = $config[$var];
			}
		}
	}

	/**
	 * Backup MySQL
	 *
	 * Sample cron job:
	 * `10 0 * * *	root	php /var/www/domain.com/mysql-backup.phpcli >/var/www/domain.com/mysql-backup.log 2> /var/www/domain.com/mysql-backup.error.log`
	 *
	 * Sample PHP script for receiving POSTed dump file using `post_to_url` option:
	 * ```
	 * $folder = 'c:/path/to/save/the/file/into';
	 * 
	 * $valid = $err_msg = false;
	 * // TODO: allow only a given IP address to send files
	 * 
	 * if ($_FILES['f1']['name']) {
	 * 	if ($_FILES['f1']['error']) {
	 * 		$err_msg = 'File Upload Error Code: '. $_FILES['f1']['error'];
	 * 	} else {
	 * 		if (preg_match("/^BACKUP_\\d{4}-\\d{2}-\\d{2}_\\d{4}_.*\\.sql\\.enc\\.gz$/", $_FILES['f1']['name'])) {
	 * 			if (is_uploaded_file($_FILES['f1']['tmp_name'])) {
	 * 				$valid = true;
	 * 				move_uploaded_file($_FILES['f1']['tmp_name'], $folder .'/'. basename($_FILES['f1']['name']));
	 * 				echo 1;
	 * 			}
	 * 		}
	 * 	}
	 * }
	 * 
	 * if (!$valid) {
	 * 	http_response_code(404);
	 * 	if ($err_msg) {
	 * 		echo $err_msg;
	 * 	} else {
	 * 		echo '404 Not Found';
	 * 	}
	 * }
	 * ```
	 *
	 * @param array $options : Array with the following keys:
	 *   - `mysql_config_file` (req.) : path to .cnf file with MySQL connection info, eg. `/var/www/example.com/mysql-backup.cnf`
	 *       Sample file:
	 *       ```
	 *       [mysqldump]
	 *       host = localhost
	 *       user = dailybackup
	 *       password = "thepasswordhere"
	 *       ```
	 *   - `mysq_dump_path` (req.) : path to folder to store the MySQL dumps (with or without ending slash), eg. `/home/myuser/backups/`
	 *   - `databases` (req.) : array with databases to backup. Database name as key where its value is a subarray with these options:
	 *      - `ignore_tables` (opt.) : array of tables to ignore and not back up
	 *      Example:
	 * 		```
	 *		$databases = [
	 *			'swiftlayout_main' => [
	 *				'ignore_tables' => ['temp_emaillog_raw', 'temp_indesign_export_requests', 'temp_server_usage'],
	 *			],
	 *		];
	 *		```
	 *   - `publickey_path` (opt.) : path to a public key in order to asymmetrically encrypt the database dump, eg. `/home/myuser/mysqldump-secure.pub.pem`
	 *       (key pair can be made with command: openssl req -x509 -nodes -newkey rsa:2048 -keyout mysqldump-secure.priv.pem -out mysqldump-secure.pub.pem)
	 *   - `purge_after` (opt.) : delete backups after X days, or set to -1 to not delete backups. Defaults to 30 days.
	 *   - `command_after` (opt.) : run a system command after creating the MySQL dump (after encryption has been done (if encryption is enabled)), eg. `rsync -larvzi --checksum --delete-during --omit-dir-times  /home/myuser/backups/ someuser@otherserver.com:/storage/mysql-backups/`
	 *   - `post_to_url` (opt.) : URL to post the MySQL dump (after encryption has been done (if encryption is enabled)). See sample above for receiving PHP script.
	 */
	public function backup_mysql($options = array()) {
		$this->check_base_config();

		if (!is_numeric($options['purge_after'])) {
			$options['purge_after'] = 30;
		}

		$starttime = time();
		$this->ln('Making MySQL backups');
		$this->ln('--------------------');
		foreach ($options['databases'] as $db => $db_details) {
			if ($options['publickey_path']) {
				$filename = 'BACKUP_'. date('Y-m-d_Hi', $starttime) .'_'. $db .'.sql.enc';
			} else {
				$filename = 'BACKUP_'. date('Y-m-d_Hi', $starttime) .'_'. $db .'.sql';
			}

			$this->ln($db .' > '. $filename);

			// Source: https://www.everythingcli.org/secure-mysqldump-script-with-encryption-and-compression/
			$command = "/usr/bin/mysqldump --defaults-extra-file=". $options['mysql_config_file'] ." --opt --allow-keywords ". $db;
			if (!empty($db_details['ignore_tables'])) {
				foreach ($db_details['ignore_tables'] as $skip_table) {
					$command .= ' --ignore-table='. $db .'.'. $skip_table;
				}
			}
			if ($options['publickey_path']) {
				$command .= " | openssl smime -encrypt -binary -text -aes256 -out ". filesystem::concat_path($options['mysq_dump_path'], $filename) ." -outform DER ". $options['publickey_path'];
				// NOTE: to decrypt: openssl smime -decrypt -in database.sql.enc -binary -inform DEM -inkey mysqldump-secure.priv.pem -out database.sql
			} else {
				$command .= " > ". filesystem::concat_path($options['mysq_dump_path'], $filename);
			}
			$cmdoutput = array();
			exec($command, $cmdoutput, $returnstatus);

			if ($returnstatus > 0 && !$this->_sent_error_notif) {
				echo '   PROBABLY FAILED! Email notif being sent...';
				$this->notify_error('Please check the '. $this->system_name .' backup script '. __FILE__ .' - there might be errors. Return code was '. $returnstatus . PHP_EOL . PHP_EOL . print_r($cmdoutput, true));
			} else {
				// Gzip the file
				$command = "gzip -f ". filesystem::concat_path($options['mysq_dump_path'], $filename);
				$filename_gz = $filename .'.gz';
				$cmdoutput = array();
				exec($command, $cmdoutput, $returnstatus);

				echo '   Done!';

				if ($options['command_after']) {
					$this->ln();
					$this->ln('Running post-command...');
					$this->ln('--------------------------------------------'. PHP_EOL);
					$handle = popen($options['command_after'], 'r');
					while (!feof($handle)) {
						echo '   '. fgets($handle);
					}
					pclose($handle);
					$this->ln('--------------------------------------------');
					$this->ln();
				}
				if ($options['post_to_url']) {
					// Command line version: curl -i -k -X POST -H "Content-Type: multipart/form-data" -F "f1=@/path/to/file" https://servertopostto.com/index.php
					// TODO: check the hashes on the receiving side
					echo '  Created. Now uploading... ';
					$ch = curl_init();
					curl_setopt($ch, CURLOPT_URL, $options['post_to_url']);
					curl_setopt($ch, CURLOPT_POST, 1);
					$fields = array();
					$fields['f1'] = new \CurlFile(filesystem::concat_path($options['mysq_dump_path'], $filename_gz), 'application/octet-stream');
					$fields['f1_hash'] = hash_file('sha256', filesystem::concat_path($options['mysq_dump_path'], $filename_gz));
					$fields['t'] = time();
					$fields['t_hash'] = hash('sha256', $fields['t'] .'FHeFzwCT8O96V4MpIjm');
					curl_setopt($ch, CURLOPT_POSTFIELDS, $fields);
					// NOT NEEDED. curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type: multipart/form-data'));
					curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
					curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);
					curl_setopt($ch, CURLOPT_TIMEOUT, 1800);
					// DOESNT SEEM TO BE CALLED DURING POSTING. curl_setopt($ch, CURLOPT_PROGRESSFUNCTION, function($download_size = 0, $downloaded = 0, $upload_size = 0, $uploaded = 0) {
					// 	var_dump(func_get_args());
					// });
					$response = curl_exec($ch);

					$transfer_info = curl_getinfo($ch);
					$curl_error = curl_error($ch);
					$curl_errno = curl_errno($ch);
					if (curl_errno($ch) || $transfer_info['http_code'] != 200 || $response !== '1') {
						echo '   (Failed URL POSTING)';
						$this->notify_error('Backup made but it could not be POSTed to the URL'. PHP_EOL . PHP_EOL . print_r($transfer_info, true) . PHP_EOL . ($curl_errno ? $curl_errno .': '. $curl_error : '') . PHP_EOL . PHP_EOL . $response);
					}
					curl_close($ch);
				}
			}
		}
		$endtime = time();
		$duration_secs = $endtime - $starttime;
		$this->ln('Duration: '. $duration_secs .' secs');

		$this->ln();

		$files = filesystem::get_files($options['mysq_dump_path']);
		if (is_array($files)) {
			$this->ln('Purging old backups');
			$this->ln('-------------------');
			foreach ($files as $file) {
				if (preg_match('/^BACKUP_(\d{4}-\d{2}-\d{2})_\d{4}_('. implode('|', array_keys($options['databases'])) .')\.sql/', $file, $match)) {
					$fullpathfile = filesystem::concat_path($options['mysq_dump_path'], $file);
					$date = $match[1];
					$age_days = round( (time() - strtotime($date)) / 60 / 60 / 24, 5);
					if ($age_days > $options['purge_after']) {
						if (file_exists($fullpathfile)) {
							unlink($fullpathfile);
							$this->ln('Purged: '. $file);
						} else {
							$this->ln('FILE '. $file  .' DOES NOT EXIST!');
						}
					} else {
						$this->ln('Too young: '. $age_days .' days - '. $file);
					}
				}
			}
		}

		$this->ln();
		$this->ln();
	}

	public function backup_files() {
		$this->check_base_config();

		// TODO
	}

	public function check_mysql_backup() {
		$this->check_base_config();

		// TODO
	}

	public function check_file_backup() {
		$this->check_base_config();

		// TODO
	}

	public function download_production_database() {
		$this->check_base_config();

		// TODO
	}

	public function send_production_database() {
		$this->check_base_config();

		// TODO
	}


	// ========== INTERNAL METHODS ======================================================

	private function check_base_config() {
		if (!$this->system_name) {
			die('Configuration error: Missing system name');
			exit;
		}
		if (!$this->email_sender_address) {
			die('Configuration error: Missing email sender address');
			exit;
		}
	}

	// ========== UTILITY METHODS ======================================================

	private function ln($line = false) {
		echo PHP_EOL. $line;
	}

	private function notify_error($mailbody) {
		$headers = "From: \"". $this->system_name ."\" <". $this->email_sender_address .">\r\n";
		mail($this->email_sender_address, $this->system_name .': Possible database backup failure', $mailbody, $headers);
		$this->_sent_error_notif = true;
	}
}
