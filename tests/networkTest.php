<?php
use PHPUnit\Framework\TestCase;
use winternet\jensenfw2\network;

/*
register_shutdown_function(function() {
	if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
		exec("taskkill /F /FI \"WINDOWTITLE eq bb7142b072e47b06686452011e7d5c86\"");
	} else {
		exec('pkill -f "php -S '. networkTest::$host .':'. networkTest::$port .'"');
	}
	// echo "Server on http://localhost:8018 stopped\n";
});
*/

final class networkTest extends TestCase {

	public static $host = 'localhost';
	public static $port = '8018';

	/*
	ATTEMPT TO START AND RUN AN INTERNAL WEBSERVER DURING THESE TESTS!
	ChatGPT gave me this but it didn't work on windows at least
	public function startWebserver() {
		if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
			$command = "start /B php -S ". static::$host .':'. static::$port ." -t ". __DIR__ ."\\webserver_docroot -S bb7142b072e47b06686452011e7d5c86";
			pclose(popen($command, 'r'));  //execute the command in the background
		} else {
			$command = 'php -S '. static::$host .':'. static::$port .' -t '. __DIR__ .'/webserver_docroot';
			exec($command . ' > /dev/null 2>&1 &');  //execute the command in the background
		}
		echo "Server started on http://localhost:8018\n";
	}
	*/

	public function isWebServerRunning() {
		$timeout = 1; // connection timeout in seconds
		$socket = @fsockopen(static::$host, static::$port, $errno, $errstr, $timeout);
		if (!$socket) {
			return false;
		} else {
			fclose($socket);
			return true;
		}
	}

	public function testHttpRequest() {
		// $this->startWebserver();

		if (!$this->isWebServerRunning()) {
			$this->markTestSkipped('Webserver is not running. See README.md.');
		}

		// Simple GET with query string
		$response = network::http_request('GET', 'http://'. static::$host .':'. static::$port .'/index.php', [
			'has_qs' => '1',
			'qs1' => 'Alfa',
			'qs2' => 'Bravo',
		]);
		$this->assertStringContainsString('"qs2":"Bravo"', $response);

		// application/x-www-form-urlencoded
		$response = network::http_request('POST', 'http://'. static::$host .':'. static::$port .'/index.php?is_post=1', [
			'field1' => 'Alfa',
			'field2' => 'Bravo',
		]);
		$this->assertStringContainsString('"field2":"Bravo"', $response);

		// application/json
		$response = network::http_request('POST', 'http://'. static::$host .':'. static::$port .'/index.php', [
			'field1' => 'Alfa',
			'field2' => 'Bravo',
		], [
			'send_json' => true,
		]);
		$this->assertStringContainsString('JSONINPUT={"field1":"Alfa","field2":"Bravo"}', $response);

		// Setting headers
		$response = network::http_request('POST', 'http://'. static::$host .':'. static::$port .'/index.php?has_headers=1', null, [
			'headers' => [
				'Authorization' => 'bearer SomeSampleApiKey',
			],
		]);
		$this->assertStringContainsString('"Authorization":"bearer SomeSampleApiKey"', $response);

		// Setting cURL options
		// TODO

		// Parsing JSON response
		$response = network::http_request('POST', 'http://'. static::$host .':'. static::$port .'/index.php?return_json=1', null, [
			'parse_json' => true,
		]);
		$this->assertEquals(['someproperty' => 'somevalue'], $response);

		// Return all request/response details
		$response = network::http_request('POST', 'http://'. static::$host .':'. static::$port .'/index.php?return_json=1', null, [
			'return_all' => true,
			'parse_json' => true,
			'headers' => [
				'My-Own-Header' => 'Important Value',
			],
		]);
		$this->assertEquals('{"someproperty":"somevalue"}', $response->response->body);
		$this->assertEquals(['someproperty' => 'somevalue'], $response->response->body_parsed);
		$this->assertEquals('Important Value', $response->request->headers['my-own-header']);
		$this->assertEquals('application/json', $response->response->headers['content-type']);
	}
}
