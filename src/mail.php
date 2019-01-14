<?php
/*
This file contains functions related to sending mail (email)
*/
namespace winternet\jensenfw2;

class mail {
	public static function class_defaults() {
		$cfg = array();

		$cfg['bounce_addr'] = false;
		$cfg['enforce_sender_as_replyto'] = false;  //Instead of setting the provided sender address as the actual sender, use this the address instead and set the provided sender address as Reply-To. This is good email practice because of spam blocking/protection systems.
		$cfg['only_enforce_for_other_domains'] = true;  //True = the mailer will allow to use any email address for the above domain as actual sender, but always use the above address for any other domain. False = always use the above address (only effective if above is set)

		// Alternative mailers
		$cfg['use_mailer'] = false;  //Options: 'swiftmailer' or false (= using standard mail() function)

		// Swift Mailer options
		$cfg['swiftmailer_path'] = '';  //full path to composer autoload (if using composer), or the include file like this: '.../swift_required.php'
		$cfg['swift_host'] = '';
		$cfg['swift_port'] = '';
		$cfg['swift_user'] = '';
		$cfg['swift_pass'] = '';
		$cfg['swift_encryption'] = false;  //'ssl', 'tls' or false (server must support the encryption if used - check with stream_get_transports() )
		$cfg['swift_fail_log'] = false;  //file to which email addresses which fail will be logged (set to false to disable logging)

		// Debugging options
		$cfg['sending_enabled'] = true;  //the global variable $GLOBALS['always_send_email'] can be used to override a value of false here
		$cfg['show_mail_in_browser'] = false;
		$cfg['log_to_file'] = false;
		$cfg['log_to_database'] = false;  //false to disable logging. To enable logging write the database name to use
		$cfg['call_url_on_error'] = false;  //call this URL (with details being POSTed) when sending an email fails (method of being able to notify ourselves of errors)

		//   Database logging (if 'log_to_database'=true)
		$cfg['db_log_server_id'] = '';  //database server ID as defined in core (empty for default)
		$cfg['db_log_table'] = 'temp_emaillog_raw';   //table must already exist, it is not auto-created (SQL for creating table is below)
		/*
		CREATE TABLE `temp_emaillog_raw` (
			`emaillog_rawID` INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
			`eml_intervowen_id` VARCHAR(40) NULL DEFAULT NULL,
			`eml_timestamp` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
			`eml_from` VARCHAR(255) NOT NULL,
			`eml_to` VARCHAR(255) NOT NULL,
			`eml_subj` VARCHAR(255) NOT NULL,
			`eml_raw` TEXT NOT NULL,
			`eml_send_status` VARCHAR(255) NULL DEFAULT NULL,
			`eml_receive_status` VARCHAR(255) NULL DEFAULT NULL,
			PRIMARY KEY (`emaillog_rawID`),
			INDEX `eml_intervowen_id` (`eml_intervowen_id`)
		)
		COLLATE='utf8_general_ci'
		*/
		$cfg['purge_database_log_after'] = 60;  //days after which the database log is automatically purge for old entries (set to false or 0 to disable purging completely)

		return $cfg;
	}

	public static function send_email($fromemail, $fromname, $to, $fsubj, $femailbody, $htmlbody = false, $options = array() ) {
		/*
		DESCRIPTION:
		- send emails, including file attachments
		REQUIREMENTS:
		- several global variables
		INPUT:
		- $fromemail (req.) : sender address
		- $fromname  (req.) : sender name
		- $to (req.) : recipient. Can either be a:
			- string with recipient email address
				OR
			- array where 1st element is recipient name and 2nd is recipient email address
				OR
			- array with a key named 'multiple', where the value is an array with subarrays with keys 'email' and optionally 'name'
				- eg: $recips['multiple'] = array(array('name' => $nameA, 'email' => $emailA), array('name' => $nameB, 'email' => $emailB))
		- $fsubj (req.) : mail subject
		- $femailbody (req.) : mail body in plain text
			- for the bodies you can make line-breaks (by using \r\n - \n only won't work) before text you don't want to be broken by chunk_split(). This could be concerning text that you want to be able to search for in the raw message. The text can normally be maximum 76 characters though (that's the line length chunk_split() uses).
		- $htmlbody (opt.) : mail body in HTML format
		- $options (opt.) : associative array with different options and flags. Keys available:
			- 'cc'  : array like this:
					array(
					  'person1@example.org',
					  'person2@otherdomain.org' => 'Person 2 Name',
					  'person3@example.org',
					  'person4@example.org' => 'Person 4 Name'
					)
				- DEPRECATED: or a string with comma-separated list of addresses only, who receive copies of the email
			- 'bcc' : array like this:
					array(
					  'person1@example.org',
					  'person2@otherdomain.org' => 'Person 2 Name',
					  'person3@example.org',
					  'person4@example.org' => 'Person 4 Name'
					)
				- DEPRECATED: or a string with comma-separated list of addresses only, invisible to regular recipients, to receive copies of email (Blind Carbon Copy)
			- 'attach_files' : array with complete path to files or content of file to attach to the email
				- to specify file content instead of a file reference make an entry an associative array instead, with these keys:
					- 'filename' : file name that should be shown in the email
					- 'content' : the content of the file
			- 'reply_to' : email address to set as Reply-To adress
			- 'extra_headers' : array with extra headers to put into the email (eg. with tracking information)
				- keys are the names of the header items, and values are the corresponding header item value
			- 'bounce_to_sender' : set value to 1 to have bounces go to sender address (Return-Path) instead of one common system-defined address
				- use string 'SKIP' to not set a bounce address at all
				- if PHP runs in safe mode, this option is ignored for mail() method
			- 'enable_debugging' : set to true to echo debugging info
		*/
		// Check arguments
		if (empty($options)) $options = array();
		if (!is_array($options)) {
			core::system_error('Invalid options for sending email.', array('Options' => $options) );
		}
		// Determine options/flags
		if (!is_array($options['attach_files']) && !empty($options['attach_files'])) {
			core::system_error('Invalid parameter with files to attach when sending email.', array('Attach files' => $options['attach_files']) );
		}
		if (!is_array($options['attach_files'])) {
			$options['attach_files'] = array();
		}
		if (!is_array($options['extra_headers']) && !empty($options['extra_headers'])) {
			core::system_error('Invalid parameter with extra headers for sending email.', array('Extra headers' => $options['extra_headers']) );
		}
		if (!is_array($options['extra_headers'])) {
			$options['extra_headers'] = array();
		}
		// Prohibit exploits attempting header injection
		if (strpos($fromemail, "\n") !== false) {
			core::system_error('Invalid sender address for sending email.');
		}
		if (strpos($fromname, "\n") !== false) {
			core::system_error('Invalid sender name for sending email.');
		}
		if (is_string($options['cc']) && $options['cc'] && strpos($options['cc'], "\n") !== false) {
			core::system_error('Invalid Carbon Copy list for sending email.');
		}
		if (is_string($options['bcc']) && $options['bcc'] && strpos($options['bcc'], "\n") !== false) {
			core::system_error('Invalid Blind Carbon Copy list for sending email.');
		}
		if (is_string($options['reply_to']) && $options['reply_to'] && strpos($options['reply_to'], "\n") !== false) {
			core::system_error('Invalid Blind Carbon Copy list for sending email.');
		}
		if (strpos($fsubj, "\n") !== false) {
			core::system_error('Invalid subject when sending email.');
		}

		$cfg = core::get_class_defaults(__CLASS__);
		$cfg = core::run_hooks('jfw.send_email_config', $cfg, $fromemail, $fromname, $to, $fsubj, $options);

		// Other preparations
		if (is_array($to)) {
			if (!$to[1] && !$to['multiple']) {
				core::system_error('Missing recipient email address for sending email.');
			}
			if ($to['multiple']) {
				// Multiple To-addresses have been specified
				$arr_recipients = $arr_recipients_log = array();
				foreach ($to['multiple'] as $curr_recip) {
					if ($curr_recip['email'] && $curr_recip['name']) {
						if (strpos($curr_recip['email'], "\n") !== false || strpos($curr_recip['name'], "\n") !== false) {  // Prohibit exploits attempting header injection
							core::system_error('An invalid recipient name or email address was found when trying to send email.');
						}
						if ($cfg['use_mailer'] == 'swiftmailer') {
							$arr_recipients[$curr_recip['email']] = $curr_recip['name'];
						} else {
							$arr_recipients[] = '"'. str_replace('"', '', $curr_recip['name']) .'" <'. $curr_recip['email'] .'>';
						}
						$arr_recipients_log[] = array($curr_recip['email'] => $curr_recip['name']);
					} elseif ($curr_recip['email']) {
						if (strpos($curr_recip['email'], "\n") !== false) {  // Prohibit exploits attempting header injection
							core::system_error('An invalid recipient email address was found when trying to send email.');
						}
						$arr_recipients[] = $curr_recip['email'];
					} else {
						core::system_error('Email address for a recipient was not specified when trying to send email.', array('Recips' => print_r($to, true)) );
					}
					if ($cfg['use_mailer'] != 'swiftmailer') {
						$recipient = implode(', ', $arr_recipients);
					}
				}
			} else {
				if (strpos($to[0], "\n") !== false || strpos($to[1], "\n") !== false) {  // Prohibit exploits attempting header injection
					core::system_error('Invalid recipient for sending email.');
				}
				$arr_recipients = array($to[1] => $to[0]);
				$arr_recipients_log = array($arr_recipients);  //to unify the format
				if ($cfg['use_mailer'] != 'swiftmailer') {
					$recipient = '"'. str_replace('"', '', $to[0]) .'" <'. $to[1] .'>';
				}
			}
		} else {
			if (!$to) {
				core::system_error('Missing recipient email address for sending email.');
			}
			if (strpos($to, "\n") !== false) {  // Prohibit exploits attempting header injection
				core::system_error('Invalid recipient address for sending email.');
			}
			$arr_recipients = array($to);
			if (!$cfg['use_mailer'] == 'swiftmailer') {
				$recipient = $to;
			}
		}

		if (!empty($arr_recipients_log)) {
			$recipient_log = json_encode($arr_recipients_log);
		} else {
			$recipient_log = json_encode($arr_recipients);
		}

		if ($options['bounce_to_sender'] && $fromemail) {
			$bounce_email_addr = $fromemail;
		} elseif ($cfg['bounce_addr']) {
			$bounce_email_addr = $cfg['bounce_addr'];
		} elseif (ini_get('sendmail_from')) {
			$bounce_email_addr = ini_get('sendmail_from');
		} else {
			$bounce_email_addr = false;
		}

		$orig_fromemail = $fromemail;
		if ($cfg['enforce_sender_as_replyto'] && strtolower($fromemail) != strtolower($cfg['enforce_sender_as_replyto'])) {
			$em = explode('@', $cfg['enforce_sender_as_replyto']);
			if ($cfg['only_enforce_for_other_domains']) {
				if (preg_match('|^.+@'. preg_quote($em[1]) .'$|i', $fromemail)) {
					$diff_sender = false;
				} else {
					$diff_sender = true;
				}
			} else {
				$diff_sender = true;
			}
			if ($diff_sender) {
				if (!$options['reply_to']) {  //specific Reply-To has higher priority
					$options['reply_to'] = $fromemail;
				}
				$fromname = $em[1] .' - '. $fromname;  //also set name to attempt avoiding that recipient thinks this address is sender's own address
				$fromemail = $cfg['enforce_sender_as_replyto'];
			}
		}

		if ($cfg['sending_enabled'] || $GLOBALS['always_send_email']) {

			if ($cfg['use_mailer'] == 'swiftmailer') {
				//==============================================================================
				//     Swift Mailer
				//==============================================================================
				require_once($cfg['swiftmailer_path']);

				// Create message
				$message = \Swift_Message::newInstance();
				$message->setSubject($fsubj);
				$message->setFrom(array($fromemail => $fromname));
				$message->setTo($arr_recipients);
				if ($bounce_email_addr && $options['bounce_to_sender'] !== 'SKIP' && $cfg['bounce_addr'] !== 'SKIP') {
					$message->setReturnPath($bounce_email_addr);
				}
				if ($options['reply_to']) {
					$message->setReplyTo($options['reply_to']);
				}
				if (!empty($options['cc'])) {
					if (is_string($options['cc'])) {
						$tmp = explode(',', $options['cc']);
						foreach ($tmp as $cemail) {
							$message->addCc(trim($cemail));
						}
						$str_cc =& $options['cc'];
					} else {
						//assuming array
						$message->setCc($options['cc']);
						//string is used later:
						$str_cc = array();
						foreach ($options['cc'] as $c_key => $c_value) {
							if (is_numeric($c_key)) {
								$str_cc[] = $c_value;
							} else {
								$str_cc[] = '"'. $c_value .'" <'. $c_key .'>';
							}
						}
						$str_cc = implode(', ', $str_cc);
					}
				}
				if (!empty($options['bcc'])) {
					if (is_string($options['bcc'])) {
						$tmp = explode(',', $options['bcc']);
						foreach ($tmp as $cemail) {
							$message->addBcc(trim($cemail));
						}
						$str_bcc =& $options['bcc'];
					} else {
						//assuming array
						$message->setBcc($options['bcc']);
						//string is used later:
						$str_bcc = array();
						foreach ($options['bcc'] as $c_key => $c_value) {
							if (is_numeric($c_key)) {
								$str_bcc[] = $c_value;
							} else {
								$str_bcc[] = '"'. $c_value .'" <'. $c_key .'>';
							}
						}
						$str_bcc = implode(', ', $str_bcc);
					}
				}

				// Attach files
				$eff_attached_files = array();
				if (count($options['attach_files']) > 0) {
					foreach ($options['attach_files'] as $attmfile) {
						if (is_array($attmfile) && array_key_exists('filename', $attmfile) && array_key_exists('content', $attmfile)) {
							if (!$attmfile['filename']) {
								core::system_error('Missing file name for inline attached file.', array('File' => print_r($attmfile, true) ) );
							}
							$message->attach(\Swift_Attachment::newInstance($attmfile['content'], $attmfile['filename']));
							$eff_attached_files[] = $attmfile['filename'];
						} else {
							$filename = basename($attmfile);
							if (is_file($attmfile)) {
								$message->attach(\Swift_Attachment::fromPath($attmfile));
								$eff_attached_files[] = $filename;
							} else {
								// Write in message that we could not find the file
								$femailbody = "WARNING: The file ". $filename ." should have been attached to this mail but could not be found. Please contact sender if you need this file.\r\n\r\n". $femailbody;
								if ($htmlbody) {
									//NOTE: this probably gives an improperly formatted HTML but we have to live with that (could make code to put it after the body if one exists...)
									$htmlbody = "<p>WARNING: The file <b>". $filename ."</b> should have been attached to this mail but could not be found. Please contact sender if you need this file.</p>\r\n\r\n". $htmlbody;
								}
								$eff_attached_files[] = 'MISSING:'. $filename;
							}
						}
					}
				}

				// Set body
				if ($htmlbody) {
					$message->setBody($htmlbody, 'text/html');

					// Automatically make a plain text version if none exists
					// SAME CODE AS BELOW
					if (!$femailbody) {
						$femailbody = $htmlbody;
						$femailbody = str_replace(array("\t", "\r"), array('', ''), $femailbody);
						$femailbody = preg_replace('|<head.*/head>|is'.(mb_internal_encoding() == 'UTF-8' ? 'u' : ''), '', $femailbody);
						$femailbody = html_entity_decode($femailbody);
						$femailbody = str_replace(chr(160), ' ', $femailbody);  //convert nbsp to normal
						$femailbody = str_ireplace('<p', "\n\n<p", $femailbody);
						$femailbody = str_ireplace('</p>', "\n\n", $femailbody);
						$femailbody = str_ireplace('<br/>', "\n", $femailbody);
						$femailbody = str_ireplace('<td', " | <td", $femailbody);
						$femailbody = str_ireplace('<tr', "------------------------\n<tr", $femailbody);
						$femailbody = str_ireplace('</tr>', "\n\n", $femailbody);
						$femailbody = str_ireplace('<div', "\n\n<div", $femailbody);
						$femailbody = str_ireplace('</div>', "\n\n", $femailbody);
						$femailbody = preg_replace('|<a.*href=["\'](.*)["\'].*>(.*)</a>|U'.(mb_internal_encoding() == 'UTF-8' ? 'u' : ''), '$2 [[[$1]]]', $femailbody);  //extract links
						$femailbody = strip_tags($femailbody);
						$femailbody = str_replace(array('[[[', ']]]'), array('<', '>'), $femailbody);  //for links
						$femailbody = preg_replace("/^[ |\t]+|[ |\t]+$/m".(mb_internal_encoding() == 'UTF-8' ? 'u' : ''), "", $femailbody);  //trim whitespace (and |) at the start and the end of each line
						while (strpos($femailbody, "\n\n\n") !== false) {
							$femailbody = str_replace("\n\n\n", "\n\n", $femailbody);  //remove unnecessary line-breaks
						}
						while (strpos($femailbody, "------------------------\n\n------------------------\n\n") !== false) {
							$femailbody = str_replace("------------------------\n\n------------------------\n\n", "------------------------\n\n", $femailbody);  //remove all double row lines
						}
						$femailbody = trim($femailbody);
						$femailbody = str_replace("\n", "\r\n", $femailbody);
						$femailbody = $fsubj ."\r\n\r\n!! Please switch to HTML view to read this message. !!\r\n\r\n". trim($femailbody);  //put the subject first since some mail readers use the plain text view to show a one-line preview of the email (eg. Zimbra)
					}

					$message->addPart($femailbody, 'text/plain');
				} else {
					$message->setBody($femailbody, 'text/plain');
				}

				// Add extra headers
				if (count($options['extra_headers']) > 0) {
					$headers = $message->getHeaders();
					foreach ($options['extra_headers'] as $cheader_name => $cheader_value) {
						$headers->addTextHeader(trim($cheader_name), trim($cheader_value));
					}
				}

				// Create a transport
				$transport = \Swift_SmtpTransport::newInstance($cfg['swift_host'], $cfg['swift_port'], $cfg['swift_encryption']);
				if ($cfg['swift_user']) {
					$transport->setUsername($cfg['swift_user']);
				}
				if ($cfg['swift_pass']) {
					$transport->setPassword($cfg['swift_pass']);
				}

				// Send the message
				$mailer = \Swift_Mailer::newInstance($transport);

				if ($options['enable_debugging']) {
					// Debugging: output the SMTP communication
					$logger = new \Swift_Plugins_Loggers_EchoLogger();
					$mailer->registerPlugin(new \Swift_Plugins_LoggerPlugin($logger));

					// Debugging: output the raw mail
					echo '<pre>';
					echo htmlentities($message->toString());
					echo '</pre>';
				}

				$mailexception = null;
				try {
					$success_recip_count = $mailer->send($message, $failed_recips);
				} catch (\Exception $e) {
					$mailexception = $e->getMessage();
				}

				// Error handling
				if ((!empty($failed_recips) || $mailexception)) {
					$log  = "=======================================\r\n";
					$log .= date('Y-m-d H:i:s') .":\r\n\r\n";
					if (!empty($failed_recips)) {
						$log .= print_r($failed_recips, true) ."\r\n\r\n";
					}
					if ($mailexception) {
						$log .= $mailexception ."\r\n\r\n";
					}
					$log .= 'Subj: '. $fsubj ."\r\n\r\n";
					$log .= "See error log table for details.\r\n";
					if ($cfg['swift_fail_log']) {
						file_put_contents($cfg['swift_fail_log'], $log, FILE_APPEND);
					}
					if ($cfg['call_url_on_error']) {
						network::get_url_post($cfg['call_url_on_error'], array('mailsubj' => 'Email error at '. $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'], 'mailbody' => $log) );
					}
					core::system_error('Sorry, an error occured while trying to send the email.', array('Exception msg' => $mailexception, 'Failed recips' => print_r($failed_recips, true)), array('xnotify' => false) );  //avoid endless loop
				}

				unset($mailer);
				unset($transport);
				unset($headers);
				unset($message);

			} else {
				//==============================================================================
				//     mail()
				//==============================================================================

				// Handle HTML messages and attachments
				if ($htmlbody || count($options['attach_files']) > 0) {
					$use_mime = true;
					$randon_seq = rand(10000, 99999);
					$outerbound = '----=_OuterBoundary_'. $randon_seq .'_000';  //outer MIME boundary
					$innerbound = '----=_InnerBoundary_'. $randon_seq .'_001';  //inner MIME boundary
					if ($htmlbody) {
						$has_alternative = true;
						// Automatically make a plain text version if none exists
						// SAME CODE AS ABOVE
						if (!$femailbody) {
							$femailbody = $htmlbody;
							$femailbody = str_replace(array("\t", "\r"), array('', ''), $femailbody);
							$femailbody = preg_replace('|<head.*/head>|is'.(mb_internal_encoding() == 'UTF-8' ? 'u' : ''), '', $femailbody);
							$femailbody = html_entity_decode($femailbody);
							$femailbody = str_replace(chr(160), ' ', $femailbody);  //convert nbsp to normal
							$femailbody = str_ireplace('<p', "\n\n<p", $femailbody);
							$femailbody = str_ireplace('</p>', "\n\n", $femailbody);
							$femailbody = str_ireplace('<br/>', "\n", $femailbody);
							$femailbody = str_ireplace('<td', " | <td", $femailbody);
							$femailbody = str_ireplace('<tr', "------------------------\n<tr", $femailbody);
							$femailbody = str_ireplace('</tr>', "\n\n", $femailbody);
							$femailbody = str_ireplace('<div', "\n\n<div", $femailbody);
							$femailbody = str_ireplace('</div>', "\n\n", $femailbody);
							$femailbody = preg_replace('|<a.*href=["\'](.*)["\'].*>(.*)</a>|U'.(mb_internal_encoding() == 'UTF-8' ? 'u' : ''), '$2 [[[$1]]]', $femailbody);  //extract links
							$femailbody = strip_tags($femailbody);
							$femailbody = str_replace(array('[[[', ']]]'), array('<', '>'), $femailbody);  //for links
							$femailbody = preg_replace("/^[ |\t]+|[ |\t]+$/m".(mb_internal_encoding() == 'UTF-8' ? 'u' : ''), "", $femailbody);  //trim whitespace (and |) at the start and the end of each line
							while (strpos($femailbody, "\n\n\n") !== false) {
								$femailbody = str_replace("\n\n\n", "\n\n", $femailbody);  //remove unnecessary line-breaks
							}
							while (strpos($femailbody, "------------------------\n\n------------------------\n\n") !== false) {
								$femailbody = str_replace("------------------------\n\n------------------------\n\n", "------------------------\n\n", $femailbody);  //remove all double row lines
							}
							$femailbody = trim($femailbody);
							$femailbody = str_replace("\n", "\r\n", $femailbody);
							$femailbody = $fsubj ."\r\n\r\n!! Please switch to HTML view to read this message. !!\r\n\r\n". trim($femailbody);  //put the subject first since some mail readers use the plain text view to show a one-line preview of the email (eg. Zimbra)
						}
					}
				}
				// Additional header lines for From, CC, BCC etc.
				$headers = 'From: ';
				if ($fromname) {
					$headers .= '"'. str_replace('"', '', $fromname) .'" <'. $fromemail .">\r\n";
				} else {
					$headers .= $fromemail ."\r\n";
				}
				if ($options['reply_to']) {
					$headers .= 'Reply-To: '. $options['reply_to'] ."\r\n";
				}
				if (!empty($options['cc'])) {
					if (is_string($options['cc'])) {
						$headers .= 'Cc: '. $options['cc'] ."\r\n";
						$str_cc =& $options['cc'];
					} else {
						$str_cc = array();
						foreach ($options['cc'] as $ckey => $cvalue) {
							if (is_numeric($ckey)) {
								$str_cc[] = $cvalue;
							} else {
								$str_cc[] = '"'. $cvalue .'" <'. $ckey .'>';
							}
						}
						$str_cc = implode(', ', $str_cc);

						$headers .= 'Cc: '. $str_cc;
						$headers .= "\r\n";
					}
				}
				if (!empty($options['bcc'])) {
					if (is_string($options['bcc'])) {
						$headers .= 'Bcc: '. $options['bcc'] ."\r\n";
						$str_bcc =& $options['bcc'];
					} else {
						$str_bcc = array();
						foreach ($options['bcc'] as $ckey => $cvalue) {
							if (is_numeric($ckey)) {
								$str_bcc[] = $cvalue;
							} else {
								$str_bcc[] = '"'. $cvalue .'" <'. $ckey .'>';
							}
						}
						$str_bcc = implode(', ', $str_bcc);
						$headers .= 'Bcc: '. $str_bcc;
						$headers .= "\r\n";
					}

				}
				foreach ($options['extra_headers'] as $cheader_name => $cheader_value) {
					$cheader_name = trim($cheader_name);
					$cheader_value = trim($cheader_value);
					// Prohibit exploits attempting header injection
					if (strpos($cheader_name, "\n") !== false || strpos($cheader_value, "\n") !== false) {
						core::system_error('Invalid extra headers for sending email.');
					}
					$headers .= $cheader_name .": ". $cheader_value ."\r\n";
				}
				$headers .= "X-Mailer: WinterNet.no_PHP_v3.0\r\n";
				if ($use_mime) {
					$headers .= "MIME-Version: 1.0\r\n";
					$headers .= 'Content-Type: multipart/mixed; boundary="'. $outerbound ."\"\r\n"; // Mime type
					#DOES LINE BREAK MAKE BETTER COMPATIBILITY? If so we should change to this style in all Content-Type and Content-Dispostion lines (using \r\n\t): $headers .= "Content-Type: multipart/mixed;\r\n\tboundary=\"". $outerbound ."\"\r\n"; // Mime type
					// First attach files (if any errors occur we can write that in the message)
					$eff_attached_files = array();
					if (count($options['attach_files']) > 0) {
						$body_files = '';
						$file_not_found = false;
						foreach ($options['attach_files'] as $attmfile) {
							if (is_array($attmfile) && isset($attmfile['filename']) && isset($attmfile['content'])) {
								if (!$attmfile['filename']) {
									core::system_error('Missing file name for inline attached file.', array('File' => print_r($attmfile, true) ) );
								}
								$filecontent = $attmfile['content'];
								$filename = $attmfile['filename'];
							} else {
								$filename = basename($attmfile);
								if (is_file($attmfile)) {
									$fp = fopen($attmfile, 'r');  //read the file
									$filecontent = fread($fp, filesize($attmfile));
									fclose($fp);
								} else {
									// Write in message that we could not find the file
									$femailbody = "WARNING: The file ". $filename ." should have been attached to this mail but could not be found. Please contact sender if you need this file.\r\n\r\n". $femailbody;
									if ($htmlbody) {
										//NOTE: this probably gives an improperly formatted HTML but we have to live with that (could make code to put it after the body if one exists...)
										$htmlbody = "<p>WARNING: The file <b>". $filename ."</b> should have been attached to this mail but could not be found. Please contact sender if you need this file.</p>\r\n\r\n". $htmlbody;
									}
									$file_not_found = true;
								}
							}
							if (!$file_not_found) {
								$body_files .= '--'. $outerbound ."\r\n";  //write to mail
								$body_files .= "Content-Type: application/octetstream; name=\"". $filename ."\"\r\n";
								$body_files .= "Content-Transfer-Encoding: base64\r\n";
								$body_files .= "Content-Disposition: attachment; filename=\"". $filename ."\"\r\n\r\n";
								$body_files .= chunk_split(base64_encode($filecontent)) ."\r\n";
								$eff_attached_files[] = $filename;
							} else {
								$eff_attached_files[] = 'MISSING:'. $filename;
							}
						}
					} else {
						$body_files = ''; //no attached files
					}
					// Make message MIME parts
					$body_msg  = "This is a multi-part message in MIME format.\r\n\r\n";
					$body_msg .= '--'. $outerbound ."\r\n";
					if ($htmlbody) { //if we have an HTML body we also ALWAYS have a plain text body
						$body_msg .= "Content-Type: multipart/alternative; boundary=\"". $innerbound ."\"\r\n\r\n"; // Mime type
						// Plain text section
						$body_msg .= '--'. $innerbound ."\r\n";
						$body_msg .= "Content-Type: text/plain; charset=utf-8\r\n";
						if (function_exists('imap_8bit')) {  //encode to quoted-printable if available on this server
							$body_msg .= "Content-Transfer-Encoding: quoted-printable\r\n\r\n";
							$body_msg .= imap_8bit($femailbody) ."\r\n\r\n";
						} else {
							$body_msg .= "Content-Transfer-Encoding: base64\r\n\r\n";
							$body_msg .= chunk_split(base64_encode($femailbody)) ."\r\n\r\n";
						}
						//HTML text section
						$body_msg .= '--'. $innerbound ."\r\n";
						$body_msg .= "Content-Type: text/html; charset=utf-8\r\n";
						if (!stristr($htmlbody, '<html')) {  //add <html><body>...</body></html> if not present (not necessary, but for the sake of perfection)
							$htmlbodytmp  = '<html>';
							$htmlbodytmp .= '<head>';
							$htmlbodytmp .= '<meta http-equiv="content-type" content="text/html; charset=utf-8" />';
							$htmlbodytmp .= '<meta name="viewport" content="width=device-width, initial-scale=1" />';
							$htmlbodytmp .= '</head>';
							$htmlbody = $htmlbodytmp .'<body>'. $htmlbody .'</body></html>';
						}
						if (function_exists('imap_8bit')) {
							$body_msg .= "Content-Transfer-Encoding: quoted-printable\r\n\r\n";
							$body_msg .= imap_8bit($htmlbody) ."\r\n\r\n";
						} else {
							$body_msg .= "Content-Transfer-Encoding: base64\r\n\r\n";
							$body_msg .= chunk_split(base64_encode($htmlbody)) ."\r\n\r\n";
						}
						$body_msg .= '--'. $innerbound ."--\r\n\r\n";  //NOTE the 2 ending dashes/hyphens that ends the MIME part
					} else {
						// Only plain text
						$body_msg .= "Content-Type: text/plain; charset=utf-8\r\n";
						if (function_exists('imap_8bit')) {  //encode to quoted-printable if available on this server
							$body_msg .= "Content-Transfer-Encoding: quoted-printable\r\n\r\n";
							$body_msg .= imap_8bit($femailbody) ."\r\n\r\n";
						} else {
							$body_msg .= "Content-Transfer-Encoding: base64\r\n\r\n";
							$body_msg .= chunk_split(base64_encode($femailbody)) ."\r\n\r\n";
						}
					}
					$body .= $body_msg . $body_files;
					$body .= '--'. $outerbound ."--\r\n";  //NOTE the 2 endings dashes/hyphens that ends the MIME part
				} else {
					//NOTE: doing this instead of using mb_send_mail() because that function will always use base64 but I prefer quoted-printable
					$headers .= "MIME-Version: 1.0\r\n";
					$headers .= "Content-Type: text/plain; charset=utf-8\r\n";
					if (function_exists('imap_8bit')) {  //encode to quoted-printable if available on this server
						$headers .= "Content-Transfer-Encoding: quoted-printable\r\n\r\n";
						$body = imap_8bit($femailbody);
					} else {
						$headers .= "Content-Transfer-Encoding: base64\r\n";
						$body = chunk_split(base64_encode($femailbody));
					}
				}
				// Remove carriage return (\r) (ONLY applicable for Linux/Unix, not Windows)
				//   NOTES: If line-breaks are made by using \r\n it will be interpreted as 2 line-breaks, at least in Pegasus Mail and some reported GMail
				//   NOTES: We place this here (after generating the MIME message) and allow \r\n in code above because chunk_split() needs \r\n to actually continue on a new line in the MIME message (which we make use of when writing footprints in emails - to not have them broken up by chunk_split() )
				if (strtoupper(substr(PHP_OS, 0, 3) != 'WIN')) {  //Alternative method: if (PHP_EOL != "\r\n")
					$body = str_replace("\r", '', $body);  //this character together with \n would cause double line-breaks
				}
				// Have bounces sent to a specific address
				if ($bounce_email_addr && $options['bounce_to_sender'] !== 'SKIP' && $cfg['bounce_addr'] !== 'SKIP') {
					$additional_parameters = '-f'. $bounce_email_addr;  //force PHP to send return bounced mail to this address
				}

				if ($options['enable_debugging']) {
					//debug raw email content
					echo '<pre>';
					echo nl2br(htmlentities($headers));
					echo '<hr>';
					echo 'Additional params: '. htmlentities($additional_parameters);
					echo '<hr>';
					echo 'To: '. htmlentities($recipient_log) .'<br>';
					echo 'Subj: '. htmlentities($subj);
					echo '<hr>';
					echo nl2br(htmlentities($body));
					echo '</pre>';
				}

				if (!ini_get('safe_mode')) {
					$mailresult = mail($recipient, $fsubj, $body, $headers, $additional_parameters);
				} else {
					//safe mode does not allow additional parameters
					$mailresult = mail($recipient, $fsubj, $body, $headers);
				}
				if (!$mailresult) {
					//NOTE: for some reason $GLOBALS['php_errormsg'] seems to be empty even though 'track_errors' is enabled and whether or not I write @mail() or mail()
					//NOTE: strangely enough I can't find any way to get any error message when mail() fails!
					core::system_error('Email could not be sent. (3)');
				}

			}  //end of: if ($cfg['swiftmailer_path']...) else {

			$email_was_sent = true;
		} else {
			// Workaround for parsing this field when sending is disabled
			if (!empty($options['cc'])) {
				$str_cc = '[one or more CC addresses]';
			}
			if (!empty($options['bcc'])) {
				$str_bcc = '[one or more BCC addresses]';
			}
		}

		// Display mail in browser (the website user will see it)
		if ($cfg['show_mail_in_browser']) {
			echo '<table width="100%" cellpadding=30>';
			echo '<tr><td>';
			echo '<div style="'. ($htmlbody ? '' : 'font-family: Courier New, Courier;') .'background-color: #EEEEEE; padding: 10px">';
			 echo '<div style="font-family: Courier New, Courier">';
			if ($email_was_sent) {
				echo '<font color="blue"><b>Email has been sent successfully</b></font>';
			} else {
				echo '<font color="red"><b>Email not sent as system is running locally or on testing server</b></font>';
			}
			echo '<br/>';
			echo 'From : <b>"'. $fromname .'" &lt;'. $fromemail .'&gt;</b><br/>';
			echo 'To&nbsp;&nbsp; : <b>'. htmlspecialchars($recipient_log) .'</b><br/>';
			if ($str_cc) {
				echo 'CC&nbsp;&nbsp; : <b>'. htmlspecialchars($str_cc) .'</b><br/>';
			}
			if ($str_bcc) {
				echo 'BCC&nbsp; : <b>'. htmlspecialchars($str_bcc) .'</b><br/>';
			}
			if ($options['reply_to']) {
				echo 'Reply-To : <b>'. htmlspecialchars($options['reply_to']) .'</b><br/>';
			}
			echo 'Subj : <b>'. $fsubj .'</b><br/>';
			// Show attachments
			if (count($options['attach_files']) > 0) {
				echo 'Attachments : <br/><b>';
				foreach ($options['attach_files'] as $attmfile) {
					if (is_array($attmfile) && array_key_exists('filename', $attmfile) && array_key_exists('content', $attmfile)) {
						$filename = $attmfile['filename'];
						echo htmlspecialchars($filename) .'<br/>';
					} else {
						$filename = basename($attmfile);
						if (is_file($attmfile)) {
							echo htmlspecialchars($filename) .'<br/>';
						} else {
							echo '<font color="#FF0000">ERROR</font><br/>';
							//write in message that we could not find the file
							if (!$email_was_sent) {  //this has already been done if the mail was actually sent
								$femailbody = 'WARNING: The file '. $filename ." should have been attached to this mail but could not be found. Please contact sender if you need this file.\r\n\r\n". $femailbody;
								if ($htmlbody) {
									//this probably gives an improperly formatted HTML but we have to live with that (could make code to put it after the body if one exists...)
									$htmlbody = '<p>WARNING: The file <b>'. $filename ."</b> should have been attached to this mail but could not be found. Please contact sender if you need this file.</p>\r\n\r\n". $htmlbody;
								}
							}
						}
					}
				}
				echo '</b>';
			}
			foreach ($options['extra_headers'] as $cheader_name => $cheader_value) {
				echo $cheader_name .' : <b>'. $cheader_value .'</b><br/>';
			}
			if ($options['bounce_to_sender'] !== 'SKIP' && $cfg['bounce_addr'] !== 'SKIP') {
				echo 'Bounce address : <b>'. $bounce_email_addr .'</b><br/>';
			}
			 echo '</div>';
			echo '<hr size="1" color="black">';
			if ($htmlbody) {
				echo '<h3 style="color: chocolate">HTML version</h3>';
				echo $htmlbody;
				echo '<hr size="1" color="black">';
				echo '<h3 style="color: chocolate">Text version</h3>';
			}
			$femailbodyHTML = str_replace("\n", '<br/>', $femailbody);
			$femailbodyHTML = str_replace('  ','&nbsp; ',$femailbodyHTML);
			$femailbodyHTML = str_replace('  ','&nbsp; ',$femailbodyHTML);
			echo $femailbodyHTML;
			echo '</div>';
			echo '</td></tr>';
			echo '</table>';
		}

		// Preprocess mail body before logging (if function is defined)
		$altered = core::run_hooks('jfw.preprocess_mail_body_log', false, $femailbody, $htmlbody);
		if (!empty($altered)) {
			list($femailbody, $htmlbody) = $altered;
		}

		// Write mail to file for debugging
		if ($cfg['log_to_file']) {
			$fp = fopen($cfg['log_to_file'], 'a');
			fwrite($fp, '====================== '. date('r') .' ==================='."\r\n");
			fwrite($fp, 'From: '. $fromname .' <'. $fromemail .'>' . "\r\n");
			fwrite($fp, 'To  : '. $recipient_log . "\r\n");
			if ($str_cc) {
				fwrite($fp, 'CC  : '. $str_cc . "\r\n");
			}
			if ($str_bcc) {
				fwrite($fp, 'BCC : '. $str_bcc . "\r\n");
			}
			if ($options['reply_to']) {
				fwrite($fp, 'Reply-To : '. $options['reply_to'] . "\r\n");
			}
			fwrite($fp, 'Subj: '. $fsubj . "\r\n");
			foreach ($options['extra_headers'] as $cheader_name => $cheader_value) {
				fwrite($fp, $cheader_name .' : '. $cheader_value . "\r\n");
			}
			if ($options['bounce_to_sender'] !== 'SKIP' && $cfg['bounce_addr'] !== 'SKIP') {
				fwrite($fp, 'Bounce address : '. $bounce_email_addr . "\r\n");
			}
			if (!empty($eff_attached_files)) {
				fwrite($fp, 'Attachments : '. implode(', ', $eff_attached_files) . "\r\n");
			}
			fwrite($fp, "\r\n" . $femailbody . "\r\n");
			if ($htmlbody) {
				fwrite($fp, "\r\n***** HTML version ************************************************************\r\n" . $htmlbody . "\r\n");
			}
			fwrite($fp, "\r\n\r\n");
			fclose($fp);
		}

		$emaillog_rawID = null;
		if ($cfg['log_to_database']) {
			$dblog  = 'From: '. $fromname .' <'. $fromemail .'>' . "\r\n";
			$dblog .= 'To  : '. $recipient_log . "\r\n";
			if ($str_cc) {
				$dblog .= 'CC  : '. $str_cc . "\r\n";
			}
			if ($str_bcc) {
				$dblog .= 'BCC : '. $str_bcc . "\r\n";
			}
			if ($options['reply_to']) {
				$dblog .= 'Reply-To : '. $options['reply_to'] . "\r\n";
			}
			#LOGGED IN OWN FIELD. $dblog .= 'Subj: '. $fsubj . "\r\n";
			foreach ($options['extra_headers'] as $cheader_name => $cheader_value) {
				$dblog .= $cheader_name .' : '. $cheader_value . "\r\n";
			}
			if ($options['bounce_to_sender'] !== 'SKIP' && $cfg['bounce_addr'] !== 'SKIP') {
				$dblog .= 'Bounce address : '. $bounce_email_addr . "\r\n";
			}
			if (!empty($eff_attached_files)) {
				$dblog .= 'Attachments : '. implode(', ', $eff_attached_files) . "\r\n";
			}
			$dblog .= str_repeat('=', 80) ."\r\n" . $femailbody . "\r\n";
			if ($htmlbody) {
				$dblog .= "\r\n***** HTML version ************************************************************\r\n" . $htmlbody;
			}

			if ($cfg['use_mailer'] == 'swiftmailer') {
				$send_status = 'SuccessRecipCount:'. $success_recip_count .' FailedRecips:'. implode(', ', (array) $failed_recips);
			} else {
				$send_status = 'EmailWasSent:'. $email_was_sent .' MailResult:'. $mailresult .'';
			}

			$emaillog_rawID = self::log_email_db([
				'from' => $orig_fromemail,
				'to' => $recipient_log,
				'subject' => $fsubj,
				'body' => $dblog,
				'send_status' => $send_status,
			]);
		}

		$result = array(
			'success_recip_count' => $success_recip_count,  //only when Swift Mailer is used
			'emaillog_rawID' => $emaillog_rawID,
		);
		return $result;
	}

	public static function prepare_mail_template($templatefile, $mailtags, $templatefile_var_is_the_content = false, $tag_format = '%%') {
		/*
		DESCRIPTION:
		- load a mail template and replace the dynamic tags with real values
		INPUT:
		- $templatefile : file name of mail template (incl. path, relative to file or absolute)
		- $mailtags : associative array where the keys are the tag names and the values the information to replace the tags with
		- $templatefile_var_is_the_content (true|false) : Set to true if $templatefile is the mail template itself
		OUTPUT:
		- numeric array:
			- first item  : subject of the mail
			- second item : body of the mail
		- you can use "list($mailsubj, $mailbody) = prepare_mail..." to easily fetch the two
		*/

		if ($tag_format == '%%') {
			$tag_start = $tag_end = '%%';
		} elseif ($tag_format == '{}') {
			$tag_start = '{';
			$tag_end = '}';
		}

		// Load the file/template
		if ($templatefile_var_is_the_content === false) {
			//load file
			$filename_only = basename($templatefile);
			$filearray = file($templatefile);
			if ($filearray) {
				core::system_error("Configuration error. Could not open mail template.", array('File' => $templatefile), array('xterminate' => false, 'xnotify' => 'developer'));
			}
		} else {
			//templatefile is the content itself
			//  make into array first
			$templatefile = str_replace("\r", '', $templatefile);  //remove all <Cr>s, they will be re-added automatically in foreach
			$filearray = explode("\n", $templatefile);
			$allkeys = array_keys($filearray);
			foreach ($allkeys as $c) {
				$filearray[$c] = $filearray[$c] ."\r\n";
			}
		}

		$default_mailtags = core::run_hooks('jfw.default_mail_tags', array(), $mailtags, $templatefile);
		$mailtags = array_merge($default_mailtags, $mailtags);

		// Divide into subject and body
		if (is_array($filearray) && count($filearray) >= 1) {
			$subject = $filearray[0];
			$subject = str_replace('SUBJ:', '', $subject);
			$subject = trim($subject);
			for ($fi = 1; $fi <= count($filearray); $fi++) {
				$body .= $filearray[$fi];
			}
			//replace tags
			$keys = array_keys($mailtags);
			foreach ($keys as $currvalue) {
				$searchtag = $tag_start . $currvalue . $tag_end;
				$replacetag = $mailtags[$currvalue];
				$body    = str_replace($searchtag, $replacetag, $body);  //TODO: by time (when we have moved on to PHP 5.x) we can change this to case-insensitive by using the function str_ireplace()
				$subject = str_replace($searchtag, $replacetag, $subject);
			}
			$body = trim($body) . "\n";
		} else {
			$subject = '[Subject not found]';
			$body  = 'An error occured when generating this email. The body text was not found and the developer has been notified.\r\n\r\n';
			// At least we can write the fields that we wanted to merge into the letter!
			if (is_array($mailtags)) {
				$body .= 'The only information available is the following:';
				foreach ($mailtags as $key => $value) {
					$body .= ucfirst(str_replace('_', ' ', $key)) .': '. $value;
				}
			}
			core::system_error('Content of email template file could not be located. This could be a derived error (check the number below).', array('File (name only)' => $filename_only), array('xterminate' => false, 'xnotify' => 'developer'));
		}

		// Return an array holdning body and subject in two different elements
		return array($subject, $body);
	}

	public static function parse_list_of_emails($string, $fail_on_invalid = false) {
		/*
		DESCRIPTION:
		- parse a list of comma- or semicolon-separated email addresses (the format "X X" <a@a.xx> is allowed)
		- test string (valid): '"John Doe" <kjule42@hotmail.slkdj.com>; ptokan@yesville.org, l-s.k_df@sdl-fkj.com  '
		INPUT:
		- $string : string of email addresses
		- $fail_on_invalid : return false when at least one invalid address is found
		OUTPUT:
		- if valid : array with addresses with subarrays of 'name' and 'email' (empty array if none found unless $fail_on_invalid=true)
		- if invalid : false (only if $fail_on_invalid=true)
		- empty string always causes an empty array returned
		*/
		$arr_addresses = array();
		$string = trim(str_replace(';', ',', $string));
		if (!$string) return $arr_addresses;
		$array = imap_rfc822_parse_adrlist($string, '');
		if (strpos(print_r($array, true), 'SYNTAX-ERROR') === false) {
			foreach ($array as $a) {
				if (strpos($a->host, 'SYNTAX-ERROR') !== false || !$a->mailbox || !$a->host || strpos($a->host, '.') == false) {  //okay to use == in last strpos() because at dot (.) at position zero will also be an error
					if ($fail_on_invalid) {
						$is_invalid = true;
						break;
					} else {
						continue;
					}
				} else {
					$arr_addresses[] = array(
						'name' => $a->personal,
						'email' => $a->mailbox .'@'. $a->host
					);
				}
			}
		} elseif (!$fail_on_invalid) {
			//RFC 822 parser failed, try regular expressions instead
			$regex_email = "|[0-9a-zA-Z][-.\\w]*[0-9a-zA-Z]*@[0-9a-zA-Z][-.\\w]*[0-9a-zA-Z]\\.+[a-zA-Z]{2,9}|";  //NOTE: this will not prohibit stuff like ".." and invalid TLD domains
			$result = preg_match_all($regex_email, $string, $matches);
			if (!empty($result)) {
				foreach ($matches[0] as $m) {
					$arr_addresses[] = array(
						'name' => '',
						'email' => $m
					);
				}
			}
		}
		if ($is_invalid) {
			return false;
		} else {
			return $arr_addresses;
		}
	}

	public static function scrample_email_addressHTML($email, $options = []) {
		/*
		DESCRIPTION:
		- scrample email addresses to protect against email harvesting by spammers
		INPUT:
		- $email (req.) : email address to scrample
		- $options (opt.) : associative array with any of the following keys:
			- 'mode' : specify scrampling mode. Available options are:
				- 'plaintext' : will scrample it to eg.: johndoe at yahoo dot com
				- 'nolink' : only show email address as text, don't make active link
			- 'text' : text to show as the link, instead of the email address itself
			- 'params' : associative array with any of the following keys:
				- 'subject' : encode a predefined subject line into the link
				- 'body' : encode a predefined body text into the link
		OUTPUT:
		- a Javascript block to put in HTML code, OR according to scrample mode
		*/
		switch ($options['mode']) {
		case 'plaintext':
			return str_replace(array('@', '.'), array(' at ', ' dot '), $email);
			break;
		default:
			list($part1, $part2) = explode('@', $email);
			$randomno1 = 'n'. rand(10,999999);
			$randomno2 = 'a'. rand(10,999999);
			$html  = '<script type="text/javascript">'."\r\n";
			$html .= '/'.'* <![CDATA[ */'."\r\n";  //break because of Sublime Text syntax highlighter
			$html .= 'var '. $randomno1 .'="'. $part1 .'";';
			$html .= 'var '. $randomno2 .'="'. $part2 .'";';
			if ($options['mode'] != 'nolink') {
				$html .= "document.write('<a hr'+'ef=\"mai'+'lto:'+". $randomno1 ."+eval('unes'+'cape(\'%40\')')+". $randomno2 ."+'". self::mailto_params($options['params']) ."\">');";
			}
			$html .= "document.write(";
			if ($options['text']) {
				require_function('js_esc');
				$html .= "'". js_esc($options['text']) ."'";
			} else {
				$html .= $randomno1 ."+eval('unes'+'cape(\'%40\')')+". $randomno2;
			}
			if ($options['mode'] != 'nolink') {
				$html .= "+'</a>'";
			}
			$html .= ");"."\r\n";
			$html .= '/* ]]> */'."\r\n";
			$html .= '</script>';
			return $html;
		}
	}

	/**
	 * Build parameters (subject, body etc) for a mailto: link
	 *
	 * More information: https://blog.escapecreative.com/customizing-mailto-links/
	 *
	 * @param array $params : Associative array with any of the following keys:
	 *   - `subject` : subject line
	 *   - `body` : body text
	 *   - `cc` : CC email addresses (comma-sep.)
	 *   - `bcc` : BCC email addresses (comma-sep.)
	 */
	public static function mailto_params($params) {
		if (!empty($params)) {
			return '?'. http_build_query($params, '', '&amp;', PHP_QUERY_RFC3986);
		} else {
			return '';
		}
	}

	/**
	 * Scrample all email addresses in a piece of HTML code
	 *
	 * @param string $html : (req.) HTML code
	 * @param array $options : (opt.) Associative array with any of the following keys:
	 *   - any options according to scrample_email_addressHTML()
	 *   - `remove_existing_links` : set to true to remove existing <a href="mailto:..."> tags before processing
	 * @return string : HTML code (incl. Javascript blocks for each email address), OR according to scrample mode
	 */
	public static function scrample_all_email_addressesHTML($html, $options = []) {
		if ($options['remove_existing_links']) {
			//NOTE: removes existing <a href="mailto:..."></a> tags and leaves just the email address (code between the opening and closing tag is discarded)
			$html = preg_replace("|<a href=[\"']?mailto:([^\"' ]+)[\"']?[^>]*>(.*)</a>|siU", '$1', $html);
		}
		return preg_replace_callback('|\b[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,4}\b|i', create_function('$matches', 'return \winternet\jensenfw2\mail::scrample_email_addressHTML($matches[0], '. var_export($options, true) .');'), $html);
	}

	/**
	 * Log sent email to the database
	 *
	 * See database schema in class_defaults() method in the beginning of this file
	 *
	 * @param array $data : (req.) Associative subarrays having the following keys:
	 *   - `from`
	 *   - `to`
	 *   - `replyto`
	 *   - `subject`
	 *   - `body`
	 *   - `send_status` (opt.)
	 *   - `intervowen_id` (opt.)
	 * @return integer : emaillog_rawID of the created log record
	 */
	public static function log_email_db($data = []) {
		$cfg = core::get_class_defaults(__CLASS__);

		$parentfunc = core::get_parent_function(2);
		$data['body'] = 'File/function: '. basename($_SERVER['SCRIPT_NAME']) . ($parentfunc ? ', '. $parentfunc .'()' : '') . "\r\n". $data['body'];

		if (!is_string($data['from'])) {
			if (!empty($data['replyto'])) {
				$data['from'] = json_encode(['ReplyTo' => $data['replyto'], 'From' => $data['from']]);
			} else {
				$data['from'] = json_encode($data['from']);
			}
		} else {
			if (!empty($data['replyto'])) {
				$data['from'] = (is_string($data['replyto']) ? $data['replyto'] .' / '. $data['from'] : array_merge($data['replyto'], ['From' => $data['from']]));
			}
		}
		if (!is_string($data['to'])) {
			$data['to'] = json_encode($data['to']);
		}

		$sql  = "INSERT INTO `". $cfg['log_to_database'] ."`.`". $cfg['db_log_table'] ."` SET ";
		$sql .= "eml_intervowen_id = :id, ";
		$sql .= "eml_timestamp = UTC_TIMESTAMP(), ";
		$sql .= "eml_from = :from, ";
		$sql .= "eml_to = :to, ";
		$sql .= "eml_subj = :subj, ";
		$sql .= "eml_raw = :raw, ";
		$sql .= "eml_send_status = :status ";
		$sql_vars = array(
			'from' => mb_substr($data['from'], 0, 255),
			'to' => mb_substr($data['to'], 0, 255),
			'subj' => mb_substr($data['subject'], 0, 255),
			'raw' => preg_replace('/(?<!\\r)\\n/'.(mb_internal_encoding() == 'UTF-8' ? 'u' : ''), "\r\n", mb_substr($data['body'], 0, 65535)),  //enforce \r\n
			'status' => (array_key_exists('send_status', $data) ? mb_substr($data['send_status'], 0, 255) : null),
			'id' => (array_key_exists('intervowen_id', $data) ? mb_substr($data['intervowen_id'], 0, 255) : null),
		);

		if (@constant('YII_BEGIN_TIME')) {
			// Using Yii framework
			\Yii::$app->db->createCommand($sql, $sql_vars)->execute();
			$emaillog_rawID = \Yii::$app->db->getLastInsertID();
		} else {
			// Not using Yii framework
			core::require_database($cfg['db_server_id']);
			$sql = str_replace(':', '?', $sql);
			$sql = core::prepare_sql($sql, $sql_vars);
			$emaillog_rawID = core::database_result(array('server_id' => $cfg['db_server_id'], $sql), false, 'Database query for logging email to database failed.');
		}

		if ($cfg['purge_database_log_after'] && !$_SESSION['_purged_db_maillog_now']) {
			$purgeSQL = "DELETE FROM `". $cfg['log_to_database'] ."`.`". $cfg['db_log_table'] ."` WHERE TO_DAYS(CURDATE()) - TO_DAYS(eml_timestamp) > ". (int) $cfg['purge_database_log_after'] ."";
			if (@constant('YII_BEGIN_TIME')) {
				\Yii::$app->db->createCommand($purgeSQL)->execute();
				\Yii::$app->session->set('_purged_db_maillog_now', true);
			} else {
				core::database_result(array('server_id' => $cfg['db_server_id'], $purgeSQL), false, 'Database query for purging raw database mail log failed.');
				$_SESSION['_purged_db_maillog_now'] = true;
			}
		}

		return $emaillog_rawID;
	}

	/**
	 * @param integer $emaillog_rawID : IF the email record from temp_emaillog_raw
	 * @param string $add_note : Note to add in the top of the email (plain text)
	 * @return array : Same as send_email(), eg. the new raw email log ID as 'emaillog_rawID'
	 */
	public static function resend_email_from_raw_log($emaillog_rawID, $add_note = '') {
		core::require_database();

		if (!is_numeric($emaillog_rawID)) {
			core::system_error('Invalid email log ID for resending email.');
		}

		$cfg = core::get_class_defaults(__CLASS__);

		$sql = "SELECT * FROM `". $cfg['log_to_database'] ."`.`". $cfg['db_log_table'] ."` WHERE emaillog_rawID = ". $emaillog_rawID;
		$e = core::database_result($sql, 'onerow', 'Database query for getting email log failed.');

		if (empty($e)) {
			core::system_error('Email to be resent could not be found.');
		}

		if (preg_match("|From: (.*) <(.*)>|", $e['eml_raw'], $from)) {
			$fromname = $from[1];
			$fromaddr = $from[2];
		} else {
			core::system_error('Cannot find From when resending email.');
		}
		$to = json_decode($e['eml_to'], true);
		$recipients = array('multiple' => array());
		foreach ($to as $t) {
			if (is_string($t)) {
				$recipients['multiple'][] = array('email' => $t);
			} else {
				$em = key($t);
				$recipients['multiple'][] = array('email' => $em, 'name' => $t[$em]);
			}
		}
		if (preg_match("|CC  : (.+)|", $e['eml_raw'], $cc)) {
			$cc = $cc[1];
		} else {
			$cc = false;
		}
		if (preg_match("|BCC : (.+)|", $e['eml_raw'], $bcc)) {
			$bcc = $bcc[1];
		} else {
			$bcc = false;
		}
		if (preg_match("|Attachments : (.*)|s", $e['eml_raw'], $attachs)) {
			$attachment = $attachs[1];
			//TODO:
			core::system_error('Need to rework dealing with attachments to make it work again');
			$attachment = 'pdf_generated/insur_servreq/'. $attachment;
			if (!file_exists($attachment)) {
				core::system_error('Attachment not found when resending email: '. e($attachment));
			}
		} else {
			$bcc = false;
		}

		$body = self::retrieve_body_from_raw_email_log($e['eml_raw']);
		$bodyplain = $body['plain'];
		$bodyhtml = $body['html'];
		if ($add_note) {
			if ($bodyplain) {
				$bodyplaintmp = core::run_hooks('jfw.insert_note_in_email_resend', $bodyplain, 'plain', $add_note);
				if ($bodyplaintmp && $bodyplaintmp !== $bodyplain) {
					$bodyplain = $bodyplaintmp;
				} else {
					$bodyplain = "\r\n". $add_note ."\r\n\r\n". $bodyplain;
				}
			}
			if ($bodyhtml) {
				$bodyhtmltmp = core::run_hooks('jfw.insert_note_in_email_resend', $bodyhtml, 'html', $add_note);
				if ($bodyhtmltmp && $bodyhtmltmp !== $bodyhtml) {
					$bodyhtml = $bodyhtmltmp;
				} else {
					$bodyhtml = preg_replace("|<body.*>|U", '$0<br><b>'. $add_note .'</b><br/><br/>', $bodyhtml);
				}
			}
		}

		$options = array();
		if ($cc) {
			$options['cc'] = $cc;
		}
		if ($bcc) {
			$options['bcc'] = $bcc;
		}
		if (preg_match("|Reply-To: (.*)|", $e['eml_raw'], $replyto)) {
			$options['reply_to'] = $replyto[1];
		}
		if ($attachment) {
			$options['attach_files'] = array($attachment);
		}

		$result = self::send_email($fromaddr, $fromname, $recipients, $e['eml_subj'], $bodyplain, $bodyhtml, $options);
		return $result;
	}

	/**
	 * Retrieve body from raw email log
	 *
	 * @param string $eml_raw
	 * @return array : Associative array with `plain` and `html`
	 */
	public static function retrieve_body_from_raw_email_log($eml_raw) {
		if (preg_match("|==============================[\\r\\n]+(.*)|s", $eml_raw, $bodymatch)) {
			$bodyplain = $bodymatch[1];
			$bodyhtml = false;
			if (preg_match("|". preg_quote('***** HTML version ************************************************************') ."[\\r\\n]+(.*)|s", $bodyplain, $htmlmatch)) {
				$bodyhtml = $htmlmatch[1];
				$bodyplain = false;
			}
		} else {
			core::system_error('Body not found in raw email.');
		}
		return array(
			'plain' => $bodyplain,
			'html' => $bodyhtml,
		);
	}
}
