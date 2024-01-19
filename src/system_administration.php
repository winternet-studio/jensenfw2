<?php
/**
 * Functions related to system/server administration
 */

namespace winternet\jensenfw2;

class system_administration {
	public $system_name = null;
	public $email_sender_address = null;

	private $_sent_error_notif = false;

	public function __construct($config = []) {
		error_reporting(E_ALL ^ E_NOTICE);

		$config_vars = ['system_name', 'email_sender_address'];

		foreach ($config_vars as $var) {
			if ($config[$var]) {
				$this->{$var} = $config[$var];
			}
		}
	}

	/**
	 * Backup MySQL
	 *
	 * Meant to be run in CLI mode.
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
	 *			'mydatabase_main' => [
	 *				'ignore_tables' => ['temp_emaillog_raw', 'temp_logs'],
	 *			],
	 *		];
	 *		```
	 *   - `publickey_path` (opt.) : path to a public key in order to asymmetrically encrypt the database dump, eg. `/home/myuser/mysqldump-secure.pub.pem`
	 *       (key pair can be made with command: openssl req -x509 -nodes -newkey rsa:2048 -keyout mysqldump-secure.priv.pem -out mysqldump-secure.pub.pem)
	 *   - `purge_after` (opt.) : delete backups after X days, or set to -1 to not delete backups. Defaults to 30 days.
	 *   - `keep_monthly_backup` (opt.) : set true to permanently keep a monthly backup (it's the latest backup of the month that is retained)
	 *   - `keep_annual_backup` (opt.) : set true to permanently keep an annual backup (it's the latest backup of the year that is retained)
	 *   - `command_after` (opt.) : run a system command after creating the MySQL dump (after encryption has been done (if encryption is enabled)), eg. `rsync -larvzi --checksum --delete-during --omit-dir-times  /home/myuser/backups/ someuser@otherserver.com:/storage/mysql-backups/`
	 *   - `post_to_url` (opt.) : URL to post the MySQL dump (after encryption has been done (if encryption is enabled)). See sample above for receiving PHP script.
	 */
	public function backup_mysql($options = []) {
		$this->check_base_config();

		if (!is_numeric($options['purge_after'])) {
			$options['purge_after'] = 30;
		}
		if (!is_array($options['databases'])) {
			$this->system_error('List of databases is not an array.');
		}

		$this->check_path($options['mysql_config_file'], true);
		$this->check_path($options['mysq_dump_path'], true);
		if ($options['publickey_path']) {
			$this->check_path($options['publickey_path'], true);
		}

		$starttime = time();
		$this->ln('Making MySQL backups');
		$this->ln('--------------------');
		foreach ($options['databases'] as $db => $db_details) {
			$this->check_database_table_name($db);

			if ($options['publickey_path']) {
				$filename = 'BACKUP_'. date('Y-m-d_Hi', $starttime) .'_'. $db .'.sql.enc';
				$filename_monthly = 'BACKUP_'. date('Y-m', $starttime) .'_monthly_'. $db .'.sql.enc';
				$filename_annual = 'BACKUP_'. date('Y', $starttime) .'_annual_'. $db .'.sql.enc';
			} else {
				$filename = 'BACKUP_'. date('Y-m-d_Hi', $starttime) .'_'. $db .'.sql';
				$filename_monthly = 'BACKUP_'. date('Y-m', $starttime) .'_monthly_'. $db .'.sql';
				$filename_annual = 'BACKUP_'. date('Y', $starttime) .'_annual_'. $db .'.sql';
			}

			$this->ln($db .' > '. $filename);

			// Source: https://www.everythingcli.org/secure-mysqldump-script-with-encryption-and-compression/
			$command = "/usr/bin/mysqldump --defaults-extra-file=". $options['mysql_config_file'] ." --opt --allow-keywords ". $db;
			if (!empty($db_details['ignore_tables'])) {
				foreach ($db_details['ignore_tables'] as $skip_table) {
					$this->check_database_table_name($skip_table);
					$command .= ' --ignore-table='. $db .'.'. $skip_table;
				}
			}
			if ($options['publickey_path']) {
				$command .= " | openssl smime -encrypt -binary -text -aes256 -out ". filesystem::concat_path($options['mysq_dump_path'], $filename) ." -outform DER ". $options['publickey_path'];
				// NOTE: to decrypt: openssl smime -decrypt -in database.sql.enc -binary -inform DEM -inkey mysqldump-secure.priv.pem -out database.sql
			} else {
				$command .= " > ". filesystem::concat_path($options['mysq_dump_path'], $filename);
			}
			$cmdoutput = [];
			exec($command, $cmdoutput, $returnstatus);

			if ($returnstatus > 0) {
				echo '   PROBABLY FAILED! Email notif being sent...';
				if (!$this->_sent_error_notif) {
					$this->notify_error('Please check the '. $this->system_name .' backup script '. __FILE__ .' - there might be errors. Return code was '. $returnstatus . PHP_EOL . PHP_EOL . print_r($cmdoutput, true));
				}
			} elseif (!file_exists(filesystem::concat_path($options['mysq_dump_path'], $filename)) || filesize(filesystem::concat_path($options['mysq_dump_path'], $filename)) < 10000) {
				echo '   PROBABLY FAILED! Email notif being sent...';
				if (!$this->_sent_error_notif) {
					$this->notify_error('Please check the '. $this->system_name .' backup script '. __FILE__ .' - there might be errors. Backup file '. filesystem::concat_path($options['mysq_dump_path'], $filename) .' is missing or seems too small. Return code was '. $returnstatus . PHP_EOL . PHP_EOL . print_r($cmdoutput, true));
				}
			} else {
				// Gzip the file
				$command = "gzip -f ". filesystem::concat_path($options['mysq_dump_path'], $filename);
				$filename_gz = $filename .'.gz';
				$cmdoutput = [];
				exec($command, $cmdoutput, $returnstatus);

				echo '   Done!';

				if ($options['keep_monthly_backup']) {
					if (!copy(filesystem::concat_path($options['mysq_dump_path'], $filename_gz), filesystem::concat_path($options['mysq_dump_path'], $filename_monthly) .'.gz')) {
						$this->notify_error('Please check the '. $this->system_name .' backup script '. __FILE__ .' - Failed to copy the monthly backup.');
					}
				}
				if ($options['keep_annual_backup']) {
					if (!copy(filesystem::concat_path($options['mysq_dump_path'], $filename_gz), filesystem::concat_path($options['mysq_dump_path'], $filename_annual) .'.gz')) {
						$this->notify_error('Please check the '. $this->system_name .' backup script '. __FILE__ .' - Failed to copy the annual backup.');
					}
				}

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
					$fields = [];
					$fields['f1'] = new \CurlFile(filesystem::concat_path($options['mysq_dump_path'], $filename_gz), 'application/octet-stream');
					$fields['f1_hash'] = hash_file('sha256', filesystem::concat_path($options['mysq_dump_path'], $filename_gz));
					$fields['t'] = time();
					$fields['t_hash'] = hash('sha256', $fields['t'] .'FHeFzwCT8O96V4MpIjm');
					curl_setopt($ch, CURLOPT_POSTFIELDS, $fields);
					// NOT NEEDED. curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type: multipart/form-data'));
					curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:105.0) Gecko/20100101 Firefox/105.0 JensenFW2');  // Some firewalls might block request without a user agent
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

	/**
	 * Backup files
	 *
	 * Meant to be run in CLI mode.
	 *
	 * @param array $options : Array with the following keys:
	 *   - `source_path` (req.) : path to backup. End with slash to copy all contents, leave slash out to copy including the folder itself.
	 *   - `destination_server` (req.) : user and server to backup to. Eg. `someuser@yourbackupserver.com`. Private key authentication, port, etc must be put in ~/.ssh/config for the user running this script, eg.:
	 *      ```
	 *      Host myhost.com
	 *          Port 22
	 *          IdentityFile ~/keyfile.pem
	 *          IdentitiesOnly yes
	 *      ```
	 *      Note: The user we connect as should be owner of all files and folders in the destination.
	 *   - `destination_path` (req.) : path on destination server to backup to. Eg. `/storage/backup/`
	 *   - `exclude_folders` (opt.) : array of folders to be excluded. Wildcards (eg. *) can be used according to rsync documentation on the --exclude parameter. Root is anchored to $source_path so don't specify full physical path. Eg. `['/runtime', '/somepath/cached_*_prod/*']`
	 *   - `skip_checksum` (opt.) : set true to only compare files using modification time and size - instead of checksum
	 */
	public function backup_files($options = []) {
		$this->check_base_config();

		$starttime = time();
		$this->ln('Making file backup');
		$this->ln('--------------------');
		$this->ln();

		$this->check_path($options['source_path'], true);
		$this->check_path($options['destination_path'], false);
		if ($options['backups_path']) {
			$this->check_path($options['backups_path'], false);
		}

		$excl_parms = [];
		if (!empty($options['exclude_folders'])) {
			foreach ($options['exclude_folders'] as $folder) {
				$excl_parms[] = "--exclude '". str_replace("'", '', $folder) ."'";
			}
		}
		$excl_parms = implode(' ', $excl_parms);

		$backup_parms = '';
		if ($options['backups_path']) {
			$backup_parms = ' --backup --suffix=".DLTD'. gmdate('Ymd') .'" --backup-dir='. $options['backups_path'];
		}

		$cmd = "rsync -larvzi". ($options['skip_checksum'] ? '' : ' --checksum') ." --delete-during --omit-dir-times ". $excl_parms . $backup_parms ." ". $options['source_path'] ." ". $options['destination_server'] .":". $options['destination_path'] ." 2>&1";
		// echo PHP_EOL . implode(PHP_EOL, str_split($cmd, 120)) . PHP_EOL . PHP_EOL; exit;
		$handle = popen($cmd, 'r');

		$unknown_lines = $files_processed = $files_uploaded = $files_deleted = $folders_processed = $folders_added = $folders_deleted = 0;
		$unknown = [];
		while (!feof($handle)) {
			$buffer = fgets($handle);
			if (trim($buffer)) {
				if (preg_match("/rsync: failed to set times on/", $buffer)) {
					// ignore errors about not being able to set time attributes on destination files
				} elseif (preg_match("/rsync: chgrp.*failed: Operation not permitted \\(1\\)/", $buffer)) {
					// ignore errors about not being able to change group permissions on destination files
				} elseif (preg_match("/^(sent |total size|rsync error: some files.attrs were not transferred|sending incremental file list)/", $buffer)) {
					// ignore the summary lines
				} elseif (preg_match("/Permanently added .* to the list of known hosts/", $buffer)) {
					// ignore notification about host being added to list of known hosts
				} elseif (preg_match("/^\\*deleting.*\\/$/", $buffer)) {  //folders end with a slash
					$folders_deleted++;
				} elseif (preg_match("/^\\*deleting.*$/", $buffer)) {  //file do not end with a slash
					$files_deleted++;
				} elseif (preg_match("/^[\\.<]f/", $buffer)) {
					$files_processed++;
					if ($match[1] == '<') {
						$files_uploaded++;
					}
				} elseif (preg_match("/^([c\\.<])d/", $buffer, $match)) {
					$folders_processed++;
					if ($match[1] == '<') {
						$folders_added++;
					}
				} else {
					$unknown_lines++;
					$unknown[] = $buffer;
				}
				//CAN'T ENABLE THIS AS IT WILL BE INCLUDED IN THE CRON RESULT MAIL. echo 'Unknown lines: '. $unknown_lines .' '.'Files processed: '. $files_processed .' '.'Files uploaded: '. $files_uploaded .' '.'Files deleted: '. $files_deleted .' '.'Folders processed: '. $folders_processed .' '.'Folders added: '. $folders_added .' '.'Folders deleted: '. $folders_deleted ."\r";
			}
		}

		$this->ln();
		$this->ln('Unknown lines: '. $unknown_lines . PHP_EOL .'Files processed: '. $files_processed . PHP_EOL .'Files uploaded: '. $files_uploaded . PHP_EOL .'Files deleted: '. $files_deleted . PHP_EOL .'Folders processed: '. $folders_processed . PHP_EOL .'Folders added: '. $folders_added . PHP_EOL .'Folders deleted: '. $folders_deleted);
		$this->ln();

		if ($unknown_lines > 0) {
			$this->notify_error('Unknown lines: '. $unknown_lines . PHP_EOL .'Files processed: '. $files_processed . PHP_EOL .'Files uploaded: '. $files_uploaded . PHP_EOL .'Files deleted: '. $files_deleted . PHP_EOL .'Folders processed: '. $folders_processed . PHP_EOL .'Folders added: '. $folders_added . PHP_EOL .'Folders deleted: '. $folders_deleted . PHP_EOL . PHP_EOL . PHP_EOL .'Unknown data:'. PHP_EOL . implode(PHP_EOL, $unknown));
		}

		$endtime = time();
		$duration_secs = $endtime - $starttime;
		$this->ln('Duration: '. $duration_secs .' secs');
		$this->ln();
	}

	/**
	 * @param array $options : Identical to $options for backup_mysql() method plus these:
	 *   - `max_hours_since_last_backup` (opt.) : Max age of latest backup we find before triggering an alert. Defaults to 48.
	 */
	public function check_mysql_backup($options) {
		$this->check_base_config();

		if (!$options['mysq_dump_path']) {
			$this->system_error('MySQL dump path unknown for checking backup.');
		}
		if (!is_numeric($options['max_hours_since_last_backup'])) {
			$options['max_hours_since_last_backup'] = 48;
		}

		$latest_backup_timestamp = 0;

		$files = filesystem::get_files($options['mysq_dump_path']);
		foreach ($files as $file) {
			if (preg_match("/BACKUP_(\\d\\d\\d\\d-\\d\\d-\\d\\d)_(\\d\\d)(\\d\\d)_.*". preg_quote(".sql.") ."/", $file, $match)) {
				$timestamp = strtotime($match[1] .' '. $match[2] .':'. $match[3]);  //recreating yyyy-mm-dd hh:mm
				if ($timestamp > $latest_backup_timestamp) {
					$latest_backup_timestamp = $timestamp;
				}
			}
		}

		$age_hours = round((time() - $latest_backup_timestamp) / 60 / 60, 3);
		if ($age_hours <= $options['max_hours_since_last_backup']) {
			return [
				'status' => 'ok',
				'age_hours' => $age_hours,
				'summary' => $this->system_name .':mysql_backup_is_okay:'. $age_hours .'hrs',
			];
		} else {
			return [
				'status' => 'error',
				'age_hours' => $age_hours,
				'summary' => $this->system_name .': Most recent MySQL backup is '. $age_hours .' hours old',
			];
		}
	}

	/**
	 * @param array $options : Identical to $options for backup_files() method
	 */
	public function check_file_backup($options) {
		$this->check_base_config();

		// TODO
	}

	public function status_beacon($options) {
		$this->check_base_config();

		// TODO
	}

	public function status_receptor($options) {
		$this->check_base_config();

		// TODO
	}

	/**
	 * Import/restore a MySQL database from a backup/dump file
	 *
	 * @param string $mysql_file : MySQL dump file to import
	 * @param string|array $tables : String '*' to import all tables (default), or array with specific tables to import, or array with key='skip' and values being an array with tables to *not* import
	 * @param array $options : Available options:
	 *   - `query_callback` : Callback to use for each query we find, instead of just executing it against the database. Receive two arguments: array with query details, and string with actual query
	 *   - database connection parameters like in download_production_database() if queries are to be executed against a database
	 */
	public function import_mysql($mysql_file, $tables = '*', $options = []) {
		if (!file_exists($mysql_file)) {
			core::system_error('File to import MySQL database from does not exist.', ['File' => $mysql_file]);
		}

		$handle = fopen($mysql_file, 'r');
		if ($handle) {
			$processed_tables = [];

			if (!is_callable($options['query_callback'])) {
				$link = $this->connect_database($options);
			}

			$sql_buffer = '';
			$queries = 0;
			while (($line = fgets($handle)) !== false) {
				if ($line === '' || substr($line, 0, 2) === '--' || substr($line,0,2) === '/*') {  //inspired by https://stackoverflow.com/questions/19751354/how-do-i-import-a-sql-file-in-mysql-database-using-php
					continue;
				} else {
					$sql_buffer .= $line;
				}

				if (substr(trim($line), -1) == ';') {
					$queries++;
					$sql_buffer = trim($sql_buffer);

					$table_enabled = true;
					if ($tables !== '*') {
						$query_details = $this->get_query_details($sql_buffer);
						$table = $query_details['table_name'];
						if (isset($tables['skip'])) {
							if (in_array($table, $tables['skip'])) {
								$table_enabled = false;
							}
						} else {
							if (!in_array($table, $tables)) {
								$table_enabled = false;
							}
						}
					}

					if ($table_enabled) {
						if (is_callable($options['query_callback'])) {
							if ($options['query_callback']($query_details, $sql_buffer) === false) {
								break;
							}
						} else {
							if (mysqli_query($link, $curr_part)) {
								while ($link->more_results()) {
									$counter++;
									$result = $link->next_result();
									if (!$result) {
										$errors = true;
										echo '<br>Part #'. $partcounter .', query #'. $counter .': ';
										echo $link->error;
									}
								}

							} else {
								core::system_error('Failed to execute SQL query while importing MySQL dump.', ['SQL' => $sql_buffer]);
								echo '<div class="alert alert-danger">Error: '. mysqli_error($link) .'</div>';
							}
							mysql_query($templine) or print('Error performing query \'<strong>' . $templine . '\': ' . mysql_error() . '<br /><br />');
						}

						if (!in_array($table, $processed_tables)) {
							$processed_tables[] = $table;
						}
					}

					$sql_buffer = '';
				}
			}

			fclose($handle);
		} else {
			core::system_error('Failed to open dump file for importing MySQL database.', ['File' => $mysql_file]);
		}

		return $processed_tables;
	}

	/**
	 * Get details about an SQL query
	 *
	 * @return array
	 */
	public function get_query_details(&$query) {
		if (preg_match("/(DROP TABLE|CREATE TABLE|INSERT INTO|REPLACE INTO).*`(.+)`/U", $query, $match)) {
			return [
				'type' => $match[1],
				'table_name' => $match[2],
			];
		} else {
			core::system_error('Failed to determine table a given SQL query is for.', ['Query' => $query]);
		}
	}

	/**
	 * Dwnload production database to this machine
	 *
	 * IMPORTANT! For Yii2 this requires also $this->enableCsrfValidation = false in beforeAction() and preferably only allowing POST for action `send-production-database`
	 *
	 * @param array $options : Array with the following keys:
	 *   - `productionURL` (req.) : URL to download the production database from
	 *   - `allow_receive_callback` (req.) : callable function that returns a boolean as to whether or not this machine is allowed to receive the production database. No arguments passed.
	 *   - `key` (req.) : a key that matches the key used on the sending side
	 *   - `database_host` (req.) : MySQL host for the local database to import production database into. Or set `USE-YII` to automatically retrieve it from Yii's configuration.
	 *   - `database_username` (req.)
	 *   - `database_password` (req.)
	 *   - `database_name` (req.)
	 *   - `where_condition` (opt.) : array with keys `table` and `where` with name of table and WHERE condition respectively (possible to specify multiple tables separated with spaces)
	 *   - `skip_confirmation` (opt.) : set true to skip asking before downloading and overwritten the local database
	 */
	public function download_production_database($options) {
		$this->check_base_config();

		ob_start();

		if (!is_callable($options['allow_receive_callback'])) {
			core::system_error('Callback for determining if this machine is allowed to receive a database is missing.');
		} else {
			if (!call_user_func($options['allow_receive_callback'])) {
				core::system_error('Not allowed on this machine.');
			}
		}

		if (!$options['skip_confirmation']) {
			if (@constant('YII_BEGIN_TIME')) {
				$is_confirmed = \Yii::$app->request->get('confirm');
				$curr_url_confirmed = \yii\helpers\Url::current(['confirm' => 1]);
			} else {
				$is_confirmed = $_GET['confirm'];
				$curr_url_confirmed = core::page_url(['confirm' => 1]);
			}
			if (!$is_confirmed) {
				echo '<div class="jumbotron">';
				echo '<h1>Please confirm...</h1>';
				echo 'Are you sure you want to download the database tables from the production server? Your existing tables will be overwritten!';
				echo ' <a href="'. $curr_url_confirmed .'">Yes, I want to do this</a>';
				echo '</div>';
				return ob_get_clean();
			}
		}

		$starttime = microtime(true);

		if ($options['database_host'] == 'USE-YII') {
			$this->use_yii_db($options);
		}

		$postfields = ['key' => $options['key']];
		if ($options['where_condition']) {
			$postfields['where_condition'] = $options['where_condition'];
		}

		// Receive the database
		$ch = curl_init();
		curl_setopt($ch, CURLOPT_URL, $options['productionURL']);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
		curl_setopt($ch, CURLOPT_POST, 1);
		curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($postfields));
		curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);
		curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:105.0) Gecko/20100101 Firefox/105.0 JensenFW2');  // Some firewalls might block request without a user agent
		curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 1);
		curl_setopt($ch, CURLOPT_TIMEOUT, 30);
		$dump = curl_exec($ch);
		if (curl_errno($ch)) {
			echo '<div class="alert alert-danger">Error code '. curl_errno($ch) .' when downloading file: '. curl_error($ch) .'</div>';
			echo '<div class="alert alert-danger">cURL info:<pre>'. json_encode(curl_getinfo($ch), JSON_PRETTY_PRINT) .'</pre></div>';
		}

		curl_close($ch);

		$link = $this->connect_database($options);

		echo '<div>Downloaded (compressed): '. number_format(strlen($dump)) .' bytes</div>';

		if ($dump && strpos($dump, '<html') !== false) {
			echo '<div class="alert alert-danger">Response from production server unexpectedly contained HTML:<br><br><i>'. nl2br(trim(strip_tags($dump))) .'</i></div>';
			return ob_get_clean();
		}

		try {
			$dump = gzuncompress($dump);
		} catch (\Exception $e) {
			echo '<div class="alert alert-danger">Failed to uncompress response from production server: '. $e->getMessage() .'</div>';
			return ob_get_clean();
		}
		echo '<div>Uncompressed: '. number_format(strlen($dump)) .' bytes</div>';
		if (false) {
			file_put_contents(\Yii::getAlias('@runtime/prod_database_dump.txt'), $dump);
		}

		// Split the dump into parts (one table per part), otherwise it is bigger than max_allowed_packet and MySQL will fail (source: https://stackoverflow.com/a/26021324/2404541)
		$dump_parts = preg_split("@(?=(DROP TABLE IF EXISTS|INSERT INTO `|REPLACE INTO `))@", $dump);

		// source: http://stackoverflow.com/questions/1463987/execute-sql-query-from-sql-file
		$errors = false;
		foreach ($dump_parts as $tkey => $curr_part) {
			if ($curr_part) {
				$firstline = substr($curr_part, 0, strpos($curr_part, "\n"));
				if ($hasValues = strpos($firstline, ' VALUES (')) {
					if ($options['where_condition']) {
						// include the beginning of VALUES so we see at least the first of the specific records we are downloading
						$firstline = substr($firstline, 0, $hasValues + 100);
					} else {
						// cut before VALUES
						$firstline = substr($firstline, 0, $hasValues);
					}
				}
				if (substr($firstline, 0, 11) != 'LOCK TABLES') {  //skip writing out LOCK TABLES statements
					echo '<div>'. ++$partcounter .'. <code>'. htmlentities($firstline) .' ...</code></div>';
				}
				$counter = 0;
				if (mysqli_multi_query($link, $curr_part)) {
					while ($link->more_results()) {
						$counter++;
						$result = $link->next_result();
						if (!$result) {
							$errors = true;
							echo '<br>Part #'. $partcounter .', query #'. $counter .': ';
							echo $link->error;
						}
					}

				} else {
					echo '<div class="alert alert-danger">Error: '. mysqli_error($link) .'</div>';
				}
			}
		}

		// Reenable foreign key check
		$link->query("SET FOREIGN_KEY_CHECKS = 1");

		if (!$errors) {
			echo '<div class="alert alert-success">Database downloaded successfully in '. round(microtime(true) - $starttime, 3) .' secs</div>';
		}

		return ob_get_clean();
	}

	/**
	 * Send production database to another machine
	 *
	 * Uses `mysqldump`.
	 *
	 * @param array $options : Array with the following keys:
	 *   - `allow_send_callback` (req.) : callable function that returns a boolean as to whether or not this machine is allowed to send its database to another machine. No arguments passed.
	 *   - `key` (req.) : a key that matches the key used on the sending side
	 *   - `database_host` (req.) : MySQL host for the local database to import production database into. Or set `USE-YII` to automatically retrieve it from Yii's configuration.
	 *   - `database_username` (req.)
	 *   - `database_password` (req.)
	 *   - `database_name` (req.)
	 */
	public function send_production_database($options) {
		$this->check_base_config();

		// IDEAS:
		//   https://www.phpclasses.org/package/10137-PHP-Dump-MySQL-database-tables-for-file-download.html
		//   http://stackoverflow.com/questions/6750531/using-a-php-file-to-generate-a-mysql-dump

		if (!is_callable($options['allow_send_callback'])) {
			core::system_error('Callback for determining if this machine is allowed to send its database is missing.');
		} else {
			if (!call_user_func($options['allow_send_callback'])) {
				core::system_error('This machine is not allowed to send its database.');
			}
		}

		if ($_POST['key'] !== $options['key']) {
			core::system_error('Invalid key.');
		}

		$output = '';

		if ($options['database_host'] == 'USE-YII') {
			$this->use_yii_db($options);
		}

		$tempname = 'database_out'. time() .'_'. rand(10000, 99999) .'.sql';
		if (@constant('YII_BEGIN_TIME')) {
			$fullpath_tempfile = \Yii::getAlias('@runtime/'. $tempname);
		} else {
			$fullpath_tempfile = sys_get_temp_dir() .'/'. $tempname;
		}

		$cmd = 'mysqldump --compact';
		if ($_POST['where_condition']) {
			$cmd .= ' --no-create-info --replace';
		} else {
			$cmd .= ' --add-drop-table';
		}
		$cmd .= ' --add-locks --user='. $options['database_username'] .' --password='. $options['database_password'] .' --host='. $options['database_host'];
		if ($_POST['where_condition']) {
			if ($_POST['where_condition']['where'] && preg_match("/^[a-z0-9_\\-\\(\\)=<>'% ]+$/i", $_POST['where_condition']['where'])) {
				$cmd .= ' --where="'. $_POST['where_condition']['where'] .'"';
			} else {
				core::system_error('Invalid where condition for table.', ['Table' => $_POST['where_condition']['table'], 'Where' => $_POST['where_condition']['where']]);
			}
		}
		$cmd .= ' '. $options['database_name'];
		if ($_POST['where_condition']) {
			if ($_POST['where_condition']['table'] && preg_match("/^[a-z0-9_ ]+$/i", $_POST['where_condition']['table'])) {
				$cmd .= ' '. $_POST['where_condition']['table'];
			} else {
				core::system_error('Invalid table for where condition.', ['Table' => $_POST['where_condition']['table']]);
			}
		} else {
			$cmd .= ' '. implode(' ', $options['tables']);
		}
		$cmd .= ' > '. $fullpath_tempfile;
		exec($cmd);
		if (!file_exists($fullpath_tempfile)) {
			core::system_error('Database dump file was not found.');
		}

		// Echo file as the response
		// Source: http://stackoverflow.com/questions/6914912/streaming-a-large-file-using-php
		$buffer = '';
		$sentbytes = 0;
		$handle = fopen($fullpath_tempfile, 'rb');
		if (!$handle) {
			core::system_error('Failed to open SQL dump file for transmission.');
		}
		while (!feof($handle)) {
			$buffer = fread($handle, 1024*1024);  //chunk size is in bytes
			$output .= $buffer;
			// $sentbytes += strlen($buffer);
		}
		fclose($handle);

		unlink($fullpath_tempfile);

		while (ob_end_clean()) {
			// cleans output buffers
		}
		echo gzcompress($output);
		exit;
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

	protected function connect_database($options) {
		$link = mysqli_connect($options['database_host'], $options['database_username'], $options['database_password'], $options['database_name']);
		if (mysqli_connect_errno()) {
			core::system_error('Connect failed: '. mysqli_connect_error());
		}
		mysqli_set_charset($link, 'utf8');

		if (empty($options['skip_disabling_foreign_checks'])) {
			// Disable foreign key check
			// NOTE: this can potentially break foreign key integrity between tables that are imported and those that are not, but it is okay because only developers will be using this feature
			$link->query("SET FOREIGN_KEY_CHECKS = 0");
		}

		return $link;
	}

	private function ln($line = false) {
		echo PHP_EOL. $line;
	}

	private function system_error($message, $notify = true) {
		if ($notify) {
			$this->notify_error($message);
		}
		$this->ln($message);
		exit;
	}

	private function notify_error($mailbody) {
		$headers = "From: \"". $this->system_name ."\" <". $this->email_sender_address .">\r\n";
		mail($this->email_sender_address, $this->system_name .': Possible database backup failure', $mailbody, $headers);
		$this->_sent_error_notif = true;
	}

	private function use_yii_db(&$options) {
		$db_config = require(\Yii::getAlias('@app/config/db.php'));
		$options['database_username'] = $db_config['username'];
		$options['database_password'] = $db_config['password'];

		preg_match("/host=([^;]+)/", $db_config['dsn'], $dbhost_match);
		$options['database_host'] = $dbhost_match[1];

		preg_match("/dbname=(.*)/", $db_config['dsn'], $dbname_match);
		$options['database_name'] = $dbname_match[1];
	}

	public function check_path($path, $check_existence = false) {
		if (preg_match("|^[a-z0-9_\\-\\/\\.]+$|i", $path)) {
			if ($check_existence) {
				if (!file_exists($path)) {
					core::system_error('Path does not exist.', ['Path' => $path]);
				} else {
					// ok, do nothing
				}
			} else {
				// ok, do nothing
			}
		} else {
			core::system_error('Path is invalid.', ['Path' => $path]);
		}
	}

	/**
	 * Check a MySQL database or table name
	 */
	public function check_database_table_name($database_or_table_name) {
		if (!preg_match("|^[a-z0-9_\\-\\.]+$|i", $database_or_table_name)) {
			core::system_error('Database name is invalid.', ['Database name' => $database_or_table_name]);
		}
	}
}
