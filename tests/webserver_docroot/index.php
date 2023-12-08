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
if (!empty($_GET['return_json'])) {
	header('Content-Type: application/json');
	echo json_encode(['someproperty' => 'somevalue']);
} else {
	echo 'Sample Webserver Response'. PHP_EOL;
}
