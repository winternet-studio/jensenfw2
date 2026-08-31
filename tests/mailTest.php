<?php
use PHPUnit\Framework\TestCase;
use winternet\jensenfw2\mail;
use winternet\jensenfw2\core;

final class mailTest extends TestCase {

	const TO_HASH_SALT = 'TestSaltValue123';

	public function isInternetAvailable() {
		$conn = @fsockopen('httpbin.org', 443, $errno, $errstr, 3);
		if ($conn) {
			fclose($conn);
			return true;
		}
		return false;
	}

	protected function tearDown(): void {
		core::$userconfig = [];
	}

	public static function provideMailConfig($class_name_short) {
		if ($class_name_short == 'mail') {
			return [
				'use_mailer' => 'url',
				'url_mailer_endpoint' => 'https://httpbin.org/post',
				'url_mailer_to_hash_salt' => self::TO_HASH_SALT,
				'url_mailer_extra_fields' => [
					'src' => 'mailTest.php',
				],
			];
		}
		return [];
	}

	public function testSendEmailUsingUrlMailer() {
		if (!$this->isInternetAvailable()) {
			$this->markTestSkipped('No internet connection to httpbin.org.');
		}

		core::$userconfig = [self::class, 'provideMailConfig'];

		ob_start();
		$result = mail::send_email('sender@example.com', 'Sender Name', ['Recipient Name', 'recipient@example.com'], 'Test subject', 'Test plain text body', false, [
			'cc' => 'cc1@example.com',
			'reply_to' => 'replyto@example.com',
			'enable_debugging' => true,
		]);
		$debugOutput = ob_get_clean();

		// The function completed without triggering an error (which would have called core::system_error())
		$this->assertArrayHasKey('emaillog_rawID', $result);

		// Check that the correct fields were POSTed (as shown by the function's own debug output, which is HTML-escaped)
		$this->assertStringContainsString('[subject] =&gt; Test subject', $debugOutput);
		$this->assertStringContainsString('[body] =&gt; Test plain text body', $debugOutput);
		$this->assertStringContainsString('[isHtml] =&gt; 0', $debugOutput);
		$this->assertStringContainsString('[to] =&gt; recipient@example.com', $debugOutput);
		$this->assertStringContainsString('[toName] =&gt; Recipient Name', $debugOutput);
		$expectedHash = hash('sha512', 'recipient@example.com' . self::TO_HASH_SALT);
		$this->assertStringContainsString('[toHash] =&gt; '. $expectedHash, $debugOutput);
		$this->assertStringContainsString('[cc] =&gt; cc1@example.com', $debugOutput);
		$this->assertStringContainsString('[replyTo] =&gt; replyto@example.com', $debugOutput);
		$this->assertStringContainsString('[fromEmail] =&gt; sender@example.com', $debugOutput);
		$this->assertStringContainsString('[fromName] =&gt; Sender Name', $debugOutput);
		$this->assertStringContainsString('[src] =&gt; mailTest.php', $debugOutput);

		// Check that httpbin.org actually received and echoed back the exact same values (proves the HTTP round-trip really happened)
		$this->assertStringContainsString('&quot;url&quot;: &quot;https://httpbin.org/post&quot;', $debugOutput);
		$this->assertStringContainsString('&quot;subject&quot;: &quot;Test subject&quot;', $debugOutput);
		$this->assertStringContainsString('&quot;to&quot;: &quot;recipient@example.com&quot;', $debugOutput);
		$this->assertStringContainsString('&quot;toHash&quot;: &quot;'. $expectedHash .'&quot;', $debugOutput);
		$this->assertStringContainsString('&quot;src&quot;: &quot;mailTest.php&quot;', $debugOutput);
	}

	public function testSendEmailUsingUrlMailerWithMultipleRecipientsOnlyCcsOnce() {
		if (!$this->isInternetAvailable()) {
			$this->markTestSkipped('No internet connection to httpbin.org.');
		}

		core::$userconfig = [self::class, 'provideMailConfig'];

		ob_start();
		$result = mail::send_email('sender@example.com', 'Sender Name', [
			'multiple' => [
				['name' => 'Recipient One', 'email' => 'recipient1@example.com'],
				['name' => 'Recipient Two', 'email' => 'recipient2@example.com'],
			],
		], 'Test subject', 'Test plain text body', false, [
			'cc' => 'cc1@example.com',
			'enable_debugging' => true,
		]);
		$debugOutput = ob_get_clean();

		$this->assertArrayHasKey('emaillog_rawID', $result);

		// Both main recipients should each get their own request (proof: each address shows up once as a `to`)
		$this->assertStringContainsString('[to] =&gt; recipient1@example.com', $debugOutput);
		$this->assertStringContainsString('[to] =&gt; recipient2@example.com', $debugOutput);
		$this->assertSame(2, substr_count($debugOutput, '&quot;to&quot;: &quot;recipient1@example.com&quot;') + substr_count($debugOutput, '&quot;to&quot;: &quot;recipient2@example.com&quot;'), 'Expected exactly one httpbin echo per main recipient (ie. one request per recipient)');

		// The CC should only be sent along with ONE of the requests, otherwise cc1@example.com would receive one copy per main recipient instead of just one copy in total
		$this->assertSame(1, substr_count($debugOutput, '[cc] =&gt; cc1@example.com'), 'CC should only be included in one of the per-recipient requests');
		$this->assertSame(1, substr_count($debugOutput, '&quot;cc&quot;: &quot;cc1@example.com&quot;'), 'httpbin should confirm the CC field was only actually received in one request');
	}
}
