<?php
namespace winternet\jensenfw2\websocket;

use Ratchet\ConnectionInterface;
use Ratchet\MessageComponentInterface;

class message_server implements MessageComponentInterface {
	protected $clients;
	protected $channels;
	protected $autocreate_channels = false;

	public function __construct($options = []) {
		$this->clients = new \SplObjectStorage;
		$this->channels = [];
		if ($options['channels'] === '*') {
			$this->autocreate_channels = true;
		} else {
			foreach ($options['channels'] as $channel) {
				$this->channels[$channel]['clients'] = new \SplObjectStorage;
			}
		}
	}

	public function onOpen(ConnectionInterface $conn) {
		// Store the new connection to send messages to later
		$this->clients->attach($conn);

		echo "----- New connection! (". $conn->resourceId .")\n";
	}

	public function onMessage(ConnectionInterface $from, $msg) {
		echo sprintf('---------------------------------------------------------------'."\n", $from->resourceId);
		$data = json_decode($msg, true);
		if ($data['action'] == 'joinChannel') {
			if (!$data['channel']) {
				echo sprintf('Connection %d did not specify which channel to join'."\n", $from->resourceId);
			} elseif ($this->channels[$data['channel']] || $this->autocreate_channels) {
				if (!$this->channels[$data['channel']]) {  //auto-create it
					$this->channels[$data['channel']]['clients'] = new \SplObjectStorage;
					echo sprintf('Connection %d create new channel "%s"'."\n", $from->resourceId, $data['channel']);
				}
				if (!$this->channels[$data['channel']]['clients']->contains($from)) {
					$this->channels[$data['channel']]['clients']->attach($from);
					echo sprintf('Connection %d joined channel "%s"'."\n", $from->resourceId, $data['channel']);
				} else {
					echo sprintf('Connection %d already joined channel "%s"'."\n", $from->resourceId, $data['channel']);
				}
			} else {
				echo sprintf('Connection %d tried to join non-existing channel "%s"'."\n", $from->resourceId, $data['channel']);
			}
		} else {
			// $numRecv = count($this->clients) - 1;
			echo sprintf('Connection %d sending msg "%s"'."\n", $from->resourceId, mb_substr($msg, 0, 50));

			if ($data['toChannel'] === '*') {
				echo sprintf('to all clients/channels'."\n");
			}

			foreach ($this->clients as $client) {
				if ($from === $client) {
					// don't send to sender
					echo sprintf('Not to %d (self)'."\n", $client->resourceId);
					continue;
				} elseif (!$data['toChannel']) {
					echo sprintf('Connection %d tried to send msg without specifying channel'."\n", $from->resourceId);
					break;
				} elseif ($data['toChannel'] === '*') {
					// allow
				} else {
					// is for a specific channel
					if (!$this->channels[$data['toChannel']]) {
						echo sprintf('Connection %d tried to send msg to non-existing channel "%s"'."\n", $from->resourceId, $data['toChannel']);
						break;
					} elseif ($data['toChannel'] === '*') {
						// allow
					} elseif (!$this->channels[$data['toChannel']]['clients']->contains($client)) {
						// client is not part of this channel the message is intended for
						echo sprintf('Not to %d'."\n", $client->resourceId);
						continue;
					}
				}

				echo sprintf('Passed to %d'."\n", $client->resourceId);
				$client->send($msg);
			}

		}
		$from->send('1');
	}

	public function onClose(ConnectionInterface $conn) {
		// The connection is closed, remove it, as we can no longer send it messages
		$this->clients->detach($conn);
		foreach ($this->channels as $channel_name => $channel) {
			if ($channel['clients']->contains($conn)) {
				$this->channels[$channel_name]['clients']->detach($conn);
				echo "Connection ". $conn->resourceId ." removed from channel ". $channel_name ."\n";
			}
		}
		echo "----- Connection ". $conn->resourceId ." has disconnected\n";
	}

	public function onError(ConnectionInterface $conn, \Exception $e) {
		echo "----- An error has occurred: ". $e->getMessage() ."\n";
		$conn->close();
	}
}
