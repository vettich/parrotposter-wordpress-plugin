<?php
/**
 * Compat HTTP helper. Expects COMPAT_URL, optional COMPAT_SECRET, COMPAT_EXPECT_CODE, COMPAT_JSON_KEY.
 */
$url = getenv('COMPAT_URL');
$secret = getenv('COMPAT_SECRET');
$expect = (int) getenv('COMPAT_EXPECT_CODE');
$json_key = getenv('COMPAT_JSON_KEY');

if (!is_string($url) || $url === '' || $expect < 100) {
	fwrite(STDERR, "compat-http: missing COMPAT_URL or COMPAT_EXPECT_CODE\n");
	exit(1);
}

$args = ['timeout' => 20];
if (is_string($secret) && $secret !== '') {
	$args['headers'] = ['Authorization' => 'Bearer ' . $secret];
}

$r = wp_remote_get($url, $args);
if (is_wp_error($r)) {
	fwrite(STDERR, 'compat-http: ' . $r->get_error_message() . "\n");
	exit(1);
}

$code = (int) wp_remote_retrieve_response_code($r);
$body = (string) wp_remote_retrieve_body($r);
if ($code !== $expect) {
	fwrite(STDERR, "compat-http: expected HTTP {$expect}, got {$code}\n{$body}\n");
	exit(1);
}

if (is_string($json_key) && $json_key !== '') {
	$data = json_decode($body, true);
	if (!is_array($data) || !array_key_exists($json_key, $data)) {
		fwrite(STDERR, "compat-http: JSON missing key {$json_key}\n{$body}\n");
		exit(1);
	}
}

echo "http {$code} ok\n";
