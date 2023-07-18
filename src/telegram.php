<?php
/**
 * Functions related to the messaging service Telegram
 */

namespace winternet\jensenfw2;

class telegram {

	public $apiKey;

	public function __construct($apiKey) {
		$this->apiKey = $apiKey;
	}

	/**
	 * Send a message to a Telegram channel
	 *
	 * Telegram API documentation: https://medium.com/in-laravel/sending-a-message-using-telegram-api-in-3-steps-894dbfecfdcc
	 */
	public function send_message($channelID, $message) {
		$url = 'https://api.telegram.org/bot'. $this->apiKey .'/sendMessage?chat_id='. urlencode($channelID) .'&text='. urlencode($message);
		network::http_request('GET', $url);
	}

}
