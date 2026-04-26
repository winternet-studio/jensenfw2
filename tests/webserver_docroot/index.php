<?php
// This is used for our internal web server that we use for testing our network class in ../networkTest.php

if (!empty($_GET['has_qs'])) {
	echo json_encode($_GET) . PHP_EOL;
}
if (!empty($_GET['is_post'])) {
	echo json_encode($_POST) . PHP_EOL;
}
if (!empty($_GET['has_headers'])) {
	echo json_encode(apache_request_headers()) . PHP_EOL;
}
if (!empty(apache_request_headers()['Content-Type'])) {
	$content_type = apache_request_headers()['Content-Type'];
	if ($content_type == 'application/json') {
		echo 'JSONINPUT='. file_get_contents('php://input');
	}
}
if (isset($_GET['retry_failures'])) {
	$retryKey = preg_replace('/[^a-z0-9_-]/i', '_', @$_GET['retry_key'] ?: 'default');
	$counterFile = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'jensenfw2_http_retry_' . $retryKey;
	$attempt = (is_file($counterFile) ? (int) file_get_contents($counterFile) : 0) + 1;
	file_put_contents($counterFile, (string) $attempt);

	header('Content-Type: application/json');
	if ($attempt <= (int) $_GET['retry_failures']) {
		http_response_code(500);
		echo json_encode(['attempt' => $attempt, 'ok' => false]);
	} else {
		echo json_encode(['attempt' => $attempt, 'ok' => true]);
	}
	exit;
}
if (!empty($_GET['return_json'])) {
	header('Content-Type: application/json');
	echo json_encode(['someproperty' => 'somevalue']);
} else {
	echo 'Sample Webserver Response'. PHP_EOL;
}
