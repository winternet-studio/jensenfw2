<?php
/**
 * Functions related to managing bounced emails (mails returned to sender)
 */

namespace winternet\jensenfw2;

class mail_bounce {
	/**
	 * "top" function for determining bouncing mail information
	 * this currently uses two other system for determining the information
	 */
	public static function parse_bounced_mail(&$raw_mail) {
		// First check the RFC1892 compliancy and parse through that
		$rfc1892_status = mail_bounce_rfc1892::get_bounce_info_rfc1892_bouncehandler($raw_mail);
		if ($rfc1892_status) {
			return $rfc1892_status;
		}

		// Then use alternative methods for determining bounced mail information
		$alternative_status = self::get_bounce_info_misc_formats($raw_mail);
		if ($alternative_status) {
			return $alternative_status;
		}

		// No engines could parse the email (which is the case if we get to this point)
		return false;
	}

	/**
	 * Determine if a message is bounced mail
	 *
	 * A bounced email contains a least one of these phrases (out of 639 messages):
	 *
	 * - "delivery status notification" (subject) *
	 * - "mail delivery failed" (subject)
	 * - "mail delivery system" (sender) * biggest group
	 * - "mail delivery subsystem" (sender) * second-biggest group
	 * - "Mail System Error - Returned Mail" (subject)
	 * - "mailer-daemon@" (sender) *
	 * - "internet mail delivery" (sender) *
	 * - "delivery has failed" (subject)
	 * - "delivery failure" (subject)
	 * - "mail delivery problem" (subject)
	 * - "malformed recipient address" (subject)
	 * - "Subject: failure notice" (subject) *
	 * - "User does not exist" (subject)
	 * - "mailbox unavailable" (body)
	 * - the group of words that has a star (*) covered all of the 639 bounced messages
	 *
	 * @param string $raw_mail : The raw message including headers
	 * @return boolean
	 */
	public static function is_bounced_mail(&$raw_mail) {
		if (!trim($raw_mail)) {
			core::system_error('No mail content for determining if it is a bounced mail.');
		}
		$pattern = '/(delivery status notification|mail delivery failed|mail delivery system|mail delivery subsystem|Mail System Error - Returned Mail|mailer-daemon\\@|internet mail delivery|delivery has failed|delivery failure|mail delivery problem|Subject: failure notice|User does not exist|mailbox unavailable)/i';
		if (preg_match($pattern, $raw_mail)) {
			return true;
		} else {
			return false;
		}
	}

	/**
	 * Determine if a bounced message should be ignored
	 *
	 * These types of bounces are ignored:
	 *   - info about delayed delivery
	 */
	public static function ignore_bounced_mail(&$raw_mail) {
		if (preg_match('/delayed \\d\\d hours/i', $raw_mail)) {
			return true;
		} else {
			return false;
		}
	}

	/**
	 * Parse bounced emails in different formats that are are not following the RFC1892 specification
	 *
	 * Tries different methods
	 */
	public static function get_bounce_info_misc_formats(&$raw_mail) {
		// Prepare output variables
		$output = array();
		$output['bounced_addresses'] = array();
		$output['status_messages'] = array();

		// Clean up new-lines (remove carriage return: \r )
		$raw_mail = str_replace("\r", '', $raw_mail);

		// --------- METHOD --------------------------------------------------------
		// Check for existence of X-Failed-Recipients in header
		$pattern = '/X-Failed-Recipients:(.*)\n\S/siU';
		if (preg_match($pattern, $raw_mail, $match)) {
			$addresses = trim($match[1]);
			$found_addresses = true;
			if (strpos($addresses, ',') !== false) {
				//multiple addresses separated by a comma
				$arr_addresses = explode(',' , $addresses);
				foreach ($arr_addresses as $key => $curr_addr) {
					$arr_addresses[$key] = trim($curr_addr);
				}
				$output['bounced_addresses'] = array_merge($output['bounced_addresses'], $arr_addresses);
			} else {
				//only a single address
				$output['bounced_addresses'][] = $addresses;
			}
		}
		if ($found_addresses) {  //= only when the above method returned results
			//  Check for delivery error message
			if (strpos($raw_mail, 'delivery error:')) {
				$pattern = '/delivery error:(.*)(\(|\n\n)/siU';
				if (preg_match($pattern, $raw_mail, $match)) {
					$match[1] = trim($match[1]);
					if (substr($match[1], 0, 3) == 'dd ') {  //some mail servers add this in front of the message
						$match[1] = substr($match[1], 3);
					}
					$output['status_messages'][] = $match[1];
				}
			}
			//  Check for error code
			if (preg_match('/\\d\\d\\d (\\d\\.\\d\\.\\d)[^\\d\\.]/', $raw_mail, $match)) {
				$statusmsgs = mail_bounce_rfc1892::bouncehandler_fetch_status_messages($match[1]);
				$output['status_messages'][] = $statusmsgs['subclass_title'];
				$output['status_messages_details'][] = $statusmsgs['main_desc'] .' --- '. $statusmsgs['subclass_desc'];
			}
			//  Check for error code, alternative method
			if (preg_match('/#(\\d\\.\\d\\.\\d[^\\d\\.])/', $raw_mail, $match)) {
				$statusmsgs = mail_bounce_rfc1892::bouncehandler_fetch_status_messages($match[1]);
				$output['status_messages'][] = $statusmsgs['subclass_title'];
				$output['status_messages_details'][] = $statusmsgs['main_desc'] .' --- '. $statusmsgs['subclass_desc'];
			}
			//  Check for error message - more generic
			if (count($output['status_messages']) == 0) {  //only if a more specific message was not found
				$pattern = '/SMTP error from remote mail server[^\\n]*\n(.*)\n\n/siU';
				if (preg_match($pattern, $raw_mail, $match)) {
					$output['status_messages'][] = trim($match[1]);
				}
			}
			//  Check for error message - even more generic!
			if (count($output['status_messages']) == 0) {  //only if a more specific message was not found
				$pattern = '/address\\(es\\) failed:(.*)--/siU';
				if (preg_match($pattern, $raw_mail, $match)) {
					//remove the addresses if they are included
					foreach ($output['bounced_addresses'] as $a) {
						$match[1] = str_replace($a, '', $match[1]);
					}
					$output['status_messages'][] = trim($match[1]);
				}
			}
			//  Check for subject (as status message)
			if (count($output['status_messages']) == 0) {  //only if a more specific message was not found
				$pattern = '/Subject:(.*)\n\S/siU';
				if (preg_match($pattern, $raw_mail, $match)) {
					$subject = trim($match[1]);
					$output['status_messages'][] = $subject;
				}
			}
			$output['parse_method'] = 'x-failed-recipients';
		}

		// --------- METHOD --------------------------------------------------------
		// Check for the odd messages from AOL with very little info
		if (!$found_addresses) {
			$raw_mail = str_replace("\r", '', $raw_mail);
			$pattern = '/\n{2,}(.*):\n]*\t(.*)\n/iU';
			if (preg_match($pattern, $raw_mail, $match)) {
				$message = trim($match[1]);
				$address = trim($match[2]) .'@aol.com';  //append the domain part
				$output['bounced_addresses'][] = $address;
				$output['status_messages'][] = $message;
				$output['parse_method'] = 'aol';
				$found_addresses = true;
			}
		}

		return $output;
	}
}
