<?php
/**
 * Functions related to parsing bounced emails following the RFC 1892 standard
 *
 * RFC1892_multipart_report_functions.php
 * cfortune@telus.net
 * open-source under BSD license http://opensource.org/licenses/bsd-license.php
 * If you improve this code, please send me a copy
 *
 * 2018-03: I found a github fork/compose package of this code which has been
 *          cleaned up, fixed and improved: https://packagist.org/packages/rambomst/php-bounce-handler
 *          But it had crazy fallback methods that were so loose that it caught wrong email addresses
 *          and reported them as being failed recipients. So I gave up switching to that library - also
 *          since this code with a few fixes seem to still work fine. -Allan
 * As of 2024 this seems to be the latest version of the library: https://github.com/zoonru/PHP-Bounce-Handler
 *          But I don't know yet if it's better than the above and/or mine below...
 */

namespace winternet\jensenfw2;

class mail_bounce_rfc1892 {
	/**
	 * Function that binds all the functions below together into one handy tool
	 *
	 * @author Allan Jensen, WinterNet Studio (www.winternet.no)
	 *
	 * @param string $raw_mail : String with entire mail in its raw format
	 *
	 * @return array|boolean : If success:
	 *   - associative array (where all or none might be present or have value(s)):
	 *     - 'bounced_addresses' : array with email addresses that bounced
	 *     - 'status_messages' : array with all the status messages found
	 *     - 'status_messages_details' : array with all the status messages found but detailed descriptions
	 *     - 'status_codes' : array with error status codes according to the RFC1892 specification
	 *     - 'original_from' : sender address from original letter
	 *     - 'original_to' : recipient address from original letter
	 *     - 'original_subject' : mail subject from original letter
	 * - If failure (when not RFC1892 compliant):
	 * 	- false
	 */
	public static function get_bounce_info_rfc1892_bouncehandler(&$raw_mail, $verbose_mode = false) {
		// Prepare output variable
		$output = [];
		$output['bounced_addresses'] = [];
		$output['status_messages'] = [];
		// Parse mail (taken from testdriver.php)
		$raw_mail_cleaned = static::bouncehandler_initialize($raw_mail, 'string');  //Cleans the line-breaks
		list($head, $body) = preg_split("/\r\n\r\n/", $raw_mail_cleaned, 2);
		$head_hash = static::bouncehandler_parse_head($head);
		if (static::is_RFC1892_multipart_report($head_hash) === TRUE) {
			if ($verbose_mode) {
				echo '<div>COMPLIANCY: RFC1892 multipart message = YES</div>';
			}
			//just continue
		} else {
			if ($verbose_mode) {
				echo '<div>COMPLIANCY: RFC1892 multipart message = NO</div>';
				echo '<div>Cannot parse mail</div>';
			}
			return false;
		}
		$boundary = $head_hash['Content-type']['boundary'];
		$mime_sections = static::parse_body_into_mime_sections($body, $boundary);
		$rpt_hash = static::parse_machine_parsable_body_part($mime_sections['machine_parsable_body_part']);
		//Error status code AND Diagnostic-code
		for($i=0; $i<count($rpt_hash['per_recipient']); $i++){
			$curr_recipient = static::bouncehandler_get_recipient($rpt_hash['per_recipient'][$i]);
			$curr_recipient = str_replace(['<', '>'], '', $curr_recipient);  //a small percentage of bounces have these characters around the address
		    $scode = $rpt_hash['per_recipient'][$i]['Status'];  //Error status code
		    $scode_status_messages = static::bouncehandler_fetch_status_messages($scode);
		    $dcode = $rpt_hash['per_recipient'][$i]['Diagnostic-code']['text'];  //Diagnostic code
			if ($dcode) {
				$dcode_status_messages = static::bouncehandler_fetch_status_messages($dcode);
			}
			// Add to output
		    $output['bounced_addresses'][] = $curr_recipient;
			if (!empty($scode_status_messages)) {
			    $output['status_messages'][] = $scode_status_messages['main_title'] .': '. $scode_status_messages['subclass_title'];
				$output['status_messages_details'][] = $scode_status_messages['main_desc'] .' --- '. $scode_status_messages['subclass_desc'];
			}
			if ($dcode) {  //add these to the same arrays where they are appropiate
			    $output['status_messages'][] = $dcode;
				if (!empty($dcode_status_messages)) {
				    $output['status_messages'][] = $dcode_status_messages['main_title'] .': '. $dcode_status_messages['subclass_title'];
					$output['status_messages_details'][] = $dcode_status_messages['main_desc'] .' --- '. $dcode_status_messages['subclass_desc'];
				}
			}
		    $output['status_codes'][] = $scode;
			// Write if verbose_mode
			if ($verbose_mode) {
				echo '<div>RECIPIENT: '. $curr_recipient .'</div>';
				echo '<div>STATUS CODE: '. $scode .'</div>';
			    if ($dcode) {
			        echo '<div>DIAGNOSTIC CODE: '. $dcode .'</div>';
			    } else {
			        echo '<div>DIAGNOSTIC CODE: n/a</div>';
			    }
			}
		}
		//Get original To, From, and Subject
		$head = static::get_head_from_returned_message_body_part($mime_sections);
		$output['original_from'] = $head['From'];
		$output['original_to'] = $head['To'];
		$output['original_subject'] = $head['Subject'];
		$output['parse_method'] = 'rfc1892';
		if ($verbose_mode) {
			echo '<div>FROM: '. $head['From'] .'</div>';
			echo '<div>TO: '. $head['To'] .'</div>';
			echo '<div>SUBJ: '. $head['Subject'] .'</div>';
		}
		return $output;
	}

	public static function bouncehandler_initialize($blob, $format){
		// Internal function
	    if ($format == 'xml_array') {
	        $strEmail = '';
	        for($i=0; $i<$blob; $i++){
	            $blob[$i] = preg_replace("/<HEADER>/i", "", $blob[$i]);
	            $blob[$i] = preg_replace("/</HEADER>/i", "", $blob[$i]);
	            $blob[$i] = preg_replace("/<MESSAGE>/i", "", $blob[$i]);
	            $blob[$i] = preg_replace("/</MESSAGE>/i", "", $blob[$i]);
	            $blob[$i] = str_replace("\r\n", "\n", $blob[$i]);
	            $blob[$i] = str_replace("\n", "\r\n", $blob[$i]);
	            $strEmail .= $blob[$i];
	        }
	    }
	    else if($format=='string'){
	        $blob = str_replace("\r\n", "\n", $blob);
	        $blob = str_replace("\n", "\r\n", $blob);
			$strEmail = $blob;
	    }
	    else if($format=='array'){
	        $strEmail = '';
	        for($i=0; $i<$blob; $i++){
	            $blob[$i] = str_replace("\r\n", "\n", $blob[$i]);
	            $blob[$i] = str_replace("\n", "\r\n", $blob[$i]);
	            $strEmail .= $blob[$i];
	        }
	    }
	    return $strEmail;
	}

	public static function is_RFC1892_multipart_report($head_hash){
		// Internal function
	    return @$head_hash['Content-type']['type']=='multipart/report'
	       &&  @$head_hash['Content-type']['report-type']=='delivery-status'
	       && @$head_hash['Content-type']['boundary']!=='';
	}

	public static function bouncehandler_parse_head($headers){
		// Internal function
	    if(!is_array($headers)) $headers = explode("\r\n", $headers);
	    $hash = static::bouncehandler_standard_parser($headers);
	    // get a little more complex
	    $arrRec = explode('|', $hash['Received']);
	    $hash['Received']= $arrRec;
	    if(preg_match('/Multipart\/Report/i', $hash['Content-type'])){
	        $multipart_report = explode (';', $hash['Content-type']);
	        $hash['Content-type']=[];
	        $hash['Content-type']['type'] = 'multipart/report';
	        foreach ($multipart_report as $mr) {
	            if (preg_match('/([a-z0-9\-\_\/]*)=(.*)?/i', $mr, $matches)) {  //previous: .*   now: [a-z0-9\-\_\/]*  (the previous caught too much data)
	                $hash['Content-type'][ strtolower(trim($matches[1])) ]= str_replace('"','',$matches[2]);
	            }
	        }
	    }
	    return $hash;
	}

	public static function parse_body_into_mime_sections($body, $boundary){
	    if(!$boundary) return [];
	    if(is_array($body)) $body = implode("\r\n", $body);
	    $body = explode($boundary, $body);
	    $mime_sections['first_body_part'] = $body[1];
	    $mime_sections['machine_parsable_body_part'] = $body[2];
	    $mime_sections['returned_message_body_part'] = $body[3];
	    return $mime_sections;
	}

	public static function bouncehandler_standard_parser($content){ // associative array orstr
		// Internal function
	    // Receives email head as array of lines
	    // simple parse (Entity: value\n)
	    if(!is_array($content)) $content = explode("\r\n", $content);
	    foreach($content as $line){
	        if(preg_match('/([^\s.]*):\s(.*)/', $line, $array)){
	            $entity = ucfirst(strtolower($array[1]));
	            if(! $hash[$entity]){
	                $hash[$entity] = $array[2];
	            }
	            else if($hash['Received']){
	                // grab extra Received headers :(
	                // pile it on with pipe delimiters,
	                // oh well, SMTP is broken in this way
	                if ($entity and $array[2] and $array[2] != $hash[$entity]){
	                    $hash[$entity] .= "|" . $array[2];
	                }
	            }
	        }
	        else{
	            if ($entity){
	                $hash[$entity] .= " $line";
	            }
	        }
	    }
	    return $hash;
	}

	public static function parse_machine_parsable_body_part($str){
		// Internal function
	    //Per-Message DSN fields
	    $hash = static::bouncehandler_parse_dsn_fields($str);
	    $hash['mime_header'] = static::bouncehandler_standard_parser($hash['mime_header']);
	    $hash['per_message'] = static::bouncehandler_standard_parser($hash['per_message']);
	    if($hash['per_message']['X-postfix-sender']){
	        $arr = explode (';', $hash['per_message']['X-postfix-sender']);
	        $hash['per_message']['X-postfix-sender'] = [];
	        $hash['per_message']['X-postfix-sender']['type'] = trim($arr[0]);
	        $hash['per_message']['X-postfix-sender']['addr'] = trim($arr[1]);
	    }
	    if($hash['per_message']['Reporting-mta']){
	        $arr = explode (';', $hash['per_message']['Reporting-mta']);
	        $hash['per_message']['Reporting-mta'] = [];
	        $hash['per_message']['Reporting-mta']['type'] = trim($arr[0]);
	        $hash['per_message']['Reporting-mta']['addr'] = trim($arr[1]);
	    }
	    //Per-Recipient DSN fields
	    for($i=0; $i<count($hash['per_recipient']); $i++){
	        $temp = static::bouncehandler_standard_parser(explode("\r\n", $hash['per_recipient'][$i]));
	        $arr = explode (';', $temp['Final-recipient']);
	        $temp['Final-recipient'] = [];
	        $temp['Final-recipient']['type'] = trim($arr[0]);
	        $temp['Final-recipient']['addr'] = trim($arr[1]);
	        $arr = explode (';', $temp['Original-recipient']);
	        $temp['Original-recipient'] = [];
	        $temp['Original-recipient']['type'] = trim($arr[0]);
	        $temp['Original-recipient']['addr'] = trim($arr[1]);
	        $arr = explode (';', $temp['Diagnostic-code']);
	        $temp['Diagnostic-code'] = [];
	        $temp['Diagnostic-code']['type'] = trim($arr[0]);
	        $temp['Diagnostic-code']['text'] = trim($arr[1]);
	        $hash['per_recipient'][$i]=$temp;
	    }
	    return $hash;
	}

	public static function get_head_from_returned_message_body_part($mime_sections){
		// Internal function
	    $temp = explode("\r\n\r\n", $mime_sections['returned_message_body_part']);
	    $head = static::bouncehandler_standard_parser($temp[1]);
	    $head['From'] = static::bouncehandler_extract_address($head['From']);
	    $head['To'] = static::bouncehandler_extract_address($head['To']);
	    return $head;
	}

	public static function bouncehandler_extract_address($str){
		// Internal function
	    $from_stuff = preg_split('/[ \"\'\<\>:\(\)\[\]]/', $str);
	    foreach ($from_stuff as $things){
	        if (strpos($things, '@')!==FALSE){$from = $things;}
	    }
	    return $from;
	}

	public static function bouncehandler_get_recipient($per_rcpt){
		// Internal function
	    if($per_rcpt['Original-recipient']['addr'] !== '') return $per_rcpt['Original-recipient']['addr'];
	    else if($per_rcpt['Final-recipient']['addr'] !== '') return $per_rcpt['Final-recipient']['addr'];
	}

	public static function bouncehandler_parse_dsn_fields($dsn_fields){
		// Internal function
	    if (!is_array($dsn_fields)) $dsn_fields = explode("\r\n\r\n", $dsn_fields);
	    $j = 0;
	    for ($i=0; $i<count($dsn_fields); $i++) {
	        if ($i==0) $hash['mime_header'] = $dsn_fields[0];
	        if ($i==1) $hash['per_message'] = $dsn_fields[1];
	        else if ($i >=2) {
	            if (trim($dsn_fields[$i]) == '--') { //trim() added by Allan Jensen (sometimes a line-break existing before the two hyphens)
					continue;
				}
	            $hash['per_recipient'][$j] = $dsn_fields[$i];
	            $j++;
	        }
	    }
	    return $hash;
	}

	public static function bouncehandler_format_status_code($code){
		// Internal function
	    if(preg_match('/([245]\.[01234567]\.[012345678])(.*)/', $code, $matches)){
	        $ret['code'] = $matches[1];
	        $ret['text'] = $matches[2];
	    }
	    else if(preg_match('/([245][01234567][012345678])(.*)/', $code, $matches)){
	        preg_match_all("/./", $matches[1], $out);
	        $ret['code'] = $out[0];
			if (is_array($ret['code'])) {  //Allan Jensen added this if statement because the output of $ret['code'] must be a string (must have been a mistake in the original code)
				$ret['code'] = implode('.', $ret['code']);
			}
	        $ret['text'] = $matches[2];
	    }
	    return $ret;
	}

	public static function bouncehandler_fetch_status_messages($code, $verbose_mode = false){
		/* --- BEGINNNING OF ERROR CODES ---------------------------------------------------- */
		// NOTE: this use to be located in a separate file but was moved here for convenience

		// RFC1893 error codes
		// cfortune@telus.net
		// open-source under BSD license http://opensource.org/licenses/bsd-license.php
		// If you improve this code, please send me a copy

		$status_code_classes['2']['title'] =  "Success";
		$status_code_classes['2']['descr'] =  "Success specifies that the DSN is reporting a positive delivery action.  Detail sub-codes may provide notification of transformations required for delivery.";

		$status_code_classes['4']['title'] =  "Persistent Transient Failure";
		$status_code_classes['4']['descr'] =  "A persistent transient failure is one in which the message as sent is valid, but some temporary event prevents the successful sending of the message.  Sending in the future may be successful.";

		$status_code_classes['5']['title'] =  "Permanent Failure";
		$status_code_classes['5']['descr'] =  "A permanent failure is one which is not likely to be resolved by resending the message in the current form.  Some change to the message or the destination must be made for successful delivery.";

		$status_code_subclasses['0.0']['title'] =  "Other undefined Status";
		$status_code_subclasses['0.0']['descr'] =  "Other undefined status is the only undefined error code. It should be used for all errors for which only the class of the error is known.";

		$status_code_subclasses['1.0']['title'] =  "Other address status";
		$status_code_subclasses['0.0']['descr'] =  "Something about the address specified in the message caused this DSN.";

		$status_code_subclasses['1.1']['title'] =  "Bad destination mailbox address";
		$status_code_subclasses['1.1']['descr'] =  "The mailbox specified in the address does not exist.  For Internet mail names, this means the address portion to the left of the @ sign is invalid.  This code is only useful for permanent failures.";

		$status_code_subclasses['1.2']['title'] =  "Bad destination system address";
		$status_code_subclasses['1.2']['descr'] =  "The destination system specified in the address does not exist or is incapable of accepting mail.  For Internet mail names, this means the address portion to the right of the @ is invalid for mail.  This codes is only useful for permanent failures.";

		$status_code_subclasses['1.3']['title'] = "Bad destination mailbox address syntax";
		$status_code_subclasses['1.3']['descr'] =  "The destination address was syntactically invalid.  This can apply to any field in the address.  This code is only useful for permanent failures.";

		$status_code_subclasses['1.4']['title'] = "Destination mailbox address ambiguous";
		$status_code_subclasses['1.4']['descr'] =  "The mailbox address as specified matches one or more recipients on the destination system.  This may result if a heuristic address mapping algorithm is used to map the specified address to a local mailbox name.";

		$status_code_subclasses['1.5']['title'] = "Destination address valid";
		$status_code_subclasses['1.5']['descr'] =  "This mailbox address as specified was valid.  This status code should be used for positive delivery reports.";

		$status_code_subclasses['1.6']['title'] = "Destination mailbox has moved, No forwarding address";
		$status_code_subclasses['1.6']['descr'] =  "The mailbox address provided was at one time valid, but mail is no longer being accepted for that address.  This code is only useful for permanent failures.";

		$status_code_subclasses['1.7']['title'] = "Bad sender's mailbox address syntax";
		$status_code_subclasses['1.7']['descr'] =  "The sender's address was syntactically invalid.  This can apply to any field in the address.";

		$status_code_subclasses['1.8']['title'] = "Bad sender's system address";
		$status_code_subclasses['1.8']['descr'] =  "The sender's system specified in the address does not exist or is incapable of accepting return mail.  For domain names, this means the address portion to the right of the @ is invalid for mail. ";

		$status_code_subclasses['2.0']['title'] = "Other or undefined mailbox status";
		$status_code_subclasses['2.0']['descr'] =  "The mailbox exists, but something about the destination mailbox has caused the sending of this DSN.";

		$status_code_subclasses['2.1']['title'] = "Mailbox disabled, not accepting messages";
		$status_code_subclasses['2.1']['descr'] =  "The mailbox exists, but is not accepting messages.  This may be a permanent error if the mailbox will never be re-enabled or a transient error if the mailbox is only temporarily disabled.";

		$status_code_subclasses['2.2']['title'] = "Mailbox full";
		$status_code_subclasses['2.2']['descr'] =  "The mailbox is full because the user has exceeded a per-mailbox administrative quota or physical capacity.  The general semantics implies that the recipient can delete messages to make more space available.  This code should be used as a persistent transient failure.";

		$status_code_subclasses['2.3']['title'] = "Message length exceeds administrative limit";
		$status_code_subclasses['2.2']['descr'] =  "A per-mailbox administrative message length limit has been exceeded.  This status code should be used when the per-mailbox message length limit is less than the general system limit.  This code should be used as a permanent failure.";

		$status_code_subclasses['2.4']['title'] = "Mailing list expansion problem";
		$status_code_subclasses['2.4']['descr'] =  "The mailbox is a mailing list address and the mailing list was unable to be expanded.  This code may represent a permanent failure or a persistent transient failure. ";

		$status_code_subclasses['3.0']['title'] = "Other or undefined mail system status";
		$status_code_subclasses['3.0']['descr'] =  "The destination system exists and normally accepts mail, but something about the system has caused the generation of this DSN.";

		$status_code_subclasses['3.1']['title'] = "Mail system full";
		$status_code_subclasses['3.1']['descr'] =  "Mail system storage has been exceeded.  The general semantics imply that the individual recipient may not be able to delete material to make room for additional messages.  This is useful only as a persistent transient error.";

		$status_code_subclasses['3.2']['title'] = "System not accepting network messages";
		$status_code_subclasses['3.2']['descr'] =  "The host on which the mailbox is resident is not accepting messages.  Examples of such conditions include an immanent shutdown, excessive load, or system maintenance.  This is useful for both permanent and permanent transient errors. ";

		$status_code_subclasses['3.3']['title'] = "System not capable of selected features";
		$status_code_subclasses['3.3']['descr'] =  "Selected features specified for the message are not supported by the destination system.  This can occur in gateways when features from one domain cannot be mapped onto the supported feature in another.";

		$status_code_subclasses['3.4']['title'] = "Message too big for system";
		$status_code_subclasses['3.4']['descr'] =  "The message is larger than per-message size limit.  This limit may either be for physical or administrative reasons. This is useful only as a permanent error.";

		$status_code_subclasses['3.5']['title'] = "System incorrectly configured";
		$status_code_subclasses['3.5']['descr'] =  "The system is not configured in a manner which will permit it to accept this message.";

		$status_code_subclasses['4.0']['title'] = "Other or undefined network or routing status";
		$status_code_subclasses['4.0']['descr'] =  "Something went wrong with the networking, but it is not clear what the problem is, or the problem cannot be well expressed with any of the other provided detail codes.";

		$status_code_subclasses['4.1']['title'] = "No answer from host";
		$status_code_subclasses['4.1']['descr'] =  "The outbound connection attempt was not answered, either because the remote system was busy, or otherwise unable to take a call.  This is useful only as a persistent transient error.";

		$status_code_subclasses['4.2']['title'] = "Bad connection";
		$status_code_subclasses['4.2']['descr'] =  "The outbound connection was established, but was otherwise unable to complete the message transaction, either because of time-out, or inadequate connection quality. This is useful only as a persistent transient error.";

		$status_code_subclasses['4.3']['title'] = "Directory server failure";
		$status_code_subclasses['4.3']['descr'] =  "The network system was unable to forward the message, because a directory server was unavailable.  This is useful only as a persistent transient error. The inability to connect to an Internet DNS server is one example of the directory server failure error. ";

		$status_code_subclasses['4.4']['title'] = "Unable to route";
		$status_code_subclasses['4.4']['descr'] =  "The mail system was unable to determine the next hop for the message because the necessary routing information was unavailable from the directory server. This is useful for both permanent and persistent transient errors.  A DNS lookup returning only an SOA (Start of Administration) record for a domain name is one example of the unable to route error.";

		$status_code_subclasses['4.5']['title'] = "Mail system congestion";
		$status_code_subclasses['4.5']['descr'] =  "The mail system was unable to deliver the message because the mail system was congested. This is useful only as a persistent transient error.";

		$status_code_subclasses['4.6']['title'] = "Routing loop detected";
		$status_code_subclasses['4.6']['descr'] =  "A routing loop caused the message to be forwarded too many times, either because of incorrect routing tables or a user forwarding loop. This is useful only as a persistent transient error.";

		$status_code_subclasses['4.7']['title'] = "Delivery time expired";
		$status_code_subclasses['4.7']['descr'] =  "The message was considered too old by the rejecting system, either because it remained on that host too long or because the time-to-live value specified by the sender of the message was exceeded. If possible, the code for the actual problem found when delivery was attempted should be returned rather than this code.  This is useful only as a persistent transient error.";

		$status_code_subclasses['5.0']['title'] = "Other or undefined protocol status";
		$status_code_subclasses['5.0']['descr'] =  "Something was wrong with the protocol necessary to deliver the message to the next hop and the problem cannot be well expressed with any of the other provided detail codes.";

		$status_code_subclasses['5.1']['title'] = "Invalid command";
		$status_code_subclasses['5.1']['descr'] =  "A mail transaction protocol command was issued which was either out of sequence or unsupported.  This is useful only as a permanent error.";

		$status_code_subclasses['5.2']['title'] = "Syntax error";
		$status_code_subclasses['5.2']['descr'] =  "A mail transaction protocol command was issued which could not be interpreted, either because the syntax was wrong or the command is unrecognized. This is useful only as a permanent error.";

		$status_code_subclasses['5.3']['title'] = "Too many recipients";
		$status_code_subclasses['5.3']['descr'] =  "More recipients were specified for the message than could have been delivered by the protocol.  This error should normally result in the segmentation of the message into two, the remainder of the recipients to be delivered on a subsequent delivery attempt.  It is included in this list in the event that such segmentation is not possible.";

		$status_code_subclasses['5.4']['title'] = "Invalid command arguments";
		$status_code_subclasses['5.4']['descr'] =  "A valid mail transaction protocol command was issued with invalid arguments, either because the arguments were out of range or represented unrecognized features. This is useful only as a permanent error. ";

		$status_code_subclasses['5.5']['title'] = "Wrong protocol version";
		$status_code_subclasses['5.5']['descr'] =  "A protocol version mis-match existed which could not be automatically resolved by the communicating parties.";

		$status_code_subclasses['6.0']['title'] = "Other or undefined media error";
		$status_code_subclasses['6.0']['descr'] =  "Something about the content of a message caused it to be considered undeliverable and the problem cannot be well expressed with any of the other provided detail codes. ";

		$status_code_subclasses['6.1']['title'] = "Media not supported";
		$status_code_subclasses['6.1']['descr'] =  "The media of the message is not supported by either the delivery protocol or the next system in the forwarding path. This is useful only as a permanent error.";

		$status_code_subclasses['6.2']['title'] = "Conversion required and prohibited";
		$status_code_subclasses['6.2']['descr'] =  "The content of the message must be converted before it can be delivered and such conversion is not permitted.  Such prohibitions may be the expression of the sender in the message itself or the policy of the sending host.";

		$status_code_subclasses['6.3']['title'] = "Conversion required but not supported";
		$status_code_subclasses['6.3']['descr'] =  "The message content must be converted to be forwarded but such conversion is not possible or is not practical by a host in the forwarding path.  This condition may result when an ESMTP gateway supports 8bit transport but is not able to downgrade the message to 7 bit as required for the next hop.";

		$status_code_subclasses['6.4']['title'] = "Conversion with loss performed";
		$status_code_subclasses['6.4']['descr'] =  "This is a warning sent to the sender when message delivery was successfully but when the delivery required a conversion in which some data was lost.  This may also be a permanant error if the sender has indicated that conversion with loss is prohibited for the message.";

		$status_code_subclasses['6.5']['title'] = "Conversion Failed";
		$status_code_subclasses['6.5']['descr'] =  "A conversion was required but was unsuccessful.  This may be useful as a permanent or persistent temporary notification.";

		$status_code_subclasses['7.0']['title'] = "Other or undefined security status";
		$status_code_subclasses['7.0']['descr'] =  "Something related to security caused the message to be returned, and the problem cannot be well expressed with any of the other provided detail codes.  This status code may also be used when the condition cannot be further described because of security policies in force.";

		$status_code_subclasses['7.1']['title'] = "Delivery not authorized, message refused";
		$status_code_subclasses['7.1']['descr'] =  "The sender is not authorized to send to the destination. This can be the result of per-host or per-recipient filtering.  This memo does not discuss the merits of any such filtering, but provides a mechanism to report such. This is useful only as a permanent error.";

		$status_code_subclasses['7.2']['title'] = "Mailing list expansion prohibited";
		$status_code_subclasses['7.2']['descr'] =  "The sender is not authorized to send a message to the intended mailing list. This is useful only as a permanent error.";

		$status_code_subclasses['7.3']['title'] = "Security conversion required but not possible";
		$status_code_subclasses['7.3']['descr'] =  "A conversion from one secure messaging protocol to another was required for delivery and such conversion was not possible. This is useful only as a permanent error. ";

		$status_code_subclasses['7.4']['title'] = "Security features not supported";
		$status_code_subclasses['7.4']['descr'] =  "A message contained security features such as secure authentication which could not be supported on the delivery protocol. This is useful only as a permanent error.";

		$status_code_subclasses['7.5']['title'] = "Cryptographic failure";
		$status_code_subclasses['7.5']['descr'] =  "A transport system otherwise authorized to validate or decrypt a message in transport was unable to do so because necessary information such as key was not available or such information was invalid.";

		$status_code_subclasses['7.6']['title'] = "Cryptographic algorithm not supported";
		$status_code_subclasses['7.6']['descr'] =  "A transport system otherwise authorized to validate or decrypt a message was unable to do so because the necessary algorithm was not supported. ";

		$status_code_subclasses['7.7']['title'] = "Message integrity failure";
		$status_code_subclasses['7.7']['descr'] =  "A transport system otherwise authorized to validate a message was unable to do so because the message was corrupted or altered.  This may be useful as a permanent, transient persistent, or successful delivery code.";
		/* --- END OF ERROR CODES ---------------------------------------------------- */

	    $ret = static::bouncehandler_format_status_code($code);
	    $arr = explode('.', $ret['code']);
		if ($verbose_mode) {
		    echo "<p><b>". $status_code_classes[$arr[0]]['title'] . "</b> - " .$status_code_classes[$arr[0]]['descr']. "  <b>". $status_code_subclasses[$arr[1].".".$arr[2]]['title'] . "</b> - " .$status_code_subclasses[$arr[1].".".$arr[2]]['descr']. "</p>";
		}
		if ($status_code_classes[$arr[0]]['title']) {
			return [
				'main_title' => $status_code_classes[$arr[0]]['title'],
				'main_desc' => $status_code_classes[$arr[0]]['descr'],
				'subclass_title' => $status_code_subclasses[$arr[1].".".$arr[2]]['title'],
				'subclass_desc' => $status_code_subclasses[$arr[1].".".$arr[2]]['descr']
			];
		} else {
			return [];
		}
	}

	public static function bouncehandler_decode_diagnostic_code($dcode){
		// Internal function
	    if(preg_match("/(\d\.\d\.\d)\s/", $dcode, $array)){
	        return $array[1];
	    }
	    else if(preg_match("/(\d\d\d)\s/", $dcode, $array)){
	        return $array[1];
	    }
	}
}